#!/usr/bin/env bash
# One-way migration of state written by the phase-one collaboration/Gorge
# control plane. This script is upgrade compatibility only; it does not enable
# the retired legacy runtime path.
set -euo pipefail

if [ "$#" -ne 5 ]; then
    echo "usage: migrate_legacy_control_plane.sh <phorge-dir> <local.json> <collaboration-state> <taskqueue-state> <notification-state>" >&2
    exit 64
fi

PHORGE_DIR="$1"
CONF_FILE="$2"
COLLABORATION_STATE_FILE="$3"
TASKQUEUE_STATE_FILE="$4"
NOTIFICATION_STATE_FILE="$5"

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
    echo "[migration] restoring phase-one control-plane state ..."
    PHORGE_CONTROL_PLANE=legacy \
        php "$PHORGE_DIR/scripts/setup/manage_collaboration_local.php" \
        full "$CONF_FILE" "$COLLABORATION_STATE_FILE" \
        "$TASKQUEUE_STATE_FILE" "$NOTIFICATION_STATE_FILE"
fi

# The earliest stateless collaboration profile can leave a numeric
# uninstalled-application set without any state file. The existing helper also
# acts as the compatibility detector for that shape and normalizes it while
# preserving administrator-owned keyed values.
PHORGE_CONTROL_PLANE=legacy \
    php "$PHORGE_DIR/scripts/setup/manage_collaboration_profile.php" \
    full "$COLLABORATION_STATE_FILE"

# Endpoints, tokens and consumer ownership belong to deployment.json under the
# deployment control plane. A copy left in local.json by the legacy control
# plane is not masked by the deployment document -- that file omits scalar
# services whose selector variables are absent -- so it stays effective and
# keeps pointing at hosts which were retired with the legacy stack. Strip them
# here, as part of the same one-way migration. Idempotent, and a no-op for
# deployments which never ran the legacy control plane.
php "$PHORGE_DIR/scripts/setup/clear_legacy_gorge_local.php" "$CONF_FILE"

chown www-data:www-data "$CONF_FILE" || true
chmod 0640 "$CONF_FILE" || true
