<?php

namespace Modules\Rebrand\Actions;

use CController;
use CControllerResponseData;

class RebrandConfig extends CController {

	use RebrandStorageTrait;

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$data = [
			'logo_main' => null,
			'logo_sidebar' => null,
			'logo_compact' => null,
			'favicon' => null,
			'brand_footer' => '',
			'brand_help_url' => '',
			'storage_url' => $this->getPrimaryStorageUrl(),
			'storage_dir' => '',
			'local_conf_dir' => '',
			'brand_conf_file' => '',
			'storage_writable' => false,
			'conf_writable' => false,
			'runtime_user' => $this->getProcessUser(),
			'using_legacy_storage' => false,
			'legacy_storage_dir' => '',
			'runtime_error' => null
		];

		try {
			$module_dir = $this->getModuleDir();
			$frontend_root = $this->getFrontendRootFromModuleDir($module_dir);
			$local_conf_dir = $this->getLocalConfDir($frontend_root);
			$config_storage_dir = $this->getPrimaryStorageDir($frontend_root);
			$logo_storage_dir = $this->getLegacyStorageDir($module_dir);
			$logo_storage_url = $this->getLegacyStorageUrl($module_dir);
			$config = $this->loadConfigFromDir($config_storage_dir);

			if (!is_file($this->getConfigFilePath($config_storage_dir))
					&& is_file($this->getConfigFilePath($logo_storage_dir))) {
				$config = $this->loadConfigFromDir($logo_storage_dir);
			}

			$this->healLegacyAssets($config, $config_storage_dir, $logo_storage_dir);

			$data = [
				'logo_main' => $this->getExistingLogoName($config, 'logo_main', $logo_storage_dir),
				'logo_sidebar' => $this->getExistingLogoName($config, 'logo_sidebar', $logo_storage_dir),
				'logo_compact' => $this->getExistingLogoName($config, 'logo_compact', $logo_storage_dir),
				'favicon' => $this->getExistingLogoName($config, 'favicon', $logo_storage_dir),
				'brand_footer' => $config['brand_footer'] ?? '',
				'brand_help_url' => $config['brand_help_url'] ?? '',
				'storage_url' => $logo_storage_url,
				'storage_dir' => $logo_storage_dir,
				'local_conf_dir' => $local_conf_dir,
				'brand_conf_file' => $this->getBrandConfFilePath($frontend_root),
				'storage_writable' => $this->canWritePath($logo_storage_dir),
				'conf_writable' => ($this->canWritePath($local_conf_dir) && $this->canWritePath($config_storage_dir)),
				'runtime_user' => $this->getProcessUser(),
				'using_legacy_storage' => false,
				'legacy_storage_dir' => '',
				'runtime_error' => null
			];
		}
		catch (\Throwable $exception) {
			$this->logRuntimeError('Failed to render Branding page', $exception);
			$data['runtime_error'] = 'Failed to initialize branding storage. Review the web server error log for details.';
		}

		$this->setResponse(new CControllerResponseData($data));
	}
}
