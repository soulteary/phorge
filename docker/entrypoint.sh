#!/usr/bin/env bash
# Phorge deployment-only container entrypoint.
set -euo pipefail

PHORGE_DIR=/opt/phorge/phorge
STORAGE_BIN="$PHORGE_DIR/bin/storage"
CONF_DIR="$PHORGE_DIR/conf/local"
CONF_FILE="$CONF_DIR/local.json"
DEPLOYMENT_CONFIG_FILE="${PHORGE_DEPLOYMENT_CONFIG:-$CONF_DIR/deployment.json}"
CONFIG_LOCK_FILE="$CONF_DIR/.entrypoint.lock"
COLLABORATION_STATE_FILE="$CONF_DIR/collaboration-profile-state.json"
NOTIFICATION_STATE_FILE="$CONF_DIR/gorge-notification-state.json"
TASKQUEUE_STATE_FILE="$CONF_DIR/gorge-taskqueue-state.json"
DB_WAIT_RETRIES=60

MYSQL_HOST="${MYSQL_HOST:-mysql}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_USER="${MYSQL_USER:-phorge}"
MYSQL_PASS="${MYSQL_PASS:-phorge}"
PHORGE_BASE_URI="${PHORGE_BASE_URI:-http://127.0.0.1/}"
PHORGE_TIMEZONE="${PHORGE_TIMEZONE:-UTC}"
PHORGE_WAIT_DB="${PHORGE_WAIT_DB:-1}"
PHORGE_AUTO_UPGRADE="${PHORGE_AUTO_UPGRADE:-1}"
PHORGE_PRODUCT_PROFILE="${PHORGE_PRODUCT_PROFILE:-auto}"
PHORGE_GORGE_POLICY="${PHORGE_GORGE_POLICY:-required}"
PHORGE_CONTAINER_ROLE="${PHORGE_CONTAINER_ROLE:-web}"
PHORGE_DB_NAMESPACE="${PHORGE_DB_NAMESPACE:-${STORAGE_NAMESPACE:-}}"
PHORGE_CONTROL_PLANE="${PHORGE_CONTROL_PLANE:-deployment}"

if [ "$PHORGE_CONTROL_PLANE" != "deployment" ]; then
    echo "[entrypoint] PHORGE_CONTROL_PLANE=$PHORGE_CONTROL_PLANE is no longer supported." >&2
    exit 64
fi
export PHORGE_CONTROL_PLANE PHORGE_GORGE_POLICY

case "$PHORGE_GORGE_POLICY" in
    required|fallback|off) ;;
    *)
        echo "[entrypoint] Unknown PHORGE_GORGE_POLICY=$PHORGE_GORGE_POLICY." >&2
        exit 64
        ;;
esac

case "$PHORGE_CONTAINER_ROLE" in
    web|daemon)
        [ -s "$CONF_FILE" ] || {
            echo "[entrypoint] missing $CONF_FILE; run phorge-migrate first." >&2
            exit 1
        }
        [ -s "$DEPLOYMENT_CONFIG_FILE" ] || {
            echo "[entrypoint] missing $DEPLOYMENT_CONFIG_FILE; run phorge-migrate first." >&2
            exit 1
        }
        exec "$@"
        ;;
    migrate) ;;
    *)
        echo "[entrypoint] Unknown PHORGE_CONTAINER_ROLE=$PHORGE_CONTAINER_ROLE." >&2
        exit 64
        ;;
esac

mkdir -p "$CONF_DIR"
exec {CONFIG_LOCK_FD}>"$CONFIG_LOCK_FILE"
flock --exclusive "$CONFIG_LOCK_FD"

if [ ! -s "$CONF_FILE" ]; then
    export CONF_FILE MYSQL_HOST MYSQL_PORT MYSQL_USER MYSQL_PASS
    export PHORGE_DB_NAMESPACE PHORGE_BASE_URI PHORGE_TIMEZONE
    php -r '
        $config = array(
            "mysql.host" => getenv("MYSQL_HOST"),
            "mysql.port" => getenv("MYSQL_PORT"),
            "mysql.user" => getenv("MYSQL_USER"),
            "mysql.pass" => getenv("MYSQL_PASS"),
            "storage.default-namespace" =>
                (getenv("PHORGE_DB_NAMESPACE") ?: "phabricator"),
            "phabricator.base-uri" => getenv("PHORGE_BASE_URI"),
            "phabricator.timezone" => getenv("PHORGE_TIMEZONE"),
        );
        $file = getenv("CONF_FILE");
        $tmp = $file.".tmp";
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            exit(1);
        }
    '
    chown www-data:www-data "$CONF_FILE" || true
    chmod 0640 "$CONF_FILE" || true
fi

db_ready() {
    php -r '
        $c = @mysqli_connect($argv[1], $argv[3], $argv[4], null, (int)$argv[2]);
        exit($c ? 0 : 1);
    ' "$MYSQL_HOST" "$MYSQL_PORT" "$MYSQL_USER" "$MYSQL_PASS" >/dev/null 2>&1
}

if [ "$PHORGE_WAIT_DB" = "1" ]; then
    for i in $(seq 1 "$DB_WAIT_RETRIES"); do
        if db_ready; then
            break
        fi
        if [ "$i" -eq "$DB_WAIT_RETRIES" ]; then
            echo "[entrypoint] database connection timed out." >&2
            exit 1
        fi
        sleep 3
    done
fi

persisted_namespace="$(php -r '
    $config = is_file($argv[1]) ? json_decode(file_get_contents($argv[1]), true) : null;
    $namespace = is_array($config) ? ($config["storage.default-namespace"] ?? "") : "";
    if (is_string($namespace) && $namespace !== "") { echo $namespace; }
' "$CONF_FILE")"

if [ -n "$PHORGE_DB_NAMESPACE" ] && [ -n "$persisted_namespace" ] &&
   [ "$PHORGE_DB_NAMESPACE" != "$persisted_namespace" ]; then
    echo "[entrypoint] database namespace conflicts with persisted configuration." >&2
    exit 1
fi
RESOLVED_DB_NAMESPACE="${PHORGE_DB_NAMESPACE:-${persisted_namespace:-phabricator}}"
case "$RESOLVED_DB_NAMESPACE" in
    ''|*[!0-9a-zA-Z_\$]*)
        echo "[entrypoint] invalid database namespace: $RESOLVED_DB_NAMESPACE" >&2
        exit 64
        ;;
esac
if [ "${#RESOLVED_DB_NAMESPACE}" -ge 45 ]; then
    echo "[entrypoint] database namespace must be shorter than 45 characters." >&2
    exit 64
fi

if [ "$PHORGE_PRODUCT_PROFILE" = "auto" ]; then
    saved_profile="$(php -r '
        foreach (array($argv[1], $argv[2]) as $path) {
            $config = is_file($path) ? json_decode(file_get_contents($path), true) : null;
            $profile = is_array($config) ? ($config["phorge.product-profile"] ?? "") : "";
            if ($profile === "collaboration" || $profile === "full") {
                echo $profile;
                exit(0);
            }
        }
    ' "$DEPLOYMENT_CONFIG_FILE" "$CONF_FILE")"
    if [ -n "$saved_profile" ]; then
        PHORGE_PRODUCT_PROFILE="$saved_profile"
    else
        set +e
        php -r '
            $c = @mysqli_connect($argv[1], $argv[3], $argv[4], null, (int)$argv[2]);
            if (!$c) { exit(2); }
            $name = $argv[5]."_meta_data";
            $s = mysqli_prepare($c, "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1");
            if (!$s) { exit(2); }
            mysqli_stmt_bind_param($s, "s", $name);
            if (!mysqli_stmt_execute($s)) { exit(2); }
            mysqli_stmt_store_result($s);
            exit(mysqli_stmt_num_rows($s) ? 0 : 1);
        ' "$MYSQL_HOST" "$MYSQL_PORT" "$MYSQL_USER" "$MYSQL_PASS" \
            "$RESOLVED_DB_NAMESPACE" >/dev/null 2>&1
        database_status=$?
        set -e
        case "$database_status" in
            0) PHORGE_PRODUCT_PROFILE=full ;;
            1) PHORGE_PRODUCT_PROFILE=collaboration ;;
            *) PHORGE_PRODUCT_PROFILE=full ;;
        esac
    fi
fi
case "$PHORGE_PRODUCT_PROFILE" in
    collaboration|full)
        ;;
    *)
        # Say which value was rejected. docker-compose.yml forwards this to
        # phorge-migrate and every other service waits on that job, so a silent
        # exit leaves the whole stack blocked behind an empty migration log.
        echo "[entrypoint] unknown PHORGE_PRODUCT_PROFILE=$PHORGE_PRODUCT_PROFILE" >&2
        exit 64
        ;;
esac

if [ "$PHORGE_AUTO_UPGRADE" = "1" ]; then
    "$STORAGE_BIN" upgrade --force
fi

# Upgrade compatibility lives behind one explicit, one-way migration helper.
# The normal deployment path no longer knows how phase-one state was shaped.
bash "$PHORGE_DIR/scripts/setup/migrate_legacy_control_plane.sh" \
    "$PHORGE_DIR" \
    "$CONF_FILE" \
    "$COLLABORATION_STATE_FILE" \
    "$TASKQUEUE_STATE_FILE" \
    "$NOTIFICATION_STATE_FILE"

php "$PHORGE_DIR/scripts/setup/build_deployment_config.php" \
    "$PHORGE_PRODUCT_PROFILE" "$DEPLOYMENT_CONFIG_FILE" "$CONF_FILE"
chown www-data:www-data "$DEPLOYMENT_CONFIG_FILE" || true
chmod 0640 "$DEPLOYMENT_CONFIG_FILE" || true

flock --unlock "$CONFIG_LOCK_FD"
exec {CONFIG_LOCK_FD}>&-
exit 0
