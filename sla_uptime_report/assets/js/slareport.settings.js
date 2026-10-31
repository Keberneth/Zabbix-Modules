(function () {
    'use strict';
    function init() {
        var root = document.querySelector('[data-sr-settings]');
        if (!root) { return; }
        var templates = {};
        var next = {};
        root.querySelectorAll('[data-sr-rule-rows]').forEach(function (body) {
            var kind = body.getAttribute('data-sr-rule-rows');
            var row = body.querySelector('[data-sr-rule-row]');
            if (row) { templates[kind] = row.cloneNode(true); }
            next[kind] = body.children.length;
        });

        function freshRow(kind, index) {
            var row = templates[kind].cloneNode(true);
            var nameLabel = row.querySelector('[data-sr-object-name]');
            if (nameLabel) { nameLabel.textContent = ''; }
            row.querySelectorAll('[name]').forEach(function (input) {
                input.name = input.name.replace(/\[\d+\]/, '[' + index + ']');
                if (input.tagName === 'SELECT') { input.value = 'auto'; }
                else if (/\[priority\]$/.test(input.name)) { input.value = '100'; }
                else if (/\[value\]$/.test(input.name)) { input.value = '1'; }
                else if (/\[min\]$/.test(input.name)) { input.value = '200'; }
                else if (/\[max\]$/.test(input.name)) { input.value = '399'; }
                else if (/\[interval_seconds\]$/.test(input.name)) { input.value = '0'; }
                else { input.value = ''; }
            });
            return row;
        }

        function field(row, name) {
            return row.querySelector('[name$="[' + name + ']"]');
        }

        function cloneValues(source) {
            var clone = source.cloneNode(true);
            var sourceFields = source.querySelectorAll('[name]');
            clone.querySelectorAll('[name]').forEach(function (input, index) {
                input.value = sourceFields[index].value;
                if ('checked' in input) { input.checked = sourceFields[index].checked; }
            });
            return clone;
        }

        function importChecks() {
            var textarea = root.querySelector('[data-sr-checks-tsv]');
            var status = root.querySelector('[data-sr-import-status]');
            var body = root.querySelector('[data-sr-rule-rows="hosts"]');
            var list = document.getElementById('sr-settings-hosts');
            if (!textarea || !status || !body || !list || !templates.hosts) { return; }
            try {
                var text = textarea.value.replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n');
                if (text.length > 500000) { throw new Error('The import is too large. Use at most 100 measurement rows.'); }
                var lines = text.split('\n').filter(function (line) { return line.trim() !== ''; });
                if (lines.length && lines[0].split('\t').map(function (cell) { return cell.trim().toLowerCase(); }).join('\t')
                        === 'host name\tmeasurement label\texact item key\thealthy value\tpoll seconds') {
                    lines.shift();
                }
                if (!lines.length || lines.length > 100) { throw new Error('Paste between 1 and 100 measurement rows.'); }
                var ids = new Set();
                var names = new Map();
                Array.prototype.forEach.call(list.options, function (option) {
                    ids.add(option.value);
                    var name = option.textContent.trim();
                    if (!names.has(name)) { names.set(name, []); }
                    names.get(name).push(option.value);
                });
                var importedHosts = new Set();
                var importedPairs = new Set();
                var encoder = new TextEncoder();
                var imported = lines.map(function (line, index) {
                    var cells = line.split('\t');
                    var prefix = 'Row ' + (index + 1) + ': ';
                    if (cells.length !== 5) { throw new Error(prefix + 'expected five tab-separated columns.'); }
                    cells = cells.map(function (cell) { return cell.trim(); });
                    var host = cells[0];
                    var matches = names.get(host) || [];
                    var hostid;
                    if (/^[1-9][0-9]{0,19}$/.test(host) && ids.has(host)) { hostid = host; }
                    else if (matches.length === 1) { hostid = matches[0]; }
                    else if (matches.length > 1) { throw new Error(prefix + 'host name is ambiguous; use its listed ID.'); }
                    else { throw new Error(prefix + 'host name or ID is not in the available host list.'); }
                    if (encoder.encode(cells[1]).length > 255 || /[\x00-\x1f\x7f]/.test(cells[1])) {
                        throw new Error(prefix + 'measurement name must be plain text of at most 255 bytes.');
                    }
                    if (!cells[2] || encoder.encode(cells[2]).length > 2048 || /[\x00-\x1f\x7f]/.test(cells[2])) {
                        throw new Error(prefix + 'provide a valid exact item key.');
                    }
                    if (!/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/.test(cells[3]) || !Number.isFinite(Number(cells[3]))) {
                        throw new Error(prefix + 'healthy value must be a finite number.');
                    }
                    var interval = cells[4] === '' ? '0' : cells[4];
                    if (!/^\d{1,5}$/.test(interval) || Number(interval) > 86400) {
                        throw new Error(prefix + 'poll seconds must be blank or an integer from 0 to 86400.');
                    }
                    var pair = hostid + '\x00' + cells[2];
                    if (importedPairs.has(pair)) { throw new Error(prefix + 'the same host and item key appears twice in this import.'); }
                    importedPairs.add(pair);
                    importedHosts.add(hostid);
                    return { hostid: hostid, label: cells[1], key: cells[2], value: cells[3], interval: String(Number(interval)), pair: pair };
                });

                // Prepare detached clones. Any invalid input leaves every live form field untouched.
                var rows = [];
                var pairs = new Map();
                var replacedAuto = 0;
                body.querySelectorAll('[data-sr-rule-row]').forEach(function (source) {
                    var hostid = field(source, 'hostid').value.trim();
                    var mode = field(source, 'mode').value;
                    var key = field(source, 'key').value.trim();
                    if (hostid && !/^[1-9][0-9]{0,19}$/.test(hostid)) {
                        throw new Error('An existing host measurement has an invalid host ID. Correct it before importing.');
                    }
                    if (hostid && mode === 'auto' && importedHosts.has(hostid)) { replacedAuto++; return; }
                    var clone = cloneValues(source);
                    if (hostid && mode !== 'auto') {
                        if (!key) { throw new Error('An existing host measurement needs an item key. Complete it before importing.'); }
                        var pair = hostid + '\x00' + key;
                        if (pairs.has(pair)) { throw new Error('Existing rows repeat a host and item key. Remove the duplicate before importing.'); }
                        pairs.set(pair, clone);
                    }
                    rows.push(clone);
                });
                imported.forEach(function (measurement) {
                    var row = pairs.get(measurement.pair);
                    if (!row) { row = freshRow('hosts', rows.length); rows.push(row); pairs.set(measurement.pair, row); }
                    field(row, 'hostid').value = measurement.hostid;
                    field(row, 'label').value = measurement.label;
                    field(row, 'key').value = measurement.key;
                    field(row, 'mode').value = 'equals';
                    field(row, 'value').value = measurement.value;
                    field(row, 'interval_seconds').value = measurement.interval;
                    var option = Array.prototype.find.call(list.options, function (item) { return item.value === measurement.hostid; });
                    row.querySelector('[data-sr-object-name]').textContent = option ? option.textContent : '';
                });
                var count = rows.filter(function (row) { return field(row, 'hostid').value.trim() !== ''; }).length;
                root.querySelectorAll('[data-sr-rule-rows="groups"] [name$="[groupid]"]').forEach(function (input) {
                    if (input.value.trim() !== '') { count++; }
                });
                if (count > 100) { throw new Error('This import would exceed the limit of 100 host measurements and group overrides.'); }
                rows.forEach(function (row, index) {
                    row.querySelectorAll('[name]').forEach(function (input) { input.name = input.name.replace(/\[\d+\]/, '[' + index + ']'); });
                });
                body.replaceChildren.apply(body, rows);
                next.hosts = rows.length;
                status.textContent = imported.length + ' measurements added or updated'
                    + (replacedAuto ? '; ' + replacedAuto + ' automatic overrides replaced' : '')
                    + '. Review the rows, then Save shared settings.';
            }
            catch (error) {
                status.textContent = 'Import unchanged: ' + error.message;
            }
        }

        root.addEventListener('click', function (event) {
            var importer = event.target.closest('[data-sr-import-checks]');
            if (importer && !importer.disabled) { importChecks(); return; }
            var remove = event.target.closest('[data-sr-remove-rule]');
            if (remove && !remove.disabled) { remove.closest('[data-sr-rule-row]').remove(); }
            var add = event.target.closest('[data-sr-add-rule]');
            if (!add || add.disabled) { return; }
            var kind = add.getAttribute('data-sr-add-rule');
            if (!templates[kind]) { return; }
            var body = root.querySelector('[data-sr-rule-rows="' + kind + '"]');
            var row = freshRow(kind, next[kind]++);
            body.appendChild(row);
            row.querySelector('input').focus();
        });
        root.addEventListener('input', function (event) {
            var input = event.target;
            if (!input.matches('[list]')) { return; }
            var list = document.getElementById(input.getAttribute('list'));
            var label = input.closest('td').querySelector('[data-sr-object-name]');
            if (!list || !label) { return; }
            var match = Array.prototype.find.call(list.options, function (option) { return option.value === input.value; });
            label.textContent = match ? match.textContent : '';
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();
