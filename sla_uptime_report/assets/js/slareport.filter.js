/**
 * SLA & Uptime Report — filter UX.
 *
 * Shows only the fields relevant to the selected period mode, mirroring the per-mode field
 * visibility used by the other report modules. Fields opt in by carrying a
 * data-slareport-mode="<mode>" attribute (space-separated for multi-mode); the active mode is read
 * from the checked filter_mode radio. No dependencies beyond the DOM.
 */
(function () {
	'use strict';

	function activeMode() {
		var checked = document.querySelector('input[name="filter_mode"]:checked');

		return checked ? checked.value : 'prev_month';
	}

	function applyModeVisibility() {
		var mode = activeMode();
		var nodes = document.querySelectorAll('[data-slareport-mode]');

		for (var i = 0; i < nodes.length; i++) {
			var modes = (nodes[i].getAttribute('data-slareport-mode') || '').split(/\s+/);
			nodes[i].style.display = (modes.indexOf(mode) === -1) ? 'none' : '';
		}
	}

	document.addEventListener('change', function (event) {
		if (event.target && event.target.name === 'filter_mode') {
			applyModeVisibility();
		}
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', applyModeVisibility);
	}
	else {
		applyModeVisibility();
	}
})();
