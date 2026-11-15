'use strict';

/**
 * 友链申请与友圈聚合（规划中 1.1.0）
 *
 * 一条链：提交申请 → 站长审核 → 进友链库 → 自动发现 RSS → 定时抓文章 → 前台渲染。
 * 数据仍然只存在这台机器上，不对外共享。
 */

const crypto = require('crypto');
const { db, getSetting } = require('./db');
const feed = require('./feed');

const POST_PER_FRIEND = 20;
const POST_TOTAL = 500;
const FETCH_CONCURRENCY = 3;

db.exec(`
CREATE TABLE IF NOT EXISTS friend_link (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  name            TEXT    NOT NULL,
  url             TEXT    NOT NULL UNIQUE,
  avatar          TEXT,
  desc            TEXT,
  email           TEXT,
  rss             TEXT,
  status          TEXT    NOT NULL DEFAULT 'pending',
  mutual          INTEGER,
  note            TEXT,
  ip_hash         TEXT,
  created_at      INTEGER NOT NULL,
  reviewed_at     INTEGER,
  checked_at      INTEGER,
  feed_state      TEXT,
  feed_error      TEXT,
  feed_fetched_at INTEGER
);

CREATE TABLE IF NOT EXISTS friend_post (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  link_id      INTEGER NOT NULL REFERENCES friend_link(id) ON DELETE CASCADE,
  title        TEXT,
  url          TEXT    NOT NULL UNIQUE,
  summary      TEXT,
  published_at INTEGER,
  fetched_at   INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_friend_post_time ON friend_post (published_at DESC);
CREATE INDEX IF NOT EXISTS idx_friend_post_link ON friend_post (link_id);
`);

const STATUSES = ['pending', 'approved', 'rejected'];

// ---------- 通用 ----------

function cleanStr(raw, max) {
  return String(raw == null ? '' : raw)
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);
}

function normalizeSiteUrl(raw) {
  let parsed;
  try {
    parsed = new URL(String(raw || '').trim());
  } catch {
    throw new Error('地址格式不正确，需要以 http:// 或 https:// 开头');
  }
  if (!/^https?:$/.test(parsed.protocol)) throw new Error('只支持 http / https 地址');
  const path = parsed.pathname.replace(/\/+$/, '');
  return parsed.origin + path;
}

function myHost() {
  const raw = String(getSetting('site_url') || process.env.SITE_URL || '').trim();
  if (!raw) return '';
  try {
    return new URL(raw).host;
  } catch {
    return '';
  }
}

// ---------- 友链 CRUD ----------

const listAllStmt = db.prepare(
  'SELECT * FROM friend_link WHERE status = ? ORDER BY created_at DESC'
);
const listByStatus = db.prepare('SELECT * FROM friend_link ORDER BY created_at DESC');
const getStmt = db.prepare('SELECT * FROM friend_link WHERE id = ?');
const getByUrlStmt = db.prepare('SELECT * FROM friend_link WHERE url = ?');

function listLinks(status) {
  if (status && STATUSES.includes(status)) return listAllStmt.all(status);
  return listByStatus.all();
}

function getLink(id) {
  return getStmt.get(Number(id)) || null;
}

function createLink(input) {
  const url = normalizeSiteUrl(input.url);
  if (getByUrlStmt.get(url)) throw new Error('这个站点已经在列表里了');

  const row = {
    name: cleanStr(input.name, 40) || new URL(url).host,
    url,
    avatar: cleanStr(input.avatar, 300) || null,
    desc: cleanStr(input.desc, 120) || null,
    email: cleanStr(input.email, 120) || null,
    rss: cleanStr(input.rss, 300) || null,
    status: STATUSES.includes(input.status) ? input.status : 'pending',
    note: cleanStr(input.note, 200) || null,
    created_at: Date.now(),
    reviewed_at: input.status === 'approved' ? Date.now() : null,
  };

  const info = db
    .prepare(
      `INSERT INTO friend_link (name, url, avatar, desc, email, rss, status, note, created_at, reviewed_at)
       VALUES (@name, @url, @avatar, @desc, @email, @rss, @status, @note, @created_at, @reviewed_at)`
    )
    .run(row);

  return getLink(info.lastInsertRowid);
}

function updateLink(id, input) {
  const current = getLink(id);
  if (!current) throw new Error('友链不存在');

  const merged = {
    id,
    name: cleanStr(input.name ?? current.name, 40) || current.name,
    url: input.url ? normalizeSiteUrl(input.url) : current.url,
    avatar: cleanStr(input.avatar ?? current.avatar, 300) || null,
    desc: cleanStr(input.desc ?? current.desc, 120) || null,
    email: cleanStr(input.email ?? current.email, 120) || null,
    rss: cleanStr(input.rss ?? current.rss, 300) || null,
    note: cleanStr(input.note ?? current.note, 200) || null,
  };

  db.prepare(
    `UPDATE friend_link
        SET name = @name, url = @url, avatar = @avatar, desc = @desc,
            email = @email, rss = @rss, note = @note
      WHERE id = @id`
  ).run(merged);

  return getLink(id);
}

function deleteLink(id) {
  return db.prepare('DELETE FROM friend_link WHERE id = ?').run(Number(id)).changes > 0;
}

/** 公开申请：落一个 pending 单子，不做任何自动通过 */
function apply(input, { ipHash } = {}) {
  const name = cleanStr(input.name, 40);
  if (name.length < 2) throw new Error('站点名称太短');
  const url = normalizeSiteUrl(input.url);

  const email = cleanStr(input.email, 120);
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new Error('邮箱格式不正确');

  if (getByUrlStmt.get(url)) throw new Error('这个站点已经提交过了');

  const recent = ipHash
    ? db
        .prepare(
          "SELECT COUNT(*) AS n FROM friend_link WHERE ip_hash = ? AND created_at > ? "
        )
        .get(ipHash, Date.now() - 3600000).n
    : 0;
  if (recent >= 3) throw new Error('提交太频繁，请一小时后再试');

  const info = db
    .prepare(
      `INSERT INTO friend_link (name, url, avatar, desc, email, ip_hash, status, created_at)
       VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)`
    )
    .run(
      name,
      url,
      cleanStr(input.avatar, 300) || null,
      cleanStr(input.desc, 120) || null,
      email || null,
      ipHash || null,
      Date.now()
    );

  return getLink(info.lastInsertRowid);
}

function review(id, status, note) {
  if (!['approved', 'rejected', 'pending'].includes(status)) throw new Error('状态不合法');
  const link = getLink(id);
  if (!link) throw new Error('友链不存在');

  db.prepare(
    'UPDATE friend_link SET status = ?, note = ?, reviewed_at = ? WHERE id = ?'
  ).run(status, cleanStr(note, 200) || null, Date.now(), Number(id));

  return getLink(id);
}

// ---------- 检测与抓取 ----------

/** 互链检测 + 发现 feed，一次搞定，结果落库 */
async function check(id) {
  const link = getLink(id);
  if (!link) throw new Error('友链不存在');

  const host = myHost();
  const [mutual, found] = await Promise.all([
    host ? feed.hasBackLink(link.url, host).catch(() => null) : Promise.resolve(null),
    feed.discoverFeed(link.url).catch((err) => ({ error: err.message, code: err.code })),
  ]);

  const noFeed = found.error && found.code === 'NO_FEED';
  const patch = {
    id: Number(id),
    mutual: mutual === null ? null : mutual ? 1 : 0,
    checked_at: Date.now(),
    rss: found.feedUrl || link.rss || null,
    feed_state: found.error ? (noFeed ? 'none' : 'error') : 'ok',
    feed_error: found.error ? cleanStr(found.error, 200) : null,
  };

  db.prepare(
    `UPDATE friend_link
        SET mutual = @mutual, checked_at = @checked_at, rss = @rss,
            feed_state = @feed_state, feed_error = @feed_error
      WHERE id = @id`
  ).run(patch);

  if (!found.error && Array.isArray(found.posts)) {
    savePosts(Number(id), found.posts);
    db.prepare('UPDATE friend_link SET feed_fetched_at = ? WHERE id = ?').run(Date.now(), Number(id));
  }

  return { mutual, feedUrl: found.feedUrl || null, posts: found.posts?.length || 0, error: found.error || null };
}

function savePosts(linkId, posts) {
  const insert = db.prepare(
    `INSERT OR IGNORE INTO friend_post (link_id, title, url, summary, published_at, fetched_at)
     VALUES (?, ?, ?, ?, ?, ?)`
  );
  const now = Date.now();
  const tx = db.transaction(() => {
    for (const post of posts) {
      insert.run(
        linkId,
        cleanStr(post.title, 200) || '(无标题)',
        post.url,
        cleanStr(post.summary, 240) || null,
        post.publishedAt || null,
        now
      );
    }
  });
  tx();
}

/** 抓一条友链的 RSS */
async function fetchOne(link) {
  try {
    let posts;
    let feedUrl = link.rss;

    if (feedUrl) {
      const xml = await feed.fetchText(feedUrl, 15000);
      posts = feed.parseFeed(xml, feedUrl);
      if (!posts.length) throw new Error('feed 里没有文章');
    } else {
      const found = await feed.discoverFeed(link.url);
      feedUrl = found.feedUrl;
      posts = found.posts;
    }

    savePosts(link.id, posts);
    db.prepare(
      `UPDATE friend_link
          SET rss = ?, feed_state = 'ok', feed_error = NULL, feed_fetched_at = ?
        WHERE id = ?`
    ).run(feedUrl, Date.now(), link.id);

    return { id: link.id, name: link.name, ok: true, count: posts.length };
  } catch (err) {
    // NO_FEED = 站点能打开但确实没提供 feed，标灰；网络类问题标红，下次还会再试
    const state = err.code === 'NO_FEED' ? 'none' : 'error';
    const detail = err.detail ? `${err.message}（${err.detail}）` : err.message;
    db.prepare('UPDATE friend_link SET feed_state = ?, feed_error = ? WHERE id = ?').run(
      state,
      cleanStr(detail, 200),
      link.id
    );
    return { id: link.id, name: link.name, ok: false, error: detail };
  }
}

let fetching = false;

/** 抓所有已通过的友链 */
async function fetchAll({ forceDiscover = false } = {}) {
  if (fetching) return { busy: true };
  fetching = true;

  try {
    const links = db
      .prepare("SELECT * FROM friend_link WHERE status = 'approved' ORDER BY id ASC")
      .all()
      .map((link) => (forceDiscover ? { ...link, rss: null } : link));

    const results = await feed.mapLimit(links, FETCH_CONCURRENCY, fetchOne);
    trimPosts();

    return {
      total: links.length,
      ok: results.filter((r) => r.ok).length,
      failed: results.filter((r) => !r.ok).length,
      results,
    };
  } finally {
    fetching = false;
  }
}

/** 每站留最新 20 条，全局留 500 条，避免无限增长 */
function trimPosts() {
  db.exec(`
    DELETE FROM friend_post WHERE id IN (
      SELECT id FROM (
        SELECT id, ROW_NUMBER() OVER (
          PARTITION BY link_id ORDER BY COALESCE(published_at, fetched_at) DESC
        ) AS rn
        FROM friend_post
      ) WHERE rn > ${POST_PER_FRIEND}
    );
  `);
  db.exec(`
    DELETE FROM friend_post WHERE id NOT IN (
      SELECT id FROM friend_post
      ORDER BY COALESCE(published_at, fetched_at) DESC
      LIMIT ${POST_TOTAL}
    );
  `);
}

// ---------- 对外读 ----------

function publicLinks() {
  return db
    .prepare(
      `SELECT name, url, avatar, desc FROM friend_link
        WHERE status = 'approved' ORDER BY created_at ASC`
    )
    .all();
}

function latestPosts(limit = 40, linkId = null) {
  const rows = linkId
    ? db
        .prepare(
          `SELECT p.*, l.name AS friend_name, l.url AS friend_url, l.avatar AS friend_avatar
             FROM friend_post p JOIN friend_link l ON l.id = p.link_id
            WHERE p.link_id = ? AND l.status = 'approved'
            ORDER BY COALESCE(p.published_at, p.fetched_at) DESC LIMIT ?`
        )
        .all(Number(linkId), limit)
    : db
        .prepare(
          `SELECT p.*, l.name AS friend_name, l.url AS friend_url, l.avatar AS friend_avatar
             FROM friend_post p JOIN friend_link l ON l.id = p.link_id
            WHERE l.status = 'approved'
            ORDER BY COALESCE(p.published_at, p.fetched_at) DESC LIMIT ?`
        )
        .all(limit);

  return rows.map((r) => ({
    id: r.id,
    title: r.title,
    url: r.url,
    summary: r.summary,
    publishedAt: r.published_at || r.fetched_at,
    friend: { name: r.friend_name, url: r.friend_url, avatar: r.friend_avatar },
  }));
}

/** 朋友圈页面用：每个朋友一张卡，卡里放他那几条最新文章 */
function momentCards({ perFriend = 3, limit = 30 } = {}) {
  const friends = db
    .prepare(
      `SELECT id, name, url, avatar, desc, feed_state, feed_error
         FROM friend_link WHERE status = 'approved' ORDER BY name ASC`
    )
    .all();

  const postsOf = db.prepare(
    `SELECT id, title, url, summary, COALESCE(published_at, fetched_at) AS ts
       FROM friend_post WHERE link_id = ?
       ORDER BY ts DESC LIMIT ?`
  );

  const cards = friends
    .map((f) => {
      const posts = postsOf.all(f.id, perFriend);
      return {
        id: f.id,
        name: f.name,
        url: f.url,
        avatar: f.avatar,
        desc: f.desc,
        state: f.feed_state,
        error: f.feed_error,
        posts,
        latestAt: posts.length ? posts[0].ts : 0,
      };
    })
    .filter((card) => card.posts.length)
    .sort((a, b) => b.latestAt - a.latestAt)
    .slice(0, clampLimit(limit));

  return cards;
}

function clampLimit(value, fallback = 30, max = 60) {
  return Math.min(max, Math.max(1, Number(value) || fallback));
}

/** 钓鱼：随机捞一篇友链文章 */
function randomPost() {
  const row = db
    .prepare(
      `SELECT p.id, p.title, p.url, p.summary, COALESCE(p.published_at, p.fetched_at) AS ts,
              l.name AS friend_name, l.url AS friend_url, l.avatar AS friend_avatar
         FROM friend_post p JOIN friend_link l ON l.id = p.link_id
        WHERE l.status = 'approved'
        ORDER BY RANDOM() LIMIT 1`
    )
    .get();

  if (!row) return null;
  return {
    id: row.id,
    title: row.title,
    url: row.url,
    summary: row.summary,
    publishedAt: row.ts,
    friend: { name: row.friend_name, url: row.friend_url, avatar: row.friend_avatar },
  };
}

function stats() {
  const pending = db
    .prepare("SELECT COUNT(*) AS n FROM friend_link WHERE status = 'pending'")
    .get().n;
  const approved = db
    .prepare("SELECT COUNT(*) AS n FROM friend_link WHERE status = 'approved'")
    .get().n;
  const posts = db.prepare('SELECT COUNT(*) AS n FROM friend_post').get().n;
  const broken = db
    .prepare("SELECT COUNT(*) AS n FROM friend_link WHERE status = 'approved' AND feed_state = 'error'")
    .get().n;
  return { pending, approved, posts, broken };
}

module.exports = {
  listLinks,
  getLink,
  createLink,
  updateLink,
  deleteLink,
  apply,
  review,
  check,
  fetchAll,
  publicLinks,
  latestPosts,
  momentCards,
  randomPost,
  stats,
};
