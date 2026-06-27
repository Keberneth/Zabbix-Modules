<?php declare(strict_types = 0);

namespace Modules\Healthcheck;

use APP,
    CMenu,
    CMenuItem,
    Zabbix\Core\CModule;

class Module extends CModule {

    public function init(): void {
        APP::Component()->get('menu.main')
            ->findOrAdd(_('Monitoring'))
            ->getSubmenu()
            ->insertAfter(
                _('Problems'),
                (new CMenuItem(_('Healthcheck')))->setSubMenu(
                    new CMenu([
                        (new CMenuItem(_('Heartbeat')))->setAction('healthcheck.heartbeat'),
                        (new CMenuItem(_('History')))->setAction('healthcheck.history'),
                        (new CMenuItem(_('Settings')))->setAction('healthcheck.settings')
                    ])
                )
            );

        $this->runDueChecksInBackground();
    }

    /**
     * Schedule due health checks to run after the HTTP response is sent.
     *
     * Uses file-based throttling (at most once per 60 seconds) and locking
     * (non-blocking flock) so that normal page loads see zero overhead.
     */
    private function runDueChecksInBackground(): void {
        $runtime_dir   = __DIR__.'/runtime';
        $throttle_file = $runtime_dir.'/last_check';
        $lock_file     = $runtime_dir.'/runner.lock';

        // Quick throttle: skip if we checked less than 60 seconds ago.
        if (@file_exists($throttle_file)) {
            $last = (int) @file_get_contents($throttle_file);

            if ($last > 0 && (time() - $last) < 60) {
                return;
            }
        }

        // Non-blocking lock – if another request is already running, bail out.
        $fp = @fopen($lock_file, 'c');

        if ($fp === false) {
            return;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return;
        }

        // Update the throttle timestamp so subsequent requests skip quickly.
        @file_put_contents($throttle_file, (string) time());

        // Defer the actual work until after the response has been flushed.
        register_shutdown_function(static function () use ($fp, $throttle_file): void {
            try {
                // Flush the response first so the user sees no delay.
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }

                // Bound the deferred heavy path so it can never run unbounded in a
                // page-load shutdown handler.
                if (function_exists('set_time_limit')) {
                    @set_time_limit(300);
                }

                require_once __DIR__.'/lib/bootstrap.php';

                $pdo    = \Modules\Healthcheck\Lib\DbConnector::connect();
                \Modules\Healthcheck\Lib\Storage::ensureSchema($pdo);

                $config = \Modules\Healthcheck\Lib\Config::get($pdo);
                \Modules\Healthcheck\Lib\Runner::runDueChecks($config, $pdo, '', false);

                // Refresh throttle after a successful run so the next window
                // is measured from completion, not from the start.
                @file_put_contents($throttle_file, (string) time());
            }
            catch (\Throwable $e) {
                // Silently ignore – the next page load will retry.
            }
            finally {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        });
    }
}
