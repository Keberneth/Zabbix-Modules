CREATE TABLE IF NOT EXISTS module_healthcheck_run (
    runid VARCHAR(64) PRIMARY KEY,
    checkid VARCHAR(128) NOT NULL,
    check_name VARCHAR(255) NOT NULL,
    started_at BIGINT NOT NULL,
    finished_at BIGINT NOT NULL,
    duration_ms INTEGER NOT NULL,
    status INTEGER NOT NULL,
    summary VARCHAR(512) NULL,
    error_text TEXT NULL,
    api_version VARCHAR(64) NULL,
    hosts_count INTEGER NULL,
    triggers_count INTEGER NULL,
    items_count INTEGER NULL,
    freshest_age_sec INTEGER NULL,
    ping_sent INTEGER NULL,
    ping_http_status INTEGER NULL,
    ping_latency_ms INTEGER NULL
);

CREATE INDEX IF NOT EXISTS idx_module_healthcheck_run_checkid_started_at
    ON module_healthcheck_run (checkid, started_at DESC);
CREATE INDEX IF NOT EXISTS idx_module_healthcheck_run_status_started_at
    ON module_healthcheck_run (status, started_at DESC);
CREATE INDEX IF NOT EXISTS idx_module_healthcheck_run_started_at
    ON module_healthcheck_run (started_at DESC);

CREATE TABLE IF NOT EXISTS module_healthcheck_run_step (
    stepid VARCHAR(64) PRIMARY KEY,
    runid VARCHAR(64) NOT NULL,
    checkid VARCHAR(128) NOT NULL,
    step_key VARCHAR(64) NOT NULL,
    step_label VARCHAR(128) NOT NULL,
    step_order INTEGER NOT NULL,
    status INTEGER NOT NULL,
    started_at BIGINT NOT NULL,
    finished_at BIGINT NOT NULL,
    duration_ms INTEGER NOT NULL,
    metric_value VARCHAR(255) NULL,
    detail_text TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_module_healthcheck_run_step_runid_order
    ON module_healthcheck_run_step (runid, step_order);
CREATE INDEX IF NOT EXISTS idx_module_healthcheck_run_step_checkid_started_at
    ON module_healthcheck_run_step (checkid, started_at DESC);
CREATE INDEX IF NOT EXISTS idx_module_healthcheck_run_step_started_at
    ON module_healthcheck_run_step (started_at DESC);
