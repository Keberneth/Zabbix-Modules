<?php

/**
 * Rebrand module - Branding configuration view script.
 *
 * @var CView $this
 */

use Modules\Rebrand\Lib\ServerNameOverride;
?>

<script>
	(() => {
		const form = document.forms['rebrand-form'];
		const server_name = form.elements['server_name'];
		const custom_mode = <?= json_encode(ServerNameOverride::MODE_CUSTOM) ?>;

		// The name is only used by the Custom mode. A disabled field is not posted,
		// so the saved name is kept while another mode is selected.
		const update = () => {
			server_name.disabled = form.elements['server_name_mode'].value !== custom_mode;
		};

		form.addEventListener('change', (e) => {
			if (e.target.name === 'server_name_mode') {
				update();

				if (!server_name.disabled) {
					server_name.focus();
				}
			}
		});

		// Re-sync after the browser restores form state on back/forward navigation.
		update();
	})();
</script>
