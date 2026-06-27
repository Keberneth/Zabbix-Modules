<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Lib;

use CControllerResponseData;

trait JsonResponse {
    protected function jsonResponse(array $payload, int $http_status = 200): void {
        if ($http_status !== 200) {
            http_response_code($http_status);
        }
        header('Content-Type: application/json; charset=UTF-8');

        $this->setResponse(
            (new CControllerResponseData([
                'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]))->disableView()
        );
    }

    protected function requestJson(): array {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Read a JSON object that the UI sends inside a single multipart/form-data
     * field (so the request still carries the framework CSRF token for the
     * normal form-POST CSRF check).
     */
    protected function postJsonField(string $name): array {
        $value = $_POST[$name] ?? '';
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function inputString(string $name, string $default = ''): string {
        $value = $_REQUEST[$name] ?? $default;
        if (is_array($value)) {
            return $default;
        }
        return trim((string) $value);
    }

    protected function inputInt(string $name, int $default = 0): int {
        $value = $_REQUEST[$name] ?? $default;
        if (is_array($value) || !is_numeric($value)) {
            return $default;
        }
        return (int) $value;
    }
}
