<?php declare(strict_types = 1);

// Exercise actual menu construction and controller gates without Zabbix or I/O.
namespace Zabbix\Core {
    class CModule {}
}

namespace {
    define('USER_TYPE_ZABBIX_USER', 1);
    define('USER_TYPE_ZABBIX_ADMIN', 2);
    define('USER_TYPE_SUPER_ADMIN', 3);

    class CWebUser {
        public static array $data = [];
        public static bool $guest = false;
        public static function isGuest(): bool { return self::$guest; }
    }
    class CController {
        protected function getUserType(): int { return CWebUser::$data['type']; }
    }
    class CMenu {
        public array $items;
        public function __construct(array $items = []) { $this->items = $items; }
        public function findOrAdd(string $label): CMenuItem {
            $item = new CMenuItem($label);
            $this->items[] = $item;
            return $item;
        }
        public function insertAfter(string $label, CMenuItem $item): self { $this->items[] = $item; return $this; }
    }
    class CMenuItem {
        public string $action = '';
        public ?CMenu $submenu = null;
        public function __construct(string $label) {}
        public function setAction(string $action): self { $this->action = $action; return $this; }
        public function setSubMenu(CMenu $menu): self { $this->submenu = $menu; return $this; }
        public function getSubmenu(): CMenu { return $this->submenu ?? ($this->submenu = new CMenu()); }
    }
    class APP {
        public static CMenu $menu;
        public static function Component(): self { return new self(); }
        public function get(string $name): CMenu { return self::$menu; }
    }
    if (!function_exists('_')) {
        function _(string $text): string { return $text; }
    }

    require_once __DIR__.'/../Module.php';
    $manifest = json_decode(file_get_contents(__DIR__.'/../manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $routes = array_filter($manifest['actions'], static fn($route) => strpos($route, 'ai.logs') === 0, ARRAY_FILTER_USE_KEY);
    foreach ($routes as $definition) {
        require_once __DIR__.'/../actions/'.$definition['class'].'.php';
    }
    function menuActions(CMenu $menu): array {
        $actions = [];
        foreach ($menu->items as $item) {
            $actions[] = $item->action;
            if ($item->submenu !== null) { $actions = array_merge($actions, menuActions($item->submenu)); }
        }
        return $actions;
    }
    $checks = 0;
    foreach ([0, USER_TYPE_ZABBIX_USER, USER_TYPE_ZABBIX_ADMIN, USER_TYPE_SUPER_ADMIN] as $type) {
        foreach ([false, true] as $guest) {
            CWebUser::$data = ['type' => $type];
            CWebUser::$guest = $guest;
            $allowed = $type === USER_TYPE_SUPER_ADMIN && !$guest;
            APP::$menu = new CMenu();
            (new \Modules\AI\Module())->init();
            if (in_array('ai.logs', menuActions(APP::$menu), true) !== $allowed) {
                throw new \RuntimeException('Logs menu visibility disagrees with the Super Admin policy.');
            }
            ++$checks;
            foreach ($routes as $route => $definition) {
                $class = 'Modules\\AI\\Actions\\'.$definition['class'];
                $gate = new \ReflectionMethod($class, 'checkPermissions');
                $gate->setAccessible(true);
                if ($gate->invoke(new $class()) !== $allowed) {
                    throw new \RuntimeException($route.' allowed an unauthorized role or refused a Super Admin.');
                }
                ++$checks;
            }
        }
    }
    echo 'Logs permission checks passed: '.$checks."\n";
}
