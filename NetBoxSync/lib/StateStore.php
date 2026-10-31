<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Lib;

use RuntimeException;

class StateStore {

    private string $state_path;
    private string $log_path;
    private array $timestamps = [];
    private bool $timestamps_loaded = false;
    /** @var resource|null */
    private $lock_handle = null;
    private string $timestamps_file;
    private string $summary_file;
    private string $lock_file;
    private string $progress_file;

    public function __construct(string $state_path, string $log_path) {
        $this->state_path = Util::cleanPath($state_path);
        $this->log_path = Util::cleanPath($log_path);
        $this->timestamps_file = $this->state_path.'/timestamps.json';
        $this->summary_file = $this->state_path.'/last_summary.json';
        $this->lock_file = $this->state_path.'/runner.lock';
        $this->progress_file = $this->state_path.'/progress.json';
    }

    public function ensureDirectories(): void {
        if ($this->state_path === '' || $this->log_path === '') {
            throw new RuntimeException('State path or log path is empty.');
        }

        foreach ([$this->state_path, $this->log_path] as $path) {
            if (is_dir($path)) {
                if (!is_writable($path)) {
                    throw new RuntimeException(Util::buildDirectoryHint($path, 'is not writable'));
                }
                continue;
            }

            if (!@mkdir($path, 0770, true) && !is_dir($path)) {
                throw new RuntimeException(Util::buildDirectoryHint($path, 'could not be created'));
            }
        }
    }

    public function acquireLock(string $run_id = ''): void {
        if ($this->ownsLock()) {
            throw new RuntimeException('This state store already owns the sync lock.');
        }
        $this->ensureDirectories();

        $handle = @fopen($this->lock_file, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Failed to open lock file: '.$this->lock_file);
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('A NetBox sync run is already in progress.');
        }

        $metadata = json_encode([
            'run_id' => $run_id,
            'pid' => getmypid(),
            'started_at' => gmdate('c')
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!@ftruncate($handle, 0) || @fwrite($handle, $metadata) !== strlen($metadata) || !@fflush($handle)) {
            @flock($handle, LOCK_UN);
            fclose($handle);
            throw new RuntimeException('Unable to write sync lock metadata.');
        }

        $this->lock_handle = $handle;
    }

    public function ownsLock(): bool {
        return is_resource($this->lock_handle);
    }

    public function releaseLock(): void {
        if ($this->lock_handle !== null) {
            @ftruncate($this->lock_handle, 0);
            @flock($this->lock_handle, LOCK_UN);
            @fclose($this->lock_handle);
            $this->lock_handle = null;
        }
    }

    public function __destruct() {
        $this->releaseLock();
    }

    public function isDue(string $hostid, string $mapping_id, int $interval_seconds): bool {
        if ($interval_seconds <= 0) {
            return true;
        }

        $this->loadTimestamps();
        $last = (int) ($this->timestamps[$hostid][$mapping_id]['last_run'] ?? 0);

        return ($last + $interval_seconds) <= time();
    }

    public function touch(string $hostid, string $mapping_id, string $status = 'ok', array $meta = []): void {
        $this->loadTimestamps();

        if (!isset($this->timestamps[$hostid]) || !is_array($this->timestamps[$hostid])) {
            $this->timestamps[$hostid] = [];
        }

        $this->timestamps[$hostid][$mapping_id] = [
            'last_run' => time(),
            'status' => $status,
            'meta' => $meta
        ];
    }

    public function saveTimestamps(): void {
        $this->writeState($this->timestamps_file, $this->timestamps);
    }

    public function getLastSummary(): array {
        if (!is_file($this->summary_file)) {
            return [];
        }

        $content = @file_get_contents($this->summary_file);

        return is_string($content) ? (Util::decodeJson($content, []) ?: []) : [];
    }

    public function saveLastSummary(array $summary): void {
        $this->writeState($this->summary_file, $summary);
    }

    /** Small atomic snapshots never expose partially written JSON to polling. */
    public function saveProgress(array $progress): void {
        $progress = $this->validateProgress($progress);
        if ($progress === null) {
            throw new RuntimeException('Invalid sync progress snapshot.');
        }
        $this->writeState($this->progress_file, $progress, 16384);
    }

    public function getProgress(string $run_id = ''): ?array {
        if ($run_id !== '' && !preg_match('/\A[0-9a-f]{32}\z/i', $run_id)) {
            throw new \InvalidArgumentException('Invalid sync run identifier.');
        }
        $progress = $this->readProgressSnapshot();
        if ($progress === null || ($run_id !== '' && strtolower($run_id) !== $progress['run_id'])) {
            return null;
        }
        if ($progress['running'] && !$this->runOwnsLock($progress['run_id'])) {
            // Completion may have been published between our first read and
            // the lock probe. Prefer that terminal record over a false crash.
            $latest = $this->readProgressSnapshot();
            if ($latest === null || $latest['run_id'] !== $progress['run_id']) {
                return null;
            }
            if (!$latest['running']) {
                return $latest;
            }
            $progress = $latest;
            // A crashed process cannot publish a terminal snapshot. Report the
            // interruption without changing history or claiming completion.
            $progress['running'] = false;
            $progress['phase'] = 'interrupted';
        }
        return $progress;
    }

    private function readProgressSnapshot(): ?array {
        if (!is_file($this->progress_file)) {
            return null;
        }
        $content = @file_get_contents($this->progress_file, false, null, 0, 16385);
        if (!is_string($content) || strlen($content) > 16384) {
            return null;
        }
        $decoded = json_decode($content, true, 16);
        return is_array($decoded) ? $this->validateProgress($decoded) : null;
    }

    protected function runOwnsLock(string $run_id): bool {
        $handle = @fopen($this->lock_file, 'r');
        if ($handle === false) {
            if (!is_file($this->lock_file)) {
                return false;
            }
            throw new RuntimeException('Unable to read the sync lock state.');
        }
        try {
            if (@flock($handle, LOCK_SH | LOCK_NB)) {
                flock($handle, LOCK_UN);
                return false;
            }
            $content = stream_get_contents($handle, 1024);
            $owner = is_string($content) ? json_decode($content, true) : null;
            if (!is_array($owner) || !isset($owner['run_id']) || !is_string($owner['run_id'])) {
                throw new RuntimeException('Unable to determine which sync run owns the lock.');
            }
            return hash_equals($owner['run_id'], $run_id);
        }
        finally {
            fclose($handle);
        }
    }

    private function validateProgress(array $progress): ?array {
        $phases = ['preparing', 'fetching', 'prefetching', 'syncing', 'finalizing', 'completed', 'failed', 'interrupted'];
        if (!is_string($progress['run_id'] ?? null) || !preg_match('/\A[0-9a-f]{32}\z/', $progress['run_id'])
                || !in_array($progress['phase'] ?? null, $phases, true)
                || !is_bool($progress['running'] ?? null) || !is_bool($progress['total_known'] ?? null)
                || !array_key_exists('percent', $progress)
                || ($progress['percent'] !== null && (!is_int($progress['percent']) || $progress['percent'] < 0 || $progress['percent'] > 100))) {
            return null;
        }
        foreach (['hosts_completed', 'hosts_total', 'errors'] as $key) {
            if (!is_int($progress[$key] ?? null) || $progress[$key] < 0) {
                return null;
            }
        }
        if (!is_string($progress['source'] ?? null) || !preg_match('/\A[a-z0-9_-]{1,32}\z/', $progress['source'])) {
            return null;
        }
        if ($progress['hosts_completed'] > $progress['hosts_total']
                || (!$progress['total_known'] && ($progress['percent'] !== null || $progress['hosts_total'] !== 0))
                || ($progress['running'] && ($progress['percent'] === 100 || in_array($progress['phase'], ['completed', 'failed', 'interrupted'], true)))
                || (!$progress['running'] && !in_array($progress['phase'], ['completed', 'failed', 'interrupted'], true))) {
            return null;
        }
        if ($progress['total_known']) {
            $ratio = $progress['hosts_total'] > 0 ? (int) floor(100 * $progress['hosts_completed'] / $progress['hosts_total']) : 0;
            $terminal = in_array($progress['phase'], ['completed', 'failed'], true);
            $expected = $terminal && $progress['hosts_completed'] === $progress['hosts_total'] ? 100 : min(99, $ratio);
            if ($progress['percent'] !== $expected) {
                return null;
            }
        }
        if ($progress['phase'] === 'completed' && (!$progress['total_known'] || $progress['percent'] !== 100 || $progress['errors'] !== 0)) {
            return null;
        }
        foreach (['started_at', 'updated_at'] as $key) {
            if (!is_string($progress[$key] ?? null) || strlen($progress[$key]) > 40 || strtotime($progress[$key]) === false) {
                return null;
            }
        }
        if (!array_key_exists('finished_at', $progress)
                || ($progress['finished_at'] !== null && (!is_string($progress['finished_at']) || strlen($progress['finished_at']) > 40 || strtotime($progress['finished_at']) === false))) {
            return null;
        }
        // Return only the defined, non-secret progress fields.
        return array_intersect_key($progress, array_flip([
            'run_id', 'phase', 'running', 'percent', 'hosts_completed', 'hosts_total',
            'total_known', 'errors', 'source', 'started_at', 'updated_at', 'finished_at'
        ]));
    }

    private function writeState(string $path, array $value, int $max_bytes = 0): void {
        if (!$this->ownsLock()) {
            throw new RuntimeException('Only the active sync run may write state.');
        }
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($max_bytes > 0 && strlen($encoded) > $max_bytes) {
            throw new RuntimeException('Sync progress snapshot exceeds the size limit.');
        }
        $temporary = @tempnam($this->state_path, '.nbs-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create an atomic sync state snapshot.');
        }
        try {
            if (!@chmod($temporary, 0660) || @file_put_contents($temporary, $encoded) !== strlen($encoded) || !@rename($temporary, $path)) {
                throw new RuntimeException('Unable to save the sync state snapshot.');
            }
        }
        finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function log(string $level, string $message, array $context = []): void {
        $this->ensureDirectories();

        $entry = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'message' => $message
        ];

        if ($context !== []) {
            $entry['context'] = $context;
        }

        $file = $this->log_path.'/'.gmdate('Y-m-d').'.jsonl';
        @file_put_contents(
            $file,
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
            FILE_APPEND
        );
    }

    private function loadTimestamps(): void {
        if ($this->timestamps_loaded) {
            return;
        }

        $this->timestamps_loaded = true;

        if (!is_file($this->timestamps_file)) {
            $this->timestamps = [];
            return;
        }

        $content = @file_get_contents($this->timestamps_file);
        $decoded = is_string($content) ? Util::decodeJson($content, []) : [];

        $this->timestamps = is_array($decoded) ? $decoded : [];
    }
}
