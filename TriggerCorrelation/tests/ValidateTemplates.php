<?php
declare(strict_types=1);

// Run with the cached Zabbix web image, network disabled, repository mounted read-only.
$zabbix = getenv('ZABBIX_FRONTEND') ?: '/usr/share/zabbix';
require $zabbix.'/vendor/autoload.php';
require $zabbix.'/include/defines.inc.php';
if (!function_exists('_s')) { function _s(string $format, ...$args): string { return vsprintf($format, $args); } }
$classes = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($zabbix.'/include/classes')) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') { $classes[$file->getBasename('.php')] = $file->getPathname(); }
}
spl_autoload_register(static function (string $name) use ($classes): void { if (isset($classes[$name])) { require_once $classes[$name]; } });
$assertions = 0;
foreach (glob(dirname(__DIR__).'/templates/*.yaml') as $path) {
    $data = (new CYamlImportReader())->read(file_get_contents($path));
    (new C70XmlValidator('yaml'))->setStrict(true)->validate($data['zabbix_export'], '/zabbix_export');
    echo basename($path).": Zabbix 7.0 import schema valid\n";
    foreach ($data['zabbix_export']['templates'][0]['discovery_rules'] ?? [] as $discovery) {
        if ($discovery['key'] !== 'trigger.correlation.discovery') { continue; }
        foreach (['4' => [4], '4,5' => [4, 5], '1,3,5' => [1, 3, 5]] as $selection => $expected) {
            $discovered = [];
            foreach ($discovery['trigger_prototypes'] as $prototype) {
                $discover = true;
                foreach ($discovery['overrides'] as $override) {
                    $condition = $override['filter']['conditions'][0];
                    $operation = $override['operations'][0];
                    if (!preg_match('~'.$condition['value'].'~', (string) $selection)
                            && preg_match('~'.$operation['value'].'~', $prototype['name'])) { $discover = false; }
                }
                if ($discover) { preg_match('/=([1-5])$/', $prototype['expression'], $m); $discovered[] = (int) $m[1]; }
            }
            $assertions++;
            if ($discovered !== $expected) { throw new RuntimeException('Unselected severity prototype would be discovered.'); }
        }
    }
}
echo "Selected severity discovery: $assertions scenarios passed\n";
