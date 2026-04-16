<?php

namespace Modules\Rebrand\Actions;

use CController;
use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;

class RebrandConfigUpdate extends CController {

	use RebrandStorageTrait;

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'brand_footer' => 'string',
			'brand_help_url' => 'string',
			'remove_logo_main' => 'in 1',
			'remove_logo_sidebar' => 'in 1',
			'remove_logo_compact' => 'in 1',
			'remove_favicon' => 'in 1',
			'update' => 'string'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$response = new CControllerResponseRedirect(
				(new CUrl('zabbix.php'))->setArgument('action', 'rebrand.config')
			);
			$response->setFormData($this->getInputAll());
			CMessageHelper::setErrorTitle(_('Invalid input.'));
			$this->setResponse($response);
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$redirect = new CUrl('zabbix.php');
		$redirect->setArgument('action', 'rebrand.config');
		$response = new CControllerResponseRedirect($redirect);

		try {
			$module_dir = $this->getModuleDir();
			$frontend_root = $this->getFrontendRootFromModuleDir($module_dir);
			$local_conf_dir = $this->getLocalConfDir($frontend_root);
			$config_storage_dir = $this->getPrimaryStorageDir($frontend_root);
			$logo_storage_dir = $this->getLegacyStorageDir($module_dir);
			$logo_storage_url = $this->getLegacyStorageUrl($module_dir);
			$runtime_user = $this->getProcessUser();
			$errors = [];

			if (!$this->ensureDirectory($local_conf_dir)) {
				$errors[] = 'Could not create or access '.$local_conf_dir.'. Grant write access to '.$runtime_user.' and ensure SELinux allows writes.';
			}

			if (!$this->ensureDirectory($config_storage_dir)) {
				$errors[] = 'Could not create or access '.$config_storage_dir.'. Grant write access to '.$runtime_user.' and ensure SELinux allows writes.';
			}

			if (!$this->ensureDirectory($logo_storage_dir)) {
				$errors[] = 'Could not create or access '.$logo_storage_dir.'. Grant write access to '.$runtime_user.' and ensure SELinux allows writes.';
			}

			$config = $this->loadConfigFromDir($config_storage_dir);
			if (!is_file($this->getConfigFilePath($config_storage_dir))
					&& is_file($this->getConfigFilePath($logo_storage_dir))) {
				$config = $this->loadConfigFromDir($logo_storage_dir);
			}
			$this->migrateLegacyAssets($config, $config_storage_dir, $logo_storage_dir, $errors);
			$this->healLegacyAssets($config, $config_storage_dir, $logo_storage_dir);

			$logo_types = [
				'logo_main' => ['label' => 'Login page logo'],
				'logo_sidebar' => ['label' => 'Sidebar logo'],
				'logo_compact' => ['label' => 'Compact sidebar icon'],
				'favicon' => ['label' => 'Browser favicon']
			];

			foreach ($logo_types as $logo_key => $meta) {
				if ($this->hasInput('remove_'.$logo_key)) {
					$existing_file = $this->normalizeLogoName($config[$logo_key] ?? null);
					if ($existing_file !== null && is_file($logo_storage_dir.DIRECTORY_SEPARATOR.$existing_file)) {
						@unlink($logo_storage_dir.DIRECTORY_SEPARATOR.$existing_file);
					}
					if ($existing_file !== null && is_file($config_storage_dir.DIRECTORY_SEPARATOR.$existing_file)) {
						@unlink($config_storage_dir.DIRECTORY_SEPARATOR.$existing_file);
					}
					$config[$logo_key] = null;
					continue;
				}

				$upload_error = $this->processUploadedLogo($logo_key, $meta, $logo_storage_dir, $config);
				if ($upload_error !== null) {
					$errors[] = $upload_error;
				}
			}

			$this->persistAssetsToPrimary($config, $logo_storage_dir, $config_storage_dir);

			$config['brand_footer'] = trim($this->getInput('brand_footer', ''));

			$current_help_url = $config['brand_help_url'] ?? '';
			$new_help_url = trim($this->getInput('brand_help_url', ''));
			if ($new_help_url !== '') {
				$scheme = parse_url($new_help_url, PHP_URL_SCHEME);
				$scheme = is_string($scheme) ? strtolower($scheme) : '';

				if (filter_var($new_help_url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
					$errors[] = 'Help URL: only HTTP and HTTPS URLs are allowed.';
					$new_help_url = $current_help_url;
				}
			}
			$config['brand_help_url'] = $new_help_url;
			$config = $this->normalizeConfig($config);

			try {
				$config_json = json_encode(
					$config,
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
				)."\n";
			}
			catch (\JsonException $exception) {
				$config_json = null;
				$errors[] = 'Failed to encode branding settings as JSON.';
				$this->logRuntimeError('Failed to encode branding config', $exception);
			}

			if ($config_json !== null
					&& !$this->writeFileAtomically($this->getConfigFilePath($config_storage_dir), $config_json)) {
				$errors[] = 'Could not write '.$this->getConfigFilePath($config_storage_dir).'. Grant write access to '.$runtime_user.' and ensure SELinux allows writes.';
			}

			$brand_error = $this->writeBrandConf($config, $frontend_root, $logo_storage_url, $runtime_user);
			if ($brand_error !== null) {
				$errors[] = $brand_error;
			}

			if ($errors) {
				CMessageHelper::setErrorTitle(_('Cannot update branding.'));
				foreach ($errors as $error) {
					CMessageHelper::addError($error);
				}
				$response->setFormData($this->getInputAll());
			}
			else {
				CMessageHelper::setSuccessTitle(_('Branding updated successfully. Clear your browser cache to see the changes.'));
			}
		}
		catch (\Throwable $exception) {
			$this->logRuntimeError('Branding update failed', $exception);
			CMessageHelper::setErrorTitle(_('Branding update failed.'));
			CMessageHelper::addError(
				_('Check write access for local/conf and review the web server error log.')
			);
			$response->setFormData($this->getInputAll());
		}

		$this->setResponse($response);
	}

	private function writeBrandConf(array $config, string $frontend_root, string $storage_url, string $runtime_user): ?string {
		$conf_file = $this->getBrandConfFilePath($frontend_root);
		$brand_data = [];

		if (!empty($config['logo_main'])) {
			$brand_data['BRAND_LOGO'] = './'.$storage_url.'/'.$config['logo_main'];
		}
		if (!empty($config['logo_sidebar'])) {
			$brand_data['BRAND_LOGO_SIDEBAR'] = './'.$storage_url.'/'.$config['logo_sidebar'];
		}
		if (!empty($config['logo_compact'])) {
			$brand_data['BRAND_LOGO_SIDEBAR_COMPACT'] = './'.$storage_url.'/'.$config['logo_compact'];
		}
		if (!empty($config['brand_footer'])) {
			$brand_data['BRAND_FOOTER'] = $config['brand_footer'];
		}
		if (!empty($config['brand_help_url'])) {
			$brand_data['BRAND_HELP_URL'] = $config['brand_help_url'];
		}

		if (!$brand_data) {
			if (is_file($conf_file) && !@unlink($conf_file)) {
				return 'Could not remove '.$conf_file.'. Grant write access to '.$runtime_user.' and ensure SELinux allows writes.';
			}

			return null;
		}

		$content = "<?php\n\nreturn ".var_export($brand_data, true).";\n";

		if (!$this->writeFileAtomically($conf_file, $content)) {
			return 'Could not write '.$conf_file.'. Grant write access to '.$runtime_user.' and ensure SELinux allows writes.';
		}

		return null;
	}
}
