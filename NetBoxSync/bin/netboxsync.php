#!/usr/bin/env php
<?php declare(strict_types = 0);

use Modules\NetBoxSync\Lib\DbConnector;
use Modules\NetBoxSync\Lib\StandaloneConfig;
use Modules\NetBoxSync\Lib\SyncEngine;
use Modules\NetBoxSync\Lib\ZabbixApiClient;

const NETBOXSYNC_EXIT_OK = 0;
const NETBOXSYNC_EXIT_FAILED = 1;
const NETBOXSYNC_EXIT_CONFIG = 2;

/** @return array{force: bool, check: bool, json: bool, help: bool, config: ?string} */
function netboxSyncParseArguments(array $argv): array {
    $options = [
        'force' => false,
        'check' => false,
        'json' => false,
        'help' => false,
        'config' => null
    ];

    $count = count($argv);
    for ($index = 1; $index < $count; $index++) {
        $argument = (string) $argv[$index];

        if ($argument === '--force') {
            $options['force'] = true;
            continue;
        }

        if ($argument === '--check') {
            $options['check'] = true;
            continue;
        }

        if ($argument === '--json') {
            $options['json'] = true;
            continue;
        }

        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }

        if ($argument === '--frontend-config' || $argument === '--config') {
            $index++;
            if ($index >= $count || trim((string) $argv[$index]) === '') {
                throw new InvalidArgumentException($argument.' requires a file path.');
            }

            netboxSyncSetConfigOption($options, (string) $argv[$index]);
            continue;
        }

        $matched = false;
        foreach (['--frontend-config=', '--config='] as $prefix) {
            if (strncmp($argument, $prefix, strlen($prefix)) !== 0) {
                continue;
            }

            $value = substr($argument, strlen($prefix));
            if (trim($value) === '') {
                throw new InvalidArgumentException(substr($prefix, 0, -1).' requires a file path.');
            }

            netboxSyncSetConfigOption($options, $value);
            $matched = true;
            break;
        }

        if ($matched) {
            continue;
        }

        throw new InvalidArgumentException('Unknown argument: '.$argument);
    }

    return $options;
}

function netboxSyncSetConfigOption(array &$options, string $value): void {
    $value = trim($value);

    if ($options['config'] !== null && $options['config'] !== $value) {
        throw new InvalidArgumentException('The frontend configuration path was specified more than once.');
    }

    $options['config'] = $value;
}

function netboxSyncUsage(): string {
    return implode("\n", [
        'Usage: php bin/netboxsync.php [options]',
        '',
        'Options:',
        '  --frontend-config=PATH  Zabbix frontend zabbix.conf.php path.',
        '  --config=PATH           Alias for --frontend-config.',
        '  --force                 Ignore per-sync scheduling intervals.',
        '  --check                 Test config and Zabbix API access; do not sync.',
        '  --json                  Emit a JSON result instead of concise text.',
        '  -h, --help              Show this help.',
        '',
        'The config path can also be set with ZABBIX_WEB_CONFIG',
        '(or the legacy ZABBIX_FRONTEND_CONFIG variable).',
        'Exit codes: 0 success, 1 sync failure, 2 usage/configuration failure.'
    ])."\n";
}

function netboxSyncWriteJson(array $payload): void {
    $json = json_encode(
        $payload,
        JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        $json = '{"ok":false,"error":"Failed to encode the CLI result."}';
    }

    fwrite(STDOUT, $json."\n");
}

function netboxSyncWriteSummary(array $summary): void {
    $ok = !empty($summary['ok']);
    $line = sprintf(
        'NetBox sync %s: %d/%d hosts, %d mappings, %d created, %d updated, %d deleted, %d unchanged, %d skipped, %d errors (%.3fs)',
        $ok ? 'OK' : 'FAILED',
        (int) ($summary['hosts_processed'] ?? 0),
        (int) ($summary['hosts_total'] ?? 0),
        (int) ($summary['mappings_run'] ?? 0),
        (int) ($summary['created'] ?? 0),
        (int) ($summary['updated'] ?? 0),
        (int) ($summary['deleted'] ?? 0),
        (int) ($summary['unchanged'] ?? 0),
        (int) ($summary['skipped'] ?? 0),
        (int) ($summary['errors'] ?? 0),
        (float) ($summary['elapsed_seconds'] ?? 0)
    );

    $stream = $ok ? STDOUT : STDERR;
    fwrite($stream, $line."\n");

    if (!$ok) {
        foreach (array_reverse((array) ($summary['messages'] ?? [])) as $message) {
            if (is_array($message) && ($message['level'] ?? '') === 'error') {
                fwrite($stream, 'Error: '.trim((string) ($message['message'] ?? 'Unknown sync error.'))."\n");
                break;
            }
        }
    }
}

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    echo "This runner can only be executed by PHP CLI.\n";
    exit(NETBOXSYNC_EXIT_CONFIG);
}

$json_output = in_array('--json', $argv, true);
$operation_started = false;

try {
    $options = netboxSyncParseArguments($argv);
    $json_output = $options['json'];

    if ($options['help']) {
        fwrite(STDOUT, netboxSyncUsage());
        exit(NETBOXSYNC_EXIT_OK);
    }

    require_once dirname(__DIR__).'/lib/bootstrap.php';

    $database = DbConnector::connect($options['config']);
    $config = StandaloneConfig::load($database);
    unset($database);

    if (!$options['check'] && empty($config['runner']['enabled'])) {
        throw new RuntimeException(
            'Unattended synchronization is disabled by the Runner enabled module setting.'
        );
    }

    $zabbix = ZabbixApiClient::fromConfig($config);

    if ($options['check']) {
        $operation_started = true;
        $hosts = $zabbix->getAllHosts(1);
        $returned = count($hosts);

        if ($json_output) {
            netboxSyncWriteJson([
                'ok' => true,
                'check' => true,
                'message' => 'Zabbix API authentication succeeded.',
                'hosts_returned' => $returned
            ]);
        }
        else {
            fwrite(
                STDOUT,
                'Zabbix API check OK: authenticated host.get succeeded ('.$returned." host returned).\n"
            );
        }

        exit(NETBOXSYNC_EXIT_OK);
    }

    $operation_started = true;
    $summary = SyncEngine::run($config, [
        'source' => 'cli',
        'force' => $options['force'],
        'zabbix_client' => $zabbix
    ]);

    $ok = !empty($summary['ok']);
    if ($json_output) {
        netboxSyncWriteJson([
            'ok' => $ok,
            'summary' => $summary
        ]);
    }
    else {
        netboxSyncWriteSummary($summary);
    }

    exit($ok ? NETBOXSYNC_EXIT_OK : NETBOXSYNC_EXIT_FAILED);
}
catch (InvalidArgumentException $e) {
    if ($json_output) {
        netboxSyncWriteJson([
            'ok' => false,
            'error' => $e->getMessage(),
            'exit_code' => NETBOXSYNC_EXIT_CONFIG
        ]);
    }
    else {
        fwrite(STDERR, 'Argument error: '.$e->getMessage()."\n\n".netboxSyncUsage());
    }

    exit(NETBOXSYNC_EXIT_CONFIG);
}
catch (Throwable $e) {
    $exit_code = $operation_started ? NETBOXSYNC_EXIT_FAILED : NETBOXSYNC_EXIT_CONFIG;

    if ($json_output) {
        netboxSyncWriteJson([
            'ok' => false,
            'error' => $e->getMessage(),
            'exit_code' => $exit_code
        ]);
    }
    else {
        fwrite(STDERR, 'NetBox sync error: '.$e->getMessage()."\n");
    }

    exit($exit_code);
}
