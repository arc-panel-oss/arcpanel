-- ArcPanel panel database — $ARCPANEL/data/arcpanel/arcpanel.sqlite
--
-- Reference DDL for docs/design/15-database-schema-and-erd.md §4.
-- Panel-owned operational data only (E15–E33). Hosting state stays in the
-- Hestia flat files and is never copied here. Laravel migrations in
-- web-app/database/migrations/ must produce exactly this schema; a Pest test
-- diffs sqlite_master against this file after `migrate:fresh`.
--
-- Conventions (15 §4.1): TEXT timestamps 'YYYY-MM-DD HH:MM:SS' in UTC;
-- INTEGER booleans with CHECK (x IN (0, 1)); TEXT enumerations with CHECK;
-- `user` and `owner` columns on every tenant-scoped table (natural keys of
-- the flat files, reconciled nightly, never foreign keys); no secrets.
--
-- Connection pragmas, set by the Laravel sqlite connection:
--   PRAGMA journal_mode = WAL;
--   PRAGMA synchronous = NORMAL;
--   PRAGMA foreign_keys = ON;
--   PRAGMA busy_timeout = 5000;
-- ============================================================================
-- UP
-- ============================================================================
BEGIN;

-- ----------------------------------------------------------------------------
-- E15 — Laravel framework tables (migration ledger, queue, batches, failures)
-- ----------------------------------------------------------------------------
CREATE TABLE migrations (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	migration TEXT NOT NULL,
	batch INTEGER NOT NULL
);

-- Laravel `database` queue driver. Rows exist only while a job is queued or
-- reserved; the durable record is jobs_log.
CREATE TABLE jobs (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	queue TEXT NOT NULL,
	payload TEXT NOT NULL,
	attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
	reserved_at INTEGER,
	available_at INTEGER NOT NULL,
	created_at INTEGER NOT NULL
);

CREATE INDEX idx_jobs_queue ON jobs (queue);

CREATE TABLE job_batches (
	id TEXT PRIMARY KEY,
	name TEXT NOT NULL,
	total_jobs INTEGER NOT NULL CHECK (total_jobs >= 0),
	pending_jobs INTEGER NOT NULL CHECK (pending_jobs >= 0),
	failed_jobs INTEGER NOT NULL CHECK (failed_jobs >= 0),
	failed_job_ids TEXT NOT NULL,
	options TEXT,
	cancelled_at INTEGER,
	created_at INTEGER NOT NULL,
	finished_at INTEGER
);

CREATE TABLE failed_jobs (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	uuid TEXT NOT NULL UNIQUE,
	connection TEXT NOT NULL,
	queue TEXT NOT NULL,
	payload TEXT NOT NULL,
	exception TEXT NOT NULL,
	failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- E15 — Job Center
-- ----------------------------------------------------------------------------
CREATE TABLE jobs_log (
	id TEXT PRIMARY KEY CHECK (length (id) = 26), -- ULID; also the log file name
	type TEXT NOT NULL, -- backup.user, docker.install, node.build, …
	user TEXT NOT NULL, -- effective hosting user; 'admin' for server jobs
	owner TEXT NOT NULL, -- owner of `user` at dispatch (ROOT_USER or reseller)
	actor TEXT NOT NULL, -- login user, 'api:<key id>', 'cli', 'cron'
	actor_role TEXT NOT NULL CHECK (
		actor_role IN ('admin', 'reseller', 'user', 'system')
	),
	state TEXT NOT NULL DEFAULT 'queued' CHECK (
		state IN (
			'queued',
			'running',
			'done',
			'failed',
			'cancelled'
		)
	),
	command TEXT NOT NULL, -- masked argv as ArcCommand logs it
	exit_code INTEGER CHECK (
		exit_code IS NULL
		OR exit_code BETWEEN 0 AND 255
	),
	output_path TEXT, -- data/arcpanel/jobs/<id>.log
	retryable INTEGER NOT NULL DEFAULT 0 CHECK (retryable IN (0, 1)),
	retry_of TEXT REFERENCES jobs_log (id) ON DELETE SET NULL, -- the job this one retried
	parent_id TEXT REFERENCES jobs_log (id) ON DELETE CASCADE, -- bulk parent (E21)
	queue_job_id INTEGER, -- jobs.id while queued (not a FK: row disappears)
	pid INTEGER,
	heartbeat_at TEXT, -- refreshed every 30 s while running
	queued_at TEXT NOT NULL,
	started_at TEXT,
	finished_at TEXT,
	created_at TEXT NOT NULL,
	updated_at TEXT NOT NULL,
	CHECK (
		started_at IS NULL
		OR started_at >= queued_at
	),
	CHECK (
		finished_at IS NULL
		OR started_at IS NOT NULL
	)
);

CREATE INDEX idx_jobs_log_user_created ON jobs_log (user, created_at DESC);

CREATE INDEX idx_jobs_log_owner_created ON jobs_log (owner, created_at DESC);

CREATE INDEX idx_jobs_log_state_heartbeat ON jobs_log (state, heartbeat_at);

CREATE INDEX idx_jobs_log_type_created ON jobs_log (type, created_at DESC);

CREATE INDEX idx_jobs_log_parent ON jobs_log (parent_id);

CREATE INDEX idx_jobs_log_retry_of ON jobs_log (retry_of);

-- ----------------------------------------------------------------------------
-- E16 — Alerts
-- ----------------------------------------------------------------------------
CREATE TABLE alerts_history (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	rule TEXT NOT NULL, -- RULE of data/alerts/rules.conf
	scope TEXT NOT NULL CHECK (scope IN ('system', 'user', 'domain')),
	object TEXT NOT NULL, -- 'system', 'user:<u>', 'web:<domain>', 'mail:<domain>'
	user TEXT, -- NULL for system alerts
	owner TEXT,
	severity TEXT NOT NULL CHECK (severity IN ('info', 'warning', 'critical')),
	state TEXT NOT NULL DEFAULT 'firing' CHECK (state IN ('firing', 'resolved')),
	value TEXT, -- observed value, e.g. '93%'
	message TEXT NOT NULL,
	channels TEXT NOT NULL DEFAULT '[]' CHECK (json_valid (channels)),
	fired_at TEXT NOT NULL,
	resolved_at TEXT,
	last_notified_at TEXT,
	notify_count INTEGER NOT NULL DEFAULT 0 CHECK (notify_count >= 0),
	created_at TEXT NOT NULL,
	updated_at TEXT NOT NULL,
	CHECK ((state = 'resolved') = (resolved_at IS NOT NULL))
);

CREATE UNIQUE INDEX uq_alerts_open ON alerts_history (rule, object)
WHERE
	state = 'firing';

CREATE INDEX idx_alerts_state_fired ON alerts_history (state, fired_at DESC);

CREATE INDEX idx_alerts_user_fired ON alerts_history (user, fired_at DESC);

CREATE INDEX idx_alerts_owner_fired ON alerts_history (owner, fired_at DESC);

-- ----------------------------------------------------------------------------
-- E23 — Audit index (projection of data/audit/YYYY-MM-DD.jsonl)
-- ----------------------------------------------------------------------------
CREATE TABLE audit_index (
	id TEXT PRIMARY KEY CHECK (length (id) = 26), -- event ULID
	ts TEXT NOT NULL,
	actor TEXT NOT NULL, -- login user, api key id, 'cli', 'collab:<login>'
	actor_kind TEXT NOT NULL CHECK (
		actor_kind IN ('user', 'api', 'cli', 'collab', 'system')
	),
	effective_user TEXT NOT NULL,
	owner TEXT,
	role TEXT CHECK (
		role IS NULL
		OR role IN ('admin', 'reseller', 'user', 'dns-cluster')
	),
	ip TEXT,
	action TEXT NOT NULL, -- capability key, e.g. web.domains.create
	object_type TEXT, -- web, dns, mail, db, cron, user, package, port, job, …
	object_id TEXT,
	result TEXT NOT NULL, -- 'ok', 'denied', 'error:E_LIMIT'
	job_id TEXT REFERENCES jobs_log (id) ON DELETE SET NULL,
	file_date TEXT NOT NULL CHECK (length (file_date) = 10), -- YYYY-MM-DD of the JSONL file
	file_offset INTEGER NOT NULL CHECK (file_offset >= 0),
	hash TEXT NOT NULL, -- sha256 of the chained line
	created_at TEXT NOT NULL
);

CREATE UNIQUE INDEX uq_audit_file_pos ON audit_index (file_date, file_offset);

CREATE INDEX idx_audit_ts ON audit_index (ts);

CREATE INDEX idx_audit_effective_user_ts ON audit_index (effective_user, ts);

CREATE INDEX idx_audit_owner_ts ON audit_index (owner, ts);

CREATE INDEX idx_audit_actor_ts ON audit_index (actor, ts);

CREATE INDEX idx_audit_action_ts ON audit_index (action, ts);

CREATE INDEX idx_audit_object_ts ON audit_index (object_type, object_id, ts);

CREATE INDEX idx_audit_job ON audit_index (job_id);

-- ----------------------------------------------------------------------------
-- E33 — Update Center history
-- ----------------------------------------------------------------------------
CREATE TABLE update_history (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	component TEXT NOT NULL CHECK (
		component IN (
			'panel',
			'os',
			'php',
			'mariadb',
			'postgresql',
			'docker_app',
			'node_global',
			'wordpress',
			'system_package'
		)
	),
	item TEXT, -- slug, domain or package name; NULL for os/panel
	user TEXT, -- owner of a docker_app/node_global/wordpress row
	from_version TEXT,
	to_version TEXT,
	security INTEGER NOT NULL DEFAULT 0 CHECK (security IN (0, 1)),
	trigger TEXT NOT NULL CHECK (trigger IN ('manual', 'policy', 'cron')),
	policy TEXT, -- POLICY value of data/updates/policy.conf
	actor TEXT NOT NULL,
	job_id TEXT REFERENCES jobs_log (id) ON DELETE SET NULL,
	snapshot_id TEXT, -- v-add-sys-snapshot id taken before the update
	result TEXT NOT NULL CHECK (
		result IN ('ok', 'failed', 'rolled_back', 'skipped')
	),
	detail TEXT,
	started_at TEXT NOT NULL,
	finished_at TEXT,
	created_at TEXT NOT NULL
);

CREATE INDEX idx_update_component_started ON update_history (component, started_at DESC);

CREATE INDEX idx_update_user_started ON update_history (user, started_at DESC);

CREATE INDEX idx_update_job ON update_history (job_id);

-- ----------------------------------------------------------------------------
-- E29 — Outgoing webhooks
-- ----------------------------------------------------------------------------
CREATE TABLE webhooks (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	user TEXT NOT NULL,
	owner TEXT NOT NULL,
	name TEXT NOT NULL,
	url TEXT NOT NULL, -- https only; private targets refused by the service
	events TEXT NOT NULL CHECK (json_valid (events)), -- JSON list of public event names
	secret_ref TEXT NOT NULL, -- data/secrets/<user>/webhooks/<id>
	enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
	failure_count INTEGER NOT NULL DEFAULT 0 CHECK (failure_count >= 0),
	disabled_reason TEXT,
	created_by TEXT NOT NULL,
	created_at TEXT NOT NULL,
	updated_at TEXT NOT NULL
);

CREATE UNIQUE INDEX uq_webhooks_user_name ON webhooks (user, name);

CREATE INDEX idx_webhooks_owner ON webhooks (owner);

CREATE TABLE webhook_deliveries (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	webhook_id INTEGER NOT NULL REFERENCES webhooks (id) ON DELETE CASCADE,
	event_id TEXT NOT NULL, -- audit_index.id (logical reference; audit rows are pruned independently)
	event TEXT NOT NULL, -- public event name, e.g. domain.added
	attempt INTEGER NOT NULL DEFAULT 1 CHECK (attempt BETWEEN 1 AND 5),
	state TEXT NOT NULL DEFAULT 'pending' CHECK (
		state IN ('pending', 'delivered', 'failed', 'dead')
	),
	status_code INTEGER CHECK (
		status_code IS NULL
		OR status_code BETWEEN 100 AND 599
	),
	response_excerpt TEXT, -- first 1 KB of the response body
	error TEXT,
	sent_at TEXT,
	next_attempt_at TEXT,
	created_at TEXT NOT NULL,
	updated_at TEXT NOT NULL
);

CREATE INDEX idx_whd_webhook_created ON webhook_deliveries (webhook_id, created_at DESC);

CREATE INDEX idx_whd_state_next ON webhook_deliveries (state, next_attempt_at);

CREATE INDEX idx_whd_event ON webhook_deliveries (event_id);

-- ----------------------------------------------------------------------------
-- E24 — WAF events (newest 100 per domain are kept)
-- ----------------------------------------------------------------------------
CREATE TABLE waf_events (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	user TEXT NOT NULL,
	owner TEXT NOT NULL,
	domain TEXT NOT NULL,
	ts TEXT NOT NULL,
	client_ip TEXT NOT NULL,
	method TEXT,
	path TEXT,
	rule_id TEXT,
	severity TEXT,
	mode TEXT NOT NULL CHECK (mode IN ('detect', 'block')),
	message TEXT,
	created_at TEXT NOT NULL
);

CREATE INDEX idx_waf_domain_ts ON waf_events (domain, ts DESC);

CREATE INDEX idx_waf_user_ts ON waf_events (user, ts DESC);

CREATE INDEX idx_waf_owner_ts ON waf_events (owner, ts DESC);

-- ----------------------------------------------------------------------------
-- E27 — Mail log index (one row per delivery line of the Exim main log)
-- ----------------------------------------------------------------------------
CREATE TABLE mail_index (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	day TEXT NOT NULL CHECK (length (day) = 10), -- YYYY-MM-DD of the log line
	user TEXT NOT NULL,
	owner TEXT NOT NULL,
	domain TEXT NOT NULL, -- panel mail domain used for scoping
	message_id TEXT NOT NULL,
	exim_id TEXT,
	ts TEXT NOT NULL,
	direction TEXT NOT NULL CHECK (direction IN ('in', 'out')),
	sender TEXT,
	recipient TEXT,
	status TEXT NOT NULL CHECK (
		status IN (
			'delivered',
			'deferred',
			'bounced',
			'rejected',
			'queued'
		)
	),
	log_offset INTEGER NOT NULL CHECK (log_offset >= 0),
	created_at TEXT NOT NULL
);

CREATE UNIQUE INDEX uq_mail_day_offset ON mail_index (day, log_offset);

CREATE INDEX idx_mail_domain_ts ON mail_index (domain, ts DESC);

CREATE INDEX idx_mail_user_ts ON mail_index (user, ts DESC);

CREATE INDEX idx_mail_message_id ON mail_index (message_id);

CREATE INDEX idx_mail_sender ON mail_index (sender);

CREATE INDEX idx_mail_recipient ON mail_index (recipient);

-- ----------------------------------------------------------------------------
-- E21 — WordPress vulnerability feed cache (server-wide)
-- ----------------------------------------------------------------------------
CREATE TABLE wp_vulns (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	source TEXT NOT NULL, -- feed name chosen by the E21 ADR
	external_id TEXT NOT NULL,
	component_type TEXT NOT NULL CHECK (component_type IN ('core', 'plugin', 'theme')),
	slug TEXT NOT NULL, -- 'core' for core entries
	affected_from TEXT,
	fixed_in TEXT,
	severity TEXT,
	title TEXT NOT NULL,
	reference_url TEXT,
	fetched_at TEXT NOT NULL,
	created_at TEXT NOT NULL,
	updated_at TEXT NOT NULL
);

CREATE UNIQUE INDEX uq_wp_vulns_source_id ON wp_vulns (source, external_id);

CREATE INDEX idx_wp_vulns_component_slug ON wp_vulns (component_type, slug);

-- ----------------------------------------------------------------------------
-- E18 — Saved log filters
-- ----------------------------------------------------------------------------
CREATE TABLE saved_filters (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	user TEXT NOT NULL,
	scope TEXT NOT NULL, -- 'web:<domain>:error', 'node:<domain>', 'docker:<slug>', 'mail:<domain>'
	name TEXT NOT NULL,
	filter TEXT NOT NULL CHECK (json_valid (filter)),
	created_at TEXT NOT NULL,
	updated_at TEXT NOT NULL
);

CREATE UNIQUE INDEX uq_saved_filters ON saved_filters (user, scope, name);

-- ----------------------------------------------------------------------------
-- E32 — AI assistant quota counters
-- ----------------------------------------------------------------------------
CREATE TABLE ai_usage (
	user TEXT NOT NULL,
	day TEXT NOT NULL CHECK (length (day) = 10),
	requests INTEGER NOT NULL DEFAULT 0 CHECK (requests >= 0),
	input_tokens INTEGER NOT NULL DEFAULT 0 CHECK (input_tokens >= 0),
	output_tokens INTEGER NOT NULL DEFAULT 0 CHECK (output_tokens >= 0),
	created_at TEXT NOT NULL,
	updated_at TEXT NOT NULL,
	PRIMARY KEY (user, day)
) WITHOUT ROWID;

COMMIT;

-- ============================================================================
-- DOWN (reverse order of creation; each Laravel migration drops its own part)
-- ============================================================================
-- BEGIN;
-- DROP TABLE IF EXISTS ai_usage;
-- DROP TABLE IF EXISTS saved_filters;
-- DROP TABLE IF EXISTS wp_vulns;
-- DROP TABLE IF EXISTS mail_index;
-- DROP TABLE IF EXISTS waf_events;
-- DROP TABLE IF EXISTS webhook_deliveries;
-- DROP TABLE IF EXISTS webhooks;
-- DROP TABLE IF EXISTS update_history;
-- DROP TABLE IF EXISTS audit_index;
-- DROP TABLE IF EXISTS alerts_history;
-- DROP TABLE IF EXISTS jobs_log;
-- DROP TABLE IF EXISTS failed_jobs;
-- DROP TABLE IF EXISTS job_batches;
-- DROP TABLE IF EXISTS jobs;
-- DROP TABLE IF EXISTS migrations;
-- COMMIT;
