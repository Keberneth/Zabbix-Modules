#!/usr/bin/env bash
# Run only in a fresh disposable Linux container; the installer provisions /etc and /var.
set -euo pipefail
[[ ${1:-} == --disposable-container && -f /.dockerenv && $EUID == 0 ]] || {
    printf 'Run in a fresh disposable Docker container with --disposable-container.\n' >&2
    exit 1
}
[[ ! -e /etc/zabbix-ai && ! -e /var/lib/zabbix-ai && ! -e /var/log/zabbix-ai ]] || {
    printf 'Test requires empty Zabbix AI system paths; refusing existing data.\n' >&2
    exit 1
}
source_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
work=$(mktemp -d /tmp/zabbix-ai-setup-test.XXXXXXXX)
module=$work/AI
mkdir -p "$module/bin" "$module/lib" "$work/pools"
cp "$source_dir/bin/setup.sh" "$module/bin/setup.sh"
printf '{"id":"custom_ai"}\n' > "$module/manifest.json"
printf '<?php // test fixture\n' > "$module/Module.php"
printf '[www]\nuser = www-data\ngroup = www-data\n' > "$work/pools/www.conf"
setup=(bash "$module/bin/setup.sh" --fpm-pool-config "$work/pools/www.conf" --apply-fpm)
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
reject() {
    if "$@" > "$work/rejected.log" 2>&1; then fail "Unexpected success: $*"; fi
}

bash "$module/bin/setup.sh" --help > "$work/help.log"
reject bash "$module/bin/setup.sh" --php-user
reject bash "$module/bin/setup.sh" --php-user root
reject "${setup[@]}" --php-user nobody
reject "${setup[@]}" --dry-run --check
"${setup[@]}" --dry-run > "$work/dry.log"
[[ ! -e /etc/zabbix-ai && ! -e /var/lib/zabbix-ai && ! -e /var/log/zabbix-ai ]] || fail 'Dry run wrote system files.'
[[ ! -e "$work/pools/zz-zabbix-ai-www.conf" ]] || fail 'Dry run installed a pool drop-in.'
reject "${setup[@]}" --check

# Preflight errors must not change ownership or create key/runtime directories.
chmod 0600 "$module/Module.php"
printf 'administrator content\n' > "$work/pools/zz-zabbix-ai-www.conf"
reject "${setup[@]}"
[[ "$(stat -c %a "$module/Module.php")" == 600 && ! -e /etc/zabbix-ai ]] || fail 'Conflict caused partial provisioning.'
rm "$work/pools/zz-zabbix-ai-www.conf"
printf 'do not touch\n' > "$work/outside"
chmod 0600 "$work/outside"
ln -s "$work/outside" "$module/linked"
reject "${setup[@]}"
[[ "$(stat -c %a "$work/outside")" == 600 ]] || fail 'Symlink target was modified.'
rm "$module/linked"
ln "$work/outside" "$module/hardlinked"
reject "${setup[@]}"
rm "$module/hardlinked"

"${setup[@]}" > "$work/apply.log"
[[ $(stat -c %a /etc/zabbix-ai/master.key) == 640 ]] || fail 'Key permissions are wrong.'
[[ $(wc -c < /etc/zabbix-ai/master.key) == 65 ]] || fail 'Generated key is not 32 random bytes in hex plus newline.'
if grep -Fq "$(cat /etc/zabbix-ai/master.key)" "$work/apply.log"; then fail 'Installer printed the key.'; fi
key_before=$(sha256sum /etc/zabbix-ai/master.key)
dropin_before=$(sha256sum "$work/pools/zz-zabbix-ai-www.conf")
"${setup[@]}" > "$work/repeat.log"
[[ "$key_before" == "$(sha256sum /etc/zabbix-ai/master.key)" ]] || fail 'Repeated setup replaced the key.'
[[ "$dropin_before" == "$(sha256sum "$work/pools/zz-zabbix-ai-www.conf")" ]] || fail 'Repeated setup changed drop-in.'
"${setup[@]}" --check > "$work/check.log"
[[ "$key_before" == "$(sha256sum /etc/zabbix-ai/master.key)" ]] || fail 'Check changed key.'

# Regression: reject runtime aliases, empty keys and a preexisting pool key source.
rmdir /var/lib/zabbix-ai/reports
ln -s "$work" /var/lib/zabbix-ai/reports
reject "${setup[@]}"
rm /var/lib/zabbix-ai/reports
mkdir /var/lib/zabbix-ai/reports
cp /etc/zabbix-ai/master.key "$work/key.saved"
: > /etc/zabbix-ai/master.key
reject "${setup[@]}"
[[ ! -s /etc/zabbix-ai/master.key ]] || fail 'Empty key was silently replaced.'
cat "$work/key.saved" > /etc/zabbix-ai/master.key
printf 'env[ZABBIX_AI_ENCRYPTION_KEY_FILE] = "/existing/key"\n' >> "$work/pools/www.conf"
reject "${setup[@]}"
printf '[www]\nuser = www-data\ngroup = www-data\nchroot = /srv/php\n' > "$work/pools/www.conf"
reject "${setup[@]}"
printf '[www]\nuser = www-data\ngroup = www-data\n[other]\nuser = nobody\n' > "$work/pools/www.conf"
reject "${setup[@]}"
"${setup[@]}" --fpm-pool www > "$work/multi.log"
printf 'PASS: installer dry run, preflight safety, permissions, key preservation, checks and pool selection.\n'
