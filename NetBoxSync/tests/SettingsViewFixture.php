<?php declare(strict_types = 1);

/** Render the real settings view without a Zabbix installation or live services. */
require_once dirname(__DIR__).'/lib/Util.php';
require_once dirname(__DIR__).'/lib/Config.php';

if (!function_exists('_')) {
    function _(string $text): string { return $text; }
}

final class CCsrfTokenHelper {
    public const CSRF_TOKEN_NAME = '_csrf_token';
    public static function get(string $action): string { return 'fixture-'.$action; }
}

$config = \Modules\NetBoxSync\Lib\Config::defaults();
$config['netbox']['url'] = 'https://netbox.example.test';
$config['netbox']['token'] = 'fixture-token-must-never-appear-in-html';
$config['zabbix_api']['url'] = 'https://zabbix.example.test/api_jsonrpc.php';
$config['zabbix_api']['token'] = 'fixture-zabbix-token-must-never-appear-in-html';

$data = [
    'title' => 'NetBox sync settings',
    'config' => \Modules\NetBoxSync\Lib\Config::sanitizeForView($config),
    'standard_sync_catalog' => \Modules\NetBoxSync\Lib\Config::standardSyncCatalog(),
    'last_summary' => [
        'ok' => false,
        'started_at' => '2026-10-31T12:00:00+00:00',
        'finished_at' => '2026-10-31T12:00:05+00:00',
        'elapsed_seconds' => 5,
        'hosts_processed' => 2,
        'hosts_total' => 3,
        'mappings_run' => 4,
        'created' => 1,
        'updated' => 2,
        'errors' => 1,
        'source' => 'ui',
        'messages' => [[
            'level' => 'error',
            'time' => '2026-10-31T12:00:04+00:00',
            'message' => 'Fixture mapping failed <script>alert("unsafe")</script>'
        ]]
    ],
    'settings_save_url' => '/save',
    'run_url' => '/run',
    'run_status_url' => '/run-status',
    'test_url' => '/test-netbox',
    'zabbix_test_url' => '/test-zabbix',
    'log_url' => '/log'
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NetBox Sync settings fixture</title>
    <style>
        body { margin: 24px; font: 14px Arial, sans-serif; }
        <?php readfile(dirname(__DIR__).'/assets/css/netboxsync.css'); ?>
        /* Exercise module buttons against frontend-style text-button resets. */
        button, .btn-alt {
            border: 0; background: transparent; padding: 0;
            border-radius: 0; color: inherit; font: inherit; box-shadow: none;
        }
    </style>
</head>
<body>
<?php require dirname(__DIR__).'/views/netboxsync.settings.php'; ?>
<script><?php readfile(dirname(__DIR__).'/assets/js/netboxsync.settings.js'); ?></script>
</body>
</html>
