# Zabbix Branding Module

Customize the Zabbix frontend branding without patching Zabbix core files.

The module provides a UI under **Administration → Branding** where a Zabbix Super Admin can configure:

- Login-page logo
- Sidebar logo
- Compact sidebar icon
- Browser favicon
- Footer text
- Help URL

The module uses Zabbix's built-in branding support for logos, footer text and the help URL. The browser favicon is handled separately through a filesystem symlink because Zabbix does not expose the favicon through the same branding configuration.

## Storage layout

The module uses three locations:

| Path | Purpose |
| --- | --- |
| `/usr/share/zabbix/modules/Branding/assets/logos/` | Web-served copies of uploaded images |
| `/usr/share/zabbix/local/conf/rebrand/` | Durable configuration and mirrored copies of uploaded images |
| `/usr/share/zabbix/local/conf/brand.conf.php` | Runtime branding configuration read by Zabbix |

The primary configuration file is:

```text
/usr/share/zabbix/local/conf/rebrand/config.json
```

Uploaded images are served from:

```text
/usr/share/zabbix/modules/Branding/assets/logos/
```

A durable copy of each uploaded image is also stored in:

```text
/usr/share/zabbix/local/conf/rebrand/
```

The module generates:

```text
/usr/share/zabbix/local/conf/brand.conf.php
```

for the branding values consumed by the Zabbix frontend.

No Zabbix core PHP files are patched.

> **Naming note**
>
> The module directory and Administration menu item are named **Branding**, while the internal Zabbix module name is **Rebrand**.
>
> Therefore:
>
> - Module directory: `Branding`
> - Zabbix module name: `Rebrand`
> - Persistent storage directory: `rebrand`

## What you can customize

| Field | Where it appears | Recommended size |
| --- | --- | --- |
| Login page logo | Zabbix login screen | 114 × 30 px |
| Sidebar logo | Top of the expanded sidebar | 91 × 24 px |
| Compact sidebar icon | Top of the collapsed sidebar | 24 × 24 px |
| Browser favicon | Browser tab | 32 × 32 px |
| Footer text | Bottom of Zabbix pages | — |
| Help URL | Help link in the user menu | — |

Supported upload formats:

- PNG
- JPG / JPEG
- GIF
- ICO

Maximum upload size:

```text
2 MB
```

The server validates both the filename extension and detected MIME type.

SVG is intentionally not accepted because an SVG can contain active content such as JavaScript and would be served from the Zabbix origin.

For normal logos, PNG is recommended.

For the browser favicon, a real ICO file is recommended for maximum browser compatibility, although the module accepts the supported image formats listed above.

---

# Installation

Copy or move the module directory into the Zabbix frontend module directory:

```bash
sudo mv Branding /usr/share/zabbix/modules/
```

The resulting path must be:

```text
/usr/share/zabbix/modules/Branding
```

## Verify the PHP-FPM user

The commands below assume that the Zabbix PHP-FPM pool runs as:

```text
apache
```

This is typical for RHEL-based Zabbix installations, but it should be verified before changing permissions.

Run:

```bash
grep -R "^[[:space:]]*user[[:space:]]*=" /etc/php-fpm.d/
grep -R "^[[:space:]]*group[[:space:]]*=" /etc/php-fpm.d/
```

For the Zabbix pool, expect something similar to:

```text
user = apache
group = apache
```

If your Zabbix PHP-FPM pool uses another account, replace `apache` in the commands below with the appropriate user and group.

---

# Permissions and SELinux

The module itself should remain read-only to PHP-FPM.

Only the locations that require runtime changes should be writable:

```text
/usr/share/zabbix/modules/Branding/assets/logos
/usr/share/zabbix/local/conf
/usr/share/zabbix/local/conf/rebrand
/usr/share/zabbix/local/conf/brand.conf.php
```

`local/conf` itself must be writable because the module atomically creates and replaces `brand.conf.php` there.

The remainder of the Branding module stays owned by `root` and read-only to the web process.

## RHEL / AlmaLinux / Rocky Linux with SELinux enforcing

### 1. Set secure base ownership and permissions

```bash
sudo chown -R root:root /usr/share/zabbix/modules/Branding

sudo find /usr/share/zabbix/modules/Branding \
    -type d -exec chmod 0755 {} \;

sudo find /usr/share/zabbix/modules/Branding \
    -type f -exec chmod 0644 {} \;
```

### 2. Create the writable logo directory

```bash
sudo install -d \
    -o apache \
    -g apache \
    -m 0775 \
    /usr/share/zabbix/modules/Branding/assets/logos
```

### 3. Create the persistent Branding storage

```bash
sudo install -d \
    -o apache \
    -g apache \
    -m 0775 \
    /usr/share/zabbix/local/conf/rebrand
```

The module also writes:

```text
/usr/share/zabbix/local/conf/brand.conf.php
```

so PHP-FPM requires directory-level write access to:

```text
/usr/share/zabbix/local/conf
```

Set the group and permissions:

```bash
sudo chgrp apache /usr/share/zabbix/local/conf
sudo chmod 0775 /usr/share/zabbix/local/conf
```

### 4. Remove obsolete SELinux rules from older installations

If an older version of this README was previously followed, remove the old Branding rules before creating the corrected rules.

```bash
sudo semanage fcontext -d \
    '/usr/share/zabbix/modules/Branding/assets/logos(/.)?' \
    2>/dev/null || true

sudo semanage fcontext -d \
    '/usr/share/zabbix/modules/Branding/assets/logos(/.*)?' \
    2>/dev/null || true

sudo semanage fcontext -d \
    '/usr/share/zabbix/modules/Branding(/.*)?' \
    2>/dev/null || true
```

Older versions of this README also created the unnecessarily broad rule:

```text
/usr/share/zabbix/local/conf(/.*)?
```

Check whether it exists:

```bash
sudo semanage fcontext -l | grep '/usr/share/zabbix/local/conf'
```

If the broad rule below was created specifically for this Branding module:

```text
/usr/share/zabbix/local/conf(/.*)?
```

remove it:

```bash
sudo semanage fcontext -d \
    '/usr/share/zabbix/local/conf(/.*)?' \
    2>/dev/null || true
```

Do not remove that rule if it was intentionally created for another local application or module.

Remove any previous Branding-specific replacement rules as well:

```bash
sudo semanage fcontext -d \
    '/usr/share/zabbix/local/conf/rebrand(/.*)?' \
    2>/dev/null || true

sudo semanage fcontext -d \
    '/usr/share/zabbix/local/conf/brand\.conf\.php' \
    2>/dev/null || true

sudo semanage fcontext -d \
    '/usr/share/zabbix/local/conf' \
    2>/dev/null || true
```

### 5. Configure SELinux contexts

The whole Branding module is read-only by default:

```bash
sudo semanage fcontext -a \
    -t httpd_sys_content_t \
    '/usr/share/zabbix/modules/Branding(/.*)?'
```

The logo storage directory is the exception and must be writable:

```bash
sudo semanage fcontext -a \
    -t httpd_sys_rw_content_t \
    '/usr/share/zabbix/modules/Branding/assets/logos(/.*)?'
```

> **Important**
>
> The writable `assets/logos` rule must be added **after** the general Branding rule.
>
> Local SELinux file-context rules are evaluated in reverse order of definition. The newest matching rule is evaluated first.
>
> Adding the general Branding rule after the writable logo rule can therefore cause `assets/logos` to be incorrectly labeled `httpd_sys_content_t`, which prevents PHP-FPM from uploading or restoring logos.

Allow PHP-FPM to create `brand.conf.php` in `local/conf`:

```bash
sudo semanage fcontext -a \
    -t httpd_sys_rw_content_t \
    '/usr/share/zabbix/local/conf'
```

Configure the persistent Branding directory as writable:

```bash
sudo semanage fcontext -a \
    -t httpd_sys_rw_content_t \
    '/usr/share/zabbix/local/conf/rebrand(/.*)?'
```

Configure the generated Zabbix branding configuration as writable:

```bash
sudo semanage fcontext -a \
    -t httpd_sys_rw_content_t \
    '/usr/share/zabbix/local/conf/brand\.conf\.php'
```

### 6. Apply the SELinux contexts

Apply the Branding module contexts:

```bash
sudo restorecon -Rv /usr/share/zabbix/modules/Branding
```

Apply the persistent storage context:

```bash
sudo restorecon -Rv /usr/share/zabbix/local/conf/rebrand
```

Apply the context to `local/conf` itself:

```bash
sudo restorecon -v /usr/share/zabbix/local/conf
```

If `brand.conf.php` already exists:

```bash
sudo restorecon -v /usr/share/zabbix/local/conf/brand.conf.php
```

### 7. Verify SELinux

Check the actual contexts:

```bash
ls -ldZ \
    /usr/share/zabbix/modules/Branding \
    /usr/share/zabbix/modules/Branding/assets/logos \
    /usr/share/zabbix/local/conf \
    /usr/share/zabbix/local/conf/rebrand
```

Expected SELinux types:

```text
/usr/share/zabbix/modules/Branding
    httpd_sys_content_t

/usr/share/zabbix/modules/Branding/assets/logos
    httpd_sys_rw_content_t

/usr/share/zabbix/local/conf
    httpd_sys_rw_content_t

/usr/share/zabbix/local/conf/rebrand
    httpd_sys_rw_content_t
```

If `brand.conf.php` exists:

```bash
ls -lZ /usr/share/zabbix/local/conf/brand.conf.php
```

Expected type:

```text
httpd_sys_rw_content_t
```

Verify the configured SELinux policy against the paths:

```bash
sudo matchpathcon -V \
    /usr/share/zabbix/modules/Branding/assets/logos

sudo matchpathcon -V \
    /usr/share/zabbix/local/conf

sudo matchpathcon -V \
    /usr/share/zabbix/local/conf/rebrand
```

If `brand.conf.php` exists:

```bash
sudo matchpathcon -V \
    /usr/share/zabbix/local/conf/brand.conf.php
```

### 8. Verify Unix permissions

Verify that the PHP-FPM user has normal filesystem write permission:

```bash
sudo -u apache test \
    -w /usr/share/zabbix/modules/Branding/assets/logos \
    && echo "OK: apache can write to logo directory"

sudo -u apache test \
    -w /usr/share/zabbix/local/conf \
    && echo "OK: apache can write to local/conf"

sudo -u apache test \
    -w /usr/share/zabbix/local/conf/rebrand \
    && echo "OK: apache can write to rebrand directory"
```

> `sudo -u apache test -w` verifies normal Unix/DAC permissions only.
>
> It does **not** prove that SELinux allows the PHP-FPM process to write. Use `matchpathcon`, `ls -Z`, and the SELinux audit log when troubleshooting SELinux problems.

### 9. Validate and restart PHP-FPM

Validate the PHP-FPM configuration:

```bash
sudo php-fpm -t
```

If successful:

```bash
sudo systemctl restart php-fpm
```

Verify:

```bash
sudo systemctl --no-pager --full status php-fpm
```

---

# Systems without SELinux

For Debian, Ubuntu, or other systems where SELinux is not used, omit the following commands:

```text
semanage
restorecon
matchpathcon
```

The ownership and normal filesystem permissions are still required.

The PHP-FPM user may also differ from `apache`, for example:

```text
www-data
```

Use the account configured for the Zabbix PHP-FPM pool.

---

# Enable the module

1. Log in to Zabbix as a **Super Admin**.
2. Go to **Administration → General → Modules**.
3. Click **Scan directory**.
4. Find and enable the **Rebrand** module.
5. Open **Administration → Branding**.
6. Configure the required logos, favicon, footer text and help URL.
7. Click **Update**.

The module is listed under its internal name:

```text
Rebrand
```

while its filesystem directory and Administration menu entry use:

```text
Branding
```

After changing logos or the favicon, perform a hard browser refresh:

```text
Ctrl+Shift+R
```

An incognito/private browser window can also be useful when testing cached branding assets.

---

# Browser favicon

Zabbix normally serves:

```text
/favicon.ico
```

from the frontend root:

```text
/usr/share/zabbix/favicon.ico
```

The Branding module stores the uploaded favicon as:

```text
/usr/share/zabbix/modules/Branding/assets/logos/favicon.ico
```

To make the uploaded favicon appear as the Zabbix browser-tab icon, replace the frontend favicon with a symlink to the Branding module favicon.

## 1. Upload a favicon first

Use **Administration → Branding** and upload the favicon.

Verify that the file exists:

```bash
ls -l /usr/share/zabbix/modules/Branding/assets/logos/favicon.ico
```

Do not create the symlink until the target exists.

## 2. Back up the original Zabbix favicon

Only back up the original file if a backup has not already been created:

```bash
if [ -e /usr/share/zabbix/favicon.ico ] \
    && [ ! -L /usr/share/zabbix/favicon.ico ] \
    && [ ! -e /usr/share/zabbix/favicon.ico.zabbix-default ]; then

    sudo mv \
        /usr/share/zabbix/favicon.ico \
        /usr/share/zabbix/favicon.ico.zabbix-default
fi
```

## 3. Create the symlink

```bash
sudo ln -sfn \
    /usr/share/zabbix/modules/Branding/assets/logos/favicon.ico \
    /usr/share/zabbix/favicon.ico
```

Verify:

```bash
ls -l /usr/share/zabbix/favicon.ico
```

Expected:

```text
/usr/share/zabbix/favicon.ico -> /usr/share/zabbix/modules/Branding/assets/logos/favicon.ico
```

## 4. SELinux

On SELinux-enabled systems, remove an old custom favicon rule if one exists:

```bash
sudo semanage fcontext -d \
    '/usr/share/zabbix/favicon\.ico' \
    2>/dev/null || true
```

Add the intended read-only web context:

```bash
sudo semanage fcontext -a \
    -t httpd_sys_content_t \
    '/usr/share/zabbix/favicon\.ico'
```

Apply it:

```bash
sudo restorecon -v /usr/share/zabbix/favicon.ico
```

The favicon target under:

```text
modules/Branding/assets/logos/
```

is already labeled:

```text
httpd_sys_rw_content_t
```

which allows PHP-FPM to update it while still allowing the web server to read it.

## 5. Clear the browser favicon cache

Favicons are cached aggressively by browsers.

After changing the favicon:

- Perform a hard refresh with **Ctrl+Shift+R**
- Or test with an incognito/private browser window
- If necessary, close and reopen the browser tab

---

# Restore the original favicon

To restore the original Zabbix favicon:

```bash
sudo rm -f /usr/share/zabbix/favicon.ico
```

Restore the backup:

```bash
if [ -e /usr/share/zabbix/favicon.ico.zabbix-default ]; then
    sudo mv \
        /usr/share/zabbix/favicon.ico.zabbix-default \
        /usr/share/zabbix/favicon.ico
fi
```

Remove the Branding-specific SELinux rule:

```bash
sudo semanage fcontext -d \
    '/usr/share/zabbix/favicon\.ico' \
    2>/dev/null || true
```

Restore the normal SELinux context:

```bash
sudo restorecon -v /usr/share/zabbix/favicon.ico
```

---

# Surviving module updates

Branding configuration and uploaded assets are mirrored outside the module directory under:

```text
/usr/share/zabbix/local/conf/rebrand/
```

This allows them to survive replacement of:

```text
/usr/share/zabbix/modules/Branding
```

The durable storage contains:

```text
config.json
logo_main.*
logo_sidebar.*
logo_compact.*
favicon.ico
```

as applicable.

When the web-served copy of an asset is missing from:

```text
/usr/share/zabbix/modules/Branding/assets/logos/
```

the module can restore it from the durable storage.

## Important after replacing the module directory

Replacing or reinstalling the module also replaces:

```text
/usr/share/zabbix/modules/Branding/assets/logos/
```

The newly installed directory will normally be owned by `root` and may have inherited the module's read-only SELinux context.

Therefore, after every full module replacement or reinstall, reapply the writable logo-directory configuration before opening the Branding page:

```bash
sudo install -d \
    -o apache \
    -g apache \
    -m 0775 \
    /usr/share/zabbix/modules/Branding/assets/logos

sudo restorecon -Rv \
    /usr/share/zabbix/modules/Branding
```

Verify:

```bash
ls -ldZ \
    /usr/share/zabbix/modules/Branding/assets/logos
```

Expected:

```text
apache apache
httpd_sys_rw_content_t
```

Once the writable serving directory is restored, the module can copy missing assets back from:

```text
/usr/share/zabbix/local/conf/rebrand/
```

No manual re-upload should be required.

---

# Clean reset

To remove all custom Branding configuration and uploaded assets, first restore the original favicon if the Branding favicon symlink is in use.

Then remove the durable configuration:

```bash
sudo rm -rf /usr/share/zabbix/local/conf/rebrand/*
```

Remove the generated Zabbix branding configuration:

```bash
sudo rm -f /usr/share/zabbix/local/conf/brand.conf.php
```

Remove the web-served Branding assets:

```bash
sudo rm -f /usr/share/zabbix/modules/Branding/assets/logos/*
```

The module remains installed and can be configured again from:

**Administration → Branding**

---

# Troubleshooting

## Branding page reports "failed to save file"

Check normal permissions:

```bash
ls -ld \
    /usr/share/zabbix/modules/Branding/assets/logos \
    /usr/share/zabbix/local/conf \
    /usr/share/zabbix/local/conf/rebrand
```

Check SELinux:

```bash
ls -ldZ \
    /usr/share/zabbix/modules/Branding/assets/logos \
    /usr/share/zabbix/local/conf \
    /usr/share/zabbix/local/conf/rebrand
```

The logo directory must be:

```text
httpd_sys_rw_content_t
```

and **not**:

```text
httpd_sys_content_t
```

Check SELinux audit events:

```bash
sudo ausearch -m AVC,USER_AVC -ts recent -i
```

A denial similar to:

```text
comm=php-fpm
scontext=system_u:system_r:httpd_t:s0
denied { write }
```

indicates that SELinux, rather than normal Unix permissions, is blocking PHP-FPM.

Check the configured SELinux rules:

```bash
sudo semanage fcontext -l | grep -E \
    '/usr/share/zabbix/modules/Branding|/usr/share/zabbix/local/conf'
```

Check what SELinux expects:

```bash
sudo matchpathcon \
    /usr/share/zabbix/modules/Branding/assets/logos
```

Expected:

```text
httpd_sys_rw_content_t
```

## Check PHP-FPM

```bash
sudo php-fpm -t
sudo systemctl --no-pager --full status php-fpm
```

Check the Zabbix PHP-FPM pool user:

```bash
grep -R "^[[:space:]]*user[[:space:]]*=" /etc/php-fpm.d/
grep -R "^[[:space:]]*group[[:space:]]*=" /etc/php-fpm.d/
```

---

# Uninstall

## 1. Disable the module

In Zabbix:

**Administration → General → Modules → Rebrand → Disable**

## 2. Restore the original favicon

If the Branding favicon symlink is configured:

```bash
sudo rm -f /usr/share/zabbix/favicon.ico

if [ -e /usr/share/zabbix/favicon.ico.zabbix-default ]; then
    sudo mv \
        /usr/share/zabbix/favicon.ico.zabbix-default \
        /usr/share/zabbix/favicon.ico
fi
```

## 3. Remove module files and persistent configuration

```bash
sudo rm -rf /usr/share/zabbix/modules/Branding
sudo rm -rf /usr/share/zabbix/local/conf/rebrand
sudo rm -f /usr/share/zabbix/local/conf/brand.conf.php
```

## 4. Remove Branding-specific SELinux rules

```bash
sudo semanage fcontext -d \
    '/usr/share/zabbix/modules/Branding/assets/logos(/.*)?' \
    2>/dev/null || true

sudo semanage fcontext -d \
    '/usr/share/zabbix/modules/Branding(/.*)?' \
    2>/dev/null || true

sudo semanage fcontext -d \
    '/usr/share/zabbix/local/conf/rebrand(/.*)?' \
    2>/dev/null || true

sudo semanage fcontext -d \
    '/usr/share/zabbix/local/conf/brand\.conf\.php' \
    2>/dev/null || true

sudo semanage fcontext -d \
    '/usr/share/zabbix/local/conf' \
    2>/dev/null || true

sudo semanage fcontext -d \
    '/usr/share/zabbix/favicon\.ico' \
    2>/dev/null || true
```

Restore the normal SELinux contexts for remaining Zabbix paths:

```bash
sudo restorecon -v /usr/share/zabbix/local/conf

if [ -e /usr/share/zabbix/favicon.ico ]; then
    sudo restorecon -v /usr/share/zabbix/favicon.ico
fi
```

## 5. Restart PHP-FPM

```bash
sudo php-fpm -t &&
sudo systemctl restart php-fpm
```

---

# Security

The Branding configuration action is restricted to Zabbix Super Admin users.

The module validates uploaded files server-side by:

- Limiting files to 2 MB
- Restricting accepted filename extensions
- Checking detected MIME type
- Rejecting unsupported formats
- Rejecting SVG uploads
- Normalizing stored filenames
- Restricting the Help URL to HTTP and HTTPS URLs
- Using Zabbix CSRF protection for configuration changes

The module itself should remain read-only to PHP-FPM except for the specifically required runtime storage locations documented above.

Do not make the entire module writable by the web server.

Do not disable SELinux to make Branding uploads work.

---

# Compatibility

Tested with:

- Zabbix 7.x
- PHP 8.x
- PHP-FPM
- nginx
- RHEL-family systems with SELinux enforcing

The module uses Zabbix's built-in branding values:

```text
BRAND_LOGO
BRAND_LOGO_SIDEBAR
BRAND_LOGO_SIDEBAR_COMPACT
BRAND_FOOTER
BRAND_HELP_URL
```

These values are written to:

```text
/usr/share/zabbix/local/conf/brand.conf.php
```

and consumed by Zabbix's built-in branding functionality.

The browser favicon does not use a Zabbix branding constant. It is provided through the filesystem symlink described above.
