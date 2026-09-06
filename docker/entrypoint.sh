#!/usr/bin/env bash
#
# Phorge 容器入口脚本
#
# 流程:
#   1. 守卫式生成本地配置 conf/local/local.json（已存在则不覆盖）
#   2. 幂等下发 Gorge 高亮配置   (GORGE_RENDER_URI / GORGE_RENDER_TOKEN)
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

# ----- 2. 下发 Gorge 高亮配置（非守卫式，刻意与上面相反）-----
# gorge.render.uri / token 描述的是部署拓扑，应该跟着编排走而不是跟着
# phorge-conf 卷走：上面那段只在首次生成 local.json 时写入，已经跑过的实例光加
# 环境变量不会生效。这里每次启动都用 bin/config set 幂等重写一遍，于是换
# gorge-render 地址或轮换 token 只要改 .env 重启容器，不必删 local.json。
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
