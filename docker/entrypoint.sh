#!/usr/bin/env bash
#
# Phorge 容器入口脚本
#
# 流程:
#   1. web / daemon 角色直接启动对应前台进程
#   2. migrate / all 角色守卫式生成本地配置；默认控制面原子生成只读的
#      deployment.json，legacy 控制面保持逐项下发 Gorge 配置
#   3. 等待数据库就绪            (PHORGE_WAIT_DB)
#   4. 升级/初始化数据库 schema  (PHORGE_AUTO_UPGRADE)
#   5. migrate 角色退出；all 角色按旧行为启动 phd 与 Web
#
# 各环境变量的含义与默认值见仓库根目录的 .env.example。
#
set -euo pipefail

PHORGE_DIR=/opt/phorge/phorge
STORAGE_BIN="$PHORGE_DIR/bin/storage"
PHD_BIN="$PHORGE_DIR/bin/phd"
CONFIG_BIN="$PHORGE_DIR/bin/config"
CONF_DIR="$PHORGE_DIR/conf/local"
CONF_FILE="$CONF_DIR/local.json"
COLLABORATION_STATE_FILE="$CONF_DIR/collaboration-profile-state.json"
NOTIFICATION_STATE_FILE="$CONF_DIR/gorge-notification-state.json"
TASKQUEUE_STATE_FILE="$CONF_DIR/gorge-taskqueue-state.json"
CONFIG_LOCK_FILE="$CONF_DIR/.entrypoint.lock"

# 数据库就绪探测的重试次数（每次间隔 3 秒）
DB_WAIT_RETRIES=60

# ----- 默认值（可被环境变量覆盖）-----
MYSQL_HOST="${MYSQL_HOST:-mysql}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_USER="${MYSQL_USER:-phorge}"
MYSQL_PASS="${MYSQL_PASS:-phorge}"
PHORGE_BASE_URI="${PHORGE_BASE_URI:-http://127.0.0.1/}"
PHORGE_TIMEZONE="${PHORGE_TIMEZONE:-UTC}"
PHORGE_WAIT_DB="${PHORGE_WAIT_DB:-1}"
PHORGE_AUTO_UPGRADE="${PHORGE_AUTO_UPGRADE:-1}"
PHORGE_START_PHD="${PHORGE_START_PHD:-1}"
PHORGE_PRODUCT_PROFILE="${PHORGE_PRODUCT_PROFILE:-full}"
PHORGE_GORGE_POLICY="${PHORGE_GORGE_POLICY:-required}"
PHORGE_CONTAINER_ROLE="${PHORGE_CONTAINER_ROLE:-all}"
PHORGE_DB_NAMESPACE="${PHORGE_DB_NAMESPACE:-${STORAGE_NAMESPACE:-}}"
PHORGE_CONTROL_PLANE="${PHORGE_CONTROL_PLANE:-legacy}"
DEPLOYMENT_CONFIG_FILE="${PHORGE_DEPLOYMENT_CONFIG:-$CONF_DIR/deployment.json}"
export PHORGE_CONTROL_PLANE PHORGE_GORGE_POLICY

case "$PHORGE_CONTROL_PLANE" in
    deployment|legacy)
        ;;
    *)
        echo "[entrypoint] 未知 PHORGE_CONTROL_PLANE=$PHORGE_CONTROL_PLANE。" >&2
        exit 64
        ;;
esac

# 默认编排把初始化、Web 与守护进程分成三个容器。只有 migrate 角色可以改配置
# 和 schema，避免 Web/daemon 副本同时执行 storage upgrade 或争写 local.json。
# all 保留旧镜像入口语义，供 docker-compose.legacy.yml 与直接 docker run 使用。
case "$PHORGE_CONTAINER_ROLE" in
    web|daemon)
        if [ ! -s "$CONF_FILE" ]; then
            echo "[entrypoint] $PHORGE_CONTAINER_ROLE 角色缺少 $CONF_FILE；请先运行 phorge-migrate。" >&2
            exit 1
        fi
        if [ "$PHORGE_CONTROL_PLANE" = "deployment" ] &&
           [ ! -s "$DEPLOYMENT_CONFIG_FILE" ]; then
            echo "[entrypoint] $PHORGE_CONTAINER_ROLE 角色缺少 $DEPLOYMENT_CONFIG_FILE；拒绝回退旧控制面。" >&2
            exit 1
        fi
        echo "[entrypoint] 启动 $PHORGE_CONTAINER_ROLE 角色: $*"
        exec "$@"
        ;;
    migrate|all)
        ;;
    *)
        echo "[entrypoint] 未知 PHORGE_CONTAINER_ROLE=$PHORGE_CONTAINER_ROLE。" >&2
        exit 64
        ;;
esac

# migrate 与 all 角色会重写共享 phorge-conf 卷中的 local.json。同时启用
# mailer/search 等 profile 时，多个一次性配置容器可能并发执行；
# PhabricatorConfigLocalSource 的读-改-写没有内建锁，因此必须把整个配置与
# profile 合并过程串行化。锁文件位于共享卷，flock 在进程异常退出时
# 会由内核自动释放，不会留下需要手工清理的哨兵状态。
mkdir -p "$CONF_DIR"
exec {CONFIG_LOCK_FD}>"$CONFIG_LOCK_FILE"
echo "[entrypoint] 等待 Phorge 配置写入锁 ..."
flock --exclusive "$CONFIG_LOCK_FD"
echo "[entrypoint] 已获取 Phorge 配置写入锁。"

# ----- 1. 生成本地配置（守卫式）-----
# 仅当 local.json 不存在或为空时才生成：conf/local 在 compose 里由 phorge-conf
# 卷持久化，这样用户在 Web 界面 (Config) 或手工做出的改动不会被下次启动覆盖。
# 需要重新按环境变量生成时，删掉该文件再重启容器即可。
if [ -s "$CONF_FILE" ]; then
    echo "[entrypoint] 已存在 $CONF_FILE，保留现有配置。"
else
    echo "[entrypoint] 生成 Phorge 本地配置 $CONF_FILE ..."
    mkdir -p "$CONF_DIR"
    export CONF_FILE MYSQL_HOST MYSQL_PORT MYSQL_USER MYSQL_PASS
    export PHORGE_DB_NAMESPACE
    export PHORGE_BASE_URI PHORGE_TIMEZONE
    # 一次性 json_encode 写出：既能表达非标量值，也避免多条 `bin/config set`
    # 中间失败留下半成品配置。先写临时文件再 rename，保证原子替换。
    if php -r '
        $config = array(
            "mysql.host"           => getenv("MYSQL_HOST"),
            "mysql.port"           => getenv("MYSQL_PORT"),
            "mysql.user"           => getenv("MYSQL_USER"),
            "mysql.pass"           => getenv("MYSQL_PASS"),
            "storage.default-namespace" =>
                (getenv("PHORGE_DB_NAMESPACE") ?: "phabricator"),
            "phabricator.base-uri" => getenv("PHORGE_BASE_URI"),
            "phabricator.timezone" => getenv("PHORGE_TIMEZONE"),
        );
        $file = getenv("CONF_FILE");
        $tmp = $file.".tmp";
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        if (file_put_contents($tmp, $json) === false) {
            exit(1);
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            exit(1);
        }
    '; then
        chown www-data:www-data "$CONF_FILE" || true
        chmod 0640 "$CONF_FILE" || true
    else
        # 不让配置生成失败拖垮整个容器：Phorge 在没有 local.json 时会展示
        # 自带的初始化引导页，进容器手工修 conf/local 比容器起不来好排查。
        echo "[entrypoint] 警告: 配置生成失败，Phorge 将以未配置状态启动。" >&2
    fi
fi

# ----- 2. 兼容控制面 -----
# 默认栈使用后文一次原子替换的 deployment.json。这里保留旧控制面，供
# docker-compose.legacy.yml、旧 Gorge overlay 和直接 docker run 使用；这条路径
# 仍按原来的逐项配置语义运行，不会因镜像升级被悄悄切换。
if [ "$PHORGE_CONTROL_PLANE" = "legacy" ]; then

# 下发 Gorge 服务配置（非守卫式，刻意与上面相反）
# gorge.render.uri / token 与 notification.servers 描述的都是部署拓扑，应该跟着
# 编排走而不是跟着 phorge-conf 卷走：上面那段只在首次生成 local.json 时写入，
# 已经跑过的实例光加环境变量不会生效。这里每次启动都用 bin/config set 幂等重写
# 一遍，于是换服务地址或轮换 token 只要改 .env 重启容器，不必删 local.json。
#
# 放在等待数据库之前是安全的：bin/config 不带 --database 时写的是
# conf/local/local.json（PhabricatorConfigLocalSource），且 bin/config 经由
# scripts/init/init-setup.php 以 config.optional 方式初始化，数据库连不上也能跑。
#
# 函数额外把「这一次到底写没写进去」留在 GORGE_CONFIG_SET_OK 里（1 写成功，0 没写）。
# 返回码仍恒为 0，调用方不检查它也不受影响；webhook 与 taskqueue 会读取
# GORGE_CONFIG_SET_OK，因为这两个域的配置同时决定消费者所有权，理由见对应段落。
gorge_config_set() {
    gorge_key="$1"
    gorge_value="$2"

    GORGE_CONFIG_SET_OK=0

    # 值为空就整项跳过：既不写空值，也不删除已有配置 —— 用户可能在 Web 界面
    # (Config) 手工配过，环境变量缺失不该被理解成「要清掉它」。
    if [ -z "$gorge_value" ]; then
        echo "[entrypoint] 未提供 $gorge_key 对应的环境变量，保留现有配置。"
        return 0
    fi

    if "$CONFIG_BIN" set "$gorge_key" "$gorge_value"; then
        GORGE_CONFIG_SET_OK=1
        # bin/config 以 root 运行，若 local.json 此前不存在会被建成 root 属主；
        # Apache 与 phd 都以 www-data 运行，读不到就等于没配。属主与权限保持与
        # 上面守卫块写出的文件一致。
        chown www-data:www-data "$CONF_FILE" || true
        chmod 0640 "$CONF_FILE" || true
    else
        # 不让它拖垮容器：这些配置项对应的都是可回退的功能（配不上就退回 Phorge 自带
        # 的实现）。最常见的失败是配置项还没在对应的
        # PhabricatorApplicationConfigOptions 子类里声明、或类映射未重新生成，此时
        # bin/config 报 "Configuration key is unknown"。
        echo "[entrypoint] 警告: 写入 $gorge_key 失败，对应的 Gorge 功能可能不生效。" >&2
    fi

    return 0
}

# 删除由部署拥有的本地配置。先直接检查 local.json，避免
# `bin/config delete` 在键未设置时把幂等清理当成错误。
gorge_config_delete() {
    gorge_key="$1"

    if php -r '
        $file = $argv[1];
        $key = $argv[2];
        if (!is_file($file)) { exit(1); }
        $config = json_decode(file_get_contents($file), true);
        if (!is_array($config)) { exit(2); }
        exit(array_key_exists($key, $config) ? 0 : 1);
    ' "$CONF_FILE" "$gorge_key"; then
        if "$CONFIG_BIN" delete "$gorge_key"; then
            echo "[entrypoint] 已删除本地配置 $gorge_key。"
            chown www-data:www-data "$CONF_FILE" || true
            chmod 0640 "$CONF_FILE" || true
            return 0
        fi
        echo "[entrypoint] 警告: 删除 $gorge_key 失败。" >&2
        return 1
    else
        gorge_status=$?
        if [ "$gorge_status" = "1" ]; then
            echo "[entrypoint] 本地配置 $gorge_key 未设置，无需删除。"
            return 0
        fi
        echo "[entrypoint] 警告: 无法解析 $CONF_FILE，未删除 $gorge_key。" >&2
        return 1
    fi
}

case "$PHORGE_GORGE_POLICY" in
    required|fallback|off)
        ;;
    *)
        echo "[entrypoint] 未知 PHORGE_GORGE_POLICY=$PHORGE_GORGE_POLICY。" >&2
        exit 64
        ;;
esac
gorge_config_set 'gorge.service-policy' "$PHORGE_GORGE_POLICY"

# 此处先记录并写入由部署拓扑拥有的本地配置。应用停用列表和 Maniphest 自定义字段
# 可能由 Web UI 存在数据库配置，而数据库源优先于 local.json；它们要等 schema
# 就绪后由 manage_collaboration_profile.php 基于有效配置合并。
configure_product_profile_local() {
    export GITEA_BASE_URI GORGE_RENDER_URI
    if ! php "$PHORGE_DIR/scripts/setup/manage_collaboration_local.php" \
        "$PHORGE_PRODUCT_PROFILE" "$CONF_FILE" \
        "$COLLABORATION_STATE_FILE"; then
        echo "[entrypoint] 警告: 产品模式本地配置失败，保留当前配置。" >&2
        return 1
    fi
    chown www-data:www-data "$CONF_FILE" || true
    chmod 0640 "$CONF_FILE" || true
}

# 不叠加 docker-compose.gorge.yml 时这两个变量都不存在，整段等于不执行。
if [ -n "${GORGE_RENDER_URI:-}" ] || [ -n "${GORGE_RENDER_TOKEN:-}" ]; then
    echo "[entrypoint] 下发 Gorge 高亮配置 ..."
    gorge_config_set 'gorge.render.uri' "${GORGE_RENDER_URI:-}"
    gorge_config_set 'gorge.render.token' "${GORGE_RENDER_TOKEN:-}"
    # 刻意不在这里轮询 gorge-render 的 /healthz：叠加编排已用
    # depends_on.condition=service_healthy 保证了启动顺序，再等一次是冗余的；
    # 而当 GORGE_RENDER_URI 指向 compose 之外的服务时，高亮不可用也不该阻塞
    # Apache 启动 —— 它只影响代码着色，且 PhabricatorGorgeSetupCheck 会在 Config
    # 页面把探活失败报出来。
else
    echo "[entrypoint] 未设置 GORGE_RENDER_URI，跳过 Gorge 高亮配置。"
fi

# conduit 网关和高亮一样是最简单的那一类：gorge.conduit.uri 与 gorge.conduit.token
# 都是标量配置项，整项归 Gorge 所有，走现成的 gorge_config_set 就够了 —— 不需要
# --stdin，也不需要像 cluster.mailers / cluster.search 那样先读出来再合并。
#
# 语义上它也是 phorge 主动调用的那一类（PhabricatorGorgeConduitClient 经网关转发
# Conduit 方法调用），所以与高亮同理：写不进去时 phorge 侧的相关调用不可用、但不
# 阻塞容器启动，PhabricatorGorgeConduitSetupCheck 会在 Config 页面把探活失败报出来。
#
# 不叠加 docker-compose.gorge.yml 时这两个变量都不存在，整段等于不执行。
if [ -n "${GORGE_CONDUIT_URI:-}" ] || [ -n "${GORGE_CONDUIT_TOKEN:-}" ]; then
    echo "[entrypoint] 下发 Gorge conduit 网关配置 ..."
    gorge_config_set 'gorge.conduit.uri' "${GORGE_CONDUIT_URI:-}"
    gorge_config_set 'gorge.conduit.token' "${GORGE_CONDUIT_TOKEN:-}"
    # 与高亮那段同样不在这里探 gorge-conduit 的 /healthz：叠加编排已用
    # depends_on.condition=service_healthy 保证了启动顺序，再等一次是冗余的。

    # ----- 把 conduit 网关上游主机名加进 phabricator.allowed-uris（compat 硬约束）-----
    # Phorge 用请求的 Host 头匹配站点（PhabricatorPlatformSite::newSiteForRequest），
    # 只认 phabricator.base-uri / production-uri / allowed-uris 里出现过的 host。
    # gorge-conduit 网关把 worker.execute 转发到 GORGE_CONDUIT_UPSTREAM_URL（默认
    # http://phorge:80），于是到达 phorge 的请求带的是 "Host: phorge" —— 与 base-uri
    # 的 127.0.0.1 不一致，Phorge 返回 "Site Not Found"（HTTP 500），委派的 worker
    # 任务因此永远 temporary-fail、在 worker_activetask 里被反复重领。把网关上游 host
    # 显式登记进 allowed-uris 即可放行这条内网回调链路。
    #
    # 用 --stdin 而不是位置参数：allowed-uris 是 list<string>，`bin/config set k v`
    # 的位置参数只能表达标量。allowed-uris 是 setLocked(true) 的配置项，但 locked 只
    # 在带 --database（写数据库源）时被拒，写 local 源（不带 --database）是允许的 ——
    # 与上面 phd.taskmasters 同理。
    GORGE_CONDUIT_UPSTREAM_URL="${GORGE_CONDUIT_UPSTREAM_URL:-http://phorge:80}"
    export GORGE_CONDUIT_UPSTREAM_URL CONF_FILE
    if gorge_allowed_uris_json="$(php -r '
        $upstream = getenv("GORGE_CONDUIT_UPSTREAM_URL");
        $host = parse_url($upstream, PHP_URL_HOST);
        if (!$host) { exit(1); }
        $scheme = parse_url($upstream, PHP_URL_SCHEME) ?: "http";
        $port = parse_url($upstream, PHP_URL_PORT);
        // allowed-uris 的每一项是一个完整 URI（PhabricatorEnv 里以 base-uri 同样的
        // 规则解析），只保留 host（含非默认端口）即可，路径固定 "/"。
        $default = ($scheme === "https") ? 443 : 80;
        $netloc = $host;
        if ($port && (int)$port !== $default) { $netloc .= ":".$port; }
        $uri = $scheme."://".$netloc."/";

        // local.json 里可能已经有迁移域名或内网别名。allowed-uris 是一个
        // 整体写回的列表，所以只能在现有 local 值后追加并去重，不能用
        // 单元素列表覆盖。解析失败时宁可跳过本次写入，也不丢用户配置。
        $file = getenv("CONF_FILE");
        $config = array();
        if (is_file($file)) {
            $config = json_decode(file_get_contents($file), true);
            if (!is_array($config)) { exit(2); }
        }
        $allowed = isset($config["phabricator.allowed-uris"])
            ? $config["phabricator.allowed-uris"]
            : array();
        if (!is_array($allowed)) { exit(2); }
        if (!in_array($uri, $allowed, true)) {
            $allowed[] = $uri;
        }
        echo json_encode(array_values($allowed), JSON_UNESCAPED_SLASHES);
    ')"; then
        if printf '%s' "$gorge_allowed_uris_json" | "$CONFIG_BIN" set --stdin phabricator.allowed-uris; then
            echo "[entrypoint] 已把 conduit 网关上游 host 登记进 phabricator.allowed-uris：$gorge_allowed_uris_json"
            chown www-data:www-data "$CONF_FILE" || true
            chmod 0640 "$CONF_FILE" || true
        else
            echo "[entrypoint] 警告: 写入 phabricator.allowed-uris 失败，经 gorge-conduit 网关" >&2
            echo "[entrypoint]   委派的 worker.execute 可能因 Host 不匹配而 \"Site Not Found\"。" >&2
        fi
    else
        echo "[entrypoint] 警告: 无法解析 conduit 上游或合并现有 allowed-uris，跳过登记。" >&2
    fi
else
    echo "[entrypoint] 未设置 GORGE_CONDUIT_URI，跳过 Gorge conduit 网关配置。"
fi

# 通知服务器列表走不了上面的 gorge_config_set：notification.servers 的类型是
# cluster.notifications（PhabricatorNotificationServersConfigType），值是一个
# JSON 列表而不是标量，而 `bin/config set <key> <value>` 的位置参数只能表达标量。
# 改用官方支持的 --stdin（PhabricatorConfigManagementSetWorkflow），把 JSON 从
# 管道喂进去。每次启动幂等重写并修正属主与权限；显式 enable/disable 写入失败时
# 让配置任务失败，避免通知仍指向与编排不一致的服务。
#
# 这一项没有「改 Web 界面还是改环境变量」的纠结：notification.servers 是
# setHidden(true) 的配置项，Config 页面上只读，本来就只能落在 local.json 里。
gorge_notification_set() {
    # 端口/协议/路径都有默认值，只有两个 host 必填 —— 它们是本机默认值无法猜准
    # 的部分（见下面对不对称性的说明）。
    GORGE_NOTIFICATION_ADMIN_HOST="${GORGE_NOTIFICATION_ADMIN_HOST:-}"
    GORGE_NOTIFICATION_ADMIN_PORT="${GORGE_NOTIFICATION_ADMIN_PORT:-22281}"
    GORGE_NOTIFICATION_CLIENT_HOST="${GORGE_NOTIFICATION_CLIENT_HOST:-}"
    GORGE_NOTIFICATION_CLIENT_PORT="${GORGE_NOTIFICATION_CLIENT_PORT:-22280}"
    GORGE_NOTIFICATION_CLIENT_PROTOCOL="${GORGE_NOTIFICATION_CLIENT_PROTOCOL:-http}"
    GORGE_NOTIFICATION_CLIENT_PATH="${GORGE_NOTIFICATION_CLIENT_PATH:-}"

    # admin 与 client 缺一不可：校验要求至少各有一条启用的条目，缺任一类就整项
    # 抛异常。写半条比不写更糟——Phorge 的 Config 页面会一直标红，而且这个配置项
    # 在界面上只读，改不回来。
    if [ -z "$GORGE_NOTIFICATION_ADMIN_HOST" ] ||
       [ -z "$GORGE_NOTIFICATION_CLIENT_HOST" ]; then
        echo "[entrypoint] 错误: GORGE_NOTIFICATION_ADMIN_HOST 与 GORGE_NOTIFICATION_CLIENT_HOST 必须同时提供。" >&2
        return 1
    fi

    # 端口先在 shell 里挡一道。下面 php 里的 (int) 会把 "abc" 悄悄变成 0，写进去
    # 类型合法、bin/config 也会报成功，但服务永远连不上，排查要绕一大圈。
    for gorge_port in "$GORGE_NOTIFICATION_ADMIN_PORT" \
                      "$GORGE_NOTIFICATION_CLIENT_PORT"; do
        case "$gorge_port" in
            ''|*[!0-9]*)
                echo "[entrypoint] 错误: 通知服务端口 \"$gorge_port\" 不是数字。" >&2
                return 1
                ;;
        esac
    done

    export GORGE_NOTIFICATION_ADMIN_HOST GORGE_NOTIFICATION_ADMIN_PORT
    export GORGE_NOTIFICATION_CLIENT_HOST GORGE_NOTIFICATION_CLIENT_PORT
    export GORGE_NOTIFICATION_CLIENT_PROTOCOL GORGE_NOTIFICATION_CLIENT_PATH

    # 用 php 的 json_encode 生成而不是 printf 拼字符串，两个理由：
    #   - port 必须是 JSON 整数。校验用 PhutilTypeSpec::checkMap 声明
    #     'port' => 'int'，写成字符串 "22281" 会被直接判非法。
    #   - host 来自环境变量，含引号或反斜杠时拼字符串会拼出非法 JSON。
    # 管道两端的成败都算数，靠的是脚本开头的 `set -o pipefail`：php 生成失败时
    # bin/config 会收到空输入，只看后者就只剩一条语焉不详的 JSON 解析错误。
    if php -r '
        $client = array(
            "type"     => "client",
            "host"     => getenv("GORGE_NOTIFICATION_CLIENT_HOST"),
            "port"     => (int)getenv("GORGE_NOTIFICATION_CLIENT_PORT"),
            "protocol" => getenv("GORGE_NOTIFICATION_CLIENT_PROTOCOL"),
        );
        // path 只在非空时写入。它只对 client 条目合法（admin 条目带 path 会被
        // 明确拒绝），而空字符串在 Phorge 侧等价于没有，不如干脆不写这个键。
        $path = getenv("GORGE_NOTIFICATION_CLIENT_PATH");
        if ($path !== false && $path !== "") {
            $client["path"] = $path;
        }
        $servers = array(
            array(
                "type" => "admin",
                "host" => getenv("GORGE_NOTIFICATION_ADMIN_HOST"),
                "port" => (int)getenv("GORGE_NOTIFICATION_ADMIN_PORT"),
                // admin 固定 http：这一跳只发生在 compose 内网里（phorge 容器到
                // gorge-notification 容器），中间没有 TLS 终止的余地，服务端的
                // admin 口也只讲明文 HTTP。client 那一跳才需要跟着反代变。
                "protocol" => "http",
            ),
            $client,
        );
        echo json_encode($servers, JSON_UNESCAPED_SLASHES);
    ' | "$CONFIG_BIN" set notification.servers --stdin; then
        # 与 gorge_config_set 同样的理由：bin/config 以 root 运行，Apache 与 phd
        # 以 www-data 运行，属主不对就等于没配。
        chown www-data:www-data "$CONF_FILE" || true
        chmod 0640 "$CONF_FILE" || true
    else
        echo "[entrypoint] 错误: 写入 notification.servers 失败。" >&2
        return 1
    fi

    return 0
}

# notification.servers 是一个没有受管 key 的共享整项配置。第一次切到 Gorge 前保存
# 本地源原值；切回 legacy 时只在快照存在的情况下恢复，避免纯 legacy 首次启动就删掉
# 管理员原有的 Aphlict 服务配置。
gorge_notification_capture_state() {
    if [ -e "$NOTIFICATION_STATE_FILE" ]; then
        return 0
    fi

    export CONF_FILE NOTIFICATION_STATE_FILE
    if ! php -r '
        $file = getenv("CONF_FILE");
        $state_file = getenv("NOTIFICATION_STATE_FILE");
        $config = array();
        if (is_file($file)) {
            $config = json_decode(file_get_contents($file), true);
            if (!is_array($config)) { exit(2); }
        }
        $present = array_key_exists("notification.servers", $config);
        $value = $present ? $config["notification.servers"] : null;
        if ($present && !is_array($value)) { exit(3); }

        $state = array("present" => $present, "value" => $value);
        $tmp = $state_file.".tmp";
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $state_file)) {
            @unlink($tmp);
            exit(4);
        }
    '; then
        echo "[entrypoint] 错误: 无法保存 notification.servers 原值，拒绝覆盖该配置。" >&2
        return 1
    fi
    chown www-data:www-data "$NOTIFICATION_STATE_FILE" || true
    chmod 0640 "$NOTIFICATION_STATE_FILE" || true
}

gorge_notification_restore_state() {
    if [ ! -f "$NOTIFICATION_STATE_FILE" ]; then
        echo "[entrypoint] 没有受管通知快照，保留现有 notification.servers。"
        return 0
    fi

    notification_json=''
    if notification_json="$(php -r '
        $state = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($state) || !array_key_exists("present", $state)) { exit(2); }
        if (!$state["present"]) { exit(10); }
        $value = $state["value"] ?? null;
        if (!is_array($value)) { exit(2); }
        echo json_encode($value, JSON_UNESCAPED_SLASHES);
    ' "$NOTIFICATION_STATE_FILE")"; then
        if ! printf '%s' "$notification_json" |
            "$CONFIG_BIN" set notification.servers --stdin; then
            echo "[entrypoint] 错误: 恢复 notification.servers 失败。" >&2
            return 1
        fi
    else
        notification_status=$?
        if [ "$notification_status" = "10" ]; then
            gorge_config_delete 'notification.servers' || return 1
        else
            echo "[entrypoint] 错误: 无法解析 $NOTIFICATION_STATE_FILE。" >&2
            return 1
        fi
    fi

    rm -f -- "$NOTIFICATION_STATE_FILE"
    chown www-data:www-data "$CONF_FILE" || true
    chmod 0640 "$CONF_FILE" || true
}

# 默认栈与 Gorge overlay 显式 enable，纯 legacy 显式 disable；未指定 MODE 的
# 旧用法按 host 是否存在决定启用或保留。
GORGE_NOTIFICATION_MODE="${GORGE_NOTIFICATION_MODE:-auto}"
case "$GORGE_NOTIFICATION_MODE" in
    auto)
        if [ -n "${GORGE_NOTIFICATION_ADMIN_HOST:-}" ] ||
           [ -n "${GORGE_NOTIFICATION_CLIENT_HOST:-}" ]; then
            GORGE_NOTIFICATION_MODE=enable
        else
            GORGE_NOTIFICATION_MODE=preserve
        fi
        ;;
    enable|disable|preserve)
        ;;
    *)
        echo "[entrypoint] 错误: 未知 GORGE_NOTIFICATION_MODE=$GORGE_NOTIFICATION_MODE。" >&2
        exit 1
        ;;
esac

if [ "$GORGE_NOTIFICATION_MODE" = "disable" ]; then
    echo "[entrypoint] 恢复进入 Gorge 前的通知配置 ..."
    gorge_notification_restore_state || exit 1
elif [ "$GORGE_NOTIFICATION_MODE" = "enable" ]; then
    echo "[entrypoint] 下发 Gorge 通知配置 ..."
    gorge_notification_capture_state || exit 1
    gorge_notification_set || exit 1
else
    echo "[entrypoint] GORGE_NOTIFICATION_MODE=preserve，保留现有通知配置。"
fi

# 发信配置比上面两项都麻烦一层，麻烦在**它必须是合并而不是覆盖**。
#
# gorge.render.* 与 notification.servers 整项都归 Gorge 所有，每次启动整体重写是
# 安全的；cluster.mailers 不是——它是一个**共享列表**，用户完全可能在里面手工配了
# postmark、smtp 等自己的条目。整体重写会把它们悄悄抹掉，而且这是一个「重启之后
# 邮件突然全走另一条路」的静默故障，比配置写不进去难查得多。
#
# 所以这里先读出现有列表，再剔除 key 等于我们托管键的那一条。
# enable 模式追加本次生成的条目，disable 模式则直接写回过滤后的列表。
# 剔除同名条目是幂等的关键：cluster.mailers 的校验
# (PhabricatorClusterMailersConfigType) 明确拒绝重复的 key，不剔就会在第二次启动
# 时整项写入失败。
#
# 其余语义与 gorge_notification_set 一致：JSON 列表只能靠 --stdin 喂进去、每次启动
# 幂等重写、写完修正属主与权限。显式 enable/disable 失败会让配置任务失败，避免持久化
# 配置与 Compose profile 状态不一致。
gorge_mailer_set() {
    # 托管键。整段逻辑「认键不认类型」：只有 key 等于它的条目会被覆盖，所以用户
    # 若想再手工配一条走 gorge 的 mailer（比如指向第二个实例），换个 key 即可，
    # 不会被这里洗掉。
    GORGE_MAILER_MODE="${GORGE_MAILER_MODE:-auto}"
    GORGE_MAILER_KEY="${GORGE_MAILER_KEY:-gorge-mailer}"
    GORGE_MAILER_URI="${GORGE_MAILER_URI:-}"
    GORGE_MAILER_TOKEN="${GORGE_MAILER_TOKEN:-}"
    GORGE_MAILER_PRIORITY="${GORGE_MAILER_PRIORITY:-}"
    GORGE_MAILER_TIMEOUT="${GORGE_MAILER_TIMEOUT:-30}"
    GORGE_MAILER_SUPPORTS_MESSAGE_ID="${GORGE_MAILER_SUPPORTS_MESSAGE_ID:-0}"

    case "$GORGE_MAILER_MODE" in
        auto)
            # 保持历史叠加编排的语义：有 URI 就启用，没有就不触碰
            # cluster.mailers。默认编排会显式传 enable/disable。
            if [ -n "$GORGE_MAILER_URI" ]; then
                GORGE_MAILER_MODE=enable
            else
                echo "[entrypoint] 未设置 GORGE_MAILER_URI，跳过 Gorge 发信配置。"
                return 0
            fi
            ;;
        enable)
            if [ -z "$GORGE_MAILER_URI" ]; then
                echo "[entrypoint] 错误: GORGE_MAILER_MODE=enable 但 GORGE_MAILER_URI 为空。" >&2
                return 1
            fi
            ;;
        disable)
            ;;
        preserve)
            echo "[entrypoint] GORGE_MAILER_MODE=preserve，保留现有 cluster.mailers。"
            return 0
            ;;
        *)
            echo "[entrypoint] 错误: 未知 GORGE_MAILER_MODE=$GORGE_MAILER_MODE。" >&2
            return 1
            ;;
    esac

    # 数字项先在 shell 里挡一道，理由同通知那段：php 里的 (int) 会把 "abc" 变成 0，
    # 写进去类型合法、bin/config 也报成功，但 timeout=0 的表现是每封信立刻超时，
    # priority=0 则会被校验拒绝（要求 > 0），两种都要绕远路才查得到。
    # 空的 priority 是合法的，表示不写这个键、让 Phorge 用默认顺序。
    if [ "$GORGE_MAILER_MODE" = "enable" ]; then
        for gorge_number in "$GORGE_MAILER_TIMEOUT" "${GORGE_MAILER_PRIORITY:-0}"; do
            case "$gorge_number" in
                ''|*[!0-9]*)
                    echo "[entrypoint] 错误: GORGE_MAILER_TIMEOUT/PRIORITY \"$gorge_number\" 不是数字。" >&2
                    return 1
                    ;;
            esac
        done
    fi

    # 先把现有值读出来。`bin/config get` 打印的是一个把两个配置源都列出来的 JSON
    # 结构，下面的 php 只取 source=local 的那一份：
    #   - bin/config set 不带 --database 时写的正是 local 源，读写必须同源，否则
    #     一次「合并」就把数据库里的值复制进 local.json，从此改数据库不再生效。
    #   - cluster.mailers 是 setHidden(true) 的配置项，Config 页面上只读，本来也
    #     只会落在 local.json 里。
    # 顺带：get 会顺手探一次数据库（拿 database 源的值），此时数据库多半还没就绪，
    # 但那个探测在 bin/config 内部是 try/catch 的，失败只会让 database 那条显示成
    # status=error，不影响我们要的 local 那条。放在等待数据库之前因此是安全的，
    # 与上面两段保持一致。
    if ! gorge_mailer_existing="$("$CONFIG_BIN" get cluster.mailers 2>/dev/null)"; then
        echo "[entrypoint] 错误: 读取 cluster.mailers 失败。" >&2
        return 1
    fi

    export GORGE_MAILER_MODE GORGE_MAILER_KEY GORGE_MAILER_URI GORGE_MAILER_TOKEN
    export GORGE_MAILER_PRIORITY GORGE_MAILER_TIMEOUT
    export GORGE_MAILER_SUPPORTS_MESSAGE_ID
    export GORGE_MAILER_EXISTING="$gorge_mailer_existing"

    # 同样用 php 的 json_encode 生成：priority/timeout 必须是 JSON 整数、inbound
    # 必须是 JSON 布尔，拼字符串既容易写错类型，也扛不住 uri/token 里的引号。
    # 管道两端的成败都算数，靠脚本开头的 `set -o pipefail`。
    if php -r '
        $mode = getenv("GORGE_MAILER_MODE");
        $key = getenv("GORGE_MAILER_KEY");

        // 解析现有列表。读不到（首次启动、或该键从未设过）就当作空列表，但解析
        // 失败必须 exit(1) 而不是当作空列表：那意味着我们没看懂用户已有的配置，
        // 此时写回去等于删掉它们，宁可这次不写。
        $existing = array();
        $raw = getenv("GORGE_MAILER_EXISTING");
        if ($raw !== false && trim($raw) !== "") {
            $parsed = json_decode($raw, true);
            if (!is_array($parsed) || !isset($parsed["config"])) {
                fwrite(STDERR, "看不懂 bin/config get cluster.mailers 的输出。\n");
                exit(1);
            }
            foreach ($parsed["config"] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (!isset($entry["source"]) || $entry["source"] !== "local") {
                    continue;
                }
                if (isset($entry["value"]) && is_array($entry["value"])) {
                    $existing = $entry["value"];
                }
            }
        }

        // 剔除同名托管条目，保留其余所有条目（含它们的相对顺序）。
        $mailers = array();
        foreach ($existing as $spec) {
            if (is_array($spec) && isset($spec["key"]) && $spec["key"] === $key) {
                continue;
            }
            $mailers[] = $spec;
        }

        if ($mode === "enable") {
            $options = array("uri" => getenv("GORGE_MAILER_URI"));
            // token 留空表示服务端不鉴权，这时干脆不写这个键：适配器的 option 声明是
            // "optional string"，写一个空串与不写等价，但不写更能表达「没配」。
            $token = getenv("GORGE_MAILER_TOKEN");
            if ($token !== false && $token !== "") {
                $options["token"] = $token;
            }
            $options["timeout"] = (int)getenv("GORGE_MAILER_TIMEOUT");
            // 默认 false：这一个适配器后面挂着 7 种后端，走 SendGrid/Postmark 时
            // Message-ID 会被 provider 覆盖，谎报支持会让邮件会话串接静默失效。
            $msgid = getenv("GORGE_MAILER_SUPPORTS_MESSAGE_ID");
            $options["supports-message-id"] = ($msgid === "1" || $msgid === "true");

            $mailer = array(
                "key"  => $key,
                "type" => "gorge",
                // gorge-mailer 只做出站。inbound 的默认值是 true，而适配器侧没有覆盖
                // 它的钩子，只能在配置里声明；留着 true 会让 Phorge 把这个 mailer 当
                // 成收信通道之一去算。
                "inbound" => false,
                "media"   => array("email"),
                "options" => $options,
            );

            $priority = getenv("GORGE_MAILER_PRIORITY");
            if ($priority !== false && $priority !== "") {
                $mailer["priority"] = (int)$priority;
            }

            $mailers[] = $mailer;
        }

        echo json_encode(array_values($mailers), JSON_UNESCAPED_SLASHES);
    ' | "$CONFIG_BIN" set cluster.mailers --stdin; then
        # 与前两段同样的理由：bin/config 以 root 运行，Apache 与 phd 以 www-data
        # 运行，属主不对就等于没配。
        chown www-data:www-data "$CONF_FILE" || true
        chmod 0640 "$CONF_FILE" || true
    else
        # 最常见的失败是 mailer 类型 "gorge" 未知——那说明 PhabricatorMailGorgeAdapter
        # 没被类映射发现（src/__phutil_library_map__.php 没重新生成）；其次是用户
        # 已有条目里就有一条 key 撞车但我们没剔掉的（不该发生，剔除逻辑见上）。
        echo "[entrypoint] 错误: 写入 cluster.mailers 失败。" >&2
        return 1
    fi

    return 0
}

# 旧编排未提供 MODE 且未叠加 Gorge 时，两个变量都不存在，整段不执行。
if [ -n "${GORGE_MAILER_MODE:-}" ] || [ -n "${GORGE_MAILER_URI:-}" ]; then
    echo "[entrypoint] 下发 Gorge 发信配置 ..."
    gorge_mailer_set || exit 1
    # 与高亮那段同样不在这里探 gorge-mailer 的 /readyz：叠加编排已用
    # depends_on.condition=service_healthy 保证了启动顺序，而「服务活着但一个后端
    # 都没配」这个状态由 PhabricatorGorgeMailerSetupCheck 在 Config 页面报出来，
    # 不该拖住 Apache 启动。
else
    echo "[entrypoint] 未设置 GORGE_MAILER_MODE/GORGE_MAILER_URI，跳过 Gorge 发信配置。"
fi

# 检索配置与发信一样是**合并而不是覆盖**，但共享的那个列表更麻烦一点：
#
#   - cluster.search 的条目**没有 key 字段**（校验用的固定键表只认 type / hosts /
#     roles / port / protocol / path / version），所以「哪一条归我管」只能认 type。
#     于是这里托管的是**所有** type=gorge 的条目：想手工再配一条指向另一个 gorge
#     实例的条目，请把 GORGE_SEARCH_HOST 留空、整段交给自己配，否则会被洗掉。
#   - 这个配置项的**默认值本身就是一条 mysql 条目**（见
#     PhabricatorClusterConfigOptions），而 bin/config get 只打印配置源里真正设过
#     的值，不打印默认值。一旦本地源被写上任何值，那条默认的 mysql 条目就整条消失
#     ——这正是默认「切到 gorge 就不再走 MySQL 全文检索」的实现方式，不是漏写。
#     GORGE_SEARCH_KEEP_MYSQL=1 时下面会把它显式写出来，双写两个索引，作为回滚路径。
#   - gorge 条目**插在列表最前**而不是追加。读检索走
#     PhabricatorSearchService::newResultSet，它按列表顺序取第一个可读且成功的服务；
#     mysql 条目只要还可读，追加就等于 gorge 永远不被读到，症状是「配置全绿但中文
#     检索没有任何变化」。
#
# 其余语义与前面两段一致：JSON 列表靠 --stdin 喂进去、每次启动幂等重写、写完修正
# 属主与权限。显式 enable/disable 失败会让配置任务失败。
gorge_search_set() {
    GORGE_SEARCH_MODE="${GORGE_SEARCH_MODE:-auto}"
    GORGE_SEARCH_HOST="${GORGE_SEARCH_HOST:-}"
    GORGE_SEARCH_PORT="${GORGE_SEARCH_PORT:-8120}"
    GORGE_SEARCH_PROTOCOL="${GORGE_SEARCH_PROTOCOL:-http}"
    GORGE_SEARCH_TOKEN="${GORGE_SEARCH_TOKEN:-}"
    GORGE_SEARCH_KEEP_MYSQL="${GORGE_SEARCH_KEEP_MYSQL:-0}"

    case "$GORGE_SEARCH_MODE" in
        auto)
            # 保持历史叠加编排的语义：有 host 就启用，没有就不触碰
            # cluster.search。默认编排会显式传 enable/disable，以便 profile
            # 停用后能撤销已持久化的 Gorge 条目。
            if [ -n "$GORGE_SEARCH_HOST" ]; then
                GORGE_SEARCH_MODE=enable
            else
                echo "[entrypoint] 未设置 GORGE_SEARCH_HOST，跳过 Gorge 检索配置。"
                return 0
            fi
            ;;
        enable)
            if [ -z "$GORGE_SEARCH_HOST" ]; then
                echo "[entrypoint] 错误: GORGE_SEARCH_MODE=enable 但 GORGE_SEARCH_HOST 为空。" >&2
                return 1
            fi
            ;;
        disable)
            ;;
        preserve)
            echo "[entrypoint] GORGE_SEARCH_MODE=preserve，保留现有 cluster.search。"
            return 0
            ;;
        *)
            echo "[entrypoint] 错误: 未知 GORGE_SEARCH_MODE=$GORGE_SEARCH_MODE。" >&2
            return 1
            ;;
    esac

    # 端口先在 shell 里挡一道，理由同前两段：php 里的 (int) 会把 "abc" 变成 0，
    # 写进去类型合法、bin/config 也报成功，但客户端会去连 host:0，每一次检索都失败。
    if [ "$GORGE_SEARCH_MODE" = "enable" ]; then
        case "$GORGE_SEARCH_PORT" in
            ''|*[!0-9]*)
                echo "[entrypoint] 错误: GORGE_SEARCH_PORT \"$GORGE_SEARCH_PORT\" 不是数字。" >&2
                return 1
                ;;
        esac
    fi

    # 先把现有值读出来，只取 source=local 的那一份。理由与 cluster.mailers 那段完全
    # 相同：bin/config set 不带 --database 写的正是 local 源，读写必须同源。
    if ! gorge_search_existing="$("$CONFIG_BIN" get cluster.search 2>/dev/null)"; then
        echo "[entrypoint] 错误: 读取 cluster.search 失败。" >&2
        return 1
    fi

    export GORGE_SEARCH_MODE GORGE_SEARCH_HOST GORGE_SEARCH_PORT GORGE_SEARCH_PROTOCOL
    export GORGE_SEARCH_KEEP_MYSQL
    export GORGE_SEARCH_EXISTING="$gorge_search_existing"

    # 同样用 php 的 json_encode 生成：port 必须是 JSON 整数（校验声明 'optional
    # int'，字符串 "8120" 会被直接判非法），roles 必须是 JSON 布尔的字典。
    # 管道两端的成败都算数，靠脚本开头的 `set -o pipefail`。
    if php -r '
        // 解析现有列表。本地源从未设过（status=unset）时 $existing 保持空列表，
        // 但解析失败必须 exit(1) 而不是当作空列表：那意味着我们没看懂用户已有的
        // 配置，此时写回去等于删掉它们，宁可这次不写。
        $existing = array();
        $raw = getenv("GORGE_SEARCH_EXISTING");
        if ($raw !== false && trim($raw) !== "") {
            $parsed = json_decode($raw, true);
            if (!is_array($parsed) || !isset($parsed["config"])) {
                fwrite(STDERR, "看不懂 bin/config get cluster.search 的输出。\n");
                exit(1);
            }
            foreach ($parsed["config"] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (!isset($entry["source"]) || $entry["source"] !== "local") {
                    continue;
                }
                if (isset($entry["value"]) && is_array($entry["value"])) {
                    $existing = $entry["value"];
                }
            }
        }

        $mode = getenv("GORGE_SEARCH_MODE");
        $enable = ($mode === "enable");
        $keep = getenv("GORGE_SEARCH_KEEP_MYSQL");
        $keep_mysql = $enable && ($keep === "1" || $keep === "true");

        // type=gorge 的条目由这个部署开关整体托管，启用和停用时都先
        // 剔除旧值。启用且未要求 fallback 时同时剔除 mysql；停用时保留
        // mysql 及其它条目（比如用户手工配的 elasticsearch）的相对顺序。
        $services = array();
        $has_mysql = false;
        foreach ($existing as $spec) {
            if (is_array($spec)) {
                $type = isset($spec["type"]) ? $spec["type"] : null;
                if ($type === "gorge") {
                    continue;
                }
                if ($enable && $type === "mysql") {
                    if (!$keep_mysql) {
                        continue;
                    }
                    $has_mysql = true;
                }
            }
            // 非字典条目原样留着：写回时校验会拒绝并把原因打出来，比在这里悄悄
            // 删掉用户的东西好。
            $services[] = $spec;
        }

        // 开关的语义是「列表里有一条可用的 mysql 条目」，不是「保留恰好存在的那
        // 一条」。这样从 0 翻到 1 真的能把 MySQL 全文检索找回来——否则关掉再开，
        // 那条 mysql 条目已经在上一次启动时被剔掉，开关就成了单向的。
        if ($keep_mysql && !$has_mysql) {
            $services[] = array(
                "type"  => "mysql",
                "roles" => array("read" => true, "write" => true),
            );
        }

        if ($enable) {
            $gorge = array(
                "type"  => "gorge",
                "hosts" => array(
                    array(
                        "host"     => getenv("GORGE_SEARCH_HOST"),
                        "port"     => (int)getenv("GORGE_SEARCH_PORT"),
                        "protocol" => getenv("GORGE_SEARCH_PROTOCOL"),
                        // 读写都给 gorge。扇出与 failover 全部交给服务端的 backends，
                        // 不在 Phorge 这一层再配一半，见 DOCKER.md。
                        "roles"    => array("read" => true, "write" => true),
                    ),
                ),
            );

            // 插到最前面，理由见上面那段注释里的第三条。
            array_unshift($services, $gorge);
        } else if (!$services) {
            // 禁用默认编排托管的 Gorge 检索后，若没有其它用户配置，
            // 显式恢复 Phorge 的 MySQL/Ferret 默认引擎。
            $services[] = array(
                "type"  => "mysql",
                "roles" => array("read" => true, "write" => true),
            );
        }

        echo json_encode(array_values($services), JSON_UNESCAPED_SLASHES);
    ' | "$CONFIG_BIN" set cluster.search --stdin; then
        # 与前几段同样的理由：bin/config 以 root 运行，Apache 与 phd 以 www-data
        # 运行，属主不对就等于没配。
        chown www-data:www-data "$CONF_FILE" || true
        chmod 0640 "$CONF_FILE" || true
    else
        # 最常见的失败是搜索引擎类型 "gorge" 未知——那说明
        # PhabricatorGorgeFulltextStorageEngine 没被类映射
        # 发现（src/__phutil_library_map__.php 没重新生成，或镜像没带 --build
        # 重建）；bin/config 会把合法类型列在上一行。
        echo "[entrypoint] 错误: 写入 cluster.search 失败。" >&2
        return 1
    fi

    # token 是标量，走通用的那条路。它与 gorge.render.token 一样是隐藏配置项，
    # Config 页面上只读。
    if [ "$GORGE_SEARCH_MODE" = "enable" ]; then
        if [ -n "$GORGE_SEARCH_TOKEN" ]; then
            gorge_config_set 'gorge.search.token' "$GORGE_SEARCH_TOKEN"
            if [ "${GORGE_CONFIG_SET_OK:-0}" != "1" ]; then
                echo "[entrypoint] 错误: gorge.search.token 未能写入。" >&2
                return 1
            fi
        else
            gorge_config_delete 'gorge.search.token' || return 1
        fi
    else
        gorge_config_delete 'gorge.search.token' || return 1
    fi

    return 0
}

# 不叠加 docker-compose.gorge.yml 时这个变量不存在，整段等于不执行。
if [ -n "${GORGE_SEARCH_MODE:-}" ] || [ -n "${GORGE_SEARCH_HOST:-}" ]; then
    echo "[entrypoint] 下发 Gorge 检索配置 ..."
    gorge_search_set || exit 1
    # 与前几段同样不在这里探 gorge-search 的 /readyz，也**刻意不跑 bin/search
    # init**：建索引要连得上数据库与 Elasticsearch，而这一段跑在等待数据库之前；
    # 更要紧的是它会删掉并重建索引，放在每次启动的路径上等于一次误启动就丢掉整个
    # 索引。首次启用要手工跑 init 与全量重建，命令见 DOCKER.md。
else
    echo "[entrypoint] 未设置 GORGE_SEARCH_MODE/GORGE_SEARCH_HOST，跳过 Gorge 检索配置。"
fi

# 文件存储回到最简单的那一类：gorge.file.uri 与 gorge.file.token 都是标量配置项，
# 整项归 Gorge 所有，所以走现成的 gorge_config_set 就够了 —— 不需要 --stdin，也不需要
# 像 cluster.mailers / cluster.search 那样先读出来再合并。
#
# 但要说清楚它和高亮那段的一个区别：写进去**不等于生效**。文件存储引擎靠
# PhutilClassMapQuery 自动发现，gorge.file.uri 一写上，PhabricatorGorgeFileStorageEngine
# 就变成「可写」并进入引擎列表；可是 Phorge 原生的 blob 引擎 priority 是 1、比它的 2
# 更小，仍然先拿到每一个文件。于是新文件按大小散落在两套引擎里，**既不生效也不报错**。
# 第二步是关掉原生引擎（storage.mysql-engine.max-size 设 0、清空 storage.local-disk.path
# 与 storage.s3.bucket），命令与理由见 DOCKER.md「用 Gorge 做文件存储」。
#
# 这一步刻意**不在这里做**，与高亮的「切引擎」是同一个道理：它是一个有数据后果的决定
# （决定新文件落到哪儿），应该由操作者显式执行，这样「服务在跑但先不接」和一条命令回滚
# 都成立。Config 页面上的 PhabricatorGorgeFileStorageSetupCheck 会把这个中间状态报出来，
# 所以它不会一直悄悄地待着。
#
# 不叠加 docker-compose.gorge.yml 时这两个变量都不存在，整段等于不执行。
if [ -n "${GORGE_FILE_URI:-}" ] || [ -n "${GORGE_FILE_TOKEN:-}" ]; then
    echo "[entrypoint] 下发 Gorge 文件存储配置 ..."
    gorge_config_set 'gorge.file.uri' "${GORGE_FILE_URI:-}"
    gorge_config_set 'gorge.file.token' "${GORGE_FILE_TOKEN:-}"
    # 与前几段同样不在这里探 gorge-file-storage 的 /readyz：叠加编排里 phorge 对它的
    # depends_on 用的就是 service_healthy，而那个探针探的正是 /readyz，所以到这里它
    # 已经就绪过了，再等一次是冗余的。
    #
    # 也刻意不在这里做任何数据迁移或存量校验：引擎标识是**按文件**存在库里的，已存文件
    # 仍由当初写它的引擎读取，所以接上这个服务是一次增量切换，没有要搬的数据。
else
    echo "[entrypoint] 未设置 GORGE_FILE_URI，跳过 Gorge 文件存储配置。"
fi

# Webhook 在形式上和文件存储一样：gorge.webhook.uri 与 gorge.webhook.token 都是标量配置
# 项，整项归 Gorge 所有，走现成的 gorge_config_set 就够了。
#
# 语义上它却和上面五段都不同，而且「写失败」比「没配」更糟：
#
#   前五个服务是 phorge 去调用的，配置写不进去，phorge 就继续用自带的实现，最坏结果是
#   Gorge 那个容器白跑。webhook 不是 —— gorge-webhook 直接轮询 herald_webhookrequest
#   这张队列表，它压根不看 phorge 的配置。phorge 侧读 gorge.webhook.uri 只是为了决定
#   「还要不要自己发」（PhabricatorGorgeWebhookClient::isDeliveryDelegated()）。
#
#   于是「gorge-webhook 容器在跑 + gorge.webhook.uri 没写进去」= phd 和 Go 同时竞争同
#   一批行，产生重复投递竞态（部分请求可能发送两次）。而这正是本段最危险的失败模式：
#   新增的配置项要在
#   PhabricatorHeraldConfigOptions 里声明并重新生成类映射（arc liberate src/），映射
#   没跟上时 bin/config 会报 "Configuration key is unknown"。默认栈会读取
#   GORGE_CONFIG_SET_OK 并让迁移失败，不允许继续进入所有权不确定的状态。
#
# 所以这里比其它几段多做一件事：读 GORGE_CONFIG_SET_OK。默认栈写失败时必须退出，
# 让 Web 与 daemon 都停在迁移依赖上，避免在委派所有权不确定时继续启动。
#
# 另外两点：
#   - 与文件存储那种「写了不等于生效」相反，这一项**写进去就立刻生效**，没有第二步手工
#     开关。已经排在 phd 队列里的旧任务也不会重复投递：HeraldWebhookWorker 里有同一个
#     守卫，跑到就直接返回，把那一行留给 Go。
#   - 全局静默（phabricator.silent）开着时这一项等于没配：静默是 phorge 自己的配置，Go
#     读不到，所以守卫要求「已配置 **且** 未静默」，静默场景仍由 phd 按原样失败掉每一个
#     请求。这一段照写不误，Config 页面的 PhabricatorGorgeWebhookSetupCheck 会把「配了
#     但因为静默没接管」报出来。
#
# 默认栈显式使用 enable，legacy 栈显式使用 disable。旧叠加编排未设
# MODE 时保持历史语义：有 URI 就启用 Gorge，没有则不触碰现有配置。
GORGE_WEBHOOK_MODE="${GORGE_WEBHOOK_MODE:-auto}"
case "$GORGE_WEBHOOK_MODE" in
    auto)
        if [ -n "${GORGE_WEBHOOK_URI:-}" ]; then
            GORGE_WEBHOOK_MODE=enable
        else
            GORGE_WEBHOOK_MODE=preserve
        fi
        ;;
    enable|disable|preserve)
        ;;
    *)
        echo "[entrypoint] 警告: 未知 GORGE_WEBHOOK_MODE=$GORGE_WEBHOOK_MODE，保留现有 webhook 配置。" >&2
        GORGE_WEBHOOK_MODE=preserve
        ;;
esac

if [ "$GORGE_WEBHOOK_MODE" = "disable" ]; then
    echo "[entrypoint] 撤销 Gorge webhook 委派，恢复 Phorge 原生投递 ..."
    gorge_config_delete 'gorge.webhook.uri' || exit 1
    gorge_config_delete 'gorge.webhook.token' || exit 1
elif [ "$GORGE_WEBHOOK_MODE" = "enable" ]; then
    if [ -z "${GORGE_WEBHOOK_URI:-}" ]; then
        echo "[entrypoint] 错误: GORGE_WEBHOOK_MODE=enable 但 GORGE_WEBHOOK_URI 为空。" >&2
        exit 1
    fi
    echo "[entrypoint] 下发 Gorge webhook 配置 ..."
    gorge_config_set 'gorge.webhook.uri' "$GORGE_WEBHOOK_URI"
    if [ "${GORGE_CONFIG_SET_OK:-0}" != "1" ]; then
        echo "[entrypoint] 错误: gorge.webhook.uri 未能写入；拒绝在委派状态不确定时启动。" >&2
        exit 1
    fi
    if [ -n "${GORGE_WEBHOOK_TOKEN:-}" ]; then
        gorge_config_set 'gorge.webhook.token' "$GORGE_WEBHOOK_TOKEN"
    else
        gorge_config_delete 'gorge.webhook.token' || exit 1
    fi
    # 与前几段同样不在这里探 gorge-webhook 的 /readyz，而且这里连探都不该探：它的就绪
    # 条件是能连上 {namespace}_herald 库，而那个库是本脚本后面几步的 bin/storage upgrade
    # 建的 —— 在这儿等它就是等一件只有自己能做的事。
else
    echo "[entrypoint] GORGE_WEBHOOK_MODE=preserve，保留现有 webhook 配置。"
fi

# Task-queue 在形式上回到最简单的那一类：gorge.taskqueue.uri 与 gorge.taskqueue.token
# 都是标量配置项，整项归 Gorge 所有，走现成的 gorge_config_set 就够了 —— 不需要 --stdin，
# 也不需要像 cluster.mailers / cluster.search 那样先读出来再合并。
#
# 语义上它是 phorge **主动调用**的那一类（和 render / conduit / file 一样，不是 webhook
# 那种接管开关）：PHP 侧 PhabricatorWorkerLeaseQuery / PhabricatorWorker /
# PhabricatorWorkerActiveTask 在读到 gorge.taskqueue.uri 非空（isConfigured()）时，把
# enqueue / lease / complete / fail / yield / cancel / awaken 全部改走 gorge-taskqueue，
# 否则回落原生 SQL 队列。但队列端点与 taskmaster 开关必须原子地成功：默认栈写不进去
# 时会让迁移失败，而不是继续进入零消费者或双消费者状态。
#
# 但它比其它五段多一件必须做的事，见下面 phd taskmaster 那段：taskqueue 必须**替换**而
# 不是**并存于** phd 的 taskmaster 守护进程。
#
# 默认栈显式使用 enable，legacy 栈显式使用 disable。旧叠加编排未设
# MODE 时保持历史语义：有 URI 就启用 Gorge，没有则不触碰现有配置。
GORGE_TASKQUEUE_MODE="${GORGE_TASKQUEUE_MODE:-auto}"
case "$GORGE_TASKQUEUE_MODE" in
    auto)
        if [ -n "${GORGE_TASKQUEUE_URI:-}" ]; then
            GORGE_TASKQUEUE_MODE=enable
        else
            GORGE_TASKQUEUE_MODE=preserve
        fi
        ;;
    enable|disable|preserve)
        ;;
    *)
        echo "[entrypoint] 警告: 未知 GORGE_TASKQUEUE_MODE=$GORGE_TASKQUEUE_MODE，保留现有队列配置。" >&2
        GORGE_TASKQUEUE_MODE=preserve
        ;;
esac

# 第一次把原生 taskmaster 池关到 0 前，保存本地配置是否存在及其原值。
# 快照文件位于同一个持久化配置卷；重复启动不会覆盖它，因此退出 Gorge 时可以
# 精确恢复管理员原来的池大小，而不是一律回到内建默认值 4。
gorge_taskqueue_capture_taskmasters() {
    if [ -e "$TASKQUEUE_STATE_FILE" ]; then
        return 0
    fi

    export CONF_FILE TASKQUEUE_STATE_FILE
    if ! php -r '
        $file = getenv("CONF_FILE");
        $state_file = getenv("TASKQUEUE_STATE_FILE");
        $config = array();
        if (is_file($file)) {
            $config = json_decode(file_get_contents($file), true);
            if (!is_array($config)) { exit(2); }
        }

        $present = array_key_exists("phd.taskmasters", $config);
        $value = $present ? $config["phd.taskmasters"] : null;

        // 兼容已经运行过本 PR 早期版本、但尚未生成快照的配置。URI 与 0
        // 同时存在时把 0 视为部署写入值，回滚应删除它而不是固化为用户基线。
        if ($present && $value === 0 &&
            !empty($config["gorge.taskqueue.uri"])) {
            $present = false;
            $value = null;
        }
        if ($present && (!is_int($value) || $value < 0)) { exit(3); }

        $state = array("present" => $present, "value" => $value);
        $tmp = $state_file.".tmp";
        $json = json_encode($state, JSON_PRETTY_PRINT)."\n";
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $state_file)) {
            @unlink($tmp);
            exit(4);
        }
    '; then
        echo "[entrypoint] 错误: 无法保存 phd.taskmasters 原值，拒绝覆盖该配置。" >&2
        return 1
    fi
    chown www-data:www-data "$TASKQUEUE_STATE_FILE" || true
    chmod 0640 "$TASKQUEUE_STATE_FILE" || true
}

gorge_taskqueue_restore_taskmasters() {
    taskmasters_action='preserve'
    taskmasters_value=''

    if [ -f "$TASKQUEUE_STATE_FILE" ]; then
        if taskmasters_value="$(php -r '
            $state = json_decode(file_get_contents($argv[1]), true);
            if (!is_array($state) || !array_key_exists("present", $state)) { exit(2); }
            if (!$state["present"]) { exit(10); }
            $value = $state["value"] ?? null;
            if (!is_int($value) || $value < 0) { exit(2); }
            echo $value;
        ' "$TASKQUEUE_STATE_FILE")"; then
            taskmasters_action='set'
        else
            taskmasters_status=$?
            if [ "$taskmasters_status" = "10" ]; then
                taskmasters_action='delete'
            else
                echo "[entrypoint] 错误: 无法解析 $TASKQUEUE_STATE_FILE，保留 phd.taskmasters。" >&2
                return 1
            fi
        fi
    else
        # 兼容早期版本：当 Gorge URI 与 0 同时存在时，0 是旧入口脚本写入的；
        # 没有这个特征则无法证明配置归部署所有，宁可保留用户值。
        if php -r '
            $config = json_decode(@file_get_contents($argv[1]), true);
            if (!is_array($config)) { exit(1); }
            $managed = !empty($config["gorge.taskqueue.uri"]) &&
                (($config["phd.taskmasters"] ?? null) === 0);
            exit($managed ? 0 : 1);
        ' "$CONF_FILE"; then
            taskmasters_action='delete'
        fi
    fi

    case "$taskmasters_action" in
        set)
            echo "[entrypoint] 恢复 phd.taskmasters=$taskmasters_value。"
            if ! "$CONFIG_BIN" set phd.taskmasters "$taskmasters_value"; then
                echo "[entrypoint] 错误: 恢复 phd.taskmasters 失败。" >&2
                return 1
            fi
            ;;
        delete)
            gorge_config_delete 'phd.taskmasters' || return 1
            ;;
        preserve)
            echo "[entrypoint] 没有受管 taskmaster 快照，保留现有 phd.taskmasters。"
            ;;
    esac

    if [ -f "$TASKQUEUE_STATE_FILE" ]; then
        rm -f -- "$TASKQUEUE_STATE_FILE"
    fi
    chown www-data:www-data "$CONF_FILE" || true
    chmod 0640 "$CONF_FILE" || true
}

if [ "$GORGE_TASKQUEUE_MODE" = "disable" ]; then
    echo "[entrypoint] 撤销 Gorge task-queue 配置，恢复 Phorge 原生队列 ..."
    gorge_taskqueue_restore_taskmasters || exit 1
    gorge_config_delete 'gorge.taskqueue.uri' || exit 1
    gorge_config_delete 'gorge.taskqueue.token' || exit 1
elif [ "$GORGE_TASKQUEUE_MODE" = "enable" ]; then
    if [ -z "${GORGE_TASKQUEUE_URI:-}" ]; then
        echo "[entrypoint] 警告: GORGE_TASKQUEUE_MODE=enable 但 GORGE_TASKQUEUE_URI 为空，保留现有队列配置。" >&2
    else
        GORGE_TASKQUEUE_DISABLE_PHD_TASKMASTER="${GORGE_TASKQUEUE_DISABLE_PHD_TASKMASTER:-1}"
        if [ "$GORGE_TASKQUEUE_DISABLE_PHD_TASKMASTER" = "1" ]; then
            gorge_taskqueue_capture_taskmasters || exit 1
        fi
        echo "[entrypoint] 下发 Gorge task-queue 配置 ..."
        gorge_config_set 'gorge.taskqueue.uri' "${GORGE_TASKQUEUE_URI:-}"
        if [ "${GORGE_CONFIG_SET_OK:-0}" != "1" ]; then
            echo "[entrypoint] 错误: gorge.taskqueue.uri 未能写入；拒绝关闭原生 taskmaster。" >&2
            exit 1
        fi
        if [ -n "${GORGE_TASKQUEUE_TOKEN:-}" ]; then
            gorge_config_set 'gorge.taskqueue.token' "$GORGE_TASKQUEUE_TOKEN"
            if [ "${GORGE_CONFIG_SET_OK:-0}" != "1" ]; then
                echo "[entrypoint] 错误: gorge.taskqueue.token 未能写入；拒绝切换队列所有权。" >&2
                exit 1
            fi
        else
            gorge_config_delete 'gorge.taskqueue.token' || exit 1
        fi

        # ----- phd taskmaster 的「替换而非并存」处理（compat 硬约束）-----
        # gorge-worker 是 PhabricatorTaskmasterDaemon 搬成的独立进程，它和 phd 里的
        # taskmaster 都从同一个队列消费。两个消费者虽然都向 gorge-taskqueue 原子 lease、
        # 同一任务不会被领两次（不会「跑两遍」），但让两套 worker 同时消费同一个队列是
        # 语义混乱且浪费的：两处执行环境、两处 lease 争抢。compat 要求 Go worker **替换**
        # 而非并存，所以这里在配置了 gorge.taskqueue 时把 phd 的 taskmaster 池关到 0，把
        # 队列的消费权完整交给 gorge-worker 容器。
        #
        # 为什么用 bin/config set 而不是写守卫块：phd.taskmasters 是 setLocked(true) 的
        # 配置项，但 locked 只在带 --database（写数据库源）时被拒绝，写 local 源
        # （bin/config set 不带 --database，即 local.json）是允许的 —— 与 gorge_config_set
        # 走的是同一条路。这样已经跑过的实例光加环境变量也会在下次启动生效，不必删 local.json。
        #
        # GORGE_TASKQUEUE_DISABLE_PHD_TASKMASTER=0 时保留 taskmaster（灰度对比等场景）。
        if [ "$GORGE_TASKQUEUE_DISABLE_PHD_TASKMASTER" = "1" ]; then
            echo "[entrypoint] 配置了 gorge.taskqueue，禁用 phd 的 taskmaster（phd.taskmasters=0），"
            echo "[entrypoint]   把队列消费权交给 gorge-worker 容器（替换而非并存）。"
            if ! "$CONFIG_BIN" set phd.taskmasters 0; then
                echo "[entrypoint] 错误: 写入 phd.taskmasters=0 失败；拒绝让两套消费者并存。" >&2
                exit 1
            else
                chown www-data:www-data "$CONF_FILE" || true
                chmod 0640 "$CONF_FILE" || true
            fi
        else
            # 只有确实由此前 enable 路径创建过快照时才恢复。没有快照意味着当前
            # phd.taskmasters（包括显式 0）属于用户，不能因 URI 随后写入而误删。
            if [ -f "$TASKQUEUE_STATE_FILE" ]; then
                gorge_taskqueue_restore_taskmasters || exit 1
            else
                echo "[entrypoint] 未禁用原生 taskmaster，保留现有 phd.taskmasters。"
            fi
        fi
    fi
else
    echo "[entrypoint] GORGE_TASKQUEUE_MODE=preserve，保留现有队列配置。"
fi

# 数据库诊断服务回到最简单的那一类：gorge.db.uri 与 gorge.db.token 都是标量配置项，
# 整项归 Gorge 所有，走现成的 gorge_config_set 就够了 —— 不需要 --stdin，也不需要像
# cluster.mailers / cluster.search 那样先读出来再合并。
#
# 语义上它是 phorge **主动调用**的那一类（和 render / conduit / file / taskqueue 一样，
# 不是 webhook 那种接管开关）：PHP 侧 PhabricatorDatabaseRef、两处 SetupCheck 与
# PhabricatorConfigSchemaQuery 在读到 gorge.db.uri 非空（isConfigured()）时，把「数据库
# 服务器」控制台的连接/复制状态、schema diff、setup 问题全部改走 gorge-db-api，否则回落
# 到从 Web 层直接开管理连接的原生实现。显式 enable/disable 写入失败时让配置任务失败，
# 避免网络错误阻止原生诊断回退。
#
# 环境变量刻意沿用 GORGE_DB_URL / GORGE_DB_TOKEN（与其它域的 *_URI/_TOKEN 命名对齐，
# 但 db 域历史上用的是 URL），写入的配置键是新范式的 gorge.db.uri / gorge.db.token
# （不是旧 phorge 那个畸形键）。
#
# 默认栈与 Gorge overlay 显式 enable，纯 legacy 显式 disable；未指定 MODE 的
# 旧用法按 URL 是否存在决定启用或保留。
GORGE_DB_MODE="${GORGE_DB_MODE:-auto}"
case "$GORGE_DB_MODE" in
    auto)
        if [ -n "${GORGE_DB_URL:-}" ]; then
            GORGE_DB_MODE=enable
        else
            GORGE_DB_MODE=preserve
        fi
        ;;
    enable|disable|preserve)
        ;;
    *)
        echo "[entrypoint] 错误: 未知 GORGE_DB_MODE=$GORGE_DB_MODE。" >&2
        exit 1
        ;;
esac

if [ "$GORGE_DB_MODE" = "disable" ]; then
    echo "[entrypoint] 撤销 Gorge 数据库诊断配置 ..."
    gorge_config_delete 'gorge.db.uri' || exit 1
    gorge_config_delete 'gorge.db.token' || exit 1
elif [ "$GORGE_DB_MODE" = "enable" ]; then
    if [ -z "${GORGE_DB_URL:-}" ]; then
        echo "[entrypoint] 错误: GORGE_DB_MODE=enable 但 GORGE_DB_URL 为空。" >&2
        exit 1
    fi
    echo "[entrypoint] 下发 Gorge 数据库服务配置 ..."
    gorge_config_set 'gorge.db.uri' "$GORGE_DB_URL"
    if [ "${GORGE_CONFIG_SET_OK:-0}" != "1" ]; then
        echo "[entrypoint] 错误: gorge.db.uri 未能写入。" >&2
        exit 1
    fi
    if [ -n "${GORGE_DB_TOKEN:-}" ]; then
        gorge_config_set 'gorge.db.token' "$GORGE_DB_TOKEN"
        if [ "${GORGE_CONFIG_SET_OK:-0}" != "1" ]; then
            echo "[entrypoint] 错误: gorge.db.token 未能写入。" >&2
            exit 1
        fi
    else
        gorge_config_delete 'gorge.db.token' || exit 1
    fi
    # 与前几段同样不在这里探 gorge-db-api 的 /readyz：叠加编排里 phorge 对它用的是
    # depends_on.condition=service_started（不是 service_healthy），刻意让可选诊断服务的
    # 数据库可达性不阻塞 Phorge 自身的首次启动与 storage upgrade。就绪状态由 /readyz
    # 和 Config 页面的 PhabricatorGorgeDBSetupCheck 报出来。
else
    echo "[entrypoint] GORGE_DB_MODE=preserve，保留现有数据库诊断配置。"
fi

fi # PHORGE_CONTROL_PLANE=legacy

# ----- 3. 等待数据库就绪 -----
# 用 PHP mysqli 探测：与 Phorge 实际使用的驱动一致。
# （不用 mariadb 客户端：它会校验 MySQL 8 的自签名 TLS 证书而失败，需 --skip-ssl）
db_ready() {
    php -r '
        $c = @mysqli_connect($argv[1], $argv[3], $argv[4], null, (int)$argv[2]);
        exit($c ? 0 : 1);
    ' "$MYSQL_HOST" "$MYSQL_PORT" "$MYSQL_USER" "$MYSQL_PASS" >/dev/null 2>&1
}

if [ "$PHORGE_WAIT_DB" = "1" ]; then
    echo "[entrypoint] 等待数据库 ${MYSQL_HOST}:${MYSQL_PORT} ..."
    for i in $(seq 1 "$DB_WAIT_RETRIES"); do
        if db_ready; then
            echo "[entrypoint] 数据库已就绪。"
            break
        fi
        echo "  ... 数据库未就绪，重试 ($i/$DB_WAIT_RETRIES)"
        sleep 3
        if [ "$i" -eq "$DB_WAIT_RETRIES" ]; then
            echo "[entrypoint] 数据库连接超时，退出。" >&2
            exit 1
        fi
    done
else
    echo "[entrypoint] PHORGE_WAIT_DB=$PHORGE_WAIT_DB，跳过数据库就绪探测。"
fi

# ----- 4. 选择产品模式并更新数据库 schema -----
# auto 只用于默认编排：有持久化选择时沿用；没有选择时，空数据库视为新安装并
# 选择 collaboration，已存在的 Phorge 数据库视为升级并保持 full。这个判定发生
# 在 storage upgrade 之前，否则新安装也会被刚创建的 meta_data 库误判为升级。
resolve_database_namespace() {
    persisted_namespace="$(php -r '
        $path = $argv[1];
        $config = is_file($path)
            ? json_decode(file_get_contents($path), true)
            : null;
        $namespace = is_array($config)
            ? ($config["storage.default-namespace"] ?? "")
            : "";
        if (is_string($namespace) && $namespace !== "") {
            echo $namespace;
        }
    ' "$CONF_FILE")"

    if [ -n "$PHORGE_DB_NAMESPACE" ] &&
       [ -n "$persisted_namespace" ] &&
       [ "$PHORGE_DB_NAMESPACE" != "$persisted_namespace" ]; then
        echo "[entrypoint] PHORGE_DB_NAMESPACE=$PHORGE_DB_NAMESPACE 与已持久化的" >&2
        echo "[entrypoint] storage.default-namespace=$persisted_namespace 不一致，拒绝连接错误的库。" >&2
        echo "[entrypoint] 请在 .env 中把 PHORGE_DB_NAMESPACE 改为已有命名空间后重试。" >&2
        exit 1
    fi

    RESOLVED_DB_NAMESPACE="${PHORGE_DB_NAMESPACE:-${persisted_namespace:-phabricator}}"
    case "$RESOLVED_DB_NAMESPACE" in
        ''|*[!0-9a-zA-Z_\$]*)
            echo "[entrypoint] 非法数据库命名空间: $RESOLVED_DB_NAMESPACE" >&2
            exit 64
            ;;
    esac
    if [ "${#RESOLVED_DB_NAMESPACE}" -ge 45 ]; then
        echo "[entrypoint] 数据库命名空间必须少于 45 个字符。" >&2
        exit 64
    fi
    echo "[entrypoint] 使用数据库命名空间 $RESOLVED_DB_NAMESPACE。"
}

resolve_database_namespace

resolve_product_profile() {
    if [ "$PHORGE_PRODUCT_PROFILE" != "auto" ]; then
        return 0
    fi

    profile_deployment_path="$DEPLOYMENT_CONFIG_FILE"
    if [ "$PHORGE_CONTROL_PLANE" = "legacy" ]; then
        profile_deployment_path=''
    fi
    saved_profile="$(php -r '
        foreach (array($argv[1], $argv[2]) as $path) {
            $config = is_file($path)
                ? json_decode(file_get_contents($path), true)
                : null;
            $profile = is_array($config)
                ? ($config["phorge.product-profile"] ?? "")
                : "";
            if ($profile === "collaboration" || $profile === "full") {
                echo $profile;
                exit(0);
            }
        }
    ' "$profile_deployment_path" "$CONF_FILE")"
    if [ -n "$saved_profile" ]; then
        PHORGE_PRODUCT_PROFILE="$saved_profile"
        echo "[entrypoint] 沿用已保存的产品模式: $PHORGE_PRODUCT_PROFILE。"
        return 0
    fi

    set +e
    php -r '
        $connection = @mysqli_connect(
            $argv[1], $argv[3], $argv[4], null, (int)$argv[2]);
        if (!$connection) {
            exit(2);
        }
        $name = $argv[5]."_meta_data";
        $statement = mysqli_prepare(
            $connection,
            "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA ".
            "WHERE SCHEMA_NAME = ? LIMIT 1");
        if (!$statement) {
            exit(2);
        }
        mysqli_stmt_bind_param($statement, "s", $name);
        if (!mysqli_stmt_execute($statement)) {
            exit(2);
        }
        mysqli_stmt_store_result($statement);
        exit(mysqli_stmt_num_rows($statement) ? 0 : 1);
    ' "$MYSQL_HOST" "$MYSQL_PORT" "$MYSQL_USER" "$MYSQL_PASS" \
        "$RESOLVED_DB_NAMESPACE" >/dev/null 2>&1
    database_status=$?
    set -e

    case "$database_status" in
        0)
            PHORGE_PRODUCT_PROFILE=full
            echo "[entrypoint] 检测到现有 Phorge 数据库；默认保持 full 模式。"
            ;;
        1)
            PHORGE_PRODUCT_PROFILE=collaboration
            echo "[entrypoint] 检测到新安装；默认启用 collaboration 模式。"
            ;;
        *)
            echo "[entrypoint] 无法判断安装状态；为避免停用现有应用，回退 full 模式。" >&2
            PHORGE_PRODUCT_PROFILE=full
            ;;
    esac
}

resolve_product_profile

if [ "$PHORGE_PRODUCT_PROFILE" != "collaboration" ] &&
   [ "$PHORGE_PRODUCT_PROFILE" != "full" ]; then
    echo "[entrypoint] 未知 PHORGE_PRODUCT_PROFILE=$PHORGE_PRODUCT_PROFILE。" >&2
    exit 64
fi

PRODUCT_PROFILE_LOCAL_OK=1
if [ "$PHORGE_CONTROL_PLANE" = "legacy" ]; then
    if [ "$PHORGE_PRODUCT_PROFILE" = "collaboration" ]; then
        echo "[entrypoint] 启用 Phorge 协作模式 ..."
    fi
    if ! configure_product_profile_local; then
        PRODUCT_PROFILE_LOCAL_OK=0
    fi
fi

if [ "$PHORGE_AUTO_UPGRADE" = "1" ]; then
    echo "[entrypoint] 升级/初始化数据库 schema ..."
    if ! "$STORAGE_BIN" upgrade --force; then
        if [ "$PHORGE_CONTAINER_ROLE" = "migrate" ]; then
            echo "[entrypoint] storage upgrade 失败，migrate 角色退出。" >&2
            exit 1
        fi
        echo "[entrypoint] 警告: storage upgrade 失败，可进容器执行 bin/storage upgrade 排查。" >&2
    fi
else
    echo "[entrypoint] PHORGE_AUTO_UPGRADE=$PHORGE_AUTO_UPGRADE，跳过 storage upgrade。"
fi

# 旧控制面需要用三方合并维护应用与字段。默认控制面把应用门控和 Gitea 字段变成
# 运行时代码，并让 deployment.json 覆盖数据库源，不再写数据库配置。
if [ "$PHORGE_CONTROL_PLANE" = "legacy" ]; then
    if [ "$PRODUCT_PROFILE_LOCAL_OK" = "1" ]; then
        if ! php "$PHORGE_DIR/scripts/setup/manage_collaboration_profile.php" \
            "$PHORGE_PRODUCT_PROFILE" "$COLLABORATION_STATE_FILE"; then
            echo "[entrypoint] 警告: 合并产品模式有效配置失败，保留状态以便重试。" >&2
        fi
        chown www-data:www-data "$CONF_FILE" || true
        chmod 0640 "$CONF_FILE" || true
    else
        echo "[entrypoint] 警告: 本地产品模式配置未完成，跳过数据库配置与状态清理。" >&2
    fi
    if [ -e "$COLLABORATION_STATE_FILE" ]; then
        chown www-data:www-data "$COLLABORATION_STATE_FILE" || true
        chmod 0640 "$COLLABORATION_STATE_FILE" || true
    fi
else
    # 从阶段一升级时，先精确恢复旧控制面保存的数据库/本地基线，再用新的
    # 运行时 profile 接管。早期 collaboration 实现没有状态文件，所以也检测
    # local.json 中的旧 profile；数据库中的无状态 numeric 应用列表由后面的
    # profile helper 无条件检查。taskqueue 快照也在这里一次性恢复。
    legacy_local_state="$(php -r '
        $config = json_decode(@file_get_contents($argv[1]), true);
        if (!is_array($config)) {
            exit(0);
        }
        $collaboration =
            (($config["phorge.product-profile"] ?? null) === "collaboration");
        $taskqueue = !empty($config["gorge.taskqueue.uri"]) &&
            (($config["phd.taskmasters"] ?? null) === 0);
        if ($collaboration || $taskqueue) {
            echo "1";
        }
    ' "$CONF_FILE")"
    if [ -e "$COLLABORATION_STATE_FILE" ] ||
       [ -e "$TASKQUEUE_STATE_FILE" ] ||
       [ -e "$NOTIFICATION_STATE_FILE" ] ||
       [ "$legacy_local_state" = "1" ]; then
        echo "[entrypoint] 迁移旧控制面状态到统一控制面 ..."
        PHORGE_CONTROL_PLANE=legacy \
            php "$PHORGE_DIR/scripts/setup/manage_collaboration_local.php" \
            full "$CONF_FILE" "$COLLABORATION_STATE_FILE" \
            "$TASKQUEUE_STATE_FILE" "$NOTIFICATION_STATE_FILE"
    fi

    # This helper is also the compatibility detector for the original
    # stateless collaboration profile: without a state file it normalizes the
    # numeric uninstalled-application set while preserving keyed admin values.
    PHORGE_CONTROL_PLANE=legacy \
        php "$PHORGE_DIR/scripts/setup/manage_collaboration_profile.php" \
        full "$COLLABORATION_STATE_FILE"
    chown www-data:www-data "$CONF_FILE" || true
    chmod 0640 "$CONF_FILE" || true

    echo "[entrypoint] 原子生成统一部署配置 $DEPLOYMENT_CONFIG_FILE ..."
    php "$PHORGE_DIR/scripts/setup/build_deployment_config.php" \
        "$PHORGE_PRODUCT_PROFILE" "$DEPLOYMENT_CONFIG_FILE" "$CONF_FILE"
    chown www-data:www-data "$DEPLOYMENT_CONFIG_FILE" || true
    chmod 0640 "$DEPLOYMENT_CONFIG_FILE" || true
fi

# all 角色接下来会 exec 长期运行的 Web 进程。在进入运行阶段前显式
# 关闭文件描述符，避免 Apache 继承它并在整个容器生命周期内持有锁。
flock --unlock "$CONFIG_LOCK_FD"
exec {CONFIG_LOCK_FD}>&-
echo "[entrypoint] 已释放 Phorge 配置写入锁。"

if [ "$PHORGE_CONTAINER_ROLE" = "migrate" ]; then
    echo "[entrypoint] 配置与数据库迁移完成。"
    exit 0
fi

# ----- 5. 兼容模式守护进程 -----
# 必须以 www-data 运行：phd 会在 /var/repo 下创建仓库工作副本，若以 root 运行
# 会留下 root 属主的文件与 Apache (www-data) 冲突，Phorge 的
# PhabricatorDaemonsSetupCheck 也会就此告警。
if [ "$PHORGE_START_PHD" = "1" ]; then
    echo "[entrypoint] 以 www-data 启动 Phorge 守护进程 (phd) ..."
    su -s /bin/sh www-data -c "$PHD_BIN start" ||
        echo "[entrypoint] 警告: phd 启动失败（可稍后手动排查）。" >&2
else
    echo "[entrypoint] PHORGE_START_PHD=$PHORGE_START_PHD，跳过 phd 启动。"
fi

echo "[entrypoint] 启动 Web 服务: $*"
exec "$@"
