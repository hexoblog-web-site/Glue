'use strict';

const { listMonitors, saveCheck, cleanup, getSetting, getMonitor } = require('./db');
const { runHttpCheck, getCertInfo } = require('./checker');

const MAX_CONCURRENT = 6;
const nextRun = new Map();
const running = new Set();
let ticker = null;

function scheduleNext(monitor, delayMs) {
  const interval = Math.max(15, Number(monitor.interval_sec) || 60) * 1000;
  nextRun.set(monitor.id, Date.now() + (delayMs != null ? delayMs : interval));
}

async function checkMonitor(monitor, { manual = false } = {}) {
  if (running.has(monitor.id)) return null;
  running.add(monitor.id);
  const prevOk = monitor.last_ok;
  try {
    const result = await runHttpCheck(monitor);
    saveCheck(monitor.id, result);
    scheduleNext(monitor);

    // 预热证书信息缓存（异步，不影响检测结果）
    if (/^https:/i.test(monitor.url)) {
      getCertInfo(monitor.url).catch(() => {});
    }

    // 仅在状态发生翻转时通知，避免刷屏
    if (prevOk != null && Boolean(prevOk) !== Boolean(result.ok)) {
      notifyStatusChange(monitor, result).catch((err) =>
        console.warn('[notify] 通知发送失败:', err.message)
      );
    }

    const flag = result.ok ? 'UP  ' : 'DOWN';
    console.log(
      `[check] ${flag} ${monitor.name.padEnd(12, ' ')} ${result.latency}ms ` +
        `${result.statusCode ?? '-'}${result.error ? ' ' + result.error : ''}${manual ? ' (手动)' : ''}`
    );
    return result;
  } catch (err) {
    console.warn(`[check] 检测异常 ${monitor.name}:`, err.message);
    return null;
  } finally {
    running.delete(monitor.id);
  }
}

async function notifyStatusChange(monitor, result) {
  const webhook = (getSetting('webhook_url') || '').trim();
  if (!webhook) return;

  const recovered = Boolean(result.ok);
  const title = recovered ? `✅ ${monitor.name} 已恢复` : `🔴 ${monitor.name} 访问异常`;
  const lines = [
    recovered ? '服务已恢复正常访问。' : '服务检测失败，请及时处理。',
    `站点：${monitor.name}`,
    `地址：${monitor.url}`,
    `时间：${new Date(result.ts).toLocaleString('zh-CN', { hour12: false })}`,
    `延迟：${result.latency}ms`,
    result.error ? `原因：${result.error}` : '',
  ].filter(Boolean);
  const body = lines.join('\n');

  let payload;
  let headers = { 'content-type': 'application/json' };

  if (webhook.includes('qyapi.weixin.qq.com')) {
    payload = { msgtype: 'text', text: { content: `${title}\n${body}` } };
  } else if (/api\.day\.app|bark/i.test(webhook)) {
    payload = { title, body, group: '站点监控', level: recovered ? 'active' : 'timeSensitive' };
  } else if (webhook.includes('open.feishu.cn')) {
    payload = { msg_type: 'text', content: { text: `${title}\n${body}` } };
  } else if (webhook.includes('oapi.dingtalk.com')) {
    payload = { msgtype: 'text', text: { content: `${title}\n${body}` } };
  } else {
    payload = {
      event: recovered ? 'monitor.recovered' : 'monitor.down',
      title,
      body,
      monitor: { id: monitor.id, name: monitor.name, url: monitor.url },
      result,
    };
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 8000);
  try {
    const res = await fetch(webhook, {
      method: 'POST',
      headers,
      body: JSON.stringify(payload),
      signal: controller.signal,
    });
    if (!res.ok) console.warn(`[notify] Webhook 返回 ${res.status}`);
  } finally {
    clearTimeout(timer);
  }
}

function tick() {
  const monitors = listMonitors(true);
  const ids = new Set(monitors.map((m) => m.id));
  for (const id of nextRun.keys()) if (!ids.has(id)) nextRun.delete(id);

  const now = Date.now();
  for (const monitor of monitors) {
    if (running.size >= MAX_CONCURRENT) break;
    if (!nextRun.has(monitor.id)) {
      nextRun.set(monitor.id, now);
    }
    if (now < nextRun.get(monitor.id)) continue;
    if (running.has(monitor.id)) continue;
    // 先占位，避免同一轮重复触发
    nextRun.set(monitor.id, now + Math.max(15, monitor.interval_sec) * 1000);
    checkMonitor(monitor);
  }
}

function start() {
  if (ticker) return;
  // 启动后错峰发起首轮检测，避免瞬间打满
  listMonitors(true).forEach((m, i) => nextRun.set(m.id, Date.now() + i * 800));
  ticker = setInterval(tick, 3000);
  ticker.unref?.();

  const retentionTimer = setInterval(() => {
    try {
      const removed = cleanup(getSetting('retention_days'));
      if (removed) console.log(`[cleanup] 清理历史记录 ${removed} 条`);
    } catch (err) {
      console.warn('[cleanup] 清理失败:', err.message);
    }
  }, 6 * 3600 * 1000);
  retentionTimer.unref?.();

  console.log('[scheduler] 已启动，最大并发 ' + MAX_CONCURRENT);
}

async function runNow(id) {
  const monitor = getMonitor(id);
  if (!monitor) return null;
  const result = await checkMonitor(monitor, { manual: true });
  return result;
}

module.exports = { start, runNow, checkMonitor };
