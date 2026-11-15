'use strict';

const tls = require('tls');
const { URL } = require('url');

const USER_AGENT =
  'Mozilla/5.0 (compatible; BeaconMonitor/1.0; +https://github.com/hexoblog-web-site/beacon-monitor) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

function matchStatus(expr, code) {
  if (code == null) return false;
  const rules = String(expr || '200-399').split(',');
  return rules.some((raw) => {
    const part = raw.trim();
    if (!part) return false;
    if (/^[1-5]xx$/i.test(part)) return Math.floor(code / 100) === Number(part[0]);
    if (part.includes('-')) {
      const [a, b] = part.split('-').map((v) => Number(v.trim()));
      return Number.isFinite(a) && Number.isFinite(b) && code >= a && code <= b;
    }
    return Number(part) === code;
  });
}

async function runHttpCheck(monitor) {
  const timeout = Math.max(1000, Number(monitor.timeout_ms) || 10000);
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeout);
  const started = Date.now();

  try {
    const res = await fetch(monitor.url, {
      method: monitor.method === 'HEAD' ? 'HEAD' : 'GET',
      redirect: 'follow',
      signal: controller.signal,
      headers: {
        'user-agent': USER_AGENT,
        accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'accept-language': 'zh-CN,zh;q=0.9,en;q=0.6',
        'cache-control': 'no-cache',
      },
    });

    let keywordOk = true;
    let keywordError = null;

    if (monitor.keyword) {
      const text = await res.text();
      keywordOk = text.includes(monitor.keyword);
      if (!keywordOk) keywordError = `页面中未找到关键字「${monitor.keyword}」`;
    } else if (res.body) {
      // 不关心响应体，直接丢弃，避免占用带宽
      res.body.cancel().catch(() => {});
    }

    const latency = Date.now() - started;
    const statusOk = matchStatus(monitor.expected_status, res.status);
    const ok = statusOk && keywordOk;

    return {
      ok,
      statusCode: res.status,
      latency,
      error: ok ? null : keywordError || `状态码 ${res.status} 不符合预期（${monitor.expected_status}）`,
      ts: Date.now(),
    };
  } catch (err) {
    const latency = Date.now() - started;
    let message = err.name === 'AbortError' ? `请求超时（>${timeout}ms）` : err.message;
    const cause = err.cause && (err.cause.code || err.cause.message);
    if (cause) message = String(cause);
    if (/ENOTFOUND|EAI_AGAIN/i.test(message)) message = `DNS 解析失败（${message}）`;
    if (/CERT|certificate/i.test(message)) message = `证书错误（${message}）`;
    if (/ECONNREFUSED/i.test(message)) message = '连接被拒绝（ECONNREFUSED）';
    if (/ECONNRESET/i.test(message)) message = '连接被重置（ECONNRESET）';
    return { ok: false, statusCode: null, latency, error: message, ts: Date.now() };
  } finally {
    clearTimeout(timer);
  }
}

/**
 * 查询站点证书剩余天数（带 6 小时内存缓存，避免高频 SSL 握手）
 */
const certCache = new Map();
const certInflight = new Map();
const CERT_TTL = 6 * 3600 * 1000;

function getCertInfo(rawUrl) {
  let parsed;
  try {
    parsed = new URL(rawUrl);
  } catch {
    return Promise.resolve(null);
  }
  if (parsed.protocol !== 'https:') return Promise.resolve(null);

  const host = parsed.hostname;
  const cached = certCache.get(host);
  if (cached && Date.now() - cached.at < CERT_TTL) return Promise.resolve(cached.value);
  if (certInflight.has(host)) return certInflight.get(host);

  const task = new Promise((resolve) => {
    let url;
    try {
      url = new URL(rawUrl);
    } catch {
      return resolve(null);
    }
    if (url.protocol !== 'https:') return resolve(null);

    let settled = false;
    const done = (value) => {
      if (settled) return;
      settled = true;
      certCache.set(host, { at: Date.now(), value });
      resolve(value);
    };

    const socket = tls.connect(
      { host, port: Number(url.port) || 443, servername: host, rejectUnauthorized: false, timeout: 8000 },
      () => {
        const cert = socket.getPeerCertificate();
        socket.end();
        if (!cert || !cert.valid_to) return done(null);
        const expiresAt = new Date(cert.valid_to).getTime();
        const days = Math.floor((expiresAt - Date.now()) / 86400000);
        done({
          days,
          expiresAt,
          issuer: cert.issuer ? (cert.issuer.O || cert.issuer.CN || '') : '',
          subject: cert.subject ? cert.subject.CN || '' : '',
        });
      }
    );

    socket.on('timeout', () => {
      socket.destroy();
      done(null);
    });
    socket.on('error', () => {
      socket.destroy();
      done(null);
    });
  });

  certInflight.set(host, task);
  task.finally(() => certInflight.delete(host));
  return task;
}

function statusOf(monitor) {
  if (!monitor || monitor.last_ts == null) return 'pending';
  if (!monitor.last_ok) return 'down';
  const slow = Number(monitor.slow_ms) || 5000;
  if (monitor.last_latency != null && monitor.last_latency > slow) return 'slow';
  return 'up';
}

module.exports = { runHttpCheck, getCertInfo, matchStatus, statusOf };
