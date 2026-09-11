#!/usr/bin/env bash
# Phorge deployment-only container entrypoint.
#
# The supported container topology is now the Phorge + Gorge deployment
# control plane. Legacy per-key Gorge configuration and the single-container
# `all` role were retired with docker-compose.legacy.yml.
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

# Keep the variable exported for PhabricatorDeploymentConfigSource. There is
# no alternate runtime path anymore: callers which try to force `legacy` fail
# instead of silently reactivating the retired control plane.
PHORGE_CONTROL_PLANE="${PHORGE_CONTROL_PLANE:-deployment}"
if [ "$PHORGE_CONTROL_PLANE" != "deployment" ]; then
    echo "[entrypoint] PHORGE_CONTROL_PLANE=$PHORGE_CONTROL_PLANE is no longer supported." >&2
    echo "[entrypoint] Use the deployment control plane and deployment.json." >&2
    exit 64
fi
export PHORGE_CONTROL_PLANE PHORGE_GORGE_POLICY

case "$PHORGE_GORGE_POLICY" in
    required|fallback|off)
        ;;
    *)
        echo "[entrypoint] Unknown PHORGE_GORGE_POLICY=$PHORGE_GORGE_POLICY." >&2
        exit 64
        ;;
esac

# Web and daemon containers are read-only consumers of configuration. Only a
# migrate role may create local.json, run schema upgrades or publish the
# atomic deployment document.
case "$PHORGE_CONTAINER_ROLE" in
    web|daemon)
        if [ ! -s "$CONF_FILE" ]; then
            echo "[entrypoint] $PHORGE_CONTAINER_ROLE role is missing $CONF_FILE; run phorge-migrate first." >&2
            exit 1
        fi
        if [ ! -s "$DEPLOYMENT_CONFIG_FILE" ]; then
            echo "[entrypoint] $PHORGE_CONTAINER_ROLE role is missing $DEPLOYMENT_CONFIG_FILE." >&2
            exit 1
        fi
        echo "[entrypoint] starting $PHORGE_CONTAINER_ROLE role: $*"
        exec "$@"
        ;;
    migrate)
        ;;
    *)
        echo "[entrypoint] Unknown PHORGE_CONTAINER_ROLE=$PHORGE_CONTAINER_ROLE; expected web, daemon, or migrate." >&2
        exit 64
        ;;
esac

mkdir -p "$CONF_DIR"
exec {CONFIG_LOCK_FD}>"$CONFIG_LOCK_FILE"
echo "[entrypoint] waiting for configuration lock ..."
flock --exclusive "$CONFIG_LOCK_FD"
echo "[entrypoint] acquired configuration lock."

# local.json contains only bootstrap settings which Phorge needs before the
# deployment source can be loaded. Deployment topology and service ownership
# belong exclusively to deployment.json.
if [ ! -s "$CONF_FILE" ]; then
    echo "[entrypoint] generating bootstrap configuration $CONF_FILE ..."
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
        $json = json_encode(
            $config,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            exit(1);
        }
    '
    chown www-data:www-data "$CONF_FILE" || true
    chmod 0640 "$CONF_FILE" || true
else
    echo "[entrypoint] preserving existing $CONF_FILE."
fi

db_ready() {
    php -r '
        $c = @mysqli_connect($argv[1], $argv[3], $argv[4], null, (int)$argv[2]);
        exit($c ? 0 : 1);
    ' "$MYSQL_HOST" "$MYSQL_PORT" "$MYSQL_USER" "$MYSQL_PASS" >/dev/null 2>&1
}

if [ "$PHORGE_WAIT_DB" = "1" ]; then
    echo "[entrypoint] waiting for database ${MYSQL_HOST}:${MYSQL_PORT} ..."
    for i in $(seq 1 "$DB_WAIT_RETRIES"); do
        if db_ready; then
            echo "[entrypoint] database is ready."
            break
        fi
        if [ "$i" -eq "$DB_WAIT_RETRIES" ]; then
            echo "[entrypoint] database connection timed out." >&2
            exit 1
        fi
        sleep 3
    done
fi

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
        echo "[entrypoint] PHORGE_DB_NAMESPACE=$PHORGE_DB_NAMESPACE conflicts with persisted namespace $persisted_namespace." >&2
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
}

resolve_database_namespace

resolve_product_profile() {
    if [ "$PHORGE_PRODUCT_PROFILE" != "auto" ]; then
        return 0
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
    ' "$DEPLOYMENT_CONFIG_FILE" "$CONF_FILE")"

    if [ -n "$saved_profile" ]; then
        PHORGE_PRODUCT_PROFILE="$saved_profile"
        return 0
    fi

    set +e
    php -r '
        $connection = @mysqli_connect(
            $argv[1], $argv[3], $argv[4], null, (int)$argv[2]);
        if (!$connection) { exit(2); }
        $name = $argv[5]."_meta_data";
        $statement = mysqli_prepare(
            $connection,
            "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA ".
            "WHERE SCHEMA_NAME = ? LIMIT 1");
        if (!$statement) { exit(2); }
        mysqli_stmt_bind_param($statement, "s", $name);
        if (!mysqli_stmt_execute($statement)) { exit(2); }
        mysqli_stmt_store_result($statement);
        exit(mysqli_stmt_num_rows($statement) ? 0 : 1);
    ' "$MYSQL_HOST" "$MYSQL_PORT" "$MYSQL_USER" "$MYSQL_PASS" \
        "$RESOLVED_DB_NAMESPACE" >/dev/null 2>&1
    database_status=$?
    set -e

    case "$database_status" in
        0) PHORGE_PRODUCT_PROFILE=full ;;
        1) PHORGE_PRODUCT_PROFILE=collaboration ;;
        *) PHORGE_PRODUCT_PROFILE=full ;;
    esac
}

resolve_product_profile
case "$PHORGE_PRODUCT_PROFILE" in
    collaboration|full)
        ;;
    *)
        echo "[entrypoint] Unknown PHORGE_PRODUCT_PROFILE=$PHORGE_PRODUCT_PROFILE." >&2
        exit 64
        ;;
esac

if [ "$PHORGE_AUTO_UPGRADE" = "1" ]; then
    echo "[entrypoint] upgrading database schema ..."
    "$STORAGE_BIN" upgrade --force
fi

# One-way compatibility migration for installations which used the phase-one
# collaboration/queue/notification state files. This is deliberately not a
# selectable legacy control plane: it only restores the old baseline before
# deployment.json takes ownership. The helpers remove their state after a
# successful restoration, so normal deployments do no work here.
legacy_local_state="$(php -r '
    $config = json_decode(@file_get_contents($argv[1]), true);
    if (!is_array($config)) { exit(0); }
    $collaboration =
        (($config["phorge.product-profile"] ?? null) === "collaboration");
    $taskqueue = !empty($config["gorge.taskqueue.uri"]) &&
        (($config["phd.taskmasters"] ?? null) === 0);
    if ($collaboration || $taskqueue) { echo "1"; }
' "$CONF_FILE")"

if [ -e "$COLLABORATION_STATE_FILE" ] ||
   [ -e "$TASKQUEUE_STATE_FILE" ] ||
   [ -e "$NOTIFICATION_STATE_FILE" ] ||
   [ "$legacy_local_state" = "1" ]; then
    echo "[entrypoint] migrating legacy control-plane state ..."
    PHORGE_CONTROL_PLANE=legacy \
        php "$PHORGE_DIR/scripts/setup/manage_collaboration_local.php" \
        full "$CONF_FILE" "$COLLABORATION_STATE_FILE" \
        "$TASKQUEUE_STATE_FILE" "$NOTIFICATION_STATE_FILE"
fi

# The original stateless collaboration profile can leave a numeric application
# set without a state file. Keep this one-way detector until that upgrade
# compatibility is retired separately.
PHORGE_CONTROL_PLANE=legacy \
    php "$PHORGE_DIR/scripts/setup/manage_collaboration_profile.php" \
    full "$COLLABORATION_STATE_FILE"
# Endpoints, tokens and consumer ownership belong to deployment.json under
# this control plane. A copy left in local.json by the legacy control plane is
# not masked by the deployment document -- that file omits scalar services
# whose selector variables are absent -- so it stays effective and keeps
# pointing at hosts which were retired with the legacy stack. Strip them
# before publishing. This is idempotent and a no-op for deployments which
# never ran the legacy control plane.
php "$PHORGE_DIR/scripts/setup/clear_legacy_gorge_local.php" "$CONF_FILE"

chown www-data:www-data "$CONF_FILE" || true
chmod 0640 "$CONF_FILE" || true

echo "[entrypoint] publishing deployment configuration $DEPLOYMENT_CONFIG_FILE ..."
php "$PHORGE_DIR/scripts/setup/build_deployment_config.php" \
    "$PHORGE_PRODUCT_PROFILE" "$DEPLOYMENT_CONFIG_FILE" "$CONF_FILE"
chown www-data:www-data "$DEPLOYMENT_CONFIG_FILE" || true
chmod 0640 "$DEPLOYMENT_CONFIG_FILE" || true

flock --unlock "$CONFIG_LOCK_FD"
exec {CONFIG_LOCK_FD}>&-
echo "[entrypoint] configuration and database migration complete."
exit 0
