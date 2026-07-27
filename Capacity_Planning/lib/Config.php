<?php

declare(strict_types=1);

namespace Modules\CapacityPlanning\Lib;

use API;
use RuntimeException;

/**
 * Global Capacity Planning settings.
 *
 * Only small operational settings belong in module.config. Time-series data is
 * stored separately by SeriesCache and is never written to the Zabbix module
 * table.
 */
final class Config {
	public const MODULE_ID = 'capacity_planning';
	public const DEFAULT_CACHE_TTL_SECONDS = 1800;
	public const ALLOWED_CACHE_TTLS = [0, 900, 1800, 3600];

	private const DEFAULT_CACHE_MAX_BYTES = 1073741824; // 1 GiB.
	private const MIN_CACHE_MAX_BYTES = 67108864; // 64 MiB.
	private const MAX_CACHE_MAX_BYTES = 21474836480; // 20 GiB.
	private const DEFAULT_CACHE_MAX_IDLE_SECONDS = 30 * 86400;

	public static function defaults(): array {
		return [
			'cache' => [
				'enabled' => true,
				'ttl_seconds' => self::DEFAULT_CACHE_TTL_SECONDS
			]
		];
	}

	/**
	 * Return the complete module configuration with cache defaults applied.
	 * Unknown keys are retained so this component can coexist with later module
	 * settings without overwriting them.
	 */
	public static function get(): array {
		$record = self::getModuleRecord();

		return self::normalize($record !== null ? self::decode($record['config'] ?? []) : []);
	}

	/** @return array{enabled: bool, ttl_seconds: int} */
	public static function cacheSettings(): array {
		$config = self::get();

		return $config['cache'];
	}

	/**
	 * Normalize untrusted or legacy module configuration while retaining unknown
	 * keys. This method is public to make schema behavior independently testable.
	 */
	public static function normalize(array $config): array {
		$defaults = self::defaults();
		$cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];

		$enabled = self::toBool($cache['enabled'] ?? $defaults['cache']['enabled']);
		$ttl = is_numeric($cache['ttl_seconds'] ?? null)
			? (int) $cache['ttl_seconds']
			: self::DEFAULT_CACHE_TTL_SECONDS;
		if (!in_array($ttl, self::ALLOWED_CACHE_TTLS, true)) {
			$ttl = self::DEFAULT_CACHE_TTL_SECONDS;
		}

		$config['cache'] = array_replace($cache, [
			'enabled' => $enabled,
			'ttl_seconds' => $ttl
		]);

		return $config;
	}

	/**
	 * Persist the two supported cache settings and verify the database readback.
	 * Callers must enforce Super admin access; the save controller does so.
	 *
	 * @return array{enabled: bool, ttl_seconds: int}
	 */
	public static function saveCacheSettings(bool $enabled, int $ttl_seconds): array {
		if (!in_array($ttl_seconds, self::ALLOWED_CACHE_TTLS, true)) {
			throw new \InvalidArgumentException('Invalid cache freshness interval.');
		}

		$record = self::getModuleRecord();
		if ($record === null) {
			throw new RuntimeException('Capacity Planning module is not registered in Zabbix.');
		}

		$config = self::normalize(self::decode($record['config'] ?? []));
		$config['cache']['enabled'] = $enabled;
		$config['cache']['ttl_seconds'] = $ttl_seconds;

		if (!class_exists('API')) {
			throw new RuntimeException('Zabbix module API is unavailable.');
		}

		API::Module()->update([[
			'moduleid' => (string) $record['moduleid'],
			'config' => $config
		]]);

		$readback = self::getModuleRecord();
		if ($readback === null) {
			throw new RuntimeException('Capacity Planning settings readback failed.');
		}
		$saved = self::normalize(self::decode($readback['config'] ?? []))['cache'];
		if ($saved['enabled'] !== $enabled || $saved['ttl_seconds'] !== $ttl_seconds) {
			throw new RuntimeException('Capacity Planning settings write verification failed.');
		}

		return $saved;
	}

	/**
	 * Writable cache root. This path is intentionally deployment configuration,
	 * not a browser-editable setting. SeriesCache rejects paths inside the web
	 * document root or this module directory.
	 */
	public static function cacheDirectory(): string {
		$configured = getenv('CAPACITY_PLANNING_CACHE_DIR');
		if ($configured !== false && trim($configured) !== '') {
			return rtrim(trim($configured), "\\/");
		}

		return rtrim(sys_get_temp_dir(), "\\/").DIRECTORY_SEPARATOR.'zabbix-capacity-planning';
	}

	/**
	 * Stable, non-secret namespace for one Zabbix installation. An explicit
	 * namespace is useful when multiple frontend nodes use different DB aliases.
	 */
	public static function installNamespace(): string {
		$explicit = getenv('CAPACITY_PLANNING_CACHE_NAMESPACE');
		if ($explicit !== false && trim($explicit) !== '') {
			$identity = 'explicit:'.trim($explicit);
		}
		else {
			global $DB;
			$db = is_array($DB ?? null) ? $DB : [];
			// An all-empty identity could make two installations sharing /tmp and
			// an OS account collide on the same numeric item IDs. Fail closed until
			// the frontend DB configuration is initialized or an explicit namespace
			// is supplied.
			if (trim((string) ($db['TYPE'] ?? '')) === ''
					|| trim((string) ($db['DATABASE'] ?? '')) === '') {
				return '';
			}
			$identity = json_encode([
				'type' => (string) ($db['TYPE'] ?? ''),
				'server' => (string) ($db['SERVER'] ?? ''),
				'port' => (string) ($db['PORT'] ?? ''),
				'database' => (string) ($db['DATABASE'] ?? ''),
				'schema' => (string) ($db['SCHEMA'] ?? '')
			], JSON_UNESCAPED_SLASHES);
			if ($identity === false) {
				return '';
			}
		}

		return 'instance-'.substr(hash('sha256', $identity), 0, 24);
	}

	/**
	 * Return a stable identity for the current operating-system boot. Including
	 * it in the cache generation makes a normal cache persistent, while an OS
	 * restart selects a new generation automatically. Zabbix does not expose a
	 * dependable server-service start epoch to frontend modules; a service-only
	 * restart therefore requires the explicit refresh/clear operation.
	 *
	 * Cache use fails closed when no trustworthy boot identity is available. An
	 * orchestrated/container deployment can provide its own boot generation in
	 * CAPACITY_PLANNING_BOOT_ID and rotate it when the monitored stack restarts.
	 *
	 * @return array{id: string, source: string, restart_safe: bool}
	 */
	public static function runtimeGeneration(): array {
		$explicit = getenv('CAPACITY_PLANNING_BOOT_ID');
		if ($explicit !== false && trim($explicit) !== '') {
			return [
				'id' => 'boot-'.substr(hash('sha256', trim($explicit)), 0, 24),
				'source' => 'environment',
				'restart_safe' => true
			];
		}

		$pid1_starttime = '';
		$pid1_stat_path = '/proc/1/stat';
		if (is_file($pid1_stat_path) && !is_link($pid1_stat_path)) {
			$pid1_starttime = self::parsePid1StartTime((string) @file_get_contents($pid1_stat_path));
		}

		$boot_id_path = '/proc/sys/kernel/random/boot_id';
		if (is_file($boot_id_path) && !is_link($boot_id_path)) {
			$boot_id = self::parseBootId((string) @file_get_contents($boot_id_path));
			if ($boot_id !== '') {
				$identity = $boot_id;
				$source = 'linux-boot-id';
				if ($pid1_starttime !== '') {
					$identity .= ':pid1:'.$pid1_starttime;
					$source .= '+pid1-starttime';
				}
				return [
					'id' => 'boot-'.substr(hash('sha256', $identity), 0, 24),
					'source' => $source,
					'restart_safe' => true
				];
			}
		}

		$proc_stat_path = '/proc/stat';
		if (is_file($proc_stat_path) && !is_link($proc_stat_path)) {
			$btime = self::parseBtime((string) @file_get_contents($proc_stat_path));
			if ($btime !== '') {
				$identity = 'btime:'.$btime;
				$source = 'linux-boot-time';
				if ($pid1_starttime !== '') {
					$identity .= ':pid1:'.$pid1_starttime;
					$source .= '+pid1-starttime';
				}
				return [
					'id' => 'boot-'.substr(hash('sha256', $identity), 0, 24),
					'source' => $source,
					'restart_safe' => true
				];
			}
		}

		return ['id' => '', 'source' => 'unavailable', 'restart_safe' => false];
	}

	/**
	 * Extract the process start time (proc field 22, in clock ticks) from
	 * /proc/1/stat content. The command name in field 2 may itself contain
	 * spaces and parentheses, so fields are counted after the last ')'.
	 * Public to make the parsing independently testable.
	 */
	public static function parsePid1StartTime(string $stat): string {
		$stat = trim($stat);
		$command_end = strrpos($stat, ')');
		if ($command_end === false) {
			return '';
		}
		$fields = preg_split('/\s+/', trim(substr($stat, $command_end + 1)));
		// After the parenthesized command, index 0 is proc field 3;
		// therefore index 19 is field 22 (process start time in ticks).
		return isset($fields[19]) && ctype_digit($fields[19]) ? $fields[19] : '';
	}

	/**
	 * Validate /proc/sys/kernel/random/boot_id content; returns the lowercased
	 * identifier or '' when the content is not a plausible boot id.
	 */
	public static function parseBootId(string $content): string {
		$boot_id = trim($content);

		return preg_match('/^[a-f0-9-]{16,64}$/i', $boot_id) === 1 ? strtolower($boot_id) : '';
	}

	/** Extract the boot epoch from /proc/stat content, or '' when absent. */
	public static function parseBtime(string $stat): string {
		return preg_match('/^btime\s+(\d+)$/m', $stat, $match) === 1 ? $match[1] : '';
	}

	public static function cacheMaxBytes(): int {
		$value = getenv('CAPACITY_PLANNING_CACHE_MAX_BYTES');
		if ($value === false || !ctype_digit(trim($value))) {
			return self::DEFAULT_CACHE_MAX_BYTES;
		}

		return max(self::MIN_CACHE_MAX_BYTES, min(self::MAX_CACHE_MAX_BYTES, (int) $value));
	}

	public static function cacheMaxIdleSeconds(): int {
		$value = getenv('CAPACITY_PLANNING_CACHE_MAX_IDLE_SECONDS');
		if ($value === false || !ctype_digit(trim($value))) {
			return self::DEFAULT_CACHE_MAX_IDLE_SECONDS;
		}

		return max(86400, min(365 * 86400, (int) $value));
	}

	private static function getModuleRecord(): ?array {
		if (!function_exists('DBselect') || !function_exists('DBfetch') || !function_exists('zbx_dbstr')) {
			return null;
		}

		$result = DBselect(
			'SELECT moduleid,config'.
			' FROM module'.
			' WHERE id='.zbx_dbstr(self::MODULE_ID),
			1
		);
		$row = $result ? DBfetch($result) : false;

		return is_array($row) ? $row : null;
	}

	private static function decode($config): array {
		if (is_array($config)) {
			return $config;
		}

		$config = trim((string) $config);
		if ($config === '') {
			return [];
		}

		try {
			$decoded = json_decode($config, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException) {
			return [];
		}

		return is_array($decoded) ? $decoded : [];
	}

	private static function toBool($value): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return (int) $value !== 0;
		}

		return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
	}
}
