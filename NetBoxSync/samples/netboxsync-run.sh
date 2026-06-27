#!/bin/sh
# Runner wrapper for the NetBoxSync module. Triggers one synchronization run via
# the secret-gated runner action. Used by the bundled systemd service, but it
# also works straight from cron.
#
# Required environment (e.g. from /etc/sysconfig/zabbix-netbox-sync):
#   NETBOXSYNC_URL    = https://<zabbix>/zabbix.php?action=netboxsync.run
#   NETBOXSYNC_SECRET = the runner shared secret configured on the settings page
#
# Cron example (every 15 minutes), keeping the secret out of the process list:
#   */15 * * * * . /etc/sysconfig/zabbix-netbox-sync; sh /usr/share/zabbix/modules/NetBoxSync/samples/netboxsync-run.sh
set -eu

: "${NETBOXSYNC_URL:?NETBOXSYNC_URL is not set}"
: "${NETBOXSYNC_SECRET:?NETBOXSYNC_SECRET is not set}"

exec curl -fsS \
    --max-time 600 \
    -X POST \
    -H "X-NetBox-Sync-Secret: ${NETBOXSYNC_SECRET}" \
    "${NETBOXSYNC_URL}"
