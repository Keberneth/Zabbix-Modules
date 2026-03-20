function errorString(e) {
    if (typeof e === 'string') {
        return e;
    }

    if (typeof e === 'object' && e !== null) {
        if (typeof e.message === 'string' && e.message !== '') {
            return e.message;
        }

        if (typeof e.title === 'string' && e.title !== '') {
            var details = [];

            if (Array.isArray(e.messages) && e.messages.length > 0) {
                details.push(e.messages.join('; '));
            }
            else if (typeof e.messages === 'string' && e.messages !== '') {
                details.push(e.messages);
            }

            if (typeof e.description === 'string' && e.description !== '') {
                details.push(e.description);
            }

            return e.title + (details.length > 0 ? ': ' + details.join(' | ') : '');
        }

        if (Array.isArray(e.messages) && e.messages.length > 0) {
            return e.messages.join('; ');
        }

        try {
            return JSON.stringify(e);
        }
        catch (_) {}
    }

    return String(e);
}

function isUnresolvedMacro(value) {
    return /^\{[A-Z0-9_.]+\}$/.test(String(value || '').trim());
}

function parseJsonSafe(value) {
    try {
        return JSON.parse(value);
    }
    catch (_) {
        return null;
    }
}

function normalizeTags(params) {
    if (params.event_tags_json) {
        var rawJson = String(params.event_tags_json).trim();

        if (rawJson !== '' && !isUnresolvedMacro(rawJson)) {
            var parsedJson = parseJsonSafe(rawJson);

            if (Array.isArray(parsedJson)) {
                return parsedJson;
            }

            Zabbix.log(4, '[AI Troubleshooter] event_tags_json is not a JSON array, falling back to plain tags.');
        }
    }

    if (params.event_tags) {
        var rawTags = String(params.event_tags).trim();

        if (rawTags !== '' && !isUnresolvedMacro(rawTags)) {
            return rawTags;
        }
    }

    return [];
}

function unwrapModuleResponse(responseText) {
    var parsed = parseJsonSafe(responseText);

    if (parsed !== null && typeof parsed === 'object' && typeof parsed.main_block === 'string') {
        var inner = parseJsonSafe(parsed.main_block);

        if (inner !== null) {
            parsed = inner;
        }
    }

    return parsed;
}

function extractModuleError(parsed) {
    if (parsed === null || parsed === undefined) {
        return 'Empty response from module.';
    }

    if (parsed === false || parsed === true) {
        return 'Unexpected boolean response: ' + String(parsed);
    }

    if (typeof parsed !== 'object') {
        return String(parsed);
    }

    if (Object.prototype.hasOwnProperty.call(parsed, 'error')) {
        return errorString(parsed.error);
    }

    if (Object.prototype.hasOwnProperty.call(parsed, 'errors')) {
        return errorString(parsed.errors);
    }

    if (Object.prototype.hasOwnProperty.call(parsed, 'response')) {
        return errorString(parsed.response);
    }

    return errorString(parsed);
}

try {
    var params = JSON.parse(value);

    if (!params.ai_webhook_url) {
        throw 'Missing parameter: ai_webhook_url';
    }
    if (!params.eventid) {
        throw 'Missing parameter: eventid';
    }
    if (!params.trigger_name) {
        throw 'Missing parameter: trigger_name';
    }
    if (!params.hostname) {
        throw 'Missing parameter: hostname';
    }

    var body = {
        eventid: params.eventid,
        event_value: params.event_value || '1',
        trigger_name: params.trigger_name,
        hostname: params.hostname,
        severity: params.severity || '',
        opdata: params.opdata || '',
        event_url: params.event_url || ''
    };

    var tags = normalizeTags(params);
    if (!(Array.isArray(tags) && tags.length === 0)) {
        body.event_tags = tags;
    }

    if (params.shared_secret && String(params.shared_secret).trim() !== '') {
        body.shared_secret = String(params.shared_secret).trim();
    }

    var request = new HttpRequest();
    request.addHeader('Content-Type: application/json');
    request.addHeader('Accept: application/json');

    if (params.shared_secret && String(params.shared_secret).trim() !== '') {
        request.addHeader('X-AI-Webhook-Secret: ' + String(params.shared_secret).trim());
    }

    Zabbix.log(4, '[AI Troubleshooter] POST ' + params.ai_webhook_url);

    var resp = request.post(params.ai_webhook_url, JSON.stringify(body));
    var code = request.getStatus();

    Zabbix.log(4, '[AI Troubleshooter] HTTP ' + code + ' response length=' + String(resp || '').length);

    if (code < 200 || code >= 300) {
        throw 'HTTP ' + code + ': ' + String(resp || '').substring(0, 500);
    }

    var parsed = unwrapModuleResponse(resp);

    if (parsed === null) {
        throw 'Invalid JSON response: ' + String(resp || '').substring(0, 500);
    }

    if (!parsed.ok) {
        throw 'Module error: ' + extractModuleError(parsed);
    }

    Zabbix.log(4, '[AI Troubleshooter] OK, posted_chunks=' + (parsed.posted_chunks || 0));

    return JSON.stringify({
        ok: true,
        posted_chunks: parsed.posted_chunks || 0
    });
}
catch (error) {
    var msg = errorString(error);
    Zabbix.log(3, '[AI Troubleshooter] FAILED: ' + msg);
    throw 'AI webhook failed: ' + msg;
}
