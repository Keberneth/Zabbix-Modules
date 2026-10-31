<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;
use Modules\TriggerCorrelation\Lib\RuleTransfer;
use Modules\TriggerCorrelation\Lib\ZabbixApiClient;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';
require_once dirname(__DIR__).'/lib/RuleTransfer.php';
require_once dirname(__DIR__).'/lib/ZabbixApiClient.php';

class RulesImport extends CController {
    use JsonResponse;

    // Both preview and apply use a CSRF-protected POST; only apply writes.
    protected function checkInput(): bool { return true; }
    protected function checkPermissions(): bool { return $this->getUserType() >= USER_TYPE_SUPER_ADMIN; }

    protected function doAction(): void {
        try {
            $op = $_POST['op'] ?? 'preview';
            $conflict = $_POST['conflict'] ?? 'skip';
            $raw = $_POST['document'] ?? '';
            if (!is_string($op) || !in_array($op, ['preview', 'apply'], true)) {
                throw new \InvalidArgumentException('Choose preview or apply.');
            }
            if (!is_string($conflict) || !is_string($raw) || strlen($raw) > 2097152) {
                throw new \InvalidArgumentException('Choose a rule export smaller than 2 MB.');
            }
            $document = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($document)) {
                throw new \InvalidArgumentException('The import document must be a JSON object.');
            }
            $store = new CorrelationStore();
            $prepare = static function () use ($store, $document, $conflict, $op): array {
                $config = $store->load();
                $api = ZabbixApiClient::fromFrontend((array) ($config['settings'] ?? []));
                if ($api === null) {
                    throw new \RuntimeException('A frontend session is required to resolve imported references.');
                }
                $result = (new RuleTransfer([$api, 'call']))->prepare($document, $config, $conflict);
                if ($op === 'apply' && $result['preview']['errors'] === []) {
                    foreach (['rules', 'severity_rules'] as $key) {
                        $config[$key] = array_merge((array) ($config[$key] ?? []), $result[$key]);
                    }
                    if ($result['rules'] !== [] || $result['severity_rules'] !== []) {
                        $store->save($config);
                    }
                }
                return $result;
            };
            // Re-resolve references and conflicts against the locked current
            // configuration on apply. No partial batch or stale preview writes.
            $result = $op === 'apply'
                ? $store->withSeverityEvaluationLock(static fn(): array => CorrelationStore::transaction($prepare))
                : $prepare();
            $valid = $result['preview']['errors'] === [];
            $this->jsonResponse([
                'ok' => $valid,
                'preview' => $result['preview'],
                'error' => $valid ? '' : 'Resolve the import errors before applying. No rules were imported.',
                'notes' => $op === 'apply' && $valid
                    ? ['Imported '.$result['preview']['rules_count'].' correlation and '.$result['preview']['severity_rules_count'].' escalation rule(s), disabled. Review, enable and save each rule to activate it.'] : []
            ] + ($op === 'apply' && $valid ? $store->publicConfig() : []));
        }
        catch (\Throwable $e) {
            $this->jsonResponse(['ok' => false, 'error' => 'Could not import rules: '.$e->getMessage()], 400);
        }
    }
}
