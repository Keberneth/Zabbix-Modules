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

    public function __construct(string $state_path, string $log_path) {
        $this->state_path = Util::cleanPath($state_path);
        $this->log_path = Util::cleanPath($log_path);
        $this->timestamps_file = $this->state_path.'/timestamps.json';
        $this->summary_file = $this->state_path.'/last_summary.json';
        $this->lock_file = $this->state_path.'/runner.lock';
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

    public function acquireLock(): void {
        $this->ensureDirectories();

        $handle = @fopen($this->lock_file, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Failed to open lock file: '.$this->lock_file);
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('A NetBox sync run is already in progress.');
        }

        ftruncate($handle, 0);
        fwrite($handle, json_encode([
            'pid' => getmypid(),
            'started_at' => gmdate('c')
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($handle);

        $this->lock_handle = $handle;
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
        $this->ensureDirectories();
        file_put_contents(
            $this->timestamps_file,
            json_encode($this->timestamps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    public function getLastSummary(): array {
        if (!is_file($this->summary_file)) {
            return [];
        }

        $content = @file_get_contents($this->summary_file);

        return is_string($content) ? (Util::decodeJson($content, []) ?: []) : [];
    }

    public function saveLastSummary(array $summary): void {
        $this->ensureDirectories();
        file_put_contents(
            $this->summary_file,
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
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
