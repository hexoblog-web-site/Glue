'use strict';

/**
 * 互动统计（互动统计 0.1.0）
 *
 * 定位：单站长私有部署。一个容器只服务一个站点，数据库里只有这个站的数据。
 * 原则：
 *   1. 公开接口只吐数字，永远不返回明细；明细必须登录后台。
 *   2. 访客只存哈希，不落 IP / UA 原文。
 *   3. 点赞、收藏、有用、没用共用一套「谁对哪个页面做过什么」的表，靠唯一键天然幂等。
 */

const crypto = require('crypto');
const { db, getSetting, setSetting } = require('./db');

// 统一按东八区切「天」，不依赖容器里的 tzdata
const TZ_OFFSET_HOURS = Number.isFinite(Number(process.env.STAT_TZ_OFFSET_HOURS))
  ? Number(process.env.STAT_TZ_OFFSET_HOURS)
  : 8;

const ACTIONS = ['like', 'collect', 'useful', 'useless'];
const PAIRED = { useful: 'useless', useless: 'useful' };

const BOT_RE =
  /(bot|crawler|spider|slurp|bingpreview|headlesschrome|phantomjs|curl\/|wget|python-requests|go-http-client|okhttp|java\/|httpclient|facebookexternalhit|monitor|uptime|lighthouse|pingdom|ahrefs|semrush|yandex|bytespider|petalbot)/i;

db.exec(`
CREATE TABLE IF NOT EXISTS page_view (
  day  TEXT NOT NULL,
  path TEXT NOT NULL,
  pv   INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (day, path)
);

CREATE TABLE IF NOT EXISTS page_visitor (
  day     TEXT NOT NULL,
  path    TEXT NOT NULL,
  visitor TEXT NOT NULL,
  ts      INTEGER NOT NULL,
  PRIMARY KEY (day, path, visitor)
);

CREATE TABLE IF NOT EXISTS engage_action (
  path    TEXT NOT NULL,
  action  TEXT NOT NULL,
  visitor TEXT NOT NULL,
  ts      INTEGER NOT NULL,
  PRIMARY KEY (path, action, visitor)
);

CREATE TABLE IF NOT EXISTS page_meta (
  path       TEXT PRIMARY KEY,
  title      TEXT,
  updated_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_page_view_path ON page_view (path);
CREATE INDEX IF NOT EXISTS idx_page_visitor_day ON page_visitor (day);
CREATE INDEX IF NOT EXISTS idx_page_visitor_path ON page_visitor (path);
CREATE INDEX IF NOT EXISTS idx_engage_path_action ON engage_action (path, action);
`);

// ---------- 时间 ----------

function dayKey(ts = Date.now()) {
  const shifted = ts + TZ_OFFSET_HOURS * 3600000;
  return new Date(shifted).toISOString().slice(0, 10);
}

function recentDays(count, endTs = Date.now()) {
  const out = [];
  for (let i = count - 1; i >= 0; i -= 1) out.push(dayKey(endTs - i * 86400000));
  return out;
}

// ---------- 访客 ----------

function salt() {
  let value = getSetting('stat_salt');
  if (!value) {
    value = crypto.randomBytes(16).toString('hex');
    setSetting('stat_salt', value);
  }
  return value;
}

function clientIp(req) {
  const raw = (req.headers['x-forwarded-for'] || '').split(',')[0].trim() || req.ip || '';
  return String(raw).replace(/^::ffff:/, '');
}

/**
 * 访客指纹，库里只留哈希，反推不出是谁。
 * 优先用浏览器本地生成的 vid：同一个人的手机和电脑算两个访客，共用出口 IP 的两个人也不会被并成一个。
 * 拿不到 vid（关了 JS、被拦截）时退回 IP + UA。
 */
function visitorOf(req, vid) {
  const local = typeof vid === 'string' ? vid.replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 64) : '';
  const seed = local
    ? `v|${local}`
    : `h|${clientIp(req)}|${String(req.headers['user-agent'] || '')}`;
  return crypto
    .createHash('sha256')
    .update(`${salt()}|${seed}`)
    .digest('hex')
    .slice(0, 16);
}

// ---------- 限流 ----------

const buckets = new Map();

/** 简单滑动窗口，够挡住脚本连点，不追求精确 */
function hitLimit(key, max, windowMs = 60000) {
  const now = Date.now();
  let rec = buckets.get(key);
  if (!rec || now > rec.resetAt) {
    rec = { count: 0, resetAt: now + windowMs };
    buckets.set(key, rec);
  }
  rec.count += 1;
  if (buckets.size > 4000) {
    for (const [k, v] of buckets) {
      if (now > v.resetAt) buckets.delete(k);
    }
  }
  return rec.count > max;
}

function isBot(req) {
  const ua = String(req.headers['user-agent'] || '');
  if (!ua || ua.length < 10) return true;
  return BOT_RE.test(ua);
}

// ---------- 路径归一 ----------

const ASSET_RE = /\.(?:png|jpe?g|gif|webp|avif|svg|ico|css|js|mjs|json|xml|txt|woff2?|ttf|otf|eot|mp4|webm|mp3|zip|pdf|rar|7z)$/i;

function normalizePath(raw) {
  let value = String(raw || '/').trim();
  if (!value) return null;
  try {
    value = new URL(value, 'http://placeholder.local').pathname;
  } catch {
    value = '/';
  }
  try {
    value = decodeURIComponent(value);
  } catch {
    /* 保持原样 */
  }
  if (value.length > 1) value = value.replace(/\/+$/, '') || '/';
  if (ASSET_RE.test(value) && !/\.html?$/i.test(value)) return null;
  if (value.length > 300) value = value.slice(0, 300);
  return value.startsWith('/') ? value : `/${value}`;
}

// ---------- 写入 ----------

const bumpViewStmt = db.prepare(`
  INSERT INTO page_view (day, path, pv) VALUES (?, ?, 1)
  ON CONFLICT(day, path) DO UPDATE SET pv = pv + 1
`);
const touchVisitorStmt = db.prepare(
  'INSERT OR IGNORE INTO page_visitor (day, path, visitor, ts) VALUES (?, ?, ?, ?)'
);
const upsertMetaStmt = db.prepare(`
  INSERT INTO page_meta (path, title, updated_at) VALUES (?, ?, ?)
  ON CONFLICT(path) DO UPDATE SET title = COALESCE(excluded.title, page_meta.title), updated_at = excluded.updated_at
`);

/**
 * 记一次浏览。
 * @returns {{path:string, newVisitor:boolean}|null} 被过滤时返回 null
 */
function collect({ path, title, vid, req }) {
  const normalized = normalizePath(path);
  if (!normalized) return null;
  if (isBot(req)) return null;

  const ts = Date.now();
  const day = dayKey(ts);
  const visitor = visitorOf(req, vid);

  const tx = db.transaction(() => {
    bumpViewStmt.run(day, normalized);
    const hit = touchVisitorStmt.run(day, normalized, visitor, ts);
    if (title) upsertMetaStmt.run(normalized, cleanTitle(title), ts);
    return hit.changes > 0;
  });

  return { path: normalized, newVisitor: tx() };
}

function cleanTitle(raw) {
  const text = String(raw || '')
    .replace(/\s+/g, ' ')
    .trim();
  return text ? text.slice(0, 120) : null;
}

/**
 * 点赞 / 收藏 / 有用 / 没用。同一访客同一动作再点一次即撤销。
 * useful 与 useless 互斥。
 */
function act({ path, action, vid, req }) {
  if (!ACTIONS.includes(action)) throw new Error('不支持的操作');
  const normalized = normalizePath(path);
  if (!normalized) throw new Error('路径不合法');
  if (isBot(req)) throw new Error('请求被忽略');

  const visitor = visitorOf(req, vid);
  const ts = Date.now();
  const delStmt = db.prepare('DELETE FROM engage_action WHERE path = ? AND action = ? AND visitor = ?');
  const insStmt = db.prepare(
    'INSERT OR IGNORE INTO engage_action (path, action, visitor, ts) VALUES (?, ?, ?, ?)'
  );

  const tx = db.transaction(() => {
    const exists = db
      .prepare('SELECT 1 FROM engage_action WHERE path = ? AND action = ? AND visitor = ?')
      .get(normalized, action, visitor);

    const paired = PAIRED[action];
    if (paired) delStmt.run(normalized, paired, visitor);

    if (exists) {
      delStmt.run(normalized, action, visitor);
      return false;
    }
    insStmt.run(normalized, action, visitor, ts);
    return true;
  });

  const toggled = tx();
  return { path: normalized, action, toggled, counts: countsOf(normalized, visitor) };
}

// ---------- 读取 ----------

const ACTIONS_SQL = `
  SUM(CASE WHEN action = 'like'    THEN 1 ELSE 0 END) AS likes,
  SUM(CASE WHEN action = 'collect' THEN 1 ELSE 0 END) AS collects,
  SUM(CASE WHEN action = 'useful'  THEN 1 ELSE 0 END) AS useful,
  SUM(CASE WHEN action = 'useless' THEN 1 ELSE 0 END) AS useless
`;

const pageActionsStmt = db.prepare(
  `SELECT ${ACTIONS_SQL} FROM engage_action WHERE path = ?`
);
const pagePvStmt = db.prepare('SELECT COALESCE(SUM(pv), 0) AS pv FROM page_view WHERE path = ?');
const pageUvStmt = db.prepare('SELECT COUNT(*) AS uv FROM page_visitor WHERE path = ?');
const myActionsStmt = db.prepare('SELECT action FROM engage_action WHERE path = ? AND visitor = ?');

/** 单页计数。visitor 传入时额外返回 mine（哪些动作我做过了） */
function countsOf(path, visitor = null) {
  const agg = pageActionsStmt.get(path) || {};
  const out = {
    path,
    like: agg.likes || 0,
    collect: agg.collects || 0,
    useful: agg.useful || 0,
    useless: agg.useless || 0,
    pv: pagePvStmt.get(path).pv,
    uv: pageUvStmt.get(path).uv,
  };
  if (visitor) {
    out.mine = {};
    for (const row of myActionsStmt.all(path, visitor)) out.mine[row.action] = true;
  }
  return out;
}

/**
 * 批量读计数：列表页 20 张卡片一次拿完，不要 20 个请求。
 * 最多 50 个，超出的丢弃。
 */
function countsBatch(paths, visitor = null) {
  const list = Array.isArray(paths) ? paths.slice(0, 50) : [];
  const out = {};
  for (const raw of list) {
    const key = normalizePath(raw);
    if (!key || out[key]) continue;
    out[key] = countsOf(key, visitor);
  }
  return out;
}

/** 总览：今日、趋势、累计 */
function overview({ days = 14 } = {}) {
  const span = Math.min(90, Math.max(1, Number(days) || 14));
  const dayList = recentDays(span);
  const from = dayList[0];
  const today = dayList[dayList.length - 1];

  const pvByDay = new Map(
    db
      .prepare('SELECT day, SUM(pv) AS pv FROM page_view WHERE day >= ? GROUP BY day')
      .all(from)
      .map((r) => [r.day, r.pv])
  );
  const uvByDay = new Map(
    db
      .prepare('SELECT day, COUNT(DISTINCT visitor) AS uv FROM page_visitor WHERE day >= ? GROUP BY day')
      .all(from)
      .map((r) => [r.day, r.uv])
  );

  const trend = dayList.map((day) => ({
    day,
    pv: pvByDay.get(day) || 0,
    uv: uvByDay.get(day) || 0,
  }));

  const total = db
    .prepare('SELECT COALESCE(SUM(pv), 0) AS pv FROM page_view')
    .get();
  const totalUv = db.prepare('SELECT COUNT(*) AS uv FROM page_visitor').get();
  const totalActions = db
    .prepare(`SELECT ${ACTIONS_SQL} FROM engage_action`)
    .get();

  const todayRow = trend[trend.length - 1] || { pv: 0, uv: 0 };

  return {
    today: { day: today, pv: todayRow.pv, uv: todayRow.uv },
    total: {
      pv: total.pv,
      uv: totalUv.uv,
      like: totalActions.likes || 0,
      collect: totalActions.collects || 0,
      useful: totalActions.useful || 0,
      useless: totalActions.useless || 0,
    },
    trend,
  };
}

/**
 * 文章明细排行。sort: pv | uv | like | useful
 * 只有后台能调，包含完整明细。
 */
function pageList({ range = '30d', sort = 'pv', limit = 50 } = {}) {
  const RANGE_DAYS = { '7d': 7, '30d': 30, '90d': 90, all: 0 };
  const days = RANGE_DAYS[range] ?? 30;
  const from = days ? dayKey(Date.now() - (days - 1) * 86400000) : '0000-00-00';

  const rows = db
    .prepare(
      `SELECT p.path,
              SUM(p.pv) AS pv,
              COALESCE(a.likes, 0)    AS likes,
              COALESCE(a.collects, 0) AS collects,
              COALESCE(a.useful, 0)   AS useful,
              COALESCE(a.useless, 0)  AS useless,
              m.title AS title
         FROM page_view p
         LEFT JOIN (
           SELECT path, ${ACTIONS_SQL} FROM engage_action GROUP BY path
         ) a ON a.path = p.path
         LEFT JOIN page_meta m ON m.path = p.path
        WHERE p.day >= ?
        GROUP BY p.path
        ORDER BY pv DESC
        LIMIT 500`
    )
    .all(from);

  const uvStmt = db.prepare('SELECT COUNT(*) AS uv FROM page_visitor WHERE path = ? AND day >= ?');
  const list = rows.map((r) => {
    const uv = uvStmt.get(r.path, from).uv;
    const decided = r.useful + r.useless;
    return {
      path: r.path,
      title: r.title || r.path,
      pv: r.pv,
      uv,
      like: r.likes,
      collect: r.collects,
      useful: r.useful,
      useless: r.useless,
      usefulRate: decided ? Math.round((r.useful / decided) * 1000) / 10 : null,
    };
  });

  const key = { pv: 'pv', uv: 'uv', like: 'like', useful: 'useful' }[sort] || 'pv';
  list.sort((x, y) => y[key] - x[key] || y.pv - x.pv);

  return list.slice(0, Math.min(200, Math.max(1, limit)));
}

/** 单篇明细（后台看） */
function pageDetail(path, { days = 30 } = {}) {
  const normalized = normalizePath(path);
  if (!normalized) return null;
  const span = Math.min(90, Math.max(1, Number(days) || 30));
  const dayList = recentDays(span);
  const pvByDay = new Map(
    db
      .prepare('SELECT day, pv FROM page_view WHERE path = ? AND day >= ?')
      .all(normalized, dayList[0])
      .map((r) => [r.day, r.pv])
  );
  const uvByDay = new Map(
    db
      .prepare(
        'SELECT day, COUNT(*) AS uv FROM page_visitor WHERE path = ? AND day >= ? GROUP BY day'
      )
      .all(normalized, dayList[0])
      .map((r) => [r.day, r.uv])
  );
  const counts = countsOf(normalized);
  const meta = db.prepare('SELECT title FROM page_meta WHERE path = ?').get(normalized);

  return {
    ...counts,
    title: meta?.title || normalized,
    trend: dayList.map((day) => ({
      day,
      pv: pvByDay.get(day) || 0,
      uv: uvByDay.get(day) || 0,
    })),
  };
}

// ---------- 维护 ----------

/** 清掉超过保留期的 UV 明细（只留数字，不留人） */
function cleanup(days = 90) {
  const keep = Math.max(7, Number(days) || 90);
  const cutoff = dayKey(Date.now() - keep * 86400000);
  const removed = db.prepare('DELETE FROM page_visitor WHERE day < ?').run(cutoff).changes;
  db.prepare('DELETE FROM page_meta WHERE updated_at < ?').run(Date.now() - 365 * 86400000);
  return removed;
}

function reset() {
  const tx = db.transaction(() => {
    db.exec('DELETE FROM page_view; DELETE FROM page_visitor; DELETE FROM engage_action; DELETE FROM page_meta;');
  });
  tx();
}

module.exports = {
  ACTIONS,
  collect,
  act,
  countsOf,
  countsBatch,
  overview,
  pageList,
  pageDetail,
  cleanup,
  reset,
  normalizePath,
  isBot,
  hitLimit,
  clientIp,
};
