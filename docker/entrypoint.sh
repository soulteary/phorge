#!/usr/bin/env bash
#
# Phorge 容器入口脚本
#
# 流程:
#   1. 守卫式生成本地配置 conf/local/local.json（已存在则不覆盖）
#   2. 幂等下发 Gorge 服务配置   (GORGE_RENDER_* 高亮 / GORGE_NOTIFICATION_* 通知 /
#                                 GORGE_MAILER_* 发信 / GORGE_SEARCH_* 全文检索)
#   3. 等待数据库就绪            (PHORGE_WAIT_DB)
#   4. 升级/初始化数据库 schema  (PHORGE_AUTO_UPGRADE)
#   5. 以 www-data 启动守护进程  (PHORGE_START_PHD)
#   6. exec 启动 Web 服务（由 CMD 传入）
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
    export PHORGE_BASE_URI PHORGE_TIMEZONE
    # 一次性 json_encode 写出：既能表达非标量值，也避免多条 `bin/config set`
    # 中间失败留下半成品配置。先写临时文件再 rename，保证原子替换。
    if php -r '
        $config = array(
            "mysql.host"           => getenv("MYSQL_HOST"),
            "mysql.port"           => getenv("MYSQL_PORT"),
            "mysql.user"           => getenv("MYSQL_USER"),
            "mysql.pass"           => getenv("MYSQL_PASS"),
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

# ----- 2. 下发 Gorge 服务配置（非守卫式，刻意与上面相反）-----
# gorge.render.uri / token 与 notification.servers 描述的都是部署拓扑，应该跟着
# 编排走而不是跟着 phorge-conf 卷走：上面那段只在首次生成 local.json 时写入，
# 已经跑过的实例光加环境变量不会生效。这里每次启动都用 bin/config set 幂等重写
# 一遍，于是换服务地址或轮换 token 只要改 .env 重启容器，不必删 local.json。
#
# 放在等待数据库之前是安全的：bin/config 不带 --database 时写的是
# conf/local/local.json（PhabricatorConfigLocalSource），且 bin/config 经由
# scripts/init/init-setup.php 以 config.optional 方式初始化，数据库连不上也能跑。
gorge_config_set() {
    gorge_key="$1"
    gorge_value="$2"

    # 值为空就整项跳过：既不写空值，也不删除已有配置 —— 用户可能在 Web 界面
    # (Config) 手工配过，环境变量缺失不该被理解成「要清掉它」。
    if [ -z "$gorge_value" ]; then
        echo "[entrypoint] 未提供 $gorge_key 对应的环境变量，保留现有配置。"
        return 0
    fi

    if "$CONFIG_BIN" set "$gorge_key" "$gorge_value"; then
        # bin/config 以 root 运行，若 local.json 此前不存在会被建成 root 属主；
        # Apache 与 phd 都以 www-data 运行，读不到就等于没配。属主与权限保持与
        # 上面守卫块写出的文件一致。
        chown www-data:www-data "$CONF_FILE" || true
        chmod 0640 "$CONF_FILE" || true
    else
        # 不让它拖垮容器：高亮是可回退的功能，配置缺失时 Phorge 用内置高亮器。
        # 最常见的失败是这两个配置项还没在
        # PhabricatorSyntaxHighlightingConfigOptions 里声明（或类映射未重新生成），
        # 此时 bin/config 报 "Configuration key is unknown"。
        echo "[entrypoint] 警告: 写入 $gorge_key 失败，Gorge 高亮可能不生效。" >&2
    fi

    return 0
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

# 通知服务器列表走不了上面的 gorge_config_set：notification.servers 的类型是
# cluster.notifications（PhabricatorNotificationServersConfigType），值是一个
# JSON 列表而不是标量，而 `bin/config set <key> <value>` 的位置参数只能表达标量。
# 改用官方支持的 --stdin（PhabricatorConfigManagementSetWorkflow），把 JSON 从
# 管道喂进去。其余语义与 gorge_config_set 一致：每次启动幂等重写、失败只告警、
# 写完修正属主与权限。
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
        echo "[entrypoint] 警告: GORGE_NOTIFICATION_ADMIN_HOST 与 GORGE_NOTIFICATION_CLIENT_HOST 必须同时提供，跳过 notification.servers。" >&2
        return 0
    fi

    # 端口先在 shell 里挡一道。下面 php 里的 (int) 会把 "abc" 悄悄变成 0，写进去
    # 类型合法、bin/config 也会报成功，但服务永远连不上，排查要绕一大圈。
    for gorge_port in "$GORGE_NOTIFICATION_ADMIN_PORT" \
                      "$GORGE_NOTIFICATION_CLIENT_PORT"; do
        case "$gorge_port" in
            ''|*[!0-9]*)
                echo "[entrypoint] 警告: 通知服务端口 \"$gorge_port\" 不是数字，跳过 notification.servers。" >&2
                return 0
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
        # 同样不阻塞容器启动：实时通知是可降级功能，配不上时 Phorge 退回到刷新
        # 页面才看到通知，站点本身照常可用。最常见的失败是校验没过——两条记录的
        # "{host}:{port}" 撞车，或 protocol 不是 http/https；bin/config 会把具体
        # 原因打在上面一行。
        echo "[entrypoint] 警告: 写入 notification.servers 失败，实时通知不可用。" >&2
    fi

    return 0
}

# 不叠加 docker-compose.gorge.yml 时这两个变量都不存在，整段等于不执行。
# 用 || 而不是 &&：只配了一半时要走进去让上面的函数把话说清楚，而不是静默跳过。
if [ -n "${GORGE_NOTIFICATION_ADMIN_HOST:-}" ] ||
   [ -n "${GORGE_NOTIFICATION_CLIENT_HOST:-}" ]; then
    echo "[entrypoint] 下发 Gorge 通知配置 ..."
    gorge_notification_set
else
    echo "[entrypoint] 未设置 GORGE_NOTIFICATION_CLIENT_HOST，跳过实时通知配置。"
fi

# 发信配置比上面两项都麻烦一层，麻烦在**它必须是合并而不是覆盖**。
#
# gorge.render.* 与 notification.servers 整项都归 Gorge 所有，每次启动整体重写是
# 安全的；cluster.mailers 不是——它是一个**共享列表**，用户完全可能在里面手工配了
# postmark、smtp 等自己的条目。整体重写会把它们悄悄抹掉，而且这是一个「重启之后
# 邮件突然全走另一条路」的静默故障，比配置写不进去难查得多。
#
# 所以这里的三步是：读出现有列表 -> 剔除 key 等于我们托管键的那一条 -> 追加本次
# 生成的条目后写回。剔除同名条目是幂等的关键：cluster.mailers 的校验
# (PhabricatorClusterMailersConfigType) 明确拒绝重复的 key，不剔就会在第二次启动
# 时整项写入失败。
#
# 其余语义与 gorge_notification_set 一致：JSON 列表只能靠 --stdin 喂进去、每次启动
# 幂等重写、写完修正属主与权限、失败只告警不阻塞容器。
gorge_mailer_set() {
    # 托管键。整段逻辑「认键不认类型」：只有 key 等于它的条目会被覆盖，所以用户
    # 若想再手工配一条走 gorge 的 mailer（比如指向第二个实例），换个 key 即可，
    # 不会被这里洗掉。
    GORGE_MAILER_KEY="${GORGE_MAILER_KEY:-gorge-mailer}"
    GORGE_MAILER_URI="${GORGE_MAILER_URI:-}"
    GORGE_MAILER_TOKEN="${GORGE_MAILER_TOKEN:-}"
    GORGE_MAILER_PRIORITY="${GORGE_MAILER_PRIORITY:-}"
    GORGE_MAILER_TIMEOUT="${GORGE_MAILER_TIMEOUT:-30}"
    GORGE_MAILER_SUPPORTS_MESSAGE_ID="${GORGE_MAILER_SUPPORTS_MESSAGE_ID:-0}"

    if [ -z "$GORGE_MAILER_URI" ]; then
        echo "[entrypoint] 警告: GORGE_MAILER_URI 为空，跳过 cluster.mailers。" >&2
        return 0
    fi

    # 数字项先在 shell 里挡一道，理由同通知那段：php 里的 (int) 会把 "abc" 变成 0，
    # 写进去类型合法、bin/config 也报成功，但 timeout=0 的表现是每封信立刻超时，
    # priority=0 则会被校验拒绝（要求 > 0），两种都要绕远路才查得到。
    # 空的 priority 是合法的，表示不写这个键、让 Phorge 用默认顺序。
    for gorge_number in "$GORGE_MAILER_TIMEOUT" "${GORGE_MAILER_PRIORITY:-0}"; do
        case "$gorge_number" in
            ''|*[!0-9]*)
                echo "[entrypoint] 警告: GORGE_MAILER_TIMEOUT/PRIORITY \"$gorge_number\" 不是数字，跳过 cluster.mailers。" >&2
                return 0
                ;;
        esac
    done

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
        echo "[entrypoint] 警告: 读取 cluster.mailers 失败，跳过 Gorge 发信配置。" >&2
        return 0
    fi

    export GORGE_MAILER_KEY GORGE_MAILER_URI GORGE_MAILER_TOKEN
    export GORGE_MAILER_PRIORITY GORGE_MAILER_TIMEOUT
    export GORGE_MAILER_SUPPORTS_MESSAGE_ID
    export GORGE_MAILER_EXISTING="$gorge_mailer_existing"

    # 同样用 php 的 json_encode 生成：priority/timeout 必须是 JSON 整数、inbound
    # 必须是 JSON 布尔，拼字符串既容易写错类型，也扛不住 uri/token 里的引号。
    # 管道两端的成败都算数，靠脚本开头的 `set -o pipefail`。
    if php -r '
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

        echo json_encode(array_values($mailers), JSON_UNESCAPED_SLASHES);
    ' | "$CONFIG_BIN" set cluster.mailers --stdin; then
        # 与前两段同样的理由：bin/config 以 root 运行，Apache 与 phd 以 www-data
        # 运行，属主不对就等于没配。
        chown www-data:www-data "$CONF_FILE" || true
        chmod 0640 "$CONF_FILE" || true
    else
        # 不阻塞容器启动：站点在发不出邮件时照常可用，邮件会留在队列里重投。
        # 最常见的失败是 mailer 类型 "gorge" 未知——那说明 PhabricatorMailGorgeAdapter
        # 没被类映射发现（src/__phutil_library_map__.php 没重新生成）；其次是用户
        # 已有条目里就有一条 key 撞车但我们没剔掉的（不该发生，剔除逻辑见上）。
        echo "[entrypoint] 警告: 写入 cluster.mailers 失败，Gorge 发信不可用。" >&2
    fi

    return 0
}

# 不叠加 docker-compose.gorge.yml 时这个变量不存在，整段等于不执行。
if [ -n "${GORGE_MAILER_URI:-}" ]; then
    echo "[entrypoint] 下发 Gorge 发信配置 ..."
    gorge_mailer_set
    # 与高亮那段同样不在这里探 gorge-mailer 的 /readyz：叠加编排已用
    # depends_on.condition=service_healthy 保证了启动顺序，而「服务活着但一个后端
    # 都没配」这个状态由 PhabricatorGorgeMailerSetupCheck 在 Config 页面报出来，
    # 不该拖住 Apache 启动。
else
    echo "[entrypoint] 未设置 GORGE_MAILER_URI，跳过 Gorge 发信配置。"
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
# 属主与权限、失败只告警不阻塞容器。
gorge_search_set() {
    GORGE_SEARCH_HOST="${GORGE_SEARCH_HOST:-}"
    GORGE_SEARCH_PORT="${GORGE_SEARCH_PORT:-8120}"
    GORGE_SEARCH_PROTOCOL="${GORGE_SEARCH_PROTOCOL:-http}"
    GORGE_SEARCH_TOKEN="${GORGE_SEARCH_TOKEN:-}"
    GORGE_SEARCH_KEEP_MYSQL="${GORGE_SEARCH_KEEP_MYSQL:-0}"

    if [ -z "$GORGE_SEARCH_HOST" ]; then
        echo "[entrypoint] 警告: GORGE_SEARCH_HOST 为空，跳过 cluster.search。" >&2
        return 0
    fi

    # 端口先在 shell 里挡一道，理由同前两段：php 里的 (int) 会把 "abc" 变成 0，
    # 写进去类型合法、bin/config 也报成功，但客户端会去连 host:0，每一次检索都失败。
    case "$GORGE_SEARCH_PORT" in
        ''|*[!0-9]*)
            echo "[entrypoint] 警告: GORGE_SEARCH_PORT \"$GORGE_SEARCH_PORT\" 不是数字，跳过 cluster.search。" >&2
            return 0
            ;;
    esac

    # 先把现有值读出来，只取 source=local 的那一份。理由与 cluster.mailers 那段完全
    # 相同：bin/config set 不带 --database 写的正是 local 源，读写必须同源。
    if ! gorge_search_existing="$("$CONFIG_BIN" get cluster.search 2>/dev/null)"; then
        echo "[entrypoint] 警告: 读取 cluster.search 失败，跳过 Gorge 检索配置。" >&2
        return 0
    fi

    export GORGE_SEARCH_HOST GORGE_SEARCH_PORT GORGE_SEARCH_PROTOCOL
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

        $keep = getenv("GORGE_SEARCH_KEEP_MYSQL");
        $keep_mysql = ($keep === "1" || $keep === "true");

        // 剔除所有 type=gorge 的条目（我们托管的，整条重写），以及默认情况下的
        // mysql 条目。其余条目（比如用户手工配的 elasticsearch）连相对顺序一起保留。
        $services = array();
        $has_mysql = false;
        foreach ($existing as $spec) {
            if (is_array($spec)) {
                $type = isset($spec["type"]) ? $spec["type"] : null;
                if ($type === "gorge") {
                    continue;
                }
                if ($type === "mysql") {
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

        echo json_encode(array_values($services), JSON_UNESCAPED_SLASHES);
    ' | "$CONFIG_BIN" set cluster.search --stdin; then
        # 与前几段同样的理由：bin/config 以 root 运行，Apache 与 phd 以 www-data
        # 运行，属主不对就等于没配。
        chown www-data:www-data "$CONF_FILE" || true
        chmod 0640 "$CONF_FILE" || true
    else
        # 不阻塞容器启动：检索失效时站点照常可用。最常见的失败是搜索引擎类型
        # "gorge" 未知——那说明 PhabricatorGorgeFulltextStorageEngine 没被类映射
        # 发现（src/__phutil_library_map__.php 没重新生成，或镜像没带 --build
        # 重建）；bin/config 会把合法类型列在上一行。
        echo "[entrypoint] 警告: 写入 cluster.search 失败，Gorge 检索不可用。" >&2
    fi

    # token 是标量，走通用的那条路。它与 gorge.render.token 一样是隐藏配置项，
    # Config 页面上只读。
    gorge_config_set 'gorge.search.token' "$GORGE_SEARCH_TOKEN"

    return 0
}

# 不叠加 docker-compose.gorge.yml 时这个变量不存在，整段等于不执行。
if [ -n "${GORGE_SEARCH_HOST:-}" ]; then
    echo "[entrypoint] 下发 Gorge 检索配置 ..."
    gorge_search_set
    # 与前几段同样不在这里探 gorge-search 的 /readyz，也**刻意不跑 bin/search
    # init**：建索引要连得上数据库与 Elasticsearch，而这一段跑在等待数据库之前；
    # 更要紧的是它会删掉并重建索引，放在每次启动的路径上等于一次误启动就丢掉整个
    # 索引。首次启用要手工跑 init 与全量重建，命令见 DOCKER.md。
else
    echo "[entrypoint] 未设置 GORGE_SEARCH_HOST，跳过 Gorge 检索配置。"
fi

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

# ----- 4. 数据库 schema -----
if [ "$PHORGE_AUTO_UPGRADE" = "1" ]; then
    echo "[entrypoint] 升级/初始化数据库 schema ..."
    "$STORAGE_BIN" upgrade --force ||
        echo "[entrypoint] 警告: storage upgrade 失败，可进容器执行 bin/storage upgrade 排查。" >&2
else
    echo "[entrypoint] PHORGE_AUTO_UPGRADE=$PHORGE_AUTO_UPGRADE，跳过 storage upgrade。"
fi

# ----- 5. 守护进程 -----
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
