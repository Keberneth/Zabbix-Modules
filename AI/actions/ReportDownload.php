<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    CWebUser,
    Modules\AI\Lib\AuditLogger,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\ReportStore,
    Modules\AI\Lib\Util;

class ReportDownload extends CController {

    protected function init(): void {
        // This action is invoked via plain GET requests (download links and
        // <img src> for inline charts). No CSRF token is sent. Session-cookie
        // authentication still applies (see checkPermissions), and the report
        // token itself is bound to the user's server session inside ReportStore.
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
    }

    protected function doAction(): void {
        $config = Config::get();
        $token = Util::cleanString($_GET['token'] ?? '', 128);
        $inline_requested = Util::truthy($_GET['inline'] ?? '');

        try {
            $meta = ReportStore::load($config, $this->serverSessionKey(), $token);
        }
        catch (\Throwable $e) {
            AuditLogger::log($config, 'reads', [
                'event' => 'report.download.failed',
                'source' => 'ai.report.download',
                'status' => 'error',
                'message' => $e->getMessage()
            ]);

            $this->respondPlain(404, $e->getMessage());
            return;
        }

        $file_path = (string) $meta['file_path'];
        $filename = (string) $meta['filename'];
        $format = (string) $meta['format'];
        $size = (int) ($meta['size'] ?? @filesize($file_path));

        // Only formats that are safe to render in a browser tab without script execution
        // may be served inline. SVG is allowed because we never embed remote content
        // in chart SVGs we generate; HTML is *not* inlined to avoid same-origin XSS risk.
        $inline_safe_formats = ['svg', 'csv', 'md', 'json'];
        $serve_inline = $inline_requested && in_array($format, $inline_safe_formats, true);

        AuditLogger::log($config, 'reads', [
            'event' => 'report.download.served',
            'source' => 'ai.report.download',
            'status' => 'ok',
            'meta' => [
                'report_type' => (string) ($meta['report_type'] ?? ''),
                'format' => $format,
                'filename' => $filename,
                'row_count' => (int) ($meta['row_count'] ?? 0),
                'size' => $size,
                'inline' => $serve_inline
            ]
        ]);

        $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        $disposition = $serve_inline ? 'inline' : 'attachment';

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        http_response_code(200);
        header('Content-Type: '.ReportStore::contentType($format));
        header('Content-Disposition: '.$disposition.'; filename="'.$safe_name.'"');
        header('Content-Length: '.$size);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');

        @readfile($file_path);

        $this->setResponse(
            (new CControllerResponseData(['main_block' => '']))->disableView()
        );

        if (!$serve_inline && Util::truthy($config['reports']['delete_after_download'] ?? false)) {
            ReportStore::delete($config, $token);
        }
    }

    private function respondPlain(int $status, string $message): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        echo $message;

        $this->setResponse(
            (new CControllerResponseData(['main_block' => '']))->disableView()
        );
    }

    private function serverSessionKey(): string {
        $sid = (string) session_id();
        if ($sid !== '') {
            return $sid;
        }

        if (class_exists('CWebUser') && isset(\CWebUser::$data) && is_array(\CWebUser::$data)) {
            $uid = (string) (\CWebUser::$data['userid'] ?? '');
            if ($uid !== '') {
                return 'user:'.$uid;
            }
        }

        return 'remote:'.Util::cleanString($_SERVER['REMOTE_ADDR'] ?? 'unknown', 128);
    }
}
