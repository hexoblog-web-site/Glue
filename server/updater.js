'use strict';

/**
 * 新版本检测。
 * 不自己改写容器：只负责「知道自己是不是旧了」，真正的升级交给
 *   - watchtower（推荐，全自动）
 *   - update.sh（手动一条命令）
 *
 * 依次尝试四个来源，按可靠性排序，任一成功即止：
 *   1. GitHub Releases API（发了 Release 时信息最全，含更新说明）
 *   2. GitHub Contents API（读 main 的 package.json，无 CDN 缓存，api.github.com 国内比 raw 稳）
 *   3. jsDelivr CDN（国内快，但 main 分支有缓存延迟，可能读到旧版本）
 *   4. raw.githubusercontent.com（最后兜底，国内经常不通）
 */

const { VERSION, REPO, IMAGE } = require('./version');

const CACHE_TTL = 30 * 60 * 1000;
const ERROR_TTL = 5 * 60 * 1000;
const TIMEOUT = 8000;

let cache = { at: 0, data: null };

function normalize(v) {
  return String(v || '')
    .trim()
    .replace(/^v/i, '')
    .split('+')[0];
}

function parse(v) {
  const core = normalize(v).split('-')[0];
  return core.split('.').map((n) => {
    const num = parseInt(n, 10);
    return Number.isFinite(num) ? num : 0;
  });
}

// 返回 1 表示 a 更新，-1 表示 a 更旧，0 相同
function compare(a, b) {
  const pa = parse(a);
  const pb = parse(b);
  const len = Math.max(pa.length, pb.length);
  for (let i = 0; i < len; i += 1) {
    const x = pa[i] || 0;
    const y = pb[i] || 0;
    if (x > y) return 1;
    if (x < y) return -1;
  }
  return 0;
}

async function getJson(url) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), TIMEOUT);
  try {
    const headers = {
      'user-agent': 'BeaconMonitor-UpdateCheck',
      accept: 'application/json',
    };
    if (process.env.GITHUB_TOKEN) {
      headers.authorization = `Bearer ${process.env.GITHUB_TOKEN}`;
    }
    const res = await fetch(url, { headers, signal: controller.signal });
    if (!res.ok) {
      const err = new Error(`HTTP ${res.status}`);
      err.status = res.status;
      throw err;
    }
    return await res.json();
  } catch (err) {
    if (err.name === 'AbortError') err.timeout = true;
    throw err;
  } finally {
    clearTimeout(timer);
  }
}

/** 取纯文本（GitHub Contents API 用 raw media type 直接返回文件内容） */
async function getText(url, accept) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), TIMEOUT);
  try {
    const headers = {
      'user-agent': 'BeaconMonitor-UpdateCheck',
      accept: accept || 'text/plain',
    };
    if (process.env.GITHUB_TOKEN) {
      headers.authorization = `Bearer ${process.env.GITHUB_TOKEN}`;
    }
    const res = await fetch(url, { headers, signal: controller.signal });
    if (!res.ok) {
      const err = new Error(`HTTP ${res.status}`);
      err.status = res.status;
      throw err;
    }
    return await res.text();
  } catch (err) {
    if (err.name === 'AbortError') err.timeout = true;
    throw err;
  } finally {
    clearTimeout(timer);
  }
}

const SOURCES = [
  async function fromRelease() {
    const release = await getJson(`https://api.github.com/repos/${REPO}/releases/latest`);
    const latest = normalize(release.tag_name || release.name);
    if (!latest) throw Object.assign(new Error('no tag'), { status: 404 });
    return {
      latest,
      url: release.html_url || `https://github.com/${REPO}/releases`,
      publishedAt: release.published_at ? Date.parse(release.published_at) : null,
      notes: String(release.body || '').slice(0, 1200),
      source: 'release',
    };
  },
  async function fromContents() {
    const text = await getText(
      `https://api.github.com/repos/${REPO}/contents/package.json?ref=main`,
      'application/vnd.github.raw'
    );
    const latest = normalize(JSON.parse(text).version);
    if (!latest) throw new Error('no version');
    return {
      latest,
      url: `https://github.com/${REPO}`,
      publishedAt: null,
      notes: '',
      source: 'github',
    };
  },
  async function fromJsdelivr() {
    const pkg = await getJson(`https://cdn.jsdelivr.net/gh/${REPO}@main/package.json`);
    return {
      latest: normalize(pkg.version),
      url: `https://github.com/${REPO}`,
      publishedAt: null,
      notes: '',
      source: 'jsdelivr',
    };
  },
  async function fromRaw() {
    const pkg = await getJson(`https://raw.githubusercontent.com/${REPO}/main/package.json`);
    return {
      latest: normalize(pkg.version),
      url: `https://github.com/${REPO}`,
      publishedAt: null,
      notes: '',
      source: 'raw',
    };
  },
];

async function fetchLatest() {
  let lastErr = null;
  for (const source of SOURCES) {
    try {
      const result = await source();
      if (result.latest) return result;
    } catch (err) {
      lastErr = err;
    }
  }

  if (lastErr && !lastErr.status) throw lastErr; // 网络类错误，值得提示用户
  return {
    latest: null,
    url: `https://github.com/${REPO}`,
    publishedAt: null,
    notes: '',
    source: 'none',
  };
}

async function check(options = {}) {
  const force = Boolean(options.force);
  const fresh = cache.data && Date.now() - cache.at < (cache.data.error ? ERROR_TTL : CACHE_TTL);
  if (!force && fresh) return cache.data;

  const base = {
    current: VERSION,
    repo: REPO,
    image: IMAGE,
    checkedAt: Date.now(),
  };

  let data;
  try {
    const latest = await fetchLatest();
    data = {
      ...base,
      ...latest,
      hasUpdate: latest.latest ? compare(latest.latest, VERSION) > 0 : false,
      error: null,
    };
  } catch (err) {
    const message = err.timeout
      ? '连接 GitHub 超时，请检查服务器网络后重试'
      : '无法连接 GitHub，请检查服务器网络（国内机器可配置代理）';
    data = {
      ...base,
      latest: null,
      hasUpdate: false,
      url: `https://github.com/${REPO}`,
      publishedAt: null,
      notes: '',
      source: 'none',
      error: message,
    };
  }

  cache = { at: Date.now(), data };
  return data;
}

module.exports = { check, compare, VERSION };
