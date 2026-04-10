<?php declare(strict_types = 0);

namespace Modules\AI;

use APP,
    CMenu,
    CMenuItem,
    CWebUser,
    Zabbix\Core\CModule;

class Module extends CModule {

    public function init(): void {
        APP::Component()->get('menu.main')
            ->findOrAdd(_('Monitoring'))
            ->getSubmenu()
            ->insertAfter(_('Problems'),
                (new CMenuItem(_('AI')))->setSubMenu(
                    new CMenu([
                        (new CMenuItem(_('Chat')))->setAction('ai.chat'),
                        (new CMenuItem(_('Settings')))->setAction('ai.settings'),
                        (new CMenuItem(_('Logs')))->setAction('ai.logs')
                    ])
                )
            );
    }

    public function getAssets(): array {
        $assets = parent::getAssets();

        if (!$this->userHasModuleAccess()) {
            return $assets;
        }

        $action = $this->getCurrentAction();

        // Inject inline problem assets on the Problems page.
        if ($action === 'problem.view') {
            $assets['js'][] = 'ai.problem.inline.js';
            $assets['css'][] = 'ai.problem.inline.css';
        }

        // ai.config.inline.js/css are loaded globally via manifest.json
        // so they work on all pages (including history.php, host_discovery.php,
        // and other direct PHP scripts that bypass zabbix.php routing).

        return $assets;
    }

    private function getCurrentAction(): string {
        // Zabbix passes the action as a GET parameter.
        return (string) ($_GET['action'] ?? $_REQUEST['action'] ?? '');
    }

    private function userHasModuleAccess(): bool {
        if (!class_exists('CWebUser') || !isset(CWebUser::$data) || !is_array(CWebUser::$data)) {
            return false;
        }

        $user_type = (int) (CWebUser::$data['type'] ?? 0);

        return $user_type >= USER_TYPE_ZABBIX_USER;
    }
}
