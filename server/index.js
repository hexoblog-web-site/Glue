'use strict';

const path = require('path');
const crypto = require('crypto');
const express = require('express');

const db = require('./db');
const engage = require('./engage');
const friends = require('./friends');
const { getCertInfo, statusOf } = require('./checker');
const scheduler = require('./scheduler');
const discovery = require('./discovery');
const updater = require('./updater');
const { VERSION } = require('./version');

const PORT = Number(process.env.PORT) || 8080;
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'admin123';
const COOKIE_NAME = 'lh_token';
const TOKEN_TTL = 7 * 24 * 3600 * 1000;
const SECRET = crypto
  .createHash('sha256')
  .update('beacon-monitor::' + (process.env.ADMIN_SECRET || ADMIN_PASSWORD))
  .digest();

const app = express();
app.disable('x-powered-by');
app.set('trust proxy', 1);
app.use(express.json({ limit: '128kb' }));

// 公开只读接口允许外部页面（博客、README 徽章）跨域调用
const PUBLIC_API = [
  '/api/status',
  '/api/summary.json',
  '/api/monitors',
  '/api/badge.svg',
  '/api/dot.svg',
  '/api/status.js',
  '/api/e/counts',
  '/api/e/count.svg',
  '/api/e/friends.json',
  '/api/e/link.json',
  '/api/e/moments.json',
  '/e.js',
  '/e/moments.js',
  '/e/apply.js',
  '/e/friends.js',
  '/dot.svg',
  '/s.js',
  '/status.js',
];

// 公开写接口：只接收计数，不返回明细
const PUBLIC_WRITE = ['/api/e/collect', '/api/e/act', '/api/e/friend-apply'];

function applyCors(res, allowPost) {
  res.set('access-control-allow-origin', '*');
  res.set('access-control-allow-methods', allowPost ? 'GET,POST,OPTIONS' : 'GET,HEAD,OPTIONS');
  res.set('access-control-allow-headers', 'content-type');
  res.set('access-control-max-age', '86400');
}

app.use(PUBLIC_API, (req, res, next) => {
  applyCors(res, false);
  if (req.method === 'OPTIONS') return res.sendStatus(204);
  next();
});

app.use(PUBLIC_WRITE, (req, res, next) => {
  applyCors(res, true);
  if (req.method === 'OPTIONS') return res.sendStatus(204);
  next();
});

// ---------- auth ----------

function sign(exp) {
  return crypto.createHmac('sha256', SECRET).update('admin|' + exp).digest('base64url');
}

function issueToken() {
  const exp = Date.now() + TOKEN_TTL;
  return `${exp}.${sign(exp)}`;
}

function verifyToken(token) {
  if (!token || typeof token !== 'string') return false;
  const [expRaw, sig] = token.split('.');
  const exp = Number(expRaw);
  if (!Number.isFinite(exp) || exp < Date.now()) return false;
  const expected = sign(exp);
  const a = Buffer.from(String(sig || ''));
  const b = Buffer.from(expected);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

function parseCookies(header) {
  const out = {};
  (header || '').split(';').forEach((pair) => {
    const idx = pair.indexOf('=');
    if (idx < 0) return;
    out[pair.slice(0, idx).trim()] = decodeURIComponent(pair.slice(idx + 1).trim());
  });
  return out;
}

function isAuthed(req) {
  return verifyToken(parseCookies(req.headers.cookie)[COOKIE_NAME]);
}

function requireAdmin(req, res, next) {
  if (isAuthed(req)) return next();
  res.status(401).json({ error: '未登录或登录已过期' });
}

const loginAttempts = new Map();
function rateLimited(ip) {
  const now = Date.now();
  const rec = loginAttempts.get(ip);
  if (!rec || now - rec.first > 10 * 60 * 1000) {
    loginAttempts.set(ip, { first: now, count: 1 });
    return false;
  }
  rec.count += 1;
  return rec.count > 10;
}

// ---------- public api ----------

const RANGES = {
  '24h': { hours: 24, bucket: 5 * 60 * 1000 },
  '7d': { hours: 24 * 7, bucket: 60 * 60 * 1000 },
  '30d': { hours: 24 * 30, bucket: 6 * 60 * 60 * 1000 },
};

function assertUrl(raw) {
  let url;
  try {
    url = new URL(raw);
  } catch {
    throw new Error('URL 格式不正确');
  }
  if (!/^https?:$/.test(url.protocol)) throw new Error('只支持 http / https 地址');
  return url.toString();
}

async function buildPublicMonitor(m, now, withCert = true) {
  const hb = db.heartbeat(m.id, 60);
  const u24 = db.uptimeSince(m.id, now - 86400000);
  const u7 = db.uptimeSince(m.id, now - 7 * 86400000);
  const u30 = db.uptimeSince(m.id, now - 30 * 86400000);
  const cert = withCert ? await getCertInfo(m.url) : null;

  return {
    id: m.id,
    name: m.name,
    url: m.url,
    group: m.group_name,
    status: statusOf(m),
    latency: m.last_latency,
    statusCode: m.last_code,
    error: m.last_error,
    lastCheck: m.last_ts,
    interval: m.interval_sec,
    slowMs: m.slow_ms,
    uptime24h: u24.pct == null ? null : Math.round(u24.pct * 100) / 100,
    uptime7d: u7.pct == null ? null : Math.round(u7.pct * 100) / 100,
    uptime30d: u30.pct == null ? null : Math.round(u30.pct * 100) / 100,
    avgLatency24h: u24.avgLatency,
    certDays: cert ? cert.days : null,
    certExpiresAt: cert ? cert.expiresAt : null,
    heartbeat: hb.map((c) => ({
      ts: c.ts,
      ok: c.ok === 1,
      code: c.code,
      latency: c.latency,
    })),
  };
}

async function buildStatus() {
  const now = Date.now();
  const settings = db.getSettings();
  const monitors = db.listMonitors(true);

  const items = await Promise.all(monitors.map((m) => buildPublicMonitor(m, now)));

  const groups = [];
  const index = new Map();
  for (const item of items) {
    if (!index.has(item.group)) {
      const group = { name: item.group, monitors: [] };
      index.set(item.group, group);
      groups.push(group);
    }
    index.get(item.group).monitors.push(item);
  }

  const up = items.filter((m) => m.status === 'up' || m.status === 'slow').length;
  const down = items.filter((m) => m.status === 'down').length;

  return {
    site: {
      title: settings.site_title,
      description: settings.site_description,
      footer: settings.footer_text,
    },
    summary: { total: items.length, up, down, unknown: items.length - up - down },
    groups: groups.map((g) => ({
      ...g,
      up: g.monitors.filter((m) => m.status === 'up' || m.status === 'slow').length,
      total: g.monitors.length,
    })),
    updatedAt: now,
  };
}

app.get('/api/status', async (req, res, next) => {
  try {
    res.set('cache-control', 'no-store');
    res.json(await buildStatus());
  } catch (err) {
    next(err);
  }
});

// ---------- 状态徽章（可直接 <img> 引到博客） ----------

const BADGE_COLORS = {
  ok: '#17a34a',
  slow: '#d97706',
  down: '#dc2626',
  idle: '#8b949e',
};

function xmlEscape(str) {
  return String(str).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;',
  }[c]));
}

function badgeTextWidth(text) {
  let width = 0;
  for (const ch of String(text)) {
    width += /[\u2e80-\u9fff\uff00-\uffef]/.test(ch) ? 11.6 : 6.7;
  }
  return width;
}

function badgeSvg(label, value, color) {
  const pad = 8;
  const labelW = Math.round(badgeTextWidth(label) + pad * 2);
  const valueW = Math.round(badgeTextWidth(value) + pad * 2);
  const total = labelW + valueW;
  const h = 20;
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${total}" height="${h}" role="img" aria-label="${xmlEscape(label)}: ${xmlEscape(value)}">
  <title>${xmlEscape(label)}: ${xmlEscape(value)}</title>
  <clipPath id="r"><rect width="${total}" height="${h}" rx="3"/></clipPath>
  <g clip-path="url(#r)">
    <rect width="${labelW}" height="${h}" fill="#555"/>
    <rect x="${labelW}" width="${valueW}" height="${h}" fill="${color}"/>
    <rect width="${total}" height="${h}" fill="url(#g)"/>
  </g>
  <defs><linearGradient id="g" x2="0" y2="100%"><stop offset="0" stop-color="#fff" stop-opacity=".18"/><stop offset="1" stop-opacity=".18"/></linearGradient></defs>
  <g fill="#fff" text-anchor="middle" font-family="Verdana,'DejaVu Sans','PingFang SC','Microsoft YaHei',sans-serif" font-size="11">
    <text x="${labelW / 2}" y="14">${xmlEscape(label)}</text>
    <text x="${labelW + valueW / 2}" y="14">${xmlEscape(value)}</text>
  </g>
</svg>`;
}

app.get('/api/badge.svg', (req, res) => {
  const label = String(req.query.label || '服务状态').slice(0, 20);
  const onlyId = req.query.monitor ? Number(req.query.monitor) : null;
  let monitors = db.listMonitors(true);
  if (onlyId) monitors = monitors.filter((m) => m.id === onlyId);

  let value;
  let color;
  if (!monitors.length) {
    value = '未配置';
    color = BADGE_COLORS.idle;
  } else {
    const statuses = monitors.map(statusOf);
    const down = statuses.filter((s) => s === 'down').length;
    const slow = statuses.filter((s) => s === 'slow').length;
    const pending = statuses.filter((s) => s === 'pending').length;
    if (down > 0) {
      value = onlyId ? '异常' : `${down} 项异常`;
      color = BADGE_COLORS.down;
    } else if (pending === monitors.length) {
      value = '检测中';
      color = BADGE_COLORS.idle;
    } else if (slow > 0) {
      value = onlyId ? '较慢' : '响应较慢';
      color = BADGE_COLORS.slow;
    } else {
      value = onlyId ? '正常' : '全部正常';
      color = BADGE_COLORS.ok;
    }
  }

  res.set({
    'content-type': 'image/svg+xml; charset=utf-8',
    'cache-control': 'public, max-age=60',
  });
  res.send(badgeSvg(label, value, color));
});

// ---------- 极简状态摘要（给博客底部圆点/自定义组件用） ----------

function summarize(monitors, onlyId) {
  if (!monitors.length) {
    return { state: 'unknown', text: onlyId ? '监控不存在' : '暂无监控项', total: 0, up: 0, down: 0, slow: 0 };
  }

  const statuses = monitors.map(statusOf);
  const down = statuses.filter((s) => s === 'down').length;
  const slow = statuses.filter((s) => s === 'slow').length;
  const pending = statuses.filter((s) => s === 'pending').length;
  const up = statuses.filter((s) => s === 'up' || s === 'slow').length;
  const base = { total: monitors.length, up, down, slow };

  if (down > 0) {
    return { ...base, state: 'down', text: onlyId ? '访问异常' : `${down} 项服务异常` };
  }
  if (pending === monitors.length) {
    return { ...base, state: 'unknown', text: '正在检测' };
  }
  if (slow > 0) {
    return { ...base, state: 'slow', text: onlyId ? '响应较慢' : '部分服务较慢' };
  }
  return { ...base, state: 'ok', text: onlyId ? '运行正常' : '所有业务正常' };
}

app.get('/api/summary.json', (req, res) => {
  const onlyId = req.query.monitor ? Number(req.query.monitor) : null;
  let monitors = db.listMonitors(true);
  let item = null;
  if (onlyId) {
    item = monitors.find((m) => m.id === onlyId);
    monitors = item ? [item] : [];
  }

  const result = summarize(monitors, onlyId);
  res.set({
    'content-type': 'application/json; charset=utf-8',
    'cache-control': 'public, max-age=30',
  });
  res.json({
    ...result,
    title: item ? item.name : db.getSettings().site_title,
    updatedAt: Date.now(),
  });
});

// ---------- 零 JS 版：一张 SVG 就是「● 所有业务正常」 ----------

function dotTextWidth(text, fontSize) {
  let width = 0;
  for (const ch of String(text)) {
    width += /[\u2e80-\u9fff\uff00-\uffef]/.test(ch) ? fontSize * 1.02 : fontSize * 0.58;
  }
  return width;
}

function dotSvg(text, dotColor, textColor) {
  const fontSize = 12;
  const dotR = 3.5;
  const gap = 6;
  const padR = 2;
  const textW = Math.round(dotTextWidth(text, fontSize));
  const width = Math.round(dotR * 2 + gap + textW + padR);
  const height = 20;
  const cy = height / 2;
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" role="img" aria-label="${xmlEscape(text)}">
  <title>${xmlEscape(text)}</title>
  <circle cx="${dotR}" cy="${cy}" r="${dotR}" fill="${dotColor}"/>
  <text x="${dotR * 2 + gap}" y="${cy}" dominant-baseline="central" font-family="-apple-system,'PingFang SC','Microsoft YaHei',sans-serif" font-size="${fontSize}" fill="${xmlEscape(
    textColor
  )}">${xmlEscape(text)}</text>
</svg>`;
}

app.get(['/api/dot.svg', '/dot.svg'], (req, res) => {
  const onlyId = req.query.monitor ? Number(req.query.monitor) : null;
  let monitors = db.listMonitors(true);
  if (onlyId) monitors = monitors.filter((m) => m.id === onlyId);

  const summary = summarize(monitors, onlyId);
  const dotColor = {
    ok: BADGE_COLORS.ok,
    slow: BADGE_COLORS.slow,
    down: BADGE_COLORS.down,
  }[summary.state] || BADGE_COLORS.idle;

  const text = String(req.query.text || summary.text).slice(0, 24);
  const textColor = String(req.query.color || '#8b949e').slice(0, 24);

  res.set({
    'content-type': 'image/svg+xml; charset=utf-8',
    'cache-control': 'public, max-age=30',
  });
  res.send(dotSvg(text, dotColor, textColor));
});

// ---------- 可嵌入脚本：博客底部「● 所有业务正常」 ----------

function sendWidget(req, res) {
  res.set({
    'content-type': 'application/javascript; charset=utf-8',
    'cache-control': 'public, max-age=600',
  });
  res.sendFile(path.join(PUBLIC_DIR, 'assets', 'status-widget.js'));
}

app.get(['/api/status.js', '/s.js', '/status.js'], sendWidget);

// ---------- 版本与更新 ----------

app.get('/api/version', (req, res) => {
  res.set('cache-control', 'public, max-age=300');
  res.json({ name: 'Beacon Monitor', version: VERSION });
});

app.get('/api/admin/update', requireAdmin, async (req, res, next) => {
  try {
    const info = await updater.check({ force: req.query.force === '1' });
    res.set('cache-control', 'no-store');
    res.json({
      ...info,
      autoUpdate: process.env.AUTO_UPDATE === '1',
      installDir: process.env.INSTALL_DIR || null,
    });
  } catch (err) {
    next(err);
  }
});

app.get('/api/monitors/:id/history', (req, res) => {
  const id = Number(req.params.id);
  if (!db.getMonitor(id)) return res.status(404).json({ error: '监控不存在' });

  const rangeKey = String(req.query.range || '24h');
  const cfg = RANGES[rangeKey] || RANGES['24h'];
  const since = Date.now() - cfg.hours * 3600 * 1000;
  const points = db.history(id, since, cfg.bucket).filter((p) => p.latency != null);

  const latencies = points.map((p) => p.latency).sort((a, b) => a - b);
  const avg = latencies.length
    ? Math.round(latencies.reduce((s, v) => s + v, 0) / latencies.length)
    : null;
  const p95 = latencies.length
    ? latencies[Math.min(latencies.length - 1, Math.floor(latencies.length * 0.95))]
    : null;

  const monitor = db.getMonitor(id);
  res.set('cache-control', 'no-store');
  res.json({
    monitor: { id: monitor.id, name: monitor.name, url: monitor.url },
    range: rangeKey,
    bucketMs: cfg.bucket,
    points,
    summary: {
      avg,
      min: latencies.length ? latencies[0] : null,
      max: latencies.length ? latencies[latencies.length - 1] : null,
      p95,
    },
  });
});

// ---------- admin api ----------

app.post('/api/admin/login', (req, res) => {
  const ip = req.ip || 'unknown';
  if (rateLimited(ip)) return res.status(429).json({ error: '尝试次数过多，请 10 分钟后再试' });

  const password = String(req.body?.password || '');
  const a = Buffer.from(password);
  const b = Buffer.from(ADMIN_PASSWORD);
  const ok = a.length === b.length && crypto.timingSafeEqual(a, b);
  if (!ok) return res.status(401).json({ error: '密码错误' });

  loginAttempts.delete(ip);
  res.cookie(COOKIE_NAME, issueToken(), {
    httpOnly: true,
    sameSite: 'lax',
    path: '/',
    maxAge: TOKEN_TTL,
    secure: req.secure,
  });
  res.json({ ok: true });
});

app.post('/api/admin/logout', (req, res) => {
  res.clearCookie(COOKIE_NAME, { path: '/' });
  res.json({ ok: true });
});

app.get('/api/admin/session', (req, res) => {
  res.json({ authed: isAuthed(req) });
});

app.get('/api/admin/monitors', requireAdmin, (req, res) => {
  const rows = db.listMonitors(false).map((m) => ({
    ...m,
    status: statusOf(m),
    uptime24h: (() => {
      const s = db.uptimeSince(m.id, Date.now() - 86400000);
      return s.pct == null ? null : Math.round(s.pct * 100) / 100;
    })(),
  }));
  res.json({ monitors: rows });
});

app.post('/api/admin/monitors', requireAdmin, (req, res) => {
  let url;
  try {
    url = assertUrl(String(req.body?.url || ''));
  } catch (err) {
    return res.status(400).json({ error: err.message });
  }
  const monitor = db.createMonitor({ ...req.body, url });
  res.json({ monitor });
});

app.put('/api/admin/monitors/:id', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  if (!db.getMonitor(id)) return res.status(404).json({ error: '监控不存在' });

  let url;
  try {
    url = assertUrl(String(req.body?.url || ''));
  } catch (err) {
    return res.status(400).json({ error: err.message });
  }
  const monitor = db.updateMonitor(id, { ...req.body, url });
  res.json({ monitor });
});

app.delete('/api/admin/monitors/:id', requireAdmin, (req, res) => {
  const monitor = db.getMonitor(Number(req.params.id));
  if (!monitor) return res.status(404).json({ error: '监控不存在' });
  // 自动发现的地址被手动删除后加入忽略名单，避免下次扫描又冒出来
  if (monitor.source === 'auto') db.ignoreDiscoveryUrl(monitor.url);
  db.deleteMonitor(monitor.id);
  res.json({ ok: true });
});

app.get('/api/admin/discover', requireAdmin, (req, res) => {
  const rows = db.listMonitors(false).filter((m) => m.source === 'auto');
  res.json({ ...discovery.status(), autoMonitors: rows.length });
});

app.post('/api/admin/discover', requireAdmin, async (req, res, next) => {
  try {
    const result = await discovery.run({ manual: true });
    res.json(result);
  } catch (err) {
    next(err);
  }
});

app.post('/api/admin/monitors/:id/check', requireAdmin, async (req, res, next) => {
  try {
    const result = await scheduler.runNow(Number(req.params.id));
    if (!result) return res.status(400).json({ error: '检测未执行（监控不存在或正在检测中）' });
    res.json({ result });
  } catch (err) {
    next(err);
  }
});

app.get('/api/admin/settings', requireAdmin, (req, res) => {
  res.json({ settings: db.getSettings() });
});

app.put('/api/admin/settings', requireAdmin, (req, res) => {
  const allowed = [
    'site_title',
    'site_description',
    'footer_text',
    'webhook_url',
    'retention_days',
    'stat_enabled',
    'stat_show_pv',
    'site_url',
    'friend_apply_enabled',
    'friend_group_name',
    'friend_group_desc',
  ];
  for (const key of allowed) {
    if (req.body && Object.prototype.hasOwnProperty.call(req.body, key)) {
      let value = req.body[key];
      if (key === 'retention_days') {
        value = Math.min(3650, Math.max(7, Number(value) || 90));
      } else if (['stat_enabled', 'stat_show_pv', 'friend_apply_enabled'].includes(key)) {
        value = value === 0 || value === '0' || value === false ? '0' : '1';
      }
      db.setSetting(key, String(value ?? '').slice(0, 1000));
    }
  }
  res.json({ settings: db.getSettings() });
});

// ---------- 互动统计（私域）：公开接口只吐数字，明细只走后台 ----------

const ACTION_LABELS = { like: '赞', collect: '收藏', useful: '有用', useless: '没用' };

function statEnabled() {
  return db.getSetting('stat_enabled') !== '0';
}

function statShowPv() {
  return db.getSetting('stat_show_pv') !== '0';
}

// 记录一次浏览。写失败绝不打扰访客，一律静默。
app.post('/api/e/collect', (req, res) => {
  res.status(204).end();
  if (!statEnabled()) return;
  try {
    if (engage.hitLimit(`c:${engage.clientIp(req)}`, 120)) return;
    engage.collect({
      path: req.body?.p,
      title: req.body?.t,
      vid: req.body?.vid,
      req,
    });
  } catch (err) {
    console.warn('[stat] 记录浏览失败:', err.message);
  }
});

// 点赞 / 收藏 / 有用 / 没用。同一访客再点即撤销，有用与没用互斥。
app.post('/api/e/act', (req, res) => {
  if (!statEnabled()) return res.status(403).json({ error: '互动统计已关闭' });
  if (engage.hitLimit(`a:${engage.clientIp(req)}`, 45)) {
    return res.status(429).json({ error: '操作太频繁，请稍后再试' });
  }
  try {
    const result = engage.act({
      path: req.body?.p,
      action: String(req.body?.a || ''),
      vid: req.body?.vid,
      req,
    });
    res.set('cache-control', 'no-store');
    res.json({ ok: true, ...result });
  } catch (err) {
    res.status(400).json({ error: err.message });
  }
});

// 批量读数字：列表页一次拿完所有卡片的赞数
app.get('/api/e/counts', (req, res) => {
  const vid = String(req.query.vid || '').slice(0, 64);
  let keys = req.query.keys ?? req.query.k ?? req.query.p ?? [];
  if (!Array.isArray(keys)) keys = [keys];

  const data = engage.countsBatch(keys.map(String), vid || null);
  if (!statShowPv()) {
    for (const item of Object.values(data)) delete item.pv;
  }

  res.set('cache-control', vid ? 'no-store' : 'public, max-age=20');
  res.json({ data, updatedAt: Date.now() });
});

// 零 JS 兜底：一张图就是一个数字
app.get('/api/e/count.svg', (req, res) => {
  const action = engage.ACTIONS.includes(String(req.query.a)) ? String(req.query.a) : 'like';
  const normalized = engage.normalizePath(String(req.query.p || '/')) || '/';
  const counts = engage.countsOf(normalized);
  const value = Number(counts[action] || 0);
  const label = String(req.query.label || ACTION_LABELS[action]).slice(0, 12);
  const color = String(req.query.color || '#6b7280').slice(0, 24);

  res.set({
    'content-type': 'image/svg+xml; charset=utf-8',
    'cache-control': 'public, max-age=60',
  });
  res.send(badgeSvg(label, String(value), color));
});

// 博客侧一行接入脚本
app.get(['/e.js', '/engage.js'], (req, res) => {
  res.set({
    'content-type': 'application/javascript; charset=utf-8',
    'cache-control': 'public, max-age=600',
  });
  res.sendFile(path.join(__dirname, '..', 'public', 'assets', 'engage.js'));
});

// ---------- 互动统计：后台明细 ----------

app.get('/api/admin/engage/overview', requireAdmin, (req, res) => {
  const days = Math.min(90, Math.max(3, Number(req.query.days) || 14));
  res.set('cache-control', 'no-store');
  res.json({
    ...engage.overview({ days }),
    settings: { enabled: statEnabled(), showPv: statShowPv() },
  });
});

app.get('/api/admin/engage/pages', requireAdmin, (req, res) => {
  const range = ['7d', '30d', '90d', 'all'].includes(String(req.query.range))
    ? String(req.query.range)
    : '30d';
  const sort = ['pv', 'uv', 'like', 'useful'].includes(String(req.query.sort))
    ? String(req.query.sort)
    : 'pv';
  const limit = Math.min(200, Math.max(1, Number(req.query.limit) || 50));

  res.set('cache-control', 'no-store');
  res.json({ range, sort, pages: engage.pageList({ range, sort, limit }) });
});

app.get('/api/admin/engage/page', requireAdmin, (req, res) => {
  const detail = engage.pageDetail(String(req.query.p || ''), {
    days: Math.min(90, Math.max(3, Number(req.query.days) || 30)),
  });
  if (!detail) return res.status(404).json({ error: '路径不合法' });
  res.set('cache-control', 'no-store');
  res.json({ page: detail });
});

app.post('/api/admin/engage/reset', requireAdmin, (req, res) => {
  if (req.body?.confirm !== 'RESET') {
    return res.status(400).json({ error: '请确认清空操作' });
  }
  engage.reset();
  res.json({ ok: true });
});

// ---------- 友链申请与友圈 ----------

function friendIpHash(req) {
  return crypto
    .createHash('sha256')
    .update(`friend|${engage.clientIp(req)}`)
    .digest('hex')
    .slice(0, 16);
}

app.post('/api/e/friend-apply', (req, res) => {
  if (db.getSetting('friend_apply_enabled') === '0') {
    return res.status(403).json({ error: '暂未开放友链申请' });
  }
  if (engage.hitLimit(`f:${engage.clientIp(req)}`, 5, 10 * 60 * 1000)) {
    return res.status(429).json({ error: '提交太频繁，请十分钟后再试' });
  }
  try {
    const link = friends.apply(req.body || {}, { ipHash: friendIpHash(req) });
    // 只回执单号，不回传任何联系方式
    res.json({ ok: true, id: link.id, message: '已收到，等站长审核' });
  } catch (err) {
    res.status(400).json({ error: err.message });
  }
});

app.get('/api/e/friends.json', (req, res) => {
  res.set('cache-control', 'public, max-age=300');
  // site / name 是公开信息（博客首页本来就有），申请页用来做「回到主站」
  res.json({
    site: db.getSetting('site_url') || '',
    name: db.getSetting('site_title') || '',
    links: friends.publicLinks(),
    updatedAt: Date.now(),
  });
});

/**
 * Butterfly 主题的友链页可以直吃这个接口。
 * 页面 front-matter 写 flink_url 指向这里，主题自己渲染，link.yml 就彻底不用了。
 * 字段名必须贴合主题的 link.yml 结构：link / name / avatar / descr。
 * class_name 必须存在（哪怕是空串），否则主题里的 replaceSymbol(undefined) 会抛错。
 */
app.get('/api/e/link.json', (req, res) => {
  res.set('cache-control', 'public, max-age=300');
  const group = String(db.getSetting('friend_group_name') || '');
  const groupDesc = String(db.getSetting('friend_group_desc') || '');
  res.json([
    {
      class_name: group,
      class_desc: groupDesc,
      link_list: friends.publicLinks().map((l) => ({
        link: l.url,
        name: l.name,
        avatar: l.avatar || '',
        descr: l.desc || '',
      })),
    },
  ]);
});

app.get('/api/e/moments.json', (req, res) => {
  const perFriend = Math.min(10, Math.max(1, Number(req.query.per) || 3));
  const limit = Math.min(60, Math.max(1, Number(req.query.limit) || 30));
  res.set('cache-control', 'public, max-age=300');
  res.json({
    friends: friends.momentCards({ perFriend, limit }),
    posts: friends.latestPosts(Math.min(100, Math.max(1, Number(req.query.posts) || 40))),
    updatedAt: Date.now(),
  });
});

app.get('/e/moments.js', (req, res) => {
  res.set({
    'content-type': 'application/javascript; charset=utf-8',
    'cache-control': 'public, max-age=600',
  });
  res.sendFile(path.join(__dirname, '..', 'public', 'assets', 'moments.js'));
});

app.get('/e/apply.js', (req, res) => {
  res.set({
    'content-type': 'application/javascript; charset=utf-8',
    'cache-control': 'public, max-age=600',
  });
  res.sendFile(path.join(__dirname, '..', 'public', 'assets', 'apply.js'));
});

app.get('/e/friends.js', (req, res) => {
  res.set({
    'content-type': 'application/javascript; charset=utf-8',
    'cache-control': 'public, max-age=600',
  });
  res.sendFile(path.join(__dirname, '..', 'public', 'assets', 'friends.js'));
});

app.get('/api/admin/friends', requireAdmin, (req, res) => {
  res.set('cache-control', 'no-store');
  res.json({
    links: friends.listLinks(String(req.query.status || '')),
    stats: friends.stats(),
    siteUrl: db.getSetting('site_url') || '',
  });
});

app.post('/api/admin/friends', requireAdmin, (req, res) => {
  try {
    res.json({ link: friends.createLink(req.body || {}) });
  } catch (err) {
    res.status(400).json({ error: err.message });
  }
});

app.put('/api/admin/friends/:id', requireAdmin, (req, res) => {
  try {
    res.json({ link: friends.updateLink(req.params.id, req.body || {}) });
  } catch (err) {
    res.status(400).json({ error: err.message });
  }
});

app.delete('/api/admin/friends/:id', requireAdmin, (req, res) => {
  res.json({ ok: friends.deleteLink(req.params.id) });
});

app.post('/api/admin/friends/:id/review', requireAdmin, (req, res) => {
  try {
    const link = friends.review(req.params.id, String(req.body?.status || ''), req.body?.note);
    res.json({ link });
  } catch (err) {
    res.status(400).json({ error: err.message });
  }
});

app.post('/api/admin/friends/:id/check', requireAdmin, async (req, res, next) => {
  try {
    res.json(await friends.check(req.params.id));
  } catch (err) {
    next(err);
  }
});

app.post('/api/admin/friends/fetch', requireAdmin, async (req, res, next) => {
  try {
    res.json(await friends.fetchAll({ forceDiscover: req.query.rediscover === '1' }));
  } catch (err) {
    next(err);
  }
});

app.get('/api/admin/moments', requireAdmin, (req, res) => {
  res.set('cache-control', 'no-store');
  res.json({
    posts: friends.latestPosts(Math.min(200, Math.max(1, Number(req.query.limit) || 60))),
    stats: friends.stats(),
  });
});

app.delete('/api/admin/moments/:id', requireAdmin, (req, res) => {
  const removed = db.prepare('DELETE FROM friend_post WHERE id = ?').run(Number(req.params.id));
  res.json({ ok: removed.changes > 0 });
});

// ---------- static ----------

const PUBLIC_DIR = path.join(__dirname, '..', 'public');
app.use(express.static(PUBLIC_DIR, { extensions: ['html'], maxAge: '5m' }));
app.get('/admin', (req, res) => res.sendFile(path.join(PUBLIC_DIR, 'admin.html')));
app.get('/healthz', (req, res) => res.json({ ok: true, uptime: process.uptime() }));

app.use((req, res) => {
  if (req.path.startsWith('/api/')) return res.status(404).json({ error: '接口不存在' });
  res.status(404).sendFile(path.join(PUBLIC_DIR, '404.html'));
});

app.use((err, req, res, next) => {
  console.error('[error]', err);
  if (res.headersSent) return next(err);
  res.status(500).json({ error: '服务器内部错误' });
});

// ---------- boot ----------

db.ensureSeed();
scheduler.start();
discovery.start();

// 每 12 小时清一次过期的访客明细，只留计数，不留人
const statCleanupTimer = setInterval(() => {
  try {
    const removed = engage.cleanup(Number(db.getSetting('retention_days')) || 90);
    if (removed) console.log(`[stat] 已清理过期访客明细 ${removed} 条`);
  } catch (err) {
    console.warn('[stat] 清理失败:', err.message);
  }
}, 12 * 3600 * 1000);
statCleanupTimer.unref?.();

// 友链 RSS：启动 1 分钟后先抓一轮，之后每 3 小时一次
const runFriendFetch = () => {
  friends
    .fetchAll()
    .then((result) => {
      if (result.busy) return;
      console.log(
        `[friend] 友圈抓取完成 ${result.ok}/${result.total}` +
          (result.failed ? `，失败 ${result.failed} 个` : '')
      );
    })
    .catch((err) => console.warn('[friend] 抓取失败:', err.message));
};
setTimeout(runFriendFetch, 60 * 1000).unref?.();
const friendTimer = setInterval(runFriendFetch, 3 * 3600 * 1000);
friendTimer.unref?.();

const server = app.listen(PORT, () => {
  console.log(`\n🧭 Beacon Monitor 已启动: http://localhost:${PORT}`);
  console.log(`   管理后台: http://localhost:${PORT}/admin`);
  if (!process.env.ADMIN_PASSWORD) {
    console.warn('   ⚠️  未设置 ADMIN_PASSWORD，正在使用默认密码 admin123，请尽快修改！');
  }
  console.log('');
});

function shutdown(signal) {
  console.log(`\n[${signal}] 正在优雅退出...`);
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 5000).unref();
}
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

module.exports = app;
