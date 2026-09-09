#!/usr/bin/env bash

set -euo pipefail

PHORGE_DIR=/opt/phorge/phorge
PHD_BIN="$PHORGE_DIR/bin/phd"
stopping=0

stop_phd() {
    if [ "$stopping" = "1" ]; then
        return
    fi
    stopping=1
    echo "[phd-foreground] 停止 Phorge 守护进程 ..."
    su -s /bin/sh www-data -c "$PHD_BIN stop" || true
}

trap stop_phd EXIT INT TERM

echo "[phd-foreground] 以 www-data 启动 Phorge 守护进程 ..."
su -s /bin/sh www-data -c "$PHD_BIN start"

# phd start 会按 Phorge 的标准方式 daemonize。这个小型监护循环让容器前台进程
# 与 overseer 的存活状态绑定，并在容器停止时调用 phd stop 做有序退出。
while su -s /bin/sh www-data -c "$PHD_BIN status" >/dev/null 2>&1; do
    sleep 5 &
    wait $!
done

echo "[phd-foreground] 未检测到运行中的 overseer。" >&2
exit 1
