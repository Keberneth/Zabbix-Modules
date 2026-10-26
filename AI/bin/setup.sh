#!/usr/bin/env bash
# Local Linux provisioning only. Does not change Zabbix settings or restart PHP.
set -euo pipefail
export LC_ALL=C

usage() {
    cat <<'HELP'
Usage: sudo bash AI/bin/setup.sh [options]

  --fpm-pool-config FILE  Existing pool file for the PHP-FPM pool serving Zabbix
  --fpm-pool NAME         Pool section (required if FILE has multiple sections)
  --apply-fpm             Install an env-path drop-in beside that pool file
  --php-user USER         Worker account; required without --fpm-pool-config
  --php-group GROUP       Worker group (defaults to pool group or user's group)
  --module-dir DIR        Deployed AI directory (defaults to this script's parent)
  --dry-run              Validate inputs and print the plan; change nothing
  --check                Check existing ownership, modes and access; change nothing
  --help                 Show this help

Creates /etc/zabbix-ai/master.key only if absent, never rotates/replaces it.
Uses /var/lib/zabbix-ai/{state,state/pending,reports} and /var/log/zabbix-ai/archive.
The PHP-FPM directory must already be included by your PHP-FPM main configuration.
Without --apply-fpm, prints the pool configuration to install manually.
Does not change SELinux policy. See INSTALL.md if SELinux is enabled.
Back up the master key securely and use the SAME key on all frontend nodes.
HELP
}
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
need_value() { [[ $# -ge 2 && -n "$2" && "$2" != --* ]] || die "$1 requires a value"; }
module_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
pool_config= pool= php_user= php_group= apply_fpm=0 mode=apply
while (($#)); do
    case "$1" in
        --help|-h) usage; exit 0 ;;
        --module-dir) need_value "$@"; module_dir=${2%/}; shift 2 ;;
        --fpm-pool-config) need_value "$@"; pool_config=$2; shift 2 ;;
        --fpm-pool) need_value "$@"; pool=$2; shift 2 ;;
        --php-user) need_value "$@"; php_user=$2; shift 2 ;;
        --php-group) need_value "$@"; php_group=$2; shift 2 ;;
        --apply-fpm) apply_fpm=1; shift ;;
        --dry-run|--check)
            [[ "$mode" == apply ]] || die 'Choose either --dry-run or --check.'
            mode=${1#--}; shift ;;
        *) die "Unknown option: $1 (use --help)" ;;
    esac
done
for command in awk find stat getent id readlink cmp openssl install grep; do
    command -v "$command" >/dev/null || die "Required command unavailable: $command"
done
[[ "$mode" == dry-run || $EUID == 0 ]] || die 'Run as root (or use --dry-run).'

# Reject aliases before recursive ownership/mode changes, including hard links.
safe_path() {
    local path=$1 component
    [[ "$path" == /* && "$path" != / && "$path" != *$'\n'* && "$path" != *$'\r'* && "$path" != *'"'* && "$path" != *'\\'* ]] || die "Unsafe absolute path: $path"
    [[ "$(readlink -m -- "$path")" == "$path" ]] || die "Path must be canonical and contain no symlink components: $path"
    component=$path
    while [[ "$component" != / ]]; do
        [[ ! -L "$component" ]] || die "Symlink path refused: $component"
        component=$(dirname -- "$component")
    done
}
safe_path "$module_dir"
[[ -f "$module_dir/manifest.json" && -f "$module_dir/Module.php" && -d "$module_dir/lib" ]] || die 'Choose the deployed AI module directory, containing manifest.json, Module.php and lib/.'
grep -Eq '"id"[[:space:]]*:[[:space:]]*"custom_ai"' "$module_dir/manifest.json" || die 'The selected manifest is not the Zabbix AI module.'
[[ -z "$(find "$module_dir" \( -type l -o -type f -links +1 \) -print -quit)" ]] || die 'Module tree contains symlinks or hard-linked files; deploy a normal copy before setup.'

if [[ -n "$pool_config" ]]; then
    safe_path "$pool_config"
    [[ -f "$pool_config" ]] || die "PHP-FPM pool file not found: $pool_config"
    mapfile -t pools < <(awk '/^[[:space:]]*\[[^]]+\][[:space:]]*([;#].*)?$/ {sub(/^[[:space:]]*\[/, ""); sub(/\].*$/, ""); if ($0 != "global") print}' "$pool_config")
    if [[ -z "$pool" ]]; then
        [[ ${#pools[@]} == 1 ]] || die 'Pool file has multiple/no pool sections; specify --fpm-pool.'
        pool=${pools[0]}
    fi
    [[ "$pool" =~ ^[a-zA-Z0-9_-]+$ ]] || die 'Pool name may contain only letters, digits, underscore or hyphen.'
    printf '%s\n' "${pools[@]}" | grep -Fxq -- "$pool" || die "Pool [$pool] not found in $pool_config"
    pool_value() {
        awk -v pool="$pool" -v key="$1" '
            /^[[:space:]]*\[/ {section=$0; sub(/^[[:space:]]*\[/,"",section); sub(/\].*$/,"",section)}
            section==pool && $0 ~ "^[[:space:]]*" key "[[:space:]]*=" {
                value=$0; sub(/^[^=]*=[[:space:]]*/,"",value); sub(/[[:space:]]*[;#].*$/,"",value); sub(/[[:space:]]*$/,"",value)
            } END {print value}' "$pool_config"
    }
    pool_user=$(pool_value user)
    pool_group=$(pool_value group)
    [[ -z "$php_user" || -z "$pool_user" || "$php_user" == "$pool_user" ]] || die '--php-user disagrees with the selected pool.'
    [[ -z "$php_group" || -z "$pool_group" || "$php_group" == "$pool_group" ]] || die '--php-group disagrees with the selected pool.'
    php_user=${php_user:-$pool_user}
    php_group=${php_group:-$pool_group}
    [[ -z "$(pool_value chroot)" ]] || die 'Chroot pools require manual provisioning of matching paths; see INSTALL.md.'
else
    [[ -z "$pool" && "$apply_fpm" == 0 ]] || die '--fpm-pool and --apply-fpm require --fpm-pool-config.'
fi
[[ "$php_user" =~ ^[a-zA-Z_][a-zA-Z0-9_-]*\$?$ ]] || die 'Specify the real PHP worker account with --php-user or --fpm-pool-config.'
id "$php_user" >/dev/null 2>&1 || die "Worker account does not exist: $php_user"
[[ "$(id -u "$php_user")" != 0 ]] || die 'PHP must not run as root.'
php_group=${php_group:-$(id -gn "$php_user")}
[[ "$php_group" =~ ^[a-zA-Z_][a-zA-Z0-9_-]*\$?$ ]] || die 'Invalid worker group.'
group_record=$(getent group "$php_group") || die "Worker group does not exist: $php_group"
group_id=$(printf '%s\n' "$group_record" | awk -F: '{print $3}')
[[ "$group_id" != 0 ]] || die 'Refusing to grant the root group to PHP.'

key_dir=/etc/zabbix-ai
key_file=$key_dir/master.key
dirs=(/var/lib/zabbix-ai /var/lib/zabbix-ai/state /var/lib/zabbix-ai/state/pending /var/lib/zabbix-ai/reports /var/log/zabbix-ai /var/log/zabbix-ai/archive)
for path in "$key_dir" "$key_file" "${dirs[@]}"; do safe_path "$path"; done
[[ "$module_dir" != "$key_dir" && "$key_dir" != "$module_dir"/* ]] || die 'Master-key directory must be outside the module.'
[[ ! -e "$key_dir" || -d "$key_dir" ]] || die "$key_dir is not a directory."
for path in "${dirs[@]}"; do
    [[ ! -e "$path" || -d "$path" ]] || die "$path is not a directory."
    [[ "$path" != "$module_dir" && "$path" != "$module_dir"/* && "$module_dir" != "$path"/* ]] || die 'Module code and runtime directories must be separate.'
done
if [[ -e "$key_file" ]]; then
    [[ -f "$key_file" && -s "$key_file" && "$(stat -c %s "$key_file")" -le 65536 && "$(stat -c %h "$key_file")" == 1 ]] || die 'Existing master key is empty, invalid or hard-linked; it will not be replaced.'
fi

dropin=
snippet() {
    printf '; Managed by Zabbix AI setup.sh: path only, no secret value.\n[%s]\nenv[ZABBIX_AI_ENCRYPTION_KEY_FILE] = "%s"\n' "${pool:-YOUR_POOL_NAME}" "$key_file"
}
if [[ -n "$pool_config" ]]; then
    dropin=$(dirname -- "$pool_config")/zz-zabbix-ai-$pool.conf
    safe_path "$dropin"
    [[ "$dropin" != "$pool_config" ]] || die 'Select the original PHP-FPM pool file, not the generated drop-in.'
    # Do not take over a deployment that already uses another key source.
    if [[ "$apply_fpm" == 1 ]] && grep -Eq '^[[:space:]]*env\[ZABBIX_AI_ENCRYPTION_KEY(_FILE)?\][[:space:]]*=' "$pool_config"; then
        die 'Pool already configures a master-key source; preserve it and configure the path manually.'
    fi
    if [[ -e "$dropin" ]]; then
        [[ -f "$dropin" && "$(stat -c %h "$dropin")" == 1 ]] || die 'Existing PHP-FPM drop-in is not a normal file.'
        cmp -s "$dropin" <(snippet) || die "Conflicting PHP-FPM drop-in; refusing to overwrite: $dropin"
    fi
fi

printf 'Module: %s\nPHP worker: %s (group %s)\nMaster key: %s (%s)\n' "$module_dir" "$php_user" "$php_group" "$key_file" "$([[ -e "$key_file" ]] && printf preserve || printf create)"
printf 'Runtime directories: %s\n' "${dirs[@]}"
if [[ "$mode" == dry-run ]]; then
    printf 'Plan: root-owned code (directories 0755/files 0644), master directory 0750/key 0640, runtime directories 02770.\n'
    [[ "$apply_fpm" == 0 ]] || printf 'Install PHP-FPM drop-in: %s\n' "$dropin"
    snippet
    printf 'Dry run complete; no files changed.\n'
    exit 0
fi

if [[ "$mode" == apply ]]; then
    chown -R root:root -- "$module_dir"
    find "$module_dir" -type d -exec chmod 0755 -- {} +
    find "$module_dir" -type f -exec chmod 0644 -- {} +
    install -d -o root -g "$php_group" -m 0750 -- "$key_dir"
    for path in "${dirs[@]}"; do install -d -o root -g "$php_group" -m 02770 -- "$path"; done
    if [[ ! -e "$key_file" ]]; then
        key_tmp=$(mktemp "$key_dir/.master.XXXXXXXX")
        trap 'rm -f -- "${key_tmp:-}" "${fpm_tmp:-}"' EXIT
        openssl rand -hex 32 > "$key_tmp"
        chown root:"$php_group" "$key_tmp"
        chmod 0640 "$key_tmp"
        ln -- "$key_tmp" "$key_file" || die 'Key appeared during setup; refusing to overwrite it.'
        rm -f -- "$key_tmp"
        key_tmp=
    else
        chown root:"$php_group" -- "$key_file"
        chmod 0640 -- "$key_file"
    fi
    if [[ "$apply_fpm" == 1 && ! -e "$dropin" ]]; then
        fpm_tmp=$(mktemp "$(dirname -- "$dropin")/.zabbix-ai.XXXXXXXX")
        trap 'rm -f -- "${key_tmp:-}" "${fpm_tmp:-}"' EXIT
        snippet > "$fpm_tmp"
        chmod 0644 "$fpm_tmp"
        chown root:root "$fpm_tmp"
        ln -- "$fpm_tmp" "$dropin" || die 'Drop-in appeared during setup; refusing to overwrite it.'
        rm -f -- "$fpm_tmp"
        fpm_tmp=
    fi
fi

# These checks examine modes without creating probe files or displaying secrets.
check_mode() {
    [[ -e "$1" ]] || die "Missing: $1 (run setup without --check)."
    [[ "$(stat -c '%u:%g:%a' "$1")" == "$2" ]] || die "Unexpected ownership/mode on $1; expected $2."
}
check_mode "$key_dir" "0:$group_id:750"
check_mode "$key_file" "0:$group_id:640"
for path in "${dirs[@]}"; do check_mode "$path" "0:$group_id:2770"; done
[[ -z "$(find "$module_dir" \( ! -user root -o ! -group root -o -type d ! -perm 0755 -o -type f ! -perm 0644 \) -print -quit)" ]] || die 'Module code ownership/modes do not match root:root 0755/0644.'
if [[ "$apply_fpm" == 1 ]]; then
    check_mode "$dropin" '0:0:644'
fi
if command -v runuser >/dev/null; then
    runuser -u "$php_user" -g "$php_group" -- test -r "$key_file" || die 'PHP worker cannot read the key.'
    for path in "${dirs[@]}"; do
        runuser -u "$php_user" -g "$php_group" -- test -w "$path" || die "PHP worker cannot write $path"
    done
fi
printf 'Filesystem checks passed. Back up %s securely, separately from database-only backups.\n' "$key_file"
if [[ "$apply_fpm" == 1 ]]; then
    printf 'PHP-FPM drop-in: %s\nValidate PHP-FPM configuration, then reload the correct service. No service was restarted.\n' "$dropin"
else
    printf 'Add this to the serving PHP-FPM pool (or expose the same path in your SAPI):\n'
    snippet
fi
printf 'If SELinux is enabled, apply the labels in INSTALL.md. Reopen AI Settings to view the automatic setup checks.\n'
