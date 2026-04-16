<?php

namespace Modules\Rebrand\Actions;

trait RebrandStorageTrait {

	private function getModuleDir(): string {
		$module_dir = realpath(dirname(__DIR__));

		return ($module_dir !== false) ? $module_dir : dirname(__DIR__);
	}

	private function getFrontendRootFromModuleDir(string $module_dir): string {
		$frontend_root = realpath($module_dir.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..');

		if ($frontend_root === false) {
			throw new \RuntimeException('Could not determine the Zabbix frontend root directory.');
		}

		return $frontend_root;
	}

	private function getLocalConfDir(string $frontend_root): string {
		return $frontend_root.DIRECTORY_SEPARATOR.'local'.DIRECTORY_SEPARATOR.'conf';
	}

	private function getPrimaryStorageDir(string $frontend_root): string {
		return $this->getLocalConfDir($frontend_root).DIRECTORY_SEPARATOR.'rebrand';
	}

	private function getPrimaryStorageUrl(): string {
		$module_dir = $this->getModuleDir();

		return 'modules/'.basename($module_dir).'/assets/logos';
	}

	private function getLegacyStorageDir(string $module_dir): string {
		return $module_dir.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'logos';
	}

	private function getLegacyStorageUrl(string $module_dir): string {
		return 'modules/'.basename($module_dir).'/assets/logos';
	}

	private function getConfigFilePath(string $storage_dir): string {
		return $storage_dir.DIRECTORY_SEPARATOR.'config.json';
	}

	private function getBrandConfFilePath(string $frontend_root): string {
		return $this->getLocalConfDir($frontend_root).DIRECTORY_SEPARATOR.'brand.conf.php';
	}

	private function normalizeLogoName(mixed $value): ?string {
		if (!is_string($value) || trim($value) === '') {
			return null;
		}

		$filename = basename(trim($value));

		return ($filename !== '') ? $filename : null;
	}

	private function normalizeConfig(array $config): array {
		return [
			'logo_main' => $this->normalizeLogoName($config['logo_main'] ?? null),
			'logo_sidebar' => $this->normalizeLogoName($config['logo_sidebar'] ?? null),
			'logo_compact' => $this->normalizeLogoName($config['logo_compact'] ?? null),
			'favicon' => $this->normalizeLogoName($config['favicon'] ?? null),
			'brand_footer' => isset($config['brand_footer']) ? trim((string) $config['brand_footer']) : '',
			'brand_help_url' => isset($config['brand_help_url']) ? trim((string) $config['brand_help_url']) : ''
		];
	}

	private function loadConfigFromDir(string $storage_dir): array {
		$config_file = $this->getConfigFilePath($storage_dir);

		if (!is_file($config_file) || !is_readable($config_file)) {
			return $this->normalizeConfig([]);
		}

		$json = @file_get_contents($config_file);
		if ($json === false || $json === '') {
			return $this->normalizeConfig([]);
		}

		$config = json_decode($json, true);

		return is_array($config) ? $this->normalizeConfig($config) : $this->normalizeConfig([]);
	}

	private function detectActiveStorage(string $module_dir, string $frontend_root): array {
		$primary_dir = $this->getPrimaryStorageDir($frontend_root);
		$primary_config = $this->loadConfigFromDir($primary_dir);

		if (is_file($this->getConfigFilePath($primary_dir))) {
			return [
				'mode' => 'primary',
				'dir' => $primary_dir,
				'url' => $this->getPrimaryStorageUrl(),
				'config' => $primary_config
			];
		}

		$legacy_dir = $this->getLegacyStorageDir($module_dir);
		$legacy_config = $this->loadConfigFromDir($legacy_dir);

		if (is_file($this->getConfigFilePath($legacy_dir))) {
			return [
				'mode' => 'legacy',
				'dir' => $legacy_dir,
				'url' => $this->getLegacyStorageUrl($module_dir),
				'config' => $legacy_config
			];
		}

		return [
			'mode' => 'primary',
			'dir' => $primary_dir,
			'url' => $this->getPrimaryStorageUrl(),
			'config' => $primary_config
		];
	}

	private function getExistingLogoName(array $config, string $logo_key, string $storage_dir): ?string {
		$filename = $this->normalizeLogoName($config[$logo_key] ?? null);

		if ($filename === null) {
			return null;
		}

		return is_file($storage_dir.DIRECTORY_SEPARATOR.$filename) ? $filename : null;
	}

	private function canWritePath(string $path): bool {
		if (is_dir($path)) {
			return is_writable($path);
		}

		$parent = dirname($path);

		if ($parent === $path) {
			return false;
		}

		return is_dir($parent) ? is_writable($parent) : $this->canWritePath($parent);
	}

	private function ensureDirectory(string $dir): bool {
		return is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir);
	}

	private function migrateLegacyAssets(array $config, string $legacy_dir, string $primary_dir, array &$errors): void {
		if ($legacy_dir === $primary_dir) {
			return;
		}

		foreach (['logo_main', 'logo_sidebar', 'logo_compact', 'favicon'] as $logo_key) {
			$filename = $this->normalizeLogoName($config[$logo_key] ?? null);

			if ($filename === null) {
				continue;
			}

			$source = $legacy_dir.DIRECTORY_SEPARATOR.$filename;
			$target = $primary_dir.DIRECTORY_SEPARATOR.$filename;

			if (!is_file($source) || is_file($target)) {
				continue;
			}

			if (!@copy($source, $target)) {
				$errors[] = 'Failed to copy existing file from legacy storage: '.$filename;
				continue;
			}

			@chmod($target, 0644);
		}
	}

	private function persistAssetsToPrimary(array $config, string $legacy_dir, string $primary_dir): void {
		if ($legacy_dir === $primary_dir) {
			return;
		}

		if (!is_dir($primary_dir) && !@mkdir($primary_dir, 0755, true) && !is_dir($primary_dir)) {
			return;
		}

		foreach (['logo_main', 'logo_sidebar', 'logo_compact', 'favicon'] as $logo_key) {
			$filename = $this->normalizeLogoName($config[$logo_key] ?? null);

			if ($filename === null) {
				continue;
			}

			$source = $legacy_dir.DIRECTORY_SEPARATOR.$filename;
			$target = $primary_dir.DIRECTORY_SEPARATOR.$filename;

			if (!is_file($source)) {
				continue;
			}

			$source_mtime = @filemtime($source);
			$target_mtime = is_file($target) ? @filemtime($target) : 0;

			if ($target_mtime !== false && $source_mtime !== false && $target_mtime >= $source_mtime) {
				continue;
			}

			if (@copy($source, $target)) {
				@chmod($target, 0644);
			}
		}
	}

	private function healLegacyAssets(array $config, string $primary_dir, string $legacy_dir): void {
		if ($legacy_dir === $primary_dir) {
			return;
		}

		if (!is_dir($legacy_dir) && !@mkdir($legacy_dir, 0755, true) && !is_dir($legacy_dir)) {
			return;
		}

		foreach (['logo_main', 'logo_sidebar', 'logo_compact', 'favicon'] as $logo_key) {
			$filename = $this->normalizeLogoName($config[$logo_key] ?? null);

			if ($filename === null) {
				continue;
			}

			$source = $primary_dir.DIRECTORY_SEPARATOR.$filename;
			$target = $legacy_dir.DIRECTORY_SEPARATOR.$filename;

			if (!is_file($source) || is_file($target)) {
				continue;
			}

			if (@copy($source, $target)) {
				@chmod($target, 0644);
			}
		}
	}

	private function writeFileAtomically(string $file_path, string $content): bool {
		$directory = dirname($file_path);

		if (!$this->ensureDirectory($directory)) {
			return false;
		}

		$temp_file = @tempnam($directory, '.rebrand-');
		if ($temp_file === false) {
			return false;
		}

		$bytes = @file_put_contents($temp_file, $content, LOCK_EX);
		if ($bytes === false) {
			@unlink($temp_file);
			return false;
		}

		@chmod($temp_file, 0644);

		if (!@rename($temp_file, $file_path)) {
			@unlink($temp_file);
			return false;
		}

		return true;
	}

	private function getUploadErrorMessage(int $code): string {
		return match ($code) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'file too large.',
			UPLOAD_ERR_PARTIAL => 'upload was only partially completed.',
			UPLOAD_ERR_NO_FILE => 'no file was uploaded.',
			UPLOAD_ERR_NO_TMP_DIR => 'missing temporary upload directory.',
			UPLOAD_ERR_CANT_WRITE => 'could not write file to disk.',
			UPLOAD_ERR_EXTENSION => 'upload blocked by a PHP extension.',
			default => 'unknown upload error.'
		};
	}

	private function detectMimeType(string $file_path): ?string {
		if (!function_exists('finfo_open') || !function_exists('finfo_file') || !function_exists('finfo_close')) {
			return null;
		}

		$finfo = @finfo_open(FILEINFO_MIME_TYPE);
		if ($finfo === false) {
			return null;
		}

		$mime = @finfo_file($finfo, $file_path);
		@finfo_close($finfo);

		return is_string($mime) ? $mime : null;
	}

	private function isAllowedUpload(string $tmp_name, string $extension): bool {
		$mime = $this->detectMimeType($tmp_name);

		if ($mime === null || $mime === '') {
			return true;
		}

		$allowed_mimes = [
			'svg' => ['image/svg+xml', 'text/plain', 'text/xml', 'application/xml'],
			'png' => ['image/png'],
			'jpg' => ['image/jpeg'],
			'jpeg' => ['image/jpeg'],
			'gif' => ['image/gif'],
			'ico' => ['image/x-icon', 'image/vnd.microsoft.icon', 'application/octet-stream']
		];

		return isset($allowed_mimes[$extension]) && in_array($mime, $allowed_mimes[$extension], true);
	}

	private function processUploadedLogo(string $logo_key, array $meta, string $storage_dir, array &$config): ?string {
		if (!isset($_FILES[$logo_key]) || !is_array($_FILES[$logo_key])) {
			return null;
		}

		$upload = $_FILES[$logo_key];
		$error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;

		if ($error === UPLOAD_ERR_NO_FILE) {
			return null;
		}

		if ($error !== UPLOAD_ERR_OK) {
			return $meta['label'].': '.$this->getUploadErrorMessage((int) $error);
		}

		$tmp_name = $upload['tmp_name'] ?? '';
		if (!is_string($tmp_name) || $tmp_name === '' || !is_uploaded_file($tmp_name)) {
			return $meta['label'].': invalid upload payload.';
		}

		$original_name = isset($upload['name']) && is_string($upload['name']) ? basename($upload['name']) : '';
		$extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

		if (!in_array($extension, ['svg', 'png', 'jpg', 'jpeg', 'gif', 'ico'], true)) {
			return $meta['label'].': invalid file type. Allowed: SVG, PNG, JPG, GIF, ICO.';
		}

		$file_size = isset($upload['size']) ? (int) $upload['size'] : 0;
		if ($file_size > 2 * 1024 * 1024) {
			return $meta['label'].': file too large. Maximum: 2 MB.';
		}

		if (!$this->isAllowedUpload($tmp_name, $extension)) {
			return $meta['label'].': file content does not match the selected image format.';
		}

		$target_name = ($logo_key === 'favicon') ? 'favicon.ico' : $logo_key.'.'.$extension;
		$target_file = $storage_dir.DIRECTORY_SEPARATOR.$target_name;
		$existing_file = $this->normalizeLogoName($config[$logo_key] ?? null);

		if ($existing_file !== null && is_file($storage_dir.DIRECTORY_SEPARATOR.$existing_file)) {
			@unlink($storage_dir.DIRECTORY_SEPARATOR.$existing_file);
		}

		if (!@move_uploaded_file($tmp_name, $target_file)) {
			return $meta['label'].': failed to save file. Check write permissions for '.$storage_dir.'.';
		}

		@chmod($target_file, 0644);
		$config[$logo_key] = basename($target_file);

		return null;
	}

	private function getProcessUid(): ?int {
		if (!is_readable('/proc/self/status')) {
			return null;
		}

		$status = @file_get_contents('/proc/self/status');
		if ($status === false) {
			return null;
		}

		if (preg_match('/^Uid:\s+(\d+)/m', $status, $matches) === 1) {
			return (int) $matches[1];
		}

		return null;
	}

	private function lookupUsernameByUid(int $uid): ?string {
		if (!is_readable('/etc/passwd')) {
			return null;
		}

		$lines = @file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($lines === false) {
			return null;
		}

		foreach ($lines as $line) {
			$parts = explode(':', $line);

			if (count($parts) < 3 || !ctype_digit($parts[2])) {
				continue;
			}

			if ((int) $parts[2] === $uid) {
				return $parts[0];
			}
		}

		return null;
	}

	private function systemUserExists(string $username): bool {
		if (function_exists('posix_getpwnam')) {
			return @posix_getpwnam($username) !== false;
		}

		if (!is_readable('/etc/passwd')) {
			return false;
		}

		$lines = @file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($lines === false) {
			return false;
		}

		foreach ($lines as $line) {
			if (strncmp($line, $username.':', strlen($username) + 1) === 0) {
				return true;
			}
		}

		return false;
	}

	private function getProcessUser(): string {
		if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
			$info = @posix_getpwuid(posix_geteuid());
			if (is_array($info) && !empty($info['name'])) {
				return $info['name'];
			}
		}

		$uid = $this->getProcessUid();
		if ($uid !== null) {
			$username = $this->lookupUsernameByUid($uid);
			if ($username !== null) {
				return $username;
			}
		}

		foreach (['apache', 'nginx', 'www-data', 'httpd'] as $candidate) {
			if ($this->systemUserExists($candidate)) {
				return $candidate;
			}
		}

		return 'web-user';
	}

	private function logRuntimeError(string $context, \Throwable $exception): void {
		@error_log(sprintf(
			'[Rebrand] %s: %s in %s:%d',
			$context,
			$exception->getMessage(),
			$exception->getFile(),
			$exception->getLine()
		));
	}
}
