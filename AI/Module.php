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

        // Only inject inline problem assets on the Problems page (problem.view)
        // and only if the user has at least regular Zabbix user access.
        $action = $this->getCurrentAction();

        if ($action !== 'problem.view') {
            return $assets;
        }

        if (!$this->userHasModuleAccess()) {
            return $assets;
        }

        $assets['js'][] = 'ai.problem.inline.js';
        $assets['css'][] = 'ai.problem.inline.css';

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
