# Zabbix-Modules
Modules for Zabbix to extend the capabilities 

## Simple Installation Guide
Download the module folder to the Zabbix Web frontend Server and place in:
/usr/share/zabbix/modules/

## Set permissions
### Folders and Files Permissions
sudo chown -R nginx:nginx /usr/share/zabbix/modules/MODULE_FOLDER_NAME<br>
sudo find /usr/share/zabbix/modules/MODULE_FOLDER_NAME -type d -exec chmod 755 {} \;<br>
sudo find /usr/share/zabbix/modules/MODULE_FOLDER_NAME -type f -exec chmod 644 {} \;<br>

### SELinux
sudo semanage fcontext -a -t httpd_sys_content_t '/usr/share/zabbix/modules/MODULE_FOLDER_NAME(/.*)?'<br>
sudo restorecon -Rv /usr/share/zabbix/modules/MODULE_FOLDER_NAME<br>
setsebool -P httpd_can_network_connect on


## Example Pictures

<details>
<summary>Click to expand example screenshots</summary>

### AI features

**AI providers**  
![AI providers example](./Example%20Pictures/AI-providers.png)

**Instructions and reference**  
![Instructions and reference example](./Example%20Pictures/Instructions_and_reference.png)

**AI chat**  
![AI chat example](./Example%20Pictures/ai_chat.png)

**AI webhook response**  
![AI webhook response example](./Example%20Pictures/ai_webhook_response.png)

### Network maps

**Zabbix Network Map**  
![Zabbix Network Map example](./Example%20Pictures/Zabbix%20Network%20Map.png)

**Zabbix Network Map with filter**  
![Zabbix Network Map with filter example](./Example%20Pictures/Zabbix%20Network%20Map_with_filter.png)

### Branding and UI

**Branding**  
![Branding example](./Example%20Pictures/branding.png)

**Branding login page**  
![Branding login page example](./Example%20Pictures/branding_loginpage.png)

**Branding menu bar**  
![Branding menu bar example](./Example%20Pictures/branding_menubar.png)

**Today's reminder top bar**  
![Today's reminder top bar example](./Example%20Pictures/todays_reminder_topbar.png)

### Healthchecks

**Healthchecks**
![Healthchecks example](./Example%20Pictures/Healtchecks.png)

**Healthchecks settings**
![Healthchecks settings example](./Example%20Pictures/Healtchecks_settings.png)

**Healthchecks history**
![Healthchecks history example](./Example%20Pictures/Healtchecks_history.png)
</details>