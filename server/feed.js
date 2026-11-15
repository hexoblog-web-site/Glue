'use strict';

/**
 * 极简 RSS / Atom 读取器，不引第三方依赖。
 * 目标不是「完整实现规范」，而是把各类博客的 feed 尽量都读出来。
 */

const UA =
  'Mozilla/5.0 (compatible; BeaconMonitor/1.0; +https://github.com/hexoblog-web-site/beacon-monitor)';

const CANDIDATES = ['/atom.xml', '/feed', '/rss.xml', '/index.xml', '/feed.xml', '/atom', '/rss'];

const ENTITY_MAP = {
  amp: '&',
  lt: '<',
  gt: '>',
  quot: '"',
  apos: "'",
  nbsp: ' ',
  hellip: '…',
  mdash: '—',
  ndash: '–',
  ldquo: '“',
  rdquo: '”',
  lsquo: '‘',
  rsquo: '’',
};

function decodeEntities(text) {
  return String(text).replace(/&(#x?[0-9a-fA-F]+|[a-zA-Z]+);/g, (all, code) => {
    if (code[0] === '#') {
      const isHex = code[1] === 'x' || code[1] === 'X';
      const num = parseInt(code.slice(isHex ? 2 : 1), isHex ? 16 : 10);
      if (Number.isFinite(num) && num > 0 && num < 0x110000) {
        try {
          return String.fromCodePoint(num);
        } catch {
          return all;
        }
      }
      return all;
    }
    return Object.prototype.hasOwnProperty.call(ENTITY_MAP, code.toLowerCase())
      ? ENTITY_MAP[code.toLowerCase()]
      : all;
  });
}

function stripCdata(text) {
  return String(text).replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, '$1');
}

function stripTags(text) {
  return String(text)
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<[^>]*>/g, ' ');
}

function cleanText(raw) {
  // 顺序很重要：先剥标签，再解码实体；解码后可能又冒出 < 尖括号，所以再剥一遍
  const stripped = stripTags(stripCdata(raw));
  const decoded = decodeEntities(stripped);
  return decodeEntities(stripTags(decoded))
    .replace(/\s+/g, ' ')
    .trim();
}

function pickTag(block, names) {
  for (const name of names) {
    const re = new RegExp(`<${name}(?:\\s[^>]*)?>([\\s\\S]*?)<\\/${name}>`, 'i');
    const hit = block.match(re);
    if (hit && hit[1].trim()) return hit[1];
  }
  return '';
}

function absolutize(raw, baseUrl) {
  const value = decodeEntities(String(raw || '').trim());
  if (!value) return '';
  try {
    return new URL(value, baseUrl).toString();
  } catch {
    return '';
  }
}

function pickLink(block, feedUrl) {
  const plain = stripCdata(pickTag(block, ['link'])).trim();
  if (/^https?:\/\//i.test(plain)) return absolutize(plain, feedUrl);

  const hrefMatch =
    block.match(/<link[^>]*rel=["']alternate["'][^>]*href=["']([^"']+)["']/i) ||
    block.match(/<link[^>]*href=["']([^"']+)["'][^>]*\/?>/i);
  if (hrefMatch) return absolutize(hrefMatch[1], feedUrl);

  const guid = stripCdata(pickTag(block, ['guid', 'id'])).trim();
  if (/^https?:\/\//i.test(guid)) return absolutize(guid, feedUrl);

  return '';
}

function parseDate(raw) {
  const value = cleanText(raw);
  if (!value) return null;
  const ts = Date.parse(value);
  return Number.isFinite(ts) ? ts : null;
}

/** 解析 feed 内容，返回文章数组；解析不出条目时返回空数组 */
function parseFeed(xml, feedUrl) {
  const text = String(xml || '');
  const blocks = [];

  for (const m of text.matchAll(/<item(?:\s[^>]*)?>([\s\S]*?)<\/item>/gi)) blocks.push(m[1]);
  if (!blocks.length) {
    for (const m of text.matchAll(/<entry(?:\s[^>]*)?>([\s\S]*?)<\/entry>/gi)) blocks.push(m[1]);
  }

  const posts = [];
  const seen = new Set();

  for (const block of blocks) {
    const url = pickLink(block, feedUrl);
    if (!url || seen.has(url)) continue;
    seen.add(url);

    const title = cleanText(pickTag(block, ['title'])).slice(0, 200);
    // 有的 feed 把 HTML 硬截断，会留下没闭合的 <a href="...，截断后再清一次
    const desc = cleanText(
      pickTag(block, ['description', 'summary', 'content:encoded', 'content'])
    )
      .slice(0, 180)
      .replace(/<[^>]*$/, '')
      .trim();
    const publishedAt = parseDate(
      pickTag(block, ['pubDate', 'published', 'updated', 'dc:date', 'date'])
    );

    posts.push({
      title: title || '(无标题)',
      url,
      summary: desc,
      publishedAt: publishedAt || null,
    });
  }

  return posts;
}

function looksLikeFeed(text) {
  return /<(rss|feed|channel)[\s>]/i.test(text);
}

async function fetchText(url, timeoutMs = 8000) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(url, {
      headers: {
        'user-agent': UA,
        accept: 'application/rss+xml, application/atom+xml, application/xml, text/xml, text/html, */*',
      },
      redirect: 'follow',
      signal: controller.signal,
    });
    if (!res.ok) throw new Error(`对方返回 HTTP ${res.status}`);
    return await res.text();
  } catch (err) {
    if (err && (err.name === 'AbortError' || err.code === 'ABORT_ERR')) {
      throw new Error(`请求超时（${Math.round(timeoutMs / 1000)} 秒）`);
    }
    if (err && /fetch failed|ENOTFOUND|ECONNREFUSED|CERT/i.test(err.message || '')) {
      throw new Error('无法连接到对方站点');
    }
    throw err;
  } finally {
    clearTimeout(timer);
  }
}

/**
 * 找出站点真正的 feed 地址并顺便抓一次。
 *
 * 为什么是串行而不是并行：常见路径一次并发 7 个，很容易被对方的 CDN / WAF
 * 判定成扫描，直接 reset 或者 429，结果就是「明明有 RSS 却报没有」。
 * 改成按概率顺序串行试，第一个命中就停，多数站点第一个请求就结束，
 * 比并发更慢不了多少，但不会再被拦。
 */
async function discoverFeed(siteUrl) {
  let origin;
  try {
    origin = new URL(siteUrl).origin;
  } catch {
    throw new Error('地址不合法');
  }

  const errors = [];
  let netFails = 0;

  async function probe(url, timeout) {
    try {
      const xml = await fetchText(url, timeout);
      if (!looksLikeFeed(xml)) throw new Error('返回的不是 feed');
      const posts = parseFeed(xml, url);
      if (!posts.length) throw new Error('feed 里没有条目');
      netFails = 0;
      return { feedUrl: url, posts };
    } catch (err) {
      errors.push(err);
      // HTTP 错误说明站点是活的；连续两次连接层失败就别再一个个试了
      if (/HTTP \d/.test(err.message || '')) netFails = 0;
      else netFails += 1;
      return null;
    }
  }

  // 第一轮：按命中概率从高到低，串行，谁先成功算谁。
  // 给整轮一个总预算，避免某个卡死的站点把 7 个路径各等 8 秒。
  const deadline = Date.now() + 25000;
  for (const pathname of CANDIDATES) {
    if (netFails >= 2) break;
    const left = deadline - Date.now();
    if (left < 1200) break;
    const hit = await probe(new URL(pathname, origin).toString(), Math.min(8000, left));
    if (hit) return hit;
  }

  // 第二轮：首页里找 <link rel="alternate" type="application/rss+xml">
  if (netFails < 2) {
    try {
      const html = await fetchText(`${origin}/`, 8000);
      const linkTag = html.match(
        /<link[^>]+type=["']application\/(?:rss|atom)\+xml["'][^>]*>/i
      );
      const href = linkTag && linkTag[0].match(/href=["']([^"']+)["']/i);
      if (href) {
        const hit = await probe(absolutize(href[1], origin), 10000);
        if (hit) return hit;
      }
    } catch (err) {
      errors.push(err);
    }
  }

  // 区分「站点能连上但确实没有 feed」和「压根连不上」，后者要报错让人去查网络
  const reachable = errors.some((e) => /HTTP \d/.test(e.message || ''));
  const err = new Error(
    reachable ? '对方站点没有提供 RSS / Atom' : '抓取失败：连不上对方站点或请求被拦截'
  );
  err.code = reachable ? 'NO_FEED' : 'NETWORK';
  err.detail = errors.slice(0, 3).map((e) => e.message).join('；');
  throw err;
}

/** 对方站点上是否挂了指向本站的链接（用于友链互检，仅供参考） */
async function hasBackLink(siteUrl, myHost) {
  let origin;
  try {
    origin = new URL(siteUrl).origin;
  } catch {
    return null;
  }

  const bare = String(myHost || '')
    .replace(/^https?:\/\//i, '')
    .replace(/^www\./i, '')
    .replace(/\/.*$/, '')
    .toLowerCase();
  if (!bare) return null;

  const pages = ['/', '/links/', '/friends/', '/link/'];
  for (const page of pages) {
    try {
      const html = await fetchText(origin + page, 7000);
      if (html.toLowerCase().includes(bare)) return true;
    } catch {
      /* 换下一个 */
    }
  }
  return false;
}

/** 并发上限控制 */
async function mapLimit(items, limit, worker) {
  const results = [];
  let cursor = 0;
  const runners = Array.from({ length: Math.min(limit, items.length) }, async () => {
    while (cursor < items.length) {
      const index = cursor;
      cursor += 1;
      results[index] = await worker(items[index], index);
    }
  });
  await Promise.all(runners);
  return results;
}

module.exports = {
  parseFeed,
  discoverFeed,
  hasBackLink,
  fetchText,
  mapLimit,
  cleanText,
  absolutize,
  UA,
};
