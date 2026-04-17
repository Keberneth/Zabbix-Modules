(function () {
    'use strict';

    var DATA_URL = 'zabbix.php?action=motd.banner.data';
    var ROOT_ID = 'motd-banner-root';
    var EXPANDED_KEY = 'zbx_motd_banner_expanded_v1';
    var REFRESH_MS = 60000;

    var refreshTimer = null;

    function init() {
        if (window.location && window.location.search.indexOf('action=motd.banner.data') !== -1) {
            return;
        }

        fetchAndRender();

        if (refreshTimer) {
            clearInterval(refreshTimer);
        }
        refreshTimer = setInterval(function () {
            if (document.hidden) {
                return;
            }
            fetchAndRender();
        }, REFRESH_MS);

        window.addEventListener('beforeunload', function () {
            if (refreshTimer) {
                clearInterval(refreshTimer);
                refreshTimer = null;
            }
        });
    }

    function fetchAndRender() {
        fetch(DATA_URL + '&_=' + Date.now(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(handleJsonResponse)
            .then(function (response) {
                if (!response || response.ok !== true || !response.data || typeof response.data !== 'object') {
                    return;
                }

                render(response.data);
            })
            .catch(function () {
                // Fail silently. The banner is convenience UI and should never disrupt frontend usage.
            });
    }

    function render(data) {
        var target = findMountTarget();

        if (!target) {
            return;
        }

        var existing = document.getElementById(ROOT_ID);
        if (existing && existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }

        var root = document.createElement('div');
        root.id = ROOT_ID;

        var banner = document.createElement('section');
        banner.className = 'motd-banner';
        root.appendChild(banner);

        var header = document.createElement('div');
        header.className = 'motd-banner__header';
        banner.appendChild(header);

        var titleWrap = document.createElement('div');
        header.appendChild(titleWrap);

        var title = document.createElement('div');
        title.className = 'motd-banner__title';
        title.textContent = data.title || 'Today\'s Reminder';
        titleWrap.appendChild(title);

        var meta = document.createElement('div');
        meta.className = 'motd-banner__meta';
        meta.textContent = buildMetaText(data);
        titleWrap.appendChild(meta);

        var summary = document.createElement('div');
        summary.className = 'motd-banner__summary';
        summary.textContent = data.summary_line || '';
        titleWrap.appendChild(summary);

        var actions = document.createElement('div');
        actions.className = 'motd-banner__actions';
        header.appendChild(actions);

        if (data.links && data.links.module) {
            var detailsLink = document.createElement('a');
            detailsLink.href = data.links.module;
            detailsLink.textContent = 'Open details';
            actions.appendChild(detailsLink);
        }

        var toggleButton = document.createElement('button');
        toggleButton.type = 'button';
        actions.appendChild(toggleButton);

        var body = document.createElement('div');
        body.className = 'motd-banner__body';
        banner.appendChild(body);

        if (Array.isArray(data.chips) && data.chips.length) {
            var bannerKinds = ['critical', 'high', 'unacked', 'suppressed', 'unreachable', 'queue', 'stale'];
            var bannerChips = data.chips.filter(function (chip) {
                return chip && bannerKinds.indexOf(chip.kind) !== -1;
            });
            if (bannerChips.length) {
                body.appendChild(buildChipRow(bannerChips));
            }
        }

        if (Array.isArray(data.banner_items) && data.banner_items.length) {
            body.appendChild(buildItemList(data.banner_items));
        }

        insertRoot(target, root);

        var fingerprint = String(data.fingerprint || 'motd');
        var expanded = sessionStorage.getItem(EXPANDED_KEY) === fingerprint;

        setCollapsed(banner, toggleButton, !expanded);

        toggleButton.addEventListener('click', function () {
            var nextCollapsed = !banner.classList.contains('motd-banner--collapsed');
            setCollapsed(banner, toggleButton, nextCollapsed);

            if (nextCollapsed) {
                sessionStorage.removeItem(EXPANDED_KEY);
            }
            else {
                sessionStorage.setItem(EXPANDED_KEY, fingerprint);
            }
        });
    }

    function buildMetaText(data) {
        var parts = [];

        if (data.generated_at_text) {
            parts.push('Updated ' + data.generated_at_text);
        }
        if (data.timezone) {
            parts.push(data.timezone);
        }

        return parts.join(' · ');
    }

    function buildChipRow(chips) {
        var row = document.createElement('div');
        row.className = 'motd-chip-row';

        chips.forEach(function (chip) {
            var tagName = chip.url ? 'a' : 'span';
            var el = document.createElement(tagName);
            el.className = 'motd-chip motd-chip--' + (chip.kind || 'info');

            if (chip.url) {
                el.href = chip.url;
            }

            var label = document.createElement('span');
            label.className = 'motd-chip__label';
            label.textContent = chip.label || '';
            el.appendChild(label);

            var value = document.createElement('span');
            value.className = 'motd-chip__value';
            value.textContent = chip.value || '';
            el.appendChild(value);

            row.appendChild(el);
        });

        return row;
    }

    function buildItemList(items) {
        var list = document.createElement('ul');
        list.className = 'motd-banner__list';

        items.forEach(function (item) {
            var li = document.createElement('li');

            if (item.url) {
                var link = document.createElement('a');
                link.href = item.url;
                link.textContent = item.text || '';
                li.appendChild(link);
            }
            else {
                li.textContent = item.text || '';
            }

            list.appendChild(li);
        });

        return list;
    }

    function setCollapsed(banner, toggleButton, collapsed) {
        if (collapsed) {
            banner.classList.add('motd-banner--collapsed');
            toggleButton.textContent = 'Expand';
        }
        else {
            banner.classList.remove('motd-banner--collapsed');
            toggleButton.textContent = 'Collapse';
        }
    }

    function findMountTarget() {
        var selectors = [
            'main',
            '.wrapper main',
            '.layout-wrapper main',
            '#page-content',
            '#content',
            '.wrapper',
            'body'
        ];

        for (var i = 0; i < selectors.length; i++) {
            var node = document.querySelector(selectors[i]);
            if (node) {
                return node;
            }
        }

        return null;
    }

    function insertRoot(target, root) {
        if (target === document.body) {
            target.insertBefore(root, target.firstChild);
            return;
        }

        target.insertBefore(root, target.firstChild);
    }

    function handleJsonResponse(response) {
        return response.text().then(function (text) {
            var parsed = parseJsonSafe(text);

            if (parsed && typeof parsed === 'object' && typeof parsed.main_block === 'string') {
                var inner = parseJsonSafe(parsed.main_block);
                if (inner) {
                    parsed = inner;
                }
            }

            if (!parsed) {
                parsed = { ok: false, error: text || 'Invalid JSON response.' };
            }

            return parsed;
        });
    }

    function parseJsonSafe(text) {
        try {
            return JSON.parse(text);
        }
        catch (error) {
            return null;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    }
    else {
        init();
    }
})();
