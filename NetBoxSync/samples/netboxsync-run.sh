#!/bin/sh
# CLI wrapper for the NetBoxSync module. It runs without a browser session and
# can be used directly from cron. The bundled systemd service invokes PHP
# directly, so this wrapper is optional.
#
# Optional environment:
#   PHP_BIN             = PHP CLI path (default /usr/bin/php)
#   NETBOXSYNC_RUNNER   = runner path shown below
#
# Cron example when tokens are stored in module settings (every 15 minutes):
#   */15 * * * * /bin/sh /usr/share/zabbix/modules/NetBoxSync/samples/netboxsync-run.sh --json
# Environment-backed tokens must already be exported by the caller. The
# bundled systemd unit handles its EnvironmentFile automatically.
set -eu

PHP_BIN=${PHP_BIN:-/usr/bin/php}
NETBOXSYNC_RUNNER=${NETBOXSYNC_RUNNER:-/usr/share/zabbix/modules/NetBoxSync/bin/netboxsync.php}

exec "${PHP_BIN}" "${NETBOXSYNC_RUNNER}" "$@"
