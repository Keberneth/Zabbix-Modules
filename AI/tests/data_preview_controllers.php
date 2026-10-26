<?php declare(strict_types = 1);

namespace Modules\AI\Lib {
    // Any accidental real provider request fails locally before opening a socket.
    function curl_init(string $url) {
        $GLOBALS['preview_network_attempts']++;
        throw new \RuntimeException('Offline provider guard reached.');
    }
}

namespace {
    const USER_TYPE_ZABBIX_USER = 1;
    const USER_TYPE_ZABBIX_ADMIN = 2;
    const USER_TYPE_SUPER_ADMIN = 3;

    class CWebUser {
        public static array $data = ['userid' => '10', 'type' => USER_TYPE_SUPER_ADMIN];
        public static bool $guest = false;
        public static function isGuest(): bool { return self::$guest; }
    }
    class CController {
        public ?CControllerResponseData $response = null;
        protected function getUserType(): int { return CWebUser::$data['type']; }
        protected function setResponse(CControllerResponseData $response): void { $this->response = $response; }
    }
    class CControllerResponseData {
        public array $data;
        public function __construct(array $data) { $this->data = $data; }
        public function disableView(): self { return $this; }
    }
    class API {
        public static array $calls = [];
        public static function __callStatic(string $name, array $args): object {
            return new class($name) {
                private string $name;
                public function __construct(string $name) { $this->name = $name; }
                public function __call(string $method, array $args): array {
                    API::$calls[] = $this->name.'.'.$method;
                    if ($method !== 'get') { throw new RuntimeException('Unexpected Zabbix mutation.'); }
                    return [];
                }
            };
        }
    }
    function DBselect(string $query): bool { return true; }
    function DBfetch($result): array { return ['config' => json_encode($GLOBALS['preview_config'])]; }
    function zbx_dbstr(string $value): string { return "'".$value."'"; }

    require_once __DIR__.'/../actions/ChatSend.php';
    require_once __DIR__.'/../actions/ChatExecute.php';

    use Modules\AI\Actions\ChatSend;
    use Modules\AI\Actions\ChatExecute;
    use Modules\AI\Lib\Config;
    use Modules\AI\Lib\DataPreview;

    function checkPreview(bool $condition, string $message): void {
        if (!$condition) { throw new RuntimeException($message); }
    }
    function runPreviewController(string $class, array $post): array {
        $_POST = $post;
        $controller = new $class();
        (new ReflectionMethod($class, 'doAction'))->invoke($controller);
        checkPreview($controller->response !== null, 'Controller produced no response.');
        $body = json_decode($controller->response->data['main_block'], true);
        checkPreview(is_array($body), 'Controller response was not JSON.');
        checkPreview(DataPreview::snapshot() === null, 'Capture state survived the request.');
        return $body;
    }

    $root = sys_get_temp_dir().'/ai-preview-controllers-'.bin2hex(random_bytes(6));
    mkdir($root, 0700);
    putenv('ZABBIX_AI_SECRET_PREVIEW_TEST_KEY=synthetic-preview-credential');
    $GLOBALS['preview_config'] = Config::defaults();
    $GLOBALS['preview_config']['providers'] = [[
        'id' => 'preview-provider', 'enabled' => true, 'name' => 'Offline fixture',
        'type' => 'openai_compatible', 'endpoint' => 'https://provider.invalid/v1',
        'model' => 'test-model', 'api_key_env' => 'env:ZABBIX_AI_SECRET_PREVIEW_TEST_KEY'
    ]];
    $GLOBALS['preview_config']['security']['state_path'] = $root.'/state';
    $GLOBALS['preview_config']['security']['categories']['zabbix_inventory'] = false;
    $GLOBALS['preview_config']['logging']['enabled'] = true;
    $GLOBALS['preview_config']['logging']['path'] = $root.'/logs';
    $GLOBALS['preview_config']['logging']['archive_path'] = $root.'/archive';
    $GLOBALS['preview_config']['logging']['archive_enabled'] = false;
    $GLOBALS['preview_config']['logging']['include_payloads'] = true;
    $GLOBALS['preview_config']['instructions'] = [[
        'enabled' => true, 'content' => 'PRIVATE_ADMIN_POLICY_MARKER'
    ]];
    $post = [
        'message' => 'Investigate PREVIEW_MESSAGE_MARKER at 10.23.45.67',
        'chat_session_id' => 'preview-session',
        'extra_context' => 'PREVIEW_CONTEXT_MARKER at 10.23.45.67',
        'preview_only' => '1'
    ];
    $GLOBALS['preview_network_attempts'] = 0;

    try {
        foreach ([['off', USER_TYPE_SUPER_ADMIN, false], ['super_admin', USER_TYPE_ZABBIX_USER, false],
                ['all', USER_TYPE_ZABBIX_USER, true]] as [$visibility, $type, $guest]) {
            $GLOBALS['preview_config']['chat']['data_preview_visibility'] = $visibility;
            CWebUser::$data['type'] = $type;
            CWebUser::$guest = $guest;
            API::$calls = [];
            $response = runPreviewController(ChatSend::class, $post);
            checkPreview(!$response['ok'] && http_response_code() === 403, 'Unauthorized preview was not rejected.');
            checkPreview(API::$calls === [], 'Denied preview read Zabbix context.');
            checkPreview(!file_exists($root.'/state'), 'Denied preview touched redaction state.');
            checkPreview(!isset($response['data_preview']) && !isset($response['data_sent']), 'Denied preview exposed data.');
        }

        CWebUser::$guest = false;
        foreach ([['super_admin', USER_TYPE_SUPER_ADMIN], ['all', USER_TYPE_ZABBIX_USER]] as [$visibility, $type]) {
            $GLOBALS['preview_config']['chat']['data_preview_visibility'] = $visibility;
            CWebUser::$data['type'] = $type;
            $response = runPreviewController(ChatSend::class, $post);
            checkPreview($response['ok'] && isset($response['data_preview']), 'Authorized preview did not return a snapshot: '.json_encode($response));
            checkPreview(!isset($response['data_sent']) && !isset($response['reply']) && !isset($response['action_pending']), 'Preview entered the normal chat result flow.');
            $encoded = json_encode($response['data_preview']);
            checkPreview(strpos($encoded, 'PREVIEW_MESSAGE_MARKER') !== false, 'Preview omitted current user content.');
            checkPreview(strpos($encoded, 'PREVIEW_CONTEXT_MARKER') !== false, 'Preview omitted authorized masked context.');
            foreach (['10.23.45.67', 'PRIVATE_ADMIN_POLICY_MARKER', 'synthetic-preview-credential'] as $private) {
                checkPreview(strpos($encoded, $private) === false, 'Preview exposed protected fixture '.$private.'.');
            }
        }
        checkPreview($GLOBALS['preview_network_attempts'] === 0, 'Preview attempted a provider network request.');
        checkPreview(!is_dir($root.'/state/pending'), 'Preview staged a pending action.');
        checkPreview((glob($root.'/logs/ai-*.jsonl') ?: []) === [], 'Preview was audited as normal chat.');
        checkPreview(count(glob($root.'/state/redaction_*.json') ?: []) > 0, 'Preview did not save stable aliases.');

        // Normal failed sends may still reveal the attempted outbound body to
        // eligible viewers; disabled viewers must receive no capture at all.
        $post['preview_only'] = '0';
        foreach (['off', 'all'] as $visibility) {
            $GLOBALS['preview_config']['chat']['data_preview_visibility'] = $visibility;
            $response = runPreviewController(ChatSend::class, $post);
            checkPreview(!$response['ok'], 'Offline fixture unexpectedly contacted a provider.');
            checkPreview(isset($response['data_sent']) === ($visibility === 'all'), 'Actual-send capture ignored visibility.');
        }
        checkPreview($GLOBALS['preview_network_attempts'] === 2, 'Normal send path did not reach the offline provider guard.');
        foreach (glob($root.'/logs/ai-*.jsonl') ?: [] as $file) {
            checkPreview(strpos(file_get_contents($file), 'PREVIEW_MESSAGE_MARKER') === false, 'Capture leaked into audit logs.');
        }
        $response = runPreviewController(ChatExecute::class, ['action_id' => 'missing-action', 'confirmation_hash' => '']);
        checkPreview(!$response['ok'] && !isset($response['data_sent']), 'Execution without a provider request exposed a capture.');
    }
    finally {
        putenv('ZABBIX_AI_SECRET_PREVIEW_TEST_KEY');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }
    echo "Data preview controller tests passed.\n";
}
