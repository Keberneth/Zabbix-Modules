# Message of the Day

Zabbix frontend module for Zabbix 7.

What it shows:
- High and Critical unresolved problem summary
- Links to matching problems and recent event entries
- Frontend software update status if Zabbix update check data is available
- Today's maintenance windows
- Additional maintenance windows later this week

Behavior:
- A top banner is injected automatically on frontend pages after login.
- The first page load in a browser session shows the expanded banner.
- Subsequent page loads in the same browser tab collapse the banner automatically to keep it visible but non-intrusive.
- A full details page is available under Monitoring -> Message of the Day -> Overview.

Install:
1. Copy the `Message_of_the_Day` directory into `ui/modules/`.
2. In Zabbix, go to Administration -> General -> Modules.
3. Scan directory and enable **Message of the Day**.
