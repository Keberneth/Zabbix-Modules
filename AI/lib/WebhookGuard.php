<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

final class WebhookRequestException extends RuntimeException {
    private int $http_status;

    public function __construct(string $message, int $http_status) {
        parent::__construct($message);
        $this->http_status = $http_status;
    }

    public function httpStatus(): int {
        return $this->http_status;
    }
}

/**
 * Authenticated delivery admission and conservative at-most-once processing.
 * Fixed shards keep the number of files bounded. OS locks survive no worker:
 * a crashed preflight can be retried, but a delivery which may already have
 * called a provider or posted a comment remains uncertain until its TTL ends.
 * All frontend nodes serving this endpoint must use the same locking-capable
 * state directory, or route webhook traffic to one frontend.
 */
final class WebhookGuard {
    public const HARD_BODY_LIMIT = 1048576;
    private const MAX_SHARD_RECORDS = 1000;
    private const MAX_STATE_BYTES = 1048576;

    private string $key;
    private string $state_file;
    private array $records;
    private $shard_lock;
    private $slot_lock = null;
    private bool $external_work_started = false;
    private bool $finished = false;
    private ?array $duplicate = null;
    private int $retention_seconds = 86400;

    private function __construct(string $key, string $state_file, array $records, $lock) {
        $this->key = $key;
        $this->state_file = $state_file;
        $this->records = $records;
        $this->shard_lock = $lock;
    }

    public static function bodyLimit(array $config): int {
        return max(4096, min(self::HARD_BODY_LIMIT, (int) ($config['webhook']['max_body_bytes'] ?? 65536)));
    }

    /** Read only limit + one byte, even when Content-Length is absent/untrue. */
    public static function readBody($stream, int $limit, ?string $content_length = null): string {
        $limit = max(1, min(self::HARD_BODY_LIMIT, $limit));
        if ($content_length !== null && ctype_digit($content_length) && (float) $content_length > $limit) {
            throw new WebhookRequestException('Webhook request body is too large.', 413);
        }
        $body = stream_get_contents($stream, $limit + 1);
        if (!is_string($body)) {
            throw new WebhookRequestException('Could not read webhook request body.', 400);
        }
        if (strlen($body) > $limit) {
            throw new WebhookRequestException('Webhook request body is too large.', 413);
        }
        return $body;
    }

    public static function claim(array $config, array $payload): self {
        $key = self::deliveryKey($config, $payload);
        $directory = self::directory($config);
        $stem = $directory.'/deliveries_'.substr($key, 0, 2);
        $lock = self::lock($stem.'.lock');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new WebhookRequestException('A webhook delivery is already processing; retry later.', 409);
        }

        $guard = null;
        try {
            $records = self::readState($stem.'.json');
            $now = time();
            foreach ($records as $id => $record) {
                if (!is_array($record) || !isset($record['expires_at'], $record['status'])
                        || !in_array($record['status'], ['processing', 'uncertain', 'completed'], true)) {
                    throw new WebhookRequestException('Webhook delivery state is invalid; administrator review is required.', 503);
                }
                if ((int) $record['expires_at'] <= $now) {
                    unset($records[$id]);
                }
            }

            $guard = new self($key, $stem.'.json', $records, $lock);
            $guard->retention_seconds = max(300, min(604800, (int) ($config['webhook']['dedup_ttl_seconds'] ?? 86400)));
            if (isset($records[$key])) {
                if ($records[$key]['status'] === 'completed') {
                    $guard->duplicate = [
                        'ok' => true, 'duplicate' => true, 'result' => 'delivery already completed',
                        'posted_chunks' => (int) ($records[$key]['posted_chunks'] ?? 0), 'reply' => ''
                    ];
                    $guard->finished = true;
                    return $guard;
                }
                if ($records[$key]['status'] === 'uncertain') {
                    $guard->finished = true;
                    throw new WebhookRequestException(
                        'Previous webhook delivery may have called AI or posted a comment. Check the event and audit log before retrying after the deduplication window.',
                        409
                    );
                }
                // An abandoned processing record cannot have begun external
                // work: beginExternalWork() persists uncertainty first.
            }
            if (!isset($records[$key]) && count($records) >= self::MAX_SHARD_RECORDS) {
                throw new WebhookRequestException('Webhook delivery capacity reached; retry later.', 429);
            }

            $maximum = max(1, min(20, (int) ($config['webhook']['max_concurrent_requests'] ?? 2)));
            for ($slot = 0; $slot < $maximum; ++$slot) {
                $slot_lock = self::lock($directory.'/slot_'.$slot.'.lock');
                if (flock($slot_lock, LOCK_EX | LOCK_NB)) {
                    $guard->slot_lock = $slot_lock;
                    break;
                }
                fclose($slot_lock);
            }
            if ($guard->slot_lock === null) {
                throw new WebhookRequestException('Webhook concurrency limit reached; retry later.', 429);
            }
            self::admitRate($directory, max(1, min(600, (int) ($config['webhook']['rate_limit_per_minute'] ?? 30))), $now);
            $guard->records[$key] = [
                'status' => 'processing',
                'expires_at' => $now + $guard->retention_seconds
            ];
            $guard->persist();
            return $guard;
        }
        catch (\Throwable $e) {
            if ($guard !== null) {
                // Claim never returned, so no new work has begun. Do not erase
                // any prior uncertain record when admission failed.
                $guard->finished = true;
                $guard->release();
            }
            else {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            throw $e;
        }
    }

    public function duplicateResult(): ?array {
        return $this->duplicate;
    }

    public function beginExternalWork(): void {
        $this->records[$this->key]['status'] = 'uncertain';
        $this->records[$this->key]['expires_at'] = time() + $this->retention_seconds;
        // Set the in-memory flag first: if persistence is interrupted, never
        // remove a record that might have been durably marked uncertain.
        $this->external_work_started = true;
        $this->persist();
    }

    public function complete(int $posted_chunks): void {
        $this->records[$this->key]['status'] = 'completed';
        $this->records[$this->key]['expires_at'] = time() + $this->retention_seconds;
        $this->records[$this->key]['posted_chunks'] = max(0, $posted_chunks);
        $this->persist();
        $this->finished = true;
        $this->release();
    }

    public function abort(): void {
        try {
            if (!$this->finished && !$this->external_work_started) {
                unset($this->records[$this->key]);
                $this->persist();
            }
        }
        finally {
            $this->finished = true;
            $this->release();
        }
    }

    public function __destruct() {
        // A crash leaves either a reclaimable preflight or an uncertain record.
        $this->release();
    }

    public static function deliveryKey(array $config, array $payload): string {
        $eventid = trim((string) ($payload['eventid'] ?? ''));
        if (!preg_match('/\A[1-9][0-9]{0,19}\z/', $eventid)) {
            throw new WebhookRequestException('A valid event ID is required for webhook delivery.', 400);
        }
        $type = (string) ($payload['delivery_type'] ?? '');
        if (!in_array($type, ['problem', 'recovery', 'update'], true)) {
            $type = (string) ($payload['event_update_status'] ?? '') === '1' ? 'update'
                : ((string) ($payload['event_value'] ?? '1') === '0' ? 'recovery' : 'problem');
        }
        $delivery_id = trim((string) ($payload['delivery_id'] ?? ''));
        if ($delivery_id === '') {
            if ($type === 'recovery') {
                $delivery_id = (string) ($payload['recovery_eventid'] ?? '');
            }
            elseif ($type === 'update') {
                // Zabbix has no universal alert/update ID macro across supported
                // versions. Date/time, user, action and message distinguish
                // updates; sender retries carry the same values.
                $delivery_id = hash('sha256', json_encode([
                    $payload['event_update_timestamp'] ?? '', $payload['event_update_user'] ?? '',
                    $payload['event_update_action'] ?? '', $payload['event_update_message'] ?? ''
                ], JSON_THROW_ON_ERROR));
                if (trim(implode('', [
                    $payload['event_update_timestamp'] ?? '', $payload['event_update_user'] ?? '',
                    $payload['event_update_action'] ?? '', $payload['event_update_message'] ?? ''
                ])) === '') {
                    throw new WebhookRequestException('Update deliveries require an update identity or delivery_id.', 400);
                }
            }
        }

        return hash('sha256', json_encode([
            'v' => 1, 'zabbix' => (string) ($config['zabbix_api']['url'] ?? ''),
            'source' => (string) ($payload['event_source'] ?? '0'),
            'object' => (string) ($payload['event_object'] ?? '0'),
            'eventid' => $eventid, 'type' => $type, 'delivery_id' => $delivery_id
        ], JSON_THROW_ON_ERROR));
    }

    private static function directory(array $config): string {
        $base = rtrim(Util::cleanPath($config['security']['state_path'] ?? '/tmp/zabbix-ai-module/state'), '/\\');
        if ($base === '') {
            throw new WebhookRequestException('Webhook state directory is not configured.', 503);
        }
        $directory = $base.'/webhook';
        Filesystem::ensureDir($directory, 0700);
        if (DIRECTORY_SEPARATOR === '/' && ((int) fileperms($directory) & 0022) !== 0) {
            throw new WebhookRequestException('Webhook state directory must not be group/world writable.', 503);
        }
        return $directory;
    }

    private static function lock(string $path) {
        if (is_link($path)) {
            throw new WebhookRequestException('Webhook lock file is invalid.', 503);
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new WebhookRequestException('Webhook state directory is not writable.', 503);
        }
        @chmod($path, 0600);
        return $handle;
    }

    private static function readState(string $path): array {
        if (!file_exists($path)) {
            return [];
        }
        if (is_link($path)) {
            throw new WebhookRequestException('Webhook state file is invalid.', 503);
        }
        $raw = @file_get_contents($path, false, null, 0, self::MAX_STATE_BYTES + 1);
        if (!is_string($raw) || strlen($raw) > self::MAX_STATE_BYTES) {
            throw new WebhookRequestException('Webhook state could not be read safely.', 503);
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        }
        catch (\Throwable $e) {
            throw new WebhookRequestException('Webhook state is corrupt; administrator review is required.', 503);
        }
        if (!is_array($decoded)) {
            throw new WebhookRequestException('Webhook state is invalid; administrator review is required.', 503);
        }
        return $decoded;
    }

    private static function admitRate(string $directory, int $maximum, int $now): void {
        $lock = self::lock($directory.'/rate.lock');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new WebhookRequestException('Webhook admission is busy; retry later.', 429);
            }
            $timestamps = self::readState($directory.'/rate.json');
            $timestamps = array_values(array_filter($timestamps, static function($time) use ($now) {
                return is_int($time) && $time > $now - 60;
            }));
            if (count($timestamps) >= $maximum) {
                throw new WebhookRequestException('Webhook rate limit reached; retry later.', 429);
            }
            $timestamps[] = $now;
            Filesystem::writeJsonAtomic($directory.'/rate.json', $timestamps);
        }
        finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function persist(): void {
        Filesystem::writeJsonAtomic($this->state_file, $this->records);
    }

    private function release(): void {
        foreach (['slot_lock', 'shard_lock'] as $property) {
            if (is_resource($this->$property)) {
                flock($this->$property, LOCK_UN);
                fclose($this->$property);
                $this->$property = null;
            }
        }
    }
}
