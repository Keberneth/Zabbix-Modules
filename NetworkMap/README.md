# Network Map - Zabbix frontend module

This is a native Zabbix frontend module rewrite of the original standalone FastAPI-based
network map application.

This package is the **Zabbix-only** variant:

- Menu entry: **Monitoring -> Network map**
- Native Zabbix action/controller/view structure
- Uses the logged-in Zabbix user's permissions through the frontend API
- No separate Zabbix API token needed
- No NetBox dependency
- Host labels come from Zabbix hosts
- Cytoscape-based graph rendering is kept

## Requirements

- Zabbix frontend with frontend module support
- The connection data already present in Zabbix items named:
  - `linux-network-connections`
  - `windows-network-connections`
- Download plugins here: https://github.com/Keberneth/Zabbix-Plugins
- Web server / php-fpm user must be able to read the module files
- Web server / php-fpm user must be able to write to the configured cache directory

# Download Zabbix plugin and template here
https://github.com/Keberneth/Zabbix-Plugins

## Installation

### 1. Copy the module directory into the Zabbix frontend modules directory

Use the module directory name exactly as provided:

```bash
cp -a NetworkMap /usr/share/zabbix/modules
```

Examples:

- Typical package/appliance frontend root: if your frontend root is `/usr/share/zabbix`,
  the module directory is usually `/usr/share/zabbix/modules/NetworkMap`

### 2. Set ownership and permissions

Example using an ngingx or php-fpm web user:

```bash
mkdir -p /var/lib/zabbix/network-map-cache
chown -R ngingx:ngingx /var/lib/zabbix/network-map-cache
chmod 0770 /var/lib/zabbix/network-map-cache

find /path/to/zabbix-frontend/modules/NetworkMap -type d -exec chmod 0755 {} \;
find /path/to/zabbix-frontend/modules/NetworkMap -type f -exec chmod 0644 {} \;
```

sudo semanage fcontext -a -t httpd_sys_content_t '/usr/share/zabbix/modules/NetworkMap(/.*)?'
sudo restorecon -Rv /usr/share/zabbix/modules/NetworkMap


If your web user is `www-data`, `apache`, or something else, adjust the ownership accordingly.

### 3. Register and enable the module in Zabbix

1. Log in as a **Super admin**
2. Open **Administration -> General -> Modules**
3. Click **Scan directory**
4. Find **Network Map**
5. Click **Disabled** to enable it

### 5. Open the map

After enabling, the menu entry appears here:

- **Monitoring -> Network map**

## Operational notes

### Permissions

The map uses the logged-in user's Zabbix frontend permissions when reading hosts/items/history
through the internal frontend API. The module also uses a per-user map cache key to reduce
the chance of serving one user's cached graph to another user.

### Host naming

The default and recommended setting is:

```php
'host_label_source' => 'visible'
```

That gives you the host visible name on the map. If you prefer the technical host name,
set it to:

```php
'host_label_source' => 'technical'
```

### Performance

This module rebuilds the graph from recent history and then caches the result. If your
connection items are very high-volume, start with the defaults and only increase
`history_limit_per_item` when needed.

### If the module does not show up

Check these first:

- `manifest.json` exists directly under `NetworkMap/`
- the module is in the correct `modules/` directory
- the web server user can read the directory tree
- owner and permissions äre correct on folders and files


