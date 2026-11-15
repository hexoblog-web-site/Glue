'use strict';

const fs = require('fs');
const path = require('path');
const Database = require('better-sqlite3');

const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, '..', 'data');
fs.mkdirSync(DATA_DIR, { recursive: true });

const db = new Database(path.join(DATA_DIR, 'beacon-monitor.db'));
db.pragma('journal_mode = WAL');
db.pragma('synchronous = NORMAL');
db.pragma('foreign_keys = ON');

db.exec(`
CREATE TABLE IF NOT EXISTS monitors (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  name           TEXT    NOT NULL,
  url            TEXT    NOT NULL,
  group_name     TEXT    NOT NULL DEFAULT '默认分组',
  method         TEXT    NOT NULL DEFAULT 'GET',
  expected_status TEXT   NOT NULL DEFAULT '200-399',
  keyword        TEXT,
  interval_sec   INTEGER NOT NULL DEFAULT 60,
  timeout_ms     INTEGER NOT NULL DEFAULT 10000,
  slow_ms        INTEGER NOT NULL DEFAULT 5000,
  active         INTEGER NOT NULL DEFAULT 1,
  sort           INTEGER NOT NULL DEFAULT 0,
  last_ts        INTEGER,
  last_ok        INTEGER,
  last_latency   INTEGER,
  last_code      INTEGER,
  last_error     TEXT,
  created_at     INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS checks (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  monitor_id INTEGER NOT NULL REFERENCES monitors(id) ON DELETE CASCADE,
  ts         INTEGER NOT NULL,
  ok         INTEGER NOT NULL,
  code       INTEGER,
  latency    INTEGER,
  error      TEXT
);

CREATE INDEX IF NOT EXISTS idx_checks_monitor_ts ON checks (monitor_id, ts);

CREATE TABLE IF NOT EXISTS settings (
  key   TEXT PRIMARY KEY,
  value TEXT
);

-- 用户手动删除过的自动发现地址，不再重复收录
CREATE TABLE IF NOT EXISTS discovery_ignored (
  url        TEXT PRIMARY KEY,
  created_at INTEGER NOT NULL
);
`);

// ---------- 轻量迁移（兼容旧数据库） ----------
(function migrate() {
  const cols = db.prepare('PRAGMA table_info(monitors)').all().map((c) => c.name);
  if (!cols.includes('source')) {
    db.exec("ALTER TABLE monitors ADD COLUMN source TEXT NOT NULL DEFAULT 'manual'");
  }
  if (!cols.includes('auto_key')) {
    db.exec('ALTER TABLE monitors ADD COLUMN auto_key TEXT');
  }
  db.exec(
    'CREATE UNIQUE INDEX IF NOT EXISTS idx_monitors_auto_key ON monitors(auto_key) WHERE auto_key IS NOT NULL'
  );
})();

const DEFAULT_SETTINGS = {
  site_title: process.env.SITE_TITLE || '站点服务状态',
  site_description: process.env.SITE_DESCRIPTION || '实时监控站点与各项服务的可用性',
  footer_text: process.env.FOOTER_TEXT || '',
  webhook_url: process.env.WEBHOOK_URL || '',
  retention_days: '90',
};

const getSettingStmt = db.prepare('SELECT value FROM settings WHERE key = ?');
const setSettingStmt = db.prepare(
  'INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value'
);

function getSetting(key, fallback = null) {
  const row = getSettingStmt.get(key);
  if (!row) return fallback != null ? fallback : DEFAULT_SETTINGS[key] ?? null;
  return row.value;
}

function setSetting(key, value) {
  setSettingStmt.run(key, value == null ? '' : String(value));
}

function getSettings() {
  const out = { ...DEFAULT_SETTINGS };
  for (const row of db.prepare('SELECT key, value FROM settings').all()) {
    out[row.key] = row.value;
  }
  return out;
}

// ---------- monitors ----------

function listMonitors(onlyActive = false) {
  const sql = `SELECT * FROM monitors ${onlyActive ? 'WHERE active = 1' : ''} ORDER BY sort ASC, id ASC`;
  return db.prepare(sql).all();
}

function getMonitor(id) {
  return db.prepare('SELECT * FROM monitors WHERE id = ?').get(id);
}

function createMonitor(input) {
  const row = normalizeMonitor(input);
  const info = db
    .prepare(
      `INSERT INTO monitors (name, url, group_name, method, expected_status, keyword, interval_sec, timeout_ms, slow_ms, active, sort, created_at, source, auto_key)
       VALUES (@name, @url, @group_name, @method, @expected_status, @keyword, @interval_sec, @timeout_ms, @slow_ms, @active, @sort, @created_at, @source, @auto_key)`
    )
    .run({ ...row, created_at: Date.now() });
  return getMonitor(info.lastInsertRowid);
}

/** 自动发现专用：URL 已存在时静默跳过 */
function createAutoMonitor(input) {
  const row = normalizeMonitor({ ...input, source: 'auto' });
  const info = db
    .prepare(
      `INSERT OR IGNORE INTO monitors (name, url, group_name, method, expected_status, keyword, interval_sec, timeout_ms, slow_ms, active, sort, created_at, source, auto_key)
       VALUES (@name, @url, @group_name, @method, @expected_status, @keyword, @interval_sec, @timeout_ms, @slow_ms, @active, @sort, @created_at, @source, @auto_key)`
    )
    .run({ ...row, created_at: Date.now() });
  return info.changes > 0 ? getMonitor(info.lastInsertRowid) : null;
}

function updateMonitor(id, input) {
  const current = getMonitor(id);
  if (!current) return null;
  const merged = normalizeMonitor({ ...current, ...input });
  db.prepare(`
    UPDATE monitors SET
      name = @name, url = @url, group_name = @group_name, method = @method,
      expected_status = @expected_status, keyword = @keyword, interval_sec = @interval_sec,
      timeout_ms = @timeout_ms, slow_ms = @slow_ms, active = @active, sort = @sort
    WHERE id = @id
  `).run({ ...merged, id });
  return getMonitor(id);
}

function deleteMonitor(id) {
  return db.prepare('DELETE FROM monitors WHERE id = ?').run(id).changes > 0;
}

function normalizeMonitor(input) {
  const num = (v, def, min, max) => {
    const n = Number(v);
    if (!Number.isFinite(n)) return def;
    return Math.min(max, Math.max(min, Math.round(n)));
  };
  const source = input.source === 'auto' ? 'auto' : 'manual';
  return {
    name: String(input.name || '').trim().slice(0, 60) || '未命名监控',
    url: String(input.url || '').trim(),
    group_name: String(input.group_name || '').trim().slice(0, 40) || '默认分组',
    method: String(input.method || 'GET').toUpperCase() === 'HEAD' ? 'HEAD' : 'GET',
    expected_status: String(input.expected_status || '200-399').trim().slice(0, 60) || '200-399',
    keyword: input.keyword ? String(input.keyword).slice(0, 200) : null,
    interval_sec: num(input.interval_sec, 60, 15, 86400),
    timeout_ms: num(input.timeout_ms, 10000, 1000, 60000),
    slow_ms: num(input.slow_ms, 5000, 500, 120000),
    active: input.active === 0 || input.active === '0' || input.active === false ? 0 : 1,
    sort: num(input.sort, 0, -9999, 9999),
    source,
    auto_key: source === 'auto' ? String(input.auto_key || input.url || '').slice(0, 500) || null : null,
  };
}

function saveCheck(monitorId, result) {
  const ts = result.ts || Date.now();
  db.prepare(
    'INSERT INTO checks (monitor_id, ts, ok, code, latency, error) VALUES (?, ?, ?, ?, ?, ?)'
  ).run(monitorId, ts, result.ok ? 1 : 0, result.statusCode ?? null, result.latency ?? null, result.error || null);
  db.prepare(
    'UPDATE monitors SET last_ts = ?, last_ok = ?, last_latency = ?, last_code = ?, last_error = ? WHERE id = ?'
  ).run(ts, result.ok ? 1 : 0, result.latency ?? null, result.statusCode ?? null, result.error || null, monitorId);
  return ts;
}

// ---------- stats ----------

function heartbeat(monitorId, limit = 60) {
  const rows = db
    .prepare('SELECT ts, ok, code, latency FROM checks WHERE monitor_id = ? ORDER BY ts DESC LIMIT ?')
    .all(monitorId, limit);
  return rows.reverse();
}

function uptimeSince(monitorId, sinceTs) {
  const row = db
    .prepare(
      `SELECT COUNT(*) AS total,
              COALESCE(SUM(ok), 0) AS up,
              COALESCE(AVG(CASE WHEN ok = 1 THEN latency END), 0) AS avg_latency
         FROM checks WHERE monitor_id = ? AND ts >= ?`
    )
    .get(monitorId, sinceTs);
  return {
    total: row.total,
    up: row.up,
    pct: row.total ? (row.up / row.total) * 100 : null,
    avgLatency: row.total ? Math.round(row.avg_latency) : null,
  };
}

function history(monitorId, sinceTs, bucketMs) {
  return db
    .prepare(
      `SELECT (ts / ?) * ? AS bucket,
              AVG(latency) AS latency,
              SUM(CASE WHEN ok = 1 THEN 1 ELSE 0 END) * 1.0 / COUNT(*) AS uptime,
              COUNT(*) AS total
         FROM checks WHERE monitor_id = ? AND ts >= ?
         GROUP BY bucket ORDER BY bucket ASC`
    )
    .all(bucketMs, bucketMs, monitorId, sinceTs)
    .map((r) => ({
      ts: r.bucket,
      latency: r.latency == null ? null : Math.round(r.latency),
      uptime: Math.round(r.uptime * 1000) / 10,
      total: r.total,
    }));
}

function cleanup(retentionDays) {
  const days = Math.max(1, Number(retentionDays) || 90);
  const cutoff = Date.now() - days * 86400000;
  return db.prepare('DELETE FROM checks WHERE ts < ?').run(cutoff).changes;
}

// ---------- 自动发现：忽略名单 ----------

function ignoreDiscoveryUrl(url) {
  db.prepare('INSERT OR IGNORE INTO discovery_ignored (url, created_at) VALUES (?, ?)').run(url, Date.now());
}

function ignoredUrls() {
  return new Set(db.prepare('SELECT url FROM discovery_ignored').all().map((r) => r.url));
}

// ---------- seed ----------

function ensureSeed() {
  const { total } = db.prepare('SELECT COUNT(*) AS total FROM monitors').get();
  if (total > 0) return;

  let seeds = [];
  if (process.env.SEED_MONITORS) {
    try {
      const parsed = JSON.parse(process.env.SEED_MONITORS);
      if (Array.isArray(parsed)) seeds = parsed;
    } catch (err) {
      console.warn('[seed] SEED_MONITORS 不是合法 JSON，已忽略:', err.message);
    }
  }
  if (!seeds.length) {
    seeds = [
      { name: '博客首页', url: 'https://www.manimblog.cn/', group_name: '站点', sort: 10 },
      { name: '文章订阅', url: 'https://www.manimblog.cn/atom.xml', group_name: '站点', sort: 20 },
    ];
  }
  for (const s of seeds) {
    try {
      createMonitor(s);
    } catch (err) {
      console.warn('[seed] 创建监控失败:', err.message);
    }
  }
  console.log(`[seed] 已初始化 ${seeds.length} 个监控项`);
}

module.exports = {
  db,
  DATA_DIR,
  getSetting,
  setSetting,
  getSettings,
  listMonitors,
  getMonitor,
  createMonitor,
  createAutoMonitor,
  updateMonitor,
  deleteMonitor,
  saveCheck,
  heartbeat,
  uptimeSince,
  history,
  cleanup,
  ensureSeed,
  ignoreDiscoveryUrl,
  ignoredUrls,
};
