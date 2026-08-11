/**
 * SLA & Uptime Report - progressive enhancement only.
 *
 * Everything on the page is rendered server-side and works with JavaScript
 * disabled: the tabs degrade to stacked sections, the filter is a plain GET
 * form, and every chart carries <title> tooltips of its own. This file adds
 * tab switching, a shared chart tooltip and the filter niceties.
 */
(function () {
    'use strict';

    var TABS = ['overview', 'slas', 'availability'];

    function init() {
        var root = document.querySelector('[data-sr-root]');
        if (!root) {
            return;
        }

        initTabs(root);
        initFilter(root);
        initTooltip(root);
    }

    // ---------------------------------------------------------------- tabs

    function initTabs(root) {
        var bar = root.querySelector('[data-sr-tabs]');
        if (!bar) {
            return;
        }

        var buttons = Array.prototype.slice.call(bar.querySelectorAll('[data-sr-tab]'));
        var panels = Array.prototype.slice.call(root.querySelectorAll('[data-sr-panel]'));

        // The tab buttons are real submit buttons bound to the filter form, so
        // they work without this script. With the script present we switch in
        // place instead, and keep the form's existing hidden field in sync so
        // Apply returns to the same tab. Reuse it rather than appending a
        // second one, which would submit filter_tab twice.
        var form = root.querySelector('[data-sr-filter]');
        var carrier = form ? form.querySelector('[data-sr-tab-input]') : null;

        function activate(name, push) {
            if (TABS.indexOf(name) === -1) {
                name = TABS[0];
            }

            buttons.forEach(function (button) {
                var on = button.getAttribute('data-sr-tab') === name;
                button.classList.toggle('is-active', on);
                button.setAttribute('aria-selected', on ? 'true' : 'false');
                button.setAttribute('tabindex', on ? '0' : '-1');
            });

            panels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-sr-panel') !== name;
            });

            // Keep the tab in the URL and in the filter form, so a reload or an
            // Apply comes back to the same place.
            if (carrier) {
                carrier.value = name;
            }

            if (push && window.history && window.history.replaceState) {
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.set('filter_tab', name);
                    window.history.replaceState({}, '', url.toString());
                }
                catch (e) {
                    /* A URL the browser will not parse is not worth failing over. */
                }
            }
        }

        bar.addEventListener('click', function (event) {
            var button = event.target.closest('[data-sr-tab]');
            if (button) {
                // Everything is already on the page, so switching needs no
                // round-trip. Suppress the form submit the button would do.
                event.preventDefault();
                activate(button.getAttribute('data-sr-tab'), true);
            }
        });

        // Left/right arrows move between tabs, as expected of a tablist.
        bar.addEventListener('keydown', function (event) {
            var keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End'];
            if (keys.indexOf(event.key) === -1) {
                return;
            }

            var current = buttons.findIndex(function (button) {
                return button.classList.contains('is-active');
            });
            if (current === -1) {
                return;
            }

            var next = event.key === 'Home' ? 0
                : (event.key === 'End' ? buttons.length - 1
                    : (event.key === 'ArrowRight'
                        ? (current + 1) % buttons.length
                        : (current - 1 + buttons.length) % buttons.length));

            event.preventDefault();
            activate(buttons[next].getAttribute('data-sr-tab'), true);
            buttons[next].focus();
        });

        activate(root.getAttribute('data-sr-initial-tab') || TABS[0], false);
    }

    // -------------------------------------------------------------- filter

    function initFilter(root) {
        var form = root.querySelector('[data-sr-filter]');
        if (!form) {
            return;
        }

        // Only show the date inputs that the selected period mode actually uses.
        var mode = form.querySelector('#sr_mode');
        if (mode) {
            var conditional = Array.prototype.slice.call(form.querySelectorAll('[data-sr-modes]'));
            var applyMode = function () {
                conditional.forEach(function (field) {
                    var modes = (field.getAttribute('data-sr-modes') || '').split(',');
                    field.classList.toggle('sr-field--hidden', modes.indexOf(mode.value) === -1);
                });
            };
            mode.addEventListener('change', applyMode);
            applyMode();
        }

        // Pills reflect their hidden checkbox.
        Array.prototype.slice.call(form.querySelectorAll('.sr-chip input')).forEach(function (input) {
            var chip = input.closest('.sr-chip');
            var sync = function () {
                chip.classList.toggle('is-on', input.checked);
            };
            input.addEventListener('change', sync);
            sync();
        });

        // Long chip lists get a text box that filters them client-side.
        Array.prototype.slice.call(form.querySelectorAll('[data-sr-chip-search]')).forEach(function (input) {
            var chips = document.getElementById(input.getAttribute('data-sr-chip-search'));
            if (!chips) {
                return;
            }
            input.addEventListener('input', function () {
                var needle = input.value.toLowerCase();
                Array.prototype.slice.call(chips.querySelectorAll('.sr-chip')).forEach(function (chip) {
                    var keep = needle === ''
                        || chip.textContent.toLowerCase().indexOf(needle) !== -1
                        || chip.querySelector('input').checked;
                    chip.style.display = keep ? '' : 'none';
                });
            });
        });

        // The advanced row ships expanded so it still works without JavaScript;
        // collapse it here unless the user already has one of those filters set.
        var advancedToggle = form.querySelector('[data-sr-advanced-toggle]');
        var advanced = form.querySelector('[data-sr-advanced]');
        if (advancedToggle && advanced) {
            var sync = function (open) {
                advanced.classList.toggle('is-collapsed', !open);
                advancedToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                advancedToggle.textContent = advancedToggle.getAttribute(
                    open ? 'data-label-less' : 'data-label-more'
                );
            };

            sync(advanced.getAttribute('data-sr-advanced') === 'open');

            advancedToggle.addEventListener('click', function () {
                sync(advanced.classList.contains('is-collapsed'));
            });
        }
    }

    // ------------------------------------------------------------- tooltip

    function initTooltip(root) {
        var tip = document.createElement('div');
        tip.className = 'sr-tooltip';
        tip.setAttribute('role', 'presentation');
        document.body.appendChild(tip);

        var hide = function () {
            tip.classList.remove('is-on');
        };

        root.addEventListener('mouseover', function (event) {
            var mark = event.target.closest('[data-tip]');
            if (!mark || !root.contains(mark)) {
                return;
            }

            tip.textContent = mark.getAttribute('data-tip');
            tip.classList.add('is-on');
            place(tip, event);
        });

        root.addEventListener('mousemove', function (event) {
            if (tip.classList.contains('is-on')) {
                place(tip, event);
            }
        });

        root.addEventListener('mouseout', function (event) {
            var mark = event.target.closest('[data-tip]');
            if (mark) {
                hide();
            }
        });

        window.addEventListener('scroll', hide, true);
    }

    function place(tip, event) {
        var pad = 14;
        var box = tip.getBoundingClientRect();
        var x = event.clientX + pad;
        var y = event.clientY + pad;

        if (x + box.width > window.innerWidth - 8) {
            x = event.clientX - box.width - pad;
        }
        if (y + box.height > window.innerHeight - 8) {
            y = event.clientY - box.height - pad;
        }

        tip.style.left = Math.max(8, x) + 'px';
        tip.style.top = Math.max(8, y) + 'px';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    }
    else {
        init();
    }
})();
