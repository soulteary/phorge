#!/usr/bin/env bash
#
# Phorge 容器入口脚本
# 依据环境变量生成本地配置 -> 等待数据库 -> 升级 schema -> 启动守护进程 -> 启动 Apache
#
set -euo pipefail

PHORGE_DIR=/opt/phorge/phorge
CONFIG_BIN="$PHORGE_DIR/bin/config"
STORAGE_BIN="$PHORGE_DIR/bin/storage"
PHD_BIN="$PHORGE_DIR/bin/phd"

# ----- 默认值（可被环境变量覆盖）-----
MYSQL_HOST="${MYSQL_HOST:-mysql}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_PASS="${MYSQL_PASS:-phorge}"
PHORGE_BASE_URI="${PHORGE_BASE_URI:-http://127.0.0.1/}"

echo "[entrypoint] 写入 Phorge 本地配置 ..."
"$CONFIG_BIN" set mysql.host "$MYSQL_HOST"
"$CONFIG_BIN" set mysql.port "$MYSQL_PORT"
"$CONFIG_BIN" set mysql.user "$MYSQL_USER"
"$CONFIG_BIN" set mysql.pass "$MYSQL_PASS"
"$CONFIG_BIN" set phabricator.base-uri "$PHORGE_BASE_URI"
# 容器内通常没有本地 sendmail，先关闭以免 setup 报错（按需调整）
"$CONFIG_BIN" set phabricator.timezone "${PHORGE_TIMEZONE:-UTC}" || true

echo "[entrypoint] 等待数据库 ${MYSQL_HOST}:${MYSQL_PORT} ..."
# 用 PHP mysqli 探测：与 Phorge 实际使用的驱动一致。
# （不用 mariadb 客户端：它会校验 MySQL 8 的自签名 TLS 证书而失败，需 --skip-ssl）
db_ready() {
    php -r '
        $c = @mysqli_connect($argv[1], $argv[3], $argv[4], null, (int)$argv[2]);
        exit($c ? 0 : 1);
    ' "$MYSQL_HOST" "$MYSQL_PORT" "$MYSQL_USER" "$MYSQL_PASS" >/dev/null 2>&1
}

for i in $(seq 1 60); do
    if db_ready; then
        echo "[entrypoint] 数据库已就绪。"
        break
    fi
    echo "  ... 数据库未就绪，重试 ($i/60)"
    sleep 3
    if [ "$i" -eq 60 ]; then
        echo "[entrypoint] 数据库连接超时，退出。" >&2
        exit 1
    fi
done

echo "[entrypoint] 升级/初始化数据库 schema ..."
"$STORAGE_BIN" upgrade --force

echo "[entrypoint] 启动 Phorge 守护进程 (phd) ..."
"$PHD_BIN" start || echo "[entrypoint] 警告: phd 启动失败（可稍后手动排查）"

echo "[entrypoint] 启动 Web 服务: $*"
exec "$@"
