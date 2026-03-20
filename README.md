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