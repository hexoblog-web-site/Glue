'use strict';

/**
 * 自动发现：零配置收录服务器上已经在跑的服务
 *
 * 两条探测路径（互为补充）：
 *  1) Docker socket（只读）→ 读取运行中的容器及其映射到宿主机的端口
 *  2) 宿主网关常见 Web 端口 TCP 探测 → 覆盖非 Docker 安装的服务（Nginx、Hexo、Node 等）
 *
 * 探测到能正常返回 HTML/JSON 的地址后自动建监控项，分组为「自动发现」；
 * 用户手动删除的地址会进入忽略名单，不会被反复收录。
 */

const http = require('http');
const net = require('net');

const db = require('./db');

const SOCK = process.env.DOCKER_SOCK || '/var/run/docker.sock';
const GATEWAY = process.env.HOST_GATEWAY || 'host.docker.internal';
const GROUP = process.env.DISCOVER_GROUP || '自动发现';
const SELF_PORT = Number(process.env.PORT) || 8080;
const MAX_PER_RUN = Number(process.env.DISCOVER_MAX) || 50;

const DEFAULT_PORTS = [
  80, 443, 3000, 4000, 4321, 5000, 5173, 7860, 8000, 8008, 8080, 8081, 8088, 8090, 8443, 8888, 9000, 9090,
  2368, 5001, 8501, 10086,
];

const PORTS = (process.env.DISCOVER_PORTS || '')
  .split(',')
  .map((p) => Number(p.trim()))
  .filter((p) => Number.isInteger(p) && p > 0 && p < 65536);
const PROBE_PORTS = PORTS.length ? PORTS : DEFAULT_PORTS;

const UA = 'Mozilla/5.0 (compatible; BeaconMonitor/1.0; AutoDiscovery)';

let lastRun = null;
let running = false;

// ---------- Docker socket ----------

function dockerGet(pathname, timeout = 4000) {
  return new Promise((resolve, reject) => {
    const req = http.request(
      { socketPath: SOCK, path: pathname, method: 'GET', timeout, headers: { Host: 'docker', Accept: 'application/json' } },
      (res) => {
        if (res.statusCode !== 200) {
          res.resume();
          return reject(new Error(`Docker API ${res.statusCode}`));
        }
        let data = '';
        res.setEncoding('utf8');
        res.on('data', (chunk) => {
          data += chunk;
          if (data.length > 4 * 1024 * 1024) req.destroy(new Error('Docker API 响应过大'));
        });
        res.on('end', () => {
          try {
            resolve(JSON.parse(data));
          } catch (err) {
            reject(err);
          }
        });
      }
    );
    req.on('timeout', () => req.destroy(new Error('Docker API 超时')));
    req.on('error', reject);
    req.end();
  });
}

function cleanName(raw) {
  const name = String(raw || '').replace(/^\//, '').trim();
  // 去掉 compose 生成的无意义后缀，例如 blog-web-1
  return name.replace(/[-_](web|app|server)[-_]?\d+$/i, '').replace(/[-_]\d+$/, '') || name;
}

async function fromDocker() {
  let containers;
  try {
    containers = await dockerGet('/containers/json');
  } catch (err) {
    if (!/ENOENT|ECONNREFUSED|EACCES/.test(err.message)) {
      console.warn('[discover] 读取 Docker 信息失败:', err.message);
    }
    return { candidates: [], ok: false, reason: err.message };
  }
  if (!Array.isArray(containers)) return { candidates: [], ok: false, reason: 'Docker 返回格式异常' };

  const candidates = [];
  for (const c of containers) {
    const name = cleanName((c.Names || [])[0]);
    const isSelf = (c.Names || []).some((n) => /beacon[-_]monitor|lighthouse/i.test(n));
    if (isSelf) continue;

    const seen = new Set();
    for (const p of c.Ports || []) {
      if (p.Type !== 'tcp' || !p.PublicPort || seen.has(p.PublicPort)) continue;
      seen.add(p.PublicPort);
      if (p.PublicPort === SELF_PORT) continue;
      const scheme = p.PublicPort === 443 || p.PublicPort === 8443 ? 'https' : 'http';
      candidates.push({
        url: `${scheme}://${GATEWAY}:${p.PublicPort}/`,
        name,
        origin: 'docker',
        detail: c.Image || '',
      });
    }
  }
  return { candidates, ok: true, total: containers.length };
}

// ---------- 端口探测 ----------

function tcpOpen(host, port, timeout = 800) {
  return new Promise((resolve) => {
    const socket = net.connect({ host, port, timeout });
    let done = false;
    const finish = (value) => {
      if (done) return;
      done = true;
      socket.destroy();
      resolve(value);
    };
    socket.once('connect', () => finish(true));
    socket.once('timeout', () => finish(false));
    socket.once('error', () => finish(false));
  });
}

async function fromPorts() {
  const ports = PROBE_PORTS.filter((p) => p !== SELF_PORT);
  const candidates = [];
  const batch = 8;
  for (let i = 0; i < ports.length; i += batch) {
    const slice = ports.slice(i, i + batch);
    const results = await Promise.all(slice.map((p) => tcpOpen(GATEWAY, p)));
    results.forEach((open, idx) => {
      if (!open) return;
      const port = slice[idx];
      const scheme = port === 443 || port === 8443 ? 'https' : 'http';
      candidates.push({ url: `${scheme}://${GATEWAY}:${port}/`, name: `端口 ${port}`, origin: 'port', detail: '' });
    });
  }
  return candidates;
}

// ---------- HTTP 探测 ----------

function decodeEntities(str) {
  return String(str)
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/\s+/g, ' ')
    .trim();
}

async function probeHttp(url, timeout = 4500) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeout);
  try {
    const res = await fetch(url, {
      redirect: 'follow',
      signal: controller.signal,
      headers: { 'user-agent': UA, accept: 'text/html,application/json;q=0.9,*/*;q=0.8' },
    });
    if (res.status >= 400) {
      res.body?.cancel().catch(() => {});
      return null;
    }
    const type = res.headers.get('content-type') || '';
    let title = '';
    if (/text\/html|text\/plain|application\/xhtml/i.test(type)) {
      const text = (await res.text()).slice(0, 30000);
      const match = text.match(/<title[^>]*>([\s\S]*?)<\/title>/i);
      if (match) title = decodeEntities(match[1]).slice(0, 40);
    } else {
      res.body?.cancel().catch(() => {});
    }
    return { status: res.status, title, contentType: type };
  } catch {
    return null;
  } finally {
    clearTimeout(timer);
  }
}

// ---------- 主流程 ----------

function normalizeUrl(raw) {
  try {
    const u = new URL(raw);
    u.hash = '';
    return u.toString();
  } catch {
    return null;
  }
}

async function run({ manual = false } = {}) {
  const disabled = process.env.AUTO_DISCOVER === '0';
  if (disabled && !manual) return { disabled: true, created: [] };
  if (running) return { busy: true, created: [] };
  running = true;

  try {
    const existing = new Set(db.listMonitors(false).map((m) => normalizeUrl(m.url)).filter(Boolean));
    const ignored = db.ignoredUrls();

    const docker = await fromDocker();
    const portCandidates = await fromPorts();
    const all = [...docker.candidates, ...portCandidates];

    const seen = new Set();
    const created = [];
    let probed = 0;

    for (const item of all) {
      if (created.length >= MAX_PER_RUN) break;
      const url = normalizeUrl(item.url);
      if (!url || seen.has(url) || existing.has(url) || ignored.has(url)) continue;
      seen.add(url);

      // 带端口的地址只保留一个根路径探测，避免同服务多路径
      probed += 1;
      const probe = await probeHttp(url);
      if (!probe) continue;

      const name = (probe.title || item.name || url).slice(0, 60);
      const monitor = db.createAutoMonitor({
        name,
        url,
        group_name: GROUP,
        interval_sec: 120,
        timeout_ms: 10000,
        slow_ms: 5000,
        expected_status: '200-399',
        sort: 900,
        source: 'auto',
        auto_key: url,
      });
      if (monitor) {
        created.push(monitor);
        console.log(`[discover] 新增监控 ${name} → ${url}`);
      }
    }

    lastRun = { at: Date.now(), docker: docker.ok, found: all.length, probed, created: created.length };
    if (created.length) {
      console.log(`[discover] 本轮新增 ${created.length} 个监控项（候选 ${all.length}）`);
    }
    return { ...lastRun, created };
  } catch (err) {
    console.warn('[discover] 扫描失败:', err.message);
    return { error: err.message, created: [] };
  } finally {
    running = false;
  }
}

let timer = null;

function start() {
  if (process.env.AUTO_DISCOVER === '0') {
    console.log('[discover] 自动发现已关闭（AUTO_DISCOVER=0）');
    return;
  }
  const intervalMin = Math.max(1, Number(process.env.DISCOVER_INTERVAL_MIN) || 10);
  setTimeout(() => run(), 6000).unref?.();
  timer = setInterval(() => run(), intervalMin * 60 * 1000);
  timer.unref?.();
  console.log(`[discover] 已启动，每 ${intervalMin} 分钟扫描一次（网关 ${GATEWAY}）`);
}

function status() {
  return {
    enabled: process.env.AUTO_DISCOVER !== '0',
    gateway: GATEWAY,
    group: GROUP,
    intervalMin: Math.max(1, Number(process.env.DISCOVER_INTERVAL_MIN) || 10),
    dockerSocket: SOCK,
    lastRun,
  };
}

module.exports = { start, run, status };
