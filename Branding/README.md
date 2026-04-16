# Zabbix Branding Module

Customize Zabbix frontend branding without patching core files. Upload a custom login-page logo, sidebar logo, compact sidebar icon and **browser favicon**, plus override the footer text and help URL, all from a UI under **Administration → Branding**.

Settings are stored in `local/conf/rebrand/config.json` and image files are served from the module's own `assets/logos/` directory. Nothing outside the module directory and `local/conf/` is written to.

## What you can customize

| Field                | Where it appears                                      | Recommended size |
| -------------------- | ----------------------------------------------------- | ---------------- |
| Login page logo      | Zabbix login screen                                   | 114 × 30 px      |
| Sidebar logo         | Top of the left sidebar (expanded)                    | 91 × 24 px       |
| Compact sidebar icon | Top of the left sidebar (collapsed)                   | 24 × 24 px       |
| Browser favicon      | Browser tab icon on every page                        | 32 × 32 px       |
| Footer text          | Bottom of every page                                  | —                |
| Help URL             | "Help" link in the user menu                          | —                |

Accepted image formats: SVG, PNG, JPG, GIF, ICO (favicon and compact icon only). Max file size: 2 MB.

## Installation

Copy the module folder to the Zabbix frontend's modules directory:

```bash
sudo mv Branding /usr/share/zabbix/modules/
```

## Permissions & SELinux

Verified install sequence on Zabbix 7.x + nginx + php-fpm + SELinux-enforcing (RHEL/Alma/Rocky). The web process user for php-fpm is **`apache`** on RHEL-based Zabbix installs, even when nginx is the HTTP front-end.

Two directories need to be writable by `apache`:

* `/usr/share/zabbix/modules/Branding/assets/logos/` — uploaded image files
* `/usr/share/zabbix/local/conf/rebrand/` — `config.json` and durable copies of uploaded files

```bash
# 1. Base ownership and mode — module files are read-only for the web user
sudo chown -R root:root /usr/share/zabbix/modules/Branding
sudo find /usr/share/zabbix/modules/Branding -type d -exec chmod 755 {} \;
sudo find /usr/share/zabbix/modules/Branding -type f -exec chmod 644 {} \;

# 2. SELinux label for the module (read-only)
sudo semanage fcontext -a -t httpd_sys_content_t '/usr/share/zabbix/modules/Branding(/.*)?'
sudo restorecon -Rv /usr/share/zabbix/modules/Branding

# 3. Writable logo upload directory (owned by apache, rw-labelled)
sudo install -d -o apache -g apache -m 0775 /usr/share/zabbix/modules/Branding/assets/logos
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/usr/share/zabbix/modules/Branding/assets/logos(/.*)?'
sudo restorecon -Rv /usr/share/zabbix/modules/Branding/assets/logos

# 4. Writable config directory (for config.json + durable asset copies)
sudo install -d -o apache -g apache -m 0775 /usr/share/zabbix/local/conf/rebrand
sudo chgrp apache /usr/share/zabbix/local/conf
sudo chmod 0775 /usr/share/zabbix/local/conf
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/usr/share/zabbix/local/conf(/.*)?'
sudo restorecon -Rv /usr/share/zabbix/local/conf

# 5. Reload php-fpm so it picks up the new module
sudo systemctl restart php-fpm

# 6. Verify the web user can write
sudo -u apache test -w /usr/share/zabbix/modules/Branding/assets/logos && echo OK_logo_dir
sudo -u apache test -w /usr/share/zabbix/local/conf && echo OK_conf
```

If you're not on SELinux (e.g. Debian/Ubuntu), omit the `semanage`/`restorecon` lines.

## Enable the module

1. Log in to Zabbix as a Super Admin.
2. Go to **Administration → General → Modules** and click **Scan directory**.
3. Enable the **Rebrand** module.
4. A new **Administration → Branding** menu item appears. Upload your assets there and click **Update**.

## Browser favicon (tab icon)

The browser tab icon (`/favicon.ico` on the Zabbix domain) is served by the web server directly from the frontend root, and Zabbix's HTML references it with a hardcoded `<link rel="icon" href="favicon.ico">`. We don't patch Zabbix's HTML, so the way to override the tab icon is to **make `/usr/share/zabbix/favicon.ico` a symlink** pointing into the module's writable assets directory.

The module always saves an uploaded favicon to `assets/logos/favicon.ico` (regardless of the original file's extension — browsers content-sniff, so PNG/SVG/ICO all render correctly from that path). Once the symlink is in place, every future upload automatically shows up as the browser tab icon.

Do this **after** uploading a favicon in the UI once (so that `assets/logos/favicon.ico` exists), then as root:

```bash
# Back up the original Zabbix favicon, then replace with a symlink into the module
sudo mv /usr/share/zabbix/favicon.ico /usr/share/zabbix/favicon.ico.zabbix-default
sudo ln -s /usr/share/zabbix/modules/Branding/assets/logos/favicon.ico /usr/share/zabbix/favicon.ico

# SELinux: label the symlink so the web server is allowed to follow it
sudo semanage fcontext -a -t httpd_sys_content_t '/usr/share/zabbix/favicon\.ico'
sudo restorecon -v /usr/share/zabbix/favicon.ico
```

To revert:

```bash
sudo rm /usr/share/zabbix/favicon.ico
sudo mv /usr/share/zabbix/favicon.ico.zabbix-default /usr/share/zabbix/favicon.ico
sudo restorecon -v /usr/share/zabbix/favicon.ico
```

After uploading a new favicon, do a hard refresh (**Ctrl+Shift+R**) — or test in an incognito window. Browsers cache favicons very aggressively, independently of the normal page cache.

## Surviving module updates

Logos and favicon survive module reinstalls because every upload is mirrored to `local/conf/rebrand/` (outside the module directory). When the module is reinstalled and the `assets/logos/` directory is empty, the module's self-heal step copies files back from `local/conf/rebrand/` automatically on the next page load. No re-upload required.

To do a clean reset, delete both the `config.json` and the uploaded files from `local/conf/rebrand/`:

```bash
sudo rm -rf /usr/share/zabbix/local/conf/rebrand/*
sudo rm -f /usr/share/zabbix/local/conf/brand.conf.php
```

## Uninstall

1. Disable the module in **Administration → General → Modules**.
2. Remove files:

```bash
sudo rm -rf /usr/share/zabbix/modules/Branding
sudo rm -rf /usr/share/zabbix/local/conf/rebrand
sudo rm -f /usr/share/zabbix/local/conf/brand.conf.php
```

## Compatibility

Tested on Zabbix 7.x with PHP 8.x, php-fpm, nginx, and SELinux-enforcing (RHEL/Alma/Rocky). The branding constants (`BRAND_LOGO`, `BRAND_LOGO_SIDEBAR`, `BRAND_LOGO_SIDEBAR_COMPACT`, `BRAND_FOOTER`, `BRAND_HELP_URL`) are read by Zabbix's built-in `CBrandHelper`; the favicon override relies on a filesystem symlink, not on any Zabbix branding hook, so it works with any browser and any web server without HTML patching.
