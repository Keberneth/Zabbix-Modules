<?php declare(strict_types=1);

// Focused offline API doubles. Run each test file in a separate PHP process.
if (!function_exists('_')) {
	function _(string $message): string { return $message; }
}
if (!function_exists('_s')) {
	function _s(string $message, ...$args): string { return sprintf($message, ...$args); }
}
foreach (['ITEM_VALUE_TYPE_FLOAT' => 0, 'ITEM_VALUE_TYPE_UINT64' => 3,
	'USER_TYPE_ZABBIX_USER' => 1, 'USER_TYPE_ZABBIX_ADMIN' => 2, 'USER_TYPE_SUPER_ADMIN' => 3,
	'ZBX_SORT_UP' => 'ASC', 'ZBX_SORT_DOWN' => 'DESC', 'ZBX_DEFAULT_TIMEZONE' => 'system'] as $name => $value) {
	if (!defined($name)) { define($name, $value); }
}
final class CWebUser {
	public static int $type = USER_TYPE_SUPER_ADMIN;
	public static function getType(): int { return self::$type; }
}
final class CSettingsHelper {
	public const DEFAULT_TIMEZONE = 'default_timezone';
	public static function get(string $key): string { return 'UTC'; }
}
final class CTimezoneHelper {
	public static function getSystemTimezone(): string { return 'UTC'; }
}
final class API {
	public static array $handlers = [];
	public static array $calls = [];
	public static function __callStatic(string $name, array $arguments): object {
		return new class($name) {
			private string $name;
			public function __construct(string $name) { $this->name = $name; }
			public function get(array $options) {
				API::$calls[] = [$this->name, $options];
				$handler = API::$handlers[$this->name] ?? null;
				if ($handler === null) { throw new RuntimeException('Unexpected API read: '.$this->name); }
				return $handler($options);
			}
			public function __call(string $method, array $arguments) {
				$key = $this->name.'.'.$method;
				API::$calls[] = [$key, $arguments[0]];
				$handler = API::$handlers[$key] ?? null;
				if ($handler === null) { throw new RuntimeException('Unexpected API mutation: '.$key); }
				return $handler($arguments[0]);
			}
		};
	}
	public static function reset(): void { self::$handlers = []; self::$calls = []; }
}
function sr_assert(bool $condition, string $message): void {
	if (!$condition) { throw new RuntimeException($message); }
}
function sr_equal($actual, $expected, string $message): void {
	if (is_numeric($actual) && is_numeric($expected) && abs((float) $actual - (float) $expected) < 0.000001) { return; }
	if ($actual !== $expected) {
		throw new RuntimeException($message.'; expected '.json_encode($expected).', got '.json_encode($actual));
	}
}
function sr_history(array $rows): void {
	API::$handlers['History'] = static function(array $options) use ($rows): array {
		$selected = array_values(array_filter($rows, static fn(array $row): bool =>
			in_array((string) $row['itemid'], array_map('strval', $options['itemids']), true)
			&& (int) ($row['type'] ?? ITEM_VALUE_TYPE_UINT64) === (int) $options['history']
			&& (int) $row['clock'] >= (int) $options['time_from']
			&& (int) $row['clock'] <= (int) $options['time_till']));
		usort($selected, static fn(array $a, array $b): int => $a['clock'] <=> $b['clock']);
		return array_slice($selected, 0, $options['limit'] ?? count($selected));
	};
}
function sr_trends(array $rows): void {
	API::$handlers['Trend'] = static function(array $options) use ($rows): array {
		return array_values(array_filter($rows, static fn(array $row): bool =>
			in_array((string) $row['itemid'], array_map('strval', $options['itemids']), true)
			&& (int) $row['clock'] >= (int) $options['time_from']
			&& (int) $row['clock'] <= (int) $options['time_till']));
	};
}
