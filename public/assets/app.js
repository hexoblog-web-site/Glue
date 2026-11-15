'use strict';

(() => {
  const $ = (sel, root = document) => root.querySelector(sel);
  const REFRESH_SEC = 30;

  const STATUS_TEXT = {
    up: '运行正常',
    slow: '响应较慢',
    down: '连接失败',
    pending: '等待检测',
  };

  const state = {
    data: null,
    countdown: REFRESH_SEC,
    timer: null,
    tickTimer: null,
    current: null,
    range: '24h',
    loadSeq: 0,
  };

  // ---------- helpers ----------

  const esc = (v) =>
    String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;',
    }[c]));

  const fmtLatency = (ms) => {
    if (ms == null || !Number.isFinite(ms)) return '—';
    return ms < 1000 ? `${Math.round(ms)} ms` : `${(ms / 1000).toFixed(2)} s`;
  };

  const fmtPct = (pct) => {
    if (pct == null) return '—';
    return pct >= 99.995 ? '100%' : `${pct.toFixed(2)}%`;
  };

  const fmtTime = (ts) => {
    if (!ts) return '—';
    const d = new Date(ts);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('zh-CN', {
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    });
  };

  const fromNow = (ts) => {
    if (!ts) return '等待首次检测';
    const diff = Date.now() - ts;
    if (diff < 45 * 1000) return '刚刚';
    if (diff < 3600 * 1000) return `${Math.max(1, Math.round(diff / 60000))} 分钟前`;
    if (diff < 86400 * 1000) return `${Math.round(diff / 3600000)} 小时前`;
    return `${Math.round(diff / 86400000)} 天前`;
  };

  const hostOf = (url) => {
    try {
      const u = new URL(url);
      const path = u.pathname === '/' ? '' : u.pathname;
      return `${u.host}${path}`;
    } catch {
      return url || '';
    }
  };

  function certText(days) {
    if (days == null) return { text: '—', cls: 'dim' };
    if (days < 0) return { text: '已过期', cls: 'bad' };
    if (days <= 15) return { text: `${days} 天`, cls: 'warn' };
    return { text: `${days} 天`, cls: '' };
  }

  // ---------- theme ----------

  function initTheme() {
    const stored = localStorage.getItem('lh-theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.dataset.theme = stored || (prefersDark ? 'dark' : 'light');
  }

  $('#themeBtn').addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    localStorage.setItem('lh-theme', next);
  });

  // ---------- render ----------

  function statusPill(status) {
    return `<span class="pill pill-${status}">${STATUS_TEXT[status] || status}</span>`;
  }

  function renderHeartbeat(monitor) {
    const bars = monitor.heartbeat || [];
    const pad = Math.max(0, 60 - bars.length);
    const cells = [];

    for (let i = 0; i < pad; i += 1) {
      cells.push('<i class="hb-bar"></i>');
    }
    for (const b of bars) {
      const slow = b.latency != null && b.latency > (monitor.slowMs || 5000);
      const cls = !b.ok ? 'down' : slow ? 'slow' : 'up';
      const tip = `${fmtTime(b.ts)} · ${b.ok ? '正常' : '异常'}${b.latency ? ' · ' + fmtLatency(b.latency) : ''}${
        b.code ? ' · HTTP ' + b.code : ''
      }`;
      cells.push(`<i class="hb-bar ${cls}" data-tip="${esc(tip)}"></i>`);
    }
    return `<div class="heartbeat" data-id="${monitor.id}">${cells.join('')}</div>`;
  }

  function renderCard(m) {
    const cert = certText(m.certDays);
    const uptimeCls = m.uptime24h == null ? 'dim' : m.uptime24h < 99 ? 'warn' : '';
    const latencyCls = m.status === 'down' ? 'bad' : m.status === 'slow' ? 'warn' : m.latency == null ? 'dim' : '';

    return `
      <article class="card${m.status === 'down' ? ' is-down' : m.status === 'slow' ? ' is-slow' : m.status === 'pending' ? ' is-pending' : ''}" data-id="${m.id}" tabindex="0" role="button" aria-label="${esc(m.name)} ${STATUS_TEXT[m.status] || ''}">
        <div class="card-head">
          <div class="card-title">
            <span class="name">${esc(m.name)}</span>
            <svg class="ext" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M9 7h8v8"/></svg>
          </div>
          ${statusPill(m.status)}
        </div>
        <div class="card-host" title="${esc(m.url)}">${esc(hostOf(m.url))}</div>
        <div class="card-metrics">
          <div class="metric">
            <span>响应延迟</span>
            <b class="${latencyCls}">${fmtLatency(m.latency)}</b>
          </div>
          <div class="metric">
            <span>24h 可用率</span>
            <b class="${uptimeCls}">${fmtPct(m.uptime24h)}</b>
          </div>
          <div class="metric">
            <span>证书剩余</span>
            <b class="${cert.cls}">${cert.text}</b>
          </div>
        </div>
        ${renderHeartbeat(m)}
        <div class="card-foot">
          <span>${fromNow(m.lastCheck)}</span>
          <span class="hint">查看详情 →</span>
        </div>
      </article>
    `;
  }

  function renderGroups(data) {
    const wrap = $('#groups');
    if (!data.groups.length) {
      wrap.innerHTML = `<div class="empty-state"><b>还没有监控项</b>前往 <a href="/admin">管理后台</a> 添加第一个站点</div>`;
      return;
    }

    wrap.innerHTML = data.groups
      .map((g) => {
        const allOk = g.up === g.total;
        return `
          <section class="group">
            <div class="group-head">
              <h2>${esc(g.name)}</h2>
              <span class="group-count ${allOk ? 'is-ok' : 'is-bad'}">${g.up}/${g.total}</span>
              <span class="spacer"></span>
              <span class="group-note">${allOk ? '全部正常' : `${g.total - g.up} 项异常`}</span>
            </div>
            <div class="cards">${g.monitors.map(renderCard).join('')}</div>
          </section>
        `;
      })
      .join('');
  }

  function renderSummary(data) {
    const { summary, site } = data;
    const hero = $('#hero');
    const down = summary.down;
    const badge = $('#heroBadge');
    const badgeText = $('#heroBadgeText');

    hero.classList.toggle('is-down', down > 0);
    badge.classList.remove('is-loading');
    badge.classList.toggle('is-down', down > 0);

    if (summary.total === 0) {
      badgeText.textContent = '暂无监控';
      $('#heroTitle').textContent = '还没有配置监控项';
      $('#heroDesc').textContent = '前往管理后台添加你的第一个站点';
    } else if (down === 0) {
      badgeText.textContent = '实时监控中';
      $('#heroTitle').textContent = '所有系统运行正常';
      $('#heroDesc').textContent = '一切就绪 · 所有服务正在被持续检测';
    } else {
      badgeText.textContent = '存在异常';
      $('#heroTitle').textContent = '部分服务出现异常';
      $('#heroDesc').textContent = `${down} 项服务连接失败，其余服务运行正常`;
    }

    $('#updatedAt').textContent = `更新于 ${new Date(data.updatedAt).toLocaleTimeString('zh-CN', { hour12: false })}`;
    $('#statUp').textContent = summary.up;
    $('#statTotal').textContent = summary.total;

    const latencies = data.groups
      .flatMap((g) => g.monitors)
      .filter((m) => m.status !== 'down' && m.latency != null)
      .map((m) => m.latency);
    $('#statAvg').textContent = latencies.length
      ? fmtLatency(Math.round(latencies.reduce((a, b) => a + b, 0) / latencies.length))
      : '—';

    document.title = `${down === 0 ? '全部正常' : `${down} 项异常`} · ${site.title}`;
    $('#siteTitle').textContent = site.title;
    $('#siteDesc').textContent = site.description || '实时监控中';
    $('#footerText').textContent = site.footer || '';
    $('#footerText').style.display = site.footer ? '' : 'none';
  }

  // ---------- data ----------

  async function load({ silent = false } = {}) {
    const seq = ++state.loadSeq;
    if (!silent) $('#refreshBtn').classList.add('is-loading');
    try {
      const res = await fetch('/api/status', { cache: 'no-store' });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      if (seq !== state.loadSeq) return;
      state.data = data;
      renderSummary(data);
      renderGroups(data);
      state.countdown = REFRESH_SEC;
      updateCountdown();
    } catch (err) {
      if (seq !== state.loadSeq) return;
      const badge = $('#heroBadge');
      badge.classList.add('is-down');
      $('#heroBadgeText').textContent = '状态页连接失败';
      $('#heroTitle').textContent = '无法获取监控数据';
      $('#heroDesc').textContent = '请检查服务是否正常运行，稍后将自动重试';
      console.warn('[status] 加载失败:', err.message);
    } finally {
      $('#refreshBtn').classList.remove('is-loading');
    }
  }

  function updateCountdown() {
    $('#autoRefresh').textContent = `${state.countdown}s 后自动刷新`;
  }

  function startTimers() {
    clearInterval(state.timer);
    clearInterval(state.tickTimer);
    state.timer = setInterval(() => {
      if (document.hidden) return;
      load({ silent: true });
    }, REFRESH_SEC * 1000);
    state.tickTimer = setInterval(() => {
      if (document.hidden) return;
      state.countdown = Math.max(0, state.countdown - 1);
      updateCountdown();
    }, 1000);
  }

  // ---------- tooltip ----------

  const tip = $('#barTip');
  document.addEventListener('mouseover', (e) => {
    const bar = e.target.closest('.hb-bar[data-tip]');
    if (!bar) return;
    const rect = bar.getBoundingClientRect();
    tip.textContent = bar.dataset.tip;
    tip.hidden = false;
    tip.style.left = `${rect.left + rect.width / 2}px`;
    tip.style.top = `${rect.top}px`;
  });
  document.addEventListener('mouseout', (e) => {
    if (e.target.closest('.hb-bar')) tip.hidden = true;
  });
  window.addEventListener('scroll', () => (tip.hidden = true), { passive: true });

  // ---------- modal ----------

  const RANGE_HOURS = { '24h': 24, '7d': 24 * 7, '30d': 24 * 30 };

  function sparkline(points, range) {
    const W = 640;
    const H = 190;
    const padL = 44;
    const padR = 12;
    const padT = 12;
    const padB = 26;
    const innerW = W - padL - padR;
    const innerH = H - padT - padB;

    if (!points.length) return null;

    const values = points.map((p) => p.latency);
    const peak = Math.max(100, ...values);
    // 取一个"好看"的刻度上限，避免出现 1400/933/467 这种刻度
    const steps = [50, 100, 200, 250, 500, 1000, 2000, 2500, 5000, 10000];
    const step = steps.find((s) => peak / s <= 3) || Math.ceil(peak / 3 / 1000) * 1000;
    const max = step * 3;

    // x 轴固定为所选时间范围，避免数据稀疏时把两个点拉满整个图
    const t1 = Date.now();
    const t0 = t1 - (RANGE_HOURS[range] || 24) * 3600 * 1000;
    const span = t1 - t0;
    const x = (ts) => padL + Math.min(1, Math.max(0, (ts - t0) / span)) * innerW;
    const y = (v) => padT + innerH - (Math.min(v, max) / max) * innerH;

    const coords = points.map((p) => [x(p.ts), y(p.latency)]);
    const line = coords.map(([cx, cy], i) => `${i === 0 ? 'M' : 'L'}${cx.toFixed(1)},${cy.toFixed(1)}`).join(' ');
    const firstX = coords[0][0];
    const lastX = coords[coords.length - 1][0];
    const area = `${line} L${lastX.toFixed(1)},${padT + innerH} L${firstX.toFixed(1)},${padT + innerH} Z`;

    const gridCount = 3;
    let grid = '';
    for (let i = 0; i <= gridCount; i += 1) {
      const gy = padT + (innerH / gridCount) * i;
      const val = Math.round(max - (max / gridCount) * i);
      grid += `<line x1="${padL}" y1="${gy}" x2="${W - padR}" y2="${gy}" stroke="currentColor" stroke-opacity="0.09" stroke-width="1"/>`;
      grid += `<text x="${padL - 8}" y="${gy + 3.5}" text-anchor="end" font-size="10" fill="currentColor" fill-opacity="0.42">${val}</text>`;
    }

    const tickCount = 4;
    let ticks = '';
    for (let i = 0; i < tickCount; i += 1) {
      // 刻度取区间中点，避免首尾出现重复时间
      const ts = t0 + (span / tickCount) * (i + 0.5);
      const d = new Date(ts);
      const label =
        range === '24h'
          ? d.toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit', hour12: false })
          : d.toLocaleDateString('zh-CN', { month: '2-digit', day: '2-digit' });
      const tx = Math.min(W - padR, Math.max(padL, x(ts)));
      ticks += `<text x="${tx}" y="${H - 8}" text-anchor="middle" font-size="10" fill="currentColor" fill-opacity="0.42">${label}</text>`;
    }

    const last = points[points.length - 1];
    const lx = x(last.ts);
    const ly = y(last.latency);

    return `
      <svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" style="color: var(--muted)">
        <defs>
          <linearGradient id="areaFill" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="var(--brand)" stop-opacity="0.26"/>
            <stop offset="100%" stop-color="var(--brand)" stop-opacity="0"/>
          </linearGradient>
        </defs>
        ${grid}
        ${ticks}
        <path d="${area}" fill="url(#areaFill)"/>
        <path d="${line}" fill="none" stroke="var(--brand)" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/>
        <line x1="${lx}" y1="${padT}" x2="${lx}" y2="${padT + innerH}" stroke="var(--brand)" stroke-opacity="0.25" stroke-dasharray="3 3"/>
        <circle cx="${lx}" cy="${ly}" r="3.4" fill="var(--brand)" stroke="var(--card)" stroke-width="2"/>
      </svg>
    `;
  }

  async function openModal(monitor) {
    state.current = monitor;
    state.range = '24h';
    const modal = $('#modal');
    $('#modalTitle').textContent = monitor.name;
    $('#modalUrl').textContent = monitor.url;
    $('#modalUrl').href = monitor.url;
    const pill = $('#modalStatus');
    pill.className = `pill pill-${monitor.status}`;
    pill.textContent = STATUS_TEXT[monitor.status] || monitor.status;

    document.querySelectorAll('#rangeTabs button').forEach((b) => {
      b.classList.toggle('active', b.dataset.range === '24h');
    });

    renderDetails(monitor);
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    await loadHistory();
  }

  function closeModal() {
    $('#modal').hidden = true;
    document.body.style.overflow = '';
    state.current = null;
  }

  function renderDetails(m) {
    const cert = m.certDays;
    const rows = [
      ['最近检测', fromNow(m.lastCheck)],
      ['HTTP 状态码', m.statusCode ? `HTTP ${m.statusCode}` : '—'],
      ['检测间隔', `${m.interval} 秒`],
      ['24 小时可用率', fmtPct(m.uptime24h)],
      ['7 天可用率', fmtPct(m.uptime7d)],
      ['30 天可用率', fmtPct(m.uptime30d)],
      ['证书到期', m.certExpiresAt ? new Date(m.certExpiresAt).toLocaleDateString('zh-CN') : '—'],
      ['证书剩余', cert == null ? '—' : cert < 0 ? '已过期' : `${cert} 天`],
    ];
    if (m.error) rows.push(['最近错误', m.error]);

    $('#detailGrid').innerHTML = rows
      .map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`)
      .join('');
  }

  async function loadHistory() {
    if (!state.current) return;
    const box = $('#chartBox');
    const stats = $('#chartStats');
    box.innerHTML = '<div class="chart-empty">加载中…</div>';
    stats.innerHTML = '';

    try {
      const res = await fetch(`/api/monitors/${state.current.id}/history?range=${state.range}`, {
        cache: 'no-store',
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      if (!state.current) return;

      stats.innerHTML = [
        ['平均延迟', fmtLatency(data.summary.avg)],
        ['最低', fmtLatency(data.summary.min)],
        ['最高', fmtLatency(data.summary.max)],
        ['P95', fmtLatency(data.summary.p95)],
      ]
        .map(([k, v]) => `<div class="chart-stat"><span>${k}</span><b>${v}</b></div>`)
        .join('');

      const svg = sparkline(data.points, state.range);
      box.innerHTML = svg || '<div class="chart-empty">该时间段暂无检测数据</div>';
    } catch (err) {
      box.innerHTML = `<div class="chart-empty">加载失败：${esc(err.message)}</div>`;
    }
  }

  // ---------- events ----------

  $('#groups').addEventListener('click', (e) => {
    const card = e.target.closest('.card');
    if (!card) return;
    const m = state.data?.groups.flatMap((g) => g.monitors).find((x) => String(x.id) === card.dataset.id);
    if (m) openModal(m);
  });
  $('#groups').addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    const card = e.target.closest('.card');
    if (!card) return;
    e.preventDefault();
    card.click();
  });

  $('#rangeTabs').addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-range]');
    if (!btn) return;
    state.range = btn.dataset.range;
    document.querySelectorAll('#rangeTabs button').forEach((b) => b.classList.toggle('active', b === btn));
    loadHistory();
  });

  $('#modal').addEventListener('click', (e) => {
    if (e.target.closest('[data-close]')) closeModal();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !$('#modal').hidden) closeModal();
  });

  $('#refreshBtn').addEventListener('click', () => load());

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) load({ silent: true });
  });

  // ---------- boot ----------

  // ?embed=1 用于博客 iframe 嵌入：隐藏页头页脚，压缩留白
  if (new URLSearchParams(location.search).get('embed') === '1') {
    document.documentElement.dataset.embed = '1';
  }

  initTheme();
  load().then(startTimers);
})();
