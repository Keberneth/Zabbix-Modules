#!/usr/bin/env php
<?php declare(strict_types = 0);

require_once dirname(__DIR__).'/lib/bootstrap.php';

use Modules\Healthcheck\Lib\Config;
use Modules\Healthcheck\Lib\DbConnector;
use Modules\Healthcheck\Lib\Runner;
use Modules\Healthcheck\Lib\Util;

$options = getopt('', ['check-id::', 'force', 'json', 'help']);

if (array_key_exists('help', $options)) {
    fwrite(STDOUT, <<<TXT
Usage:
  /usr/bin/php healthcheck-runner.php [--check-id=<id>] [--force] [--json]

Options:
  --check-id   Run only one configured check by its internal ID.
  --force      Ignore the per-check interval and execute immediately.
  --json       Print a JSON summary.
  --help       Show this help.

TXT
    );
    exit(0);
}

if (function_exists('set_time_limit')) {
    @set_time_limit(300);
}

$check_id = Util::cleanString($options['check-id'] ?? '', 128);
$force = array_key_exists('force', $options);
$json = array_key_exists('json', $options);

try {
    $pdo = DbConnector::connect();
    $config = Config::get($pdo);
    $result = Runner::runDueChecks($config, $pdo, $check_id, $force);

    if ($json) {
        $encoded = json_encode(
            $result,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($encoded === false) {
            $encoded = '{"ok":false,"message":"Failed to encode result."}';
        }

        fwrite(STDOUT, $encoded.PHP_EOL);
    }
    else {
        fwrite(STDOUT, ($result['message'] ?? 'Healthcheck runner finished.').PHP_EOL);

        foreach (($result['results'] ?? []) as $row) {
            fwrite(
                STDOUT,
                '- '.($row['check_name'] ?? $row['checkid'] ?? 'unknown')
                .': '.Util::statusLabel((int) ($row['status'] ?? 0))
                .' — '.($row['summary'] ?? '')
                .PHP_EOL
            );
        }
    }

    exit(!empty($result['ok']) ? 0 : 1);
}
catch (Throwable $e) {
    error_log('Healthcheck runner: '.$e->getMessage());

    $message = 'Healthcheck runner failed: '.$e->getMessage();

    if ($json) {
        $encoded = json_encode([
            'ok' => false,
            'message' => $message
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            $encoded = '{"ok":false,"message":"Healthcheck runner failed."}';
        }

        fwrite(STDOUT, $encoded.PHP_EOL);
    }
    else {
        fwrite(STDERR, $message.PHP_EOL);
    }

    exit(2);
}
