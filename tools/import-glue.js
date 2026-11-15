'use strict';

/**
 * Glue → Beacon Monitor 点赞数据导入
 *
 * Glue（PHP 版）把点赞存在 MySQL 的 `{prefix}log` 表里：一行 = 一个人对一篇文章点过一次。
 * 这里把同一份明细原样搬进 beacon 的 `engage_action`，而不是只搬一个总数——
 * 这样「一人一篇只能点一次」的语义、每篇的点赞人、时间分布都保住了。
 *
 * 输入目录里放两个 TSV（由 tools/migrate-from-glue.sh 生成，三列都用 base64，
 * 避开标题里的制表符/换行把行拆坏）：
 *
 *   likes.tsv   B64(page_key)  B64(visitor_hash)  unix_ts
 *   pages.tsv   B64(page_key)  B64(title)         updated_at_ms
 *
 * 用法：
 *   node tools/import-glue.js /data/glue-migrate
 *   node tools/import-glue.js /data/glue-migrate --dry-run
 *
 * 幂等：重复跑不会翻倍，已经存在的 (path, action, visitor) 会被跳过。
 */

const fs = require('fs');
const path = require('path');
const { db } = require('../server/db');

// 只为建表：engage.js 模块加载时会把 engage_* 四张表建好
require('../server/engage');

const args = process.argv.slice(2);
const dryRun = args.includes('--dry-run');
const dir = args.find((a) => !a.startsWith('-')) || process.env.GLUE_EXPORT_DIR || '/data/glue-migrate';

function b64(value) {
  if (!value) return '';
  try {
    return Buffer.from(value, 'base64').toString('utf8');
  } catch {
    return '';
  }
}

function readTsv(file) {
  if (!fs.existsSync(file)) return [];
  return fs
    .readFileSync(file, 'utf8')
    .split('\n')
    .map((line) => line.replace(/\r$/, ''))
    .filter((line) => line.trim() !== '')
    .map((line) => line.split('\t'));
}

const likesFile = path.join(dir, 'likes.tsv');
const pagesFile = path.join(dir, 'pages.tsv');

if (!fs.existsSync(dir)) {
  console.error(`\n✗ 找不到导出目录：${dir}`);
  console.error('  请先运行 tools/migrate-from-glue.sh 生成数据。\n');
  process.exit(1);
}

const likeRows = readTsv(likesFile);
const pageRows = readTsv(pagesFile);

if (!fs.existsSync(likesFile)) {
  console.error(`\n✗ 找不到 ${likesFile}`);
  console.error('  请先运行 tools/migrate-from-glue.sh 生成数据。\n');
  process.exit(1);
}

if (likeRows.length === 0) {
  console.log(`\n  ${likesFile} 是空的 —— Glue 里本来就没有点赞记录，跳过。`);
}

const insertLike = db.prepare(
  'INSERT OR IGNORE INTO engage_action (path, action, visitor, ts) VALUES (?, ?, ?, ?)'
);
const upsertPage = db.prepare(
  `INSERT INTO page_meta (path, title, updated_at) VALUES (?, ?, ?)
   ON CONFLICT(path) DO UPDATE SET
     title      = CASE WHEN excluded.title <> '' THEN excluded.title ELSE page_meta.title END,
     updated_at = MAX(page_meta.updated_at, excluded.updated_at)`
);

const stats = { rows: 0, inserted: 0, skipped: 0, bad: 0, pages: 0, paths: new Set() };

const run = db.transaction(() => {
  for (const cols of likeRows) {
    if (cols.length < 2) {
      stats.bad += 1;
      continue;
    }
    const pagePath = b64(cols[0]);
    const visitor = b64(cols[1]);
    const ts = Number(cols[2]) > 0 ? Number(cols[2]) * 1000 : Date.now();

    if (!pagePath || !visitor) {
      stats.bad += 1;
      continue;
    }

    stats.rows += 1;
    stats.paths.add(pagePath);

    if (dryRun) {
      stats.inserted += 1;
      continue;
    }

    const info = insertLike.run(pagePath, 'like', visitor, ts);
    if (info.changes > 0) stats.inserted += 1;
    else stats.skipped += 1;
  }

  for (const cols of pageRows) {
    if (cols.length < 2) continue;
    const pagePath = b64(cols[0]);
    if (!pagePath) continue;
    const title = b64(cols[1]);
    const updated = Number(cols[2]) > 0 ? Number(cols[2]) : Date.now();
    if (!dryRun) upsertPage.run(pagePath, title, updated);
    stats.pages += 1;
  }
});

run();

const total = db.prepare("SELECT COUNT(*) AS n FROM engage_action WHERE action = 'like'").get().n;

console.log('');
console.log(`  来源文件     ${likesFile}`);
console.log(`  源记录数     ${stats.rows}`);
console.log(`  新导入       ${stats.inserted}${dryRun ? '（试运行，未写入）' : ''}`);
console.log(`  已存在跳过   ${stats.skipped}`);
console.log(`  异常行       ${stats.bad}`);
console.log(`  涉及文章     ${stats.paths.size}`);
console.log(`  页面标题     ${stats.pages}`);
console.log(`  库内赞数合计 ${dryRun ? '—' : total}`);
console.log('');
