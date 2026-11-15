'use strict';

(() => {
  const $ = (sel) => document.querySelector(sel);
  const STATUS_TEXT = { up: '正常', slow: '较慢', down: '异常', pending: '待检测' };
  let monitors = [];
  let discoveryInfo = null;

  const esc = (v) =>
    String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));

  const fmtLatency = (ms) => (ms == null ? '—' : ms < 1000 ? `${Math.round(ms)} ms` : `${(ms / 1000).toFixed(2)} s`);
  const fmtPct = (p) => (p == null ? '—' : p >= 99.995 ? '100%' : `${p.toFixed(2)}%`);

  async function api(path, options = {}) {
    const res = await fetch(path, {
      method: options.method || 'GET',
      headers: options.body ? { 'content-type': 'application/json' } : undefined,
      body: options.body ? JSON.stringify(options.body) : undefined,
      cache: 'no-store',
    });
    const data = await res.json().catch(() => ({}));
    if (res.status === 401 && !path.includes('/login')) {
      showLogin();
      throw new Error('登录已过期');
    }
    if (!res.ok) throw new Error(data.error || `请求失败（${res.status}）`);
    return data;
  }

  let toastTimer = null;
  function toast(message, isError = false) {
    const el = $('#toast');
    el.textContent = message;
    el.classList.toggle('is-error', isError);
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => (el.hidden = true), 2600);
  }

  function showLogin() {
    $('#adminView').hidden = true;
    $('#loginView').hidden = false;
    setTimeout(() => $('#password').focus(), 60);
  }

  async function showAdmin() {
    $('#loginView').hidden = true;
    $('#adminView').hidden = false;
    await Promise.all([loadMonitors(), loadSettings(), loadStats(), loadFriends(), loadMoments()]);
    await loadDiscovery().catch(() => {});
    loadUpdate().catch(() => {});
  }

  // ---------- 版本与更新 ----------

  let updateBusy = false;

  async function loadUpdate(force = false) {
    const pill = $('#versionBtn');
    if (updateBusy) return;
    updateBusy = true;
    pill.classList.add('is-busy');

    try {
      const info = await api('/api/admin/update' + (force ? '?force=1' : ''));
      $('#versionText').textContent = 'v' + info.current;

      if (info.error) {
        pill.dataset.state = 'error';
        pill.title = info.error;
        if (force) toast(info.error, true);
        return;
      }

      if (!info.hasUpdate) {
        pill.dataset.state = info.latest ? 'ok' : 'error';
        pill.title = info.latest
          ? info.latest === info.current
            ? `已是最新版本 v${info.latest}`
            : `当前 v${info.current}，仓库版本 v${info.latest}`
          : '暂无可比对的版本信息（仓库未发布 release）';
        if (force) toast(info.latest ? '已是最新版本' : '暂无可比对的版本信息');
        $('#updateBanner').hidden = true;
        return;
      }

      pill.dataset.state = 'update';
      pill.title = `新版本 v${info.latest} 可用`;
      $('#updateLatest').textContent = 'v' + info.latest;
      $('#updateCurrent').textContent = info.current;
      $('#updateBanner').hidden = false;

      if (info.autoUpdate) {
        $('#updateHint').textContent =
          '已开启自动更新：watchtower 会自己拉取新镜像并重启容器，最多 1 小时完成，数据与设置都保留。不想等就手动执行：';
      } else {
        $('#updateHint').textContent =
          '在服务器上执行下面这一条即可，不用重新安装，数据与设置都会保留。';
      }

      $('#updateCmd').textContent = info.installDir
        ? `cd ${info.installDir} && bash update.sh`
        : 'bash update.sh';

      const link = $('#updateLink');
      if (info.url) {
        link.href = info.url;
        link.hidden = false;
        link.textContent = info.notes ? '查看更新内容' : '打开仓库';
      } else {
        link.hidden = true;
      }
    } catch (err) {
      pill.dataset.state = 'error';
      if (force) toast(err.message, true);
    } finally {
      updateBusy = false;
      pill.classList.remove('is-busy');
    }
  }

  $('#versionBtn').addEventListener('click', () => loadUpdate(true));
  $('#updateRecheck').addEventListener('click', () => loadUpdate(true));


  async function loadDiscovery() {
    discoveryInfo = await api('/api/admin/discover');
    $('#scanBtn').hidden = !discoveryInfo.enabled;
    renderMonitors();
  }

  // ---------- auth ----------

  $('#loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('#loginBtn');
    const err = $('#loginError');
    err.textContent = '';
    btn.disabled = true;
    btn.textContent = '登录中…';
    try {
      await api('/api/admin/login', { method: 'POST', body: { password: $('#password').value } });
      $('#password').value = '';
      await showAdmin();
    } catch (ex) {
      err.textContent = ex.message;
    } finally {
      btn.disabled = false;
      btn.textContent = '登录';
    }
  });

  $('#logoutBtn').addEventListener('click', async () => {
    await api('/api/admin/logout', { method: 'POST' }).catch(() => {});
    showLogin();
  });

  // ---------- monitors ----------

  async function loadMonitors() {
    try {
      const data = await api('/api/admin/monitors');
      monitors = data.monitors || [];
      renderMonitors();
    } catch (err) {
      toast(err.message, true);
    }
  }

  function renderMonitors() {
    const tbody = $('#monitorRows');
    const activeCount = monitors.filter((m) => m.active).length;
    const down = monitors.filter((m) => m.status === 'down').length;
    const autoCount = monitors.filter((m) => m.source === 'auto').length;
    const scanInfo =
      discoveryInfo && discoveryInfo.enabled
        ? ` · 自动发现 ${autoCount} 个，每 ${discoveryInfo.intervalMin} 分钟扫描`
        : ' · 自动发现已关闭';
    $('#monitorSummary').textContent = `共 ${monitors.length} 个监控项 · ${activeCount} 个启用${
      down ? ` · ${down} 个异常` : ''
    }${scanInfo}`;

    $('#groupList').innerHTML = [...new Set(monitors.map((m) => m.group_name))]
      .map((g) => `<option value="${esc(g)}"></option>`)
      .join('');

    if (!monitors.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="table-empty">还没有监控项，点击右上角「新增监控」开始</td></tr>';
      return;
    }

    tbody.innerHTML = monitors
      .map((m) => `
        <tr data-id="${m.id}" class="${m.active ? '' : 'row-disabled'}">
          <td>
            <div class="cell-name">
              ${esc(m.name)}
              ${m.source === 'auto' ? '<span class="badge-auto">自动发现</span>' : ''}
              ${m.active ? '' : '<span class="badge-off">已停用</span>'}
            </div>
            <a class="cell-url" href="${esc(m.url)}" target="_blank" rel="noopener" title="${esc(m.url)}">${esc(m.url)}</a>
          </td>
          <td>${esc(m.group_name)}</td>
          <td>${m.interval_sec}s</td>
          <td><span class="pill pill-${m.status}">${STATUS_TEXT[m.status] || m.status}</span></td>
          <td>${fmtLatency(m.last_latency)}</td>
          <td>${fmtPct(m.uptime24h)}</td>
          <td class="col-actions">
            <div class="row-actions">
              <button class="btn btn-sm" data-act="check" type="button">立即检测</button>
              <button class="btn btn-sm" data-act="edit" type="button">编辑</button>
              <button class="btn btn-sm btn-danger" data-act="del" type="button">删除</button>
            </div>
          </td>
        </tr>
      `)
      .join('');
  }

  $('#monitorRows').addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const tr = btn.closest('tr');
    const id = Number(tr.dataset.id);
    const monitor = monitors.find((m) => m.id === id);
    if (!monitor) return;

    if (btn.dataset.act === 'edit') return openEditor(monitor);

    if (btn.dataset.act === 'del') {
      const message =
        monitor.source === 'auto'
          ? `确认删除「${monitor.name}」？删除后该地址会加入忽略名单，自动扫描不会再收录它。`
          : `确认删除监控「${monitor.name}」？历史记录也会一并删除。`;
      if (!window.confirm(message)) return;
      try {
        await api(`/api/admin/monitors/${id}`, { method: 'DELETE' });
        toast('已删除');
        await loadMonitors();
      } catch (err) {
        toast(err.message, true);
      }
      return;
    }

    if (btn.dataset.act === 'check') {
      const original = btn.textContent;
      btn.disabled = true;
      btn.textContent = '检测中…';
      try {
        const { result } = await api(`/api/admin/monitors/${id}/check`, { method: 'POST' });
        toast(result.ok ? `正常 · ${fmtLatency(result.latency)}` : `异常：${result.error || '未知错误'}`, !result.ok);
        await loadMonitors();
      } catch (err) {
        toast(err.message, true);
        btn.disabled = false;
        btn.textContent = original;
      }
    }
  });

  // ---------- editor ----------

  function openEditor(monitor = null) {
    $('#editorTitle').textContent = monitor ? '编辑监控' : '新增监控';
    $('#editId').value = monitor ? monitor.id : '';
    $('#fName').value = monitor ? monitor.name : '';
    $('#fGroup').value = monitor ? monitor.group_name : monitors[0]?.group_name || '站点';
    $('#fUrl').value = monitor ? monitor.url : 'https://';
    $('#fInterval').value = monitor ? monitor.interval_sec : 60;
    $('#fTimeout').value = monitor ? monitor.timeout_ms : 10000;
    $('#fStatus').value = monitor ? monitor.expected_status : '200-399';
    $('#fSlow').value = monitor ? monitor.slow_ms : 5000;
    $('#fKeyword').value = monitor ? monitor.keyword || '' : '';
    $('#fActive').checked = monitor ? Boolean(monitor.active) : true;
    $('#fSort').value = monitor ? monitor.sort : 0;
    $('#editor').hidden = false;
    document.body.style.overflow = 'hidden';
    setTimeout(() => $('#fName').focus(), 60);
  }

  function closeEditor() {
    $('#editor').hidden = true;
    document.body.style.overflow = '';
  }

  $('#editor').addEventListener('click', (e) => {
    if (e.target.closest('[data-close]')) closeEditor();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !$('#editor').hidden) closeEditor();
  });

  $('#addBtn').addEventListener('click', () => openEditor());

  $('#scanBtn').addEventListener('click', async () => {
    const btn = $('#scanBtn');
    const label = btn.querySelector('span');
    const original = label.textContent;
    btn.disabled = true;
    label.textContent = '扫描中…';
    try {
      const res = await api('/api/admin/discover', { method: 'POST' });
      if (res.busy) toast('已有扫描任务在跑，请稍后');
      else if (res.created && res.created.length) toast(`发现 ${res.created.length} 个新服务，已开始监控`);
      else toast(`未发现新服务（本机候选 ${res.found || 0} 个）`);
      await Promise.all([loadMonitors(), loadDiscovery().catch(() => {})]);
    } catch (err) {
      toast(err.message, true);
    } finally {
      btn.disabled = false;
      label.textContent = original;
    }
  });

  $('#editorForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = $('#editId').value;
    const payload = {
      name: $('#fName').value.trim(),
      group_name: $('#fGroup').value.trim() || '默认分组',
      url: $('#fUrl').value.trim(),
      interval_sec: Number($('#fInterval').value) || 60,
      timeout_ms: Number($('#fTimeout').value) || 10000,
      expected_status: $('#fStatus').value.trim() || '200-399',
      slow_ms: Number($('#fSlow').value) || 5000,
      keyword: $('#fKeyword').value.trim(),
      active: $('#fActive').checked ? 1 : 0,
      sort: Number($('#fSort').value) || 0,
    };

    const btn = $('#editorSaveBtn');
    btn.disabled = true;
    btn.textContent = '保存中…';
    try {
      if (id) {
        await api(`/api/admin/monitors/${id}`, { method: 'PUT', body: payload });
        toast('已保存');
      } else {
        await api('/api/admin/monitors', { method: 'POST', body: payload });
        toast('已创建，稍后自动开始检测');
      }
      closeEditor();
      await loadMonitors();
    } catch (err) {
      toast(err.message, true);
    } finally {
      btn.disabled = false;
      btn.textContent = '保存';
    }
  });

  // ---------- 互动统计 ----------

  let statRange = '30d';
  let statSort = 'pv';

  const fmtNum = (n) => Number(n || 0).toLocaleString('zh-CN');

  async function loadStats() {
    try {
      const data = await api('/api/admin/engage/overview?days=14');
      renderStatCards(data);
      renderStatBars(data.trend || []);
      await loadStatPages();
    } catch (err) {
      toast(err.message, true);
    }
  }

  function renderStatCards(data) {
    $('#statTodayPv').textContent = fmtNum(data.today?.pv);
    $('#statTodayUv').textContent = fmtNum(data.today?.uv);
    $('#statTotalPv').textContent = fmtNum(data.total?.pv);
    $('#statTotalUv').textContent = fmtNum(data.total?.uv);
    $('#statTotalLike').textContent = fmtNum(data.total?.like);
  }

  function renderStatBars(trend) {
    const box = $('#statBars');
    if (!trend.length) {
      box.innerHTML = '';
      return;
    }
    const peak = Math.max(1, ...trend.map((d) => d.pv));
    $('#statTrendPeak').textContent = peak > 1 ? `峰值 ${fmtNum(peak)} 次` : '';

    box.innerHTML = trend
      .map((d, i) => {
        const isToday = i === trend.length - 1;
        const ratio = d.pv ? Math.max(4, Math.round((d.pv / peak) * 100)) : 0;
        const label = isToday ? '今天' : d.day.slice(8);
        const tip = `${d.day} · ${d.pv} 次浏览 / ${d.uv} 位访客`;
        return `<div class="stat-bar${isToday ? ' is-today' : ''}${d.pv ? '' : ' is-zero'}" title="${tip}">
          <i style="height:${d.pv ? ratio + '%' : '2px'}"></i>
          <em>${label}</em>
        </div>`;
      })
      .join('');
  }

  async function loadStatPages() {
    const tbody = $('#statRows');
    tbody.innerHTML = '<tr><td colspan="6" class="table-empty">加载中…</td></tr>';
    const data = await api(`/api/admin/engage/pages?range=${statRange}&sort=${statSort}&limit=50`);
    const pages = data.pages || [];

    if (!pages.length) {
      tbody.innerHTML =
        '<tr><td colspan="6" class="table-empty">还没有数据，接入脚本后会自动开始统计</td></tr>';
      return;
    }

    tbody.innerHTML = pages
      .map(
        (p) => `
        <tr>
          <td>
            <div class="stat-title">
              <b title="${esc(p.title)}">${esc(p.title)}</b>
              <code title="${esc(p.path)}">${esc(p.path)}</code>
            </div>
          </td>
          <td class="num">${fmtNum(p.pv)}</td>
          <td class="num">${fmtNum(p.uv)}</td>
          <td class="num">${fmtNum(p.like)}</td>
          <td class="num">${fmtNum(p.collect)}</td>
          <td class="num">${p.usefulRate == null ? '—' : p.usefulRate + '%'}</td>
        </tr>`
      )
      .join('');
  }

  $('#statRefresh').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.disabled = true;
    btn.textContent = '刷新中…';
    await loadStats();
    btn.disabled = false;
    btn.textContent = '刷新数据';
  });

  $('#statRange').addEventListener('change', (e) => {
    statRange = e.target.value;
    loadStatPages().catch((err) => toast(err.message, true));
  });

  $('#statSort').addEventListener('change', (e) => {
    statSort = e.target.value;
    loadStatPages().catch((err) => toast(err.message, true));
  });

  $('#statResetBtn').addEventListener('click', async () => {
    if (!window.confirm('确认清空所有统计数据？浏览量、点赞、收藏、有用率都会归零，且无法恢复。')) return;
    try {
      await api('/api/admin/engage/reset', { method: 'POST', body: { confirm: 'RESET' } });
      toast('统计数据已清空');
      await loadStats();
    } catch (err) {
      toast(err.message, true);
    }
  });

  // ---------- 友链与友圈 ----------

  let friends = [];

  const FRIEND_STATUS = {
    pending: { text: '待审核', cls: 'pill-pending' },
    approved: { text: '已通过', cls: 'pill-up' },
    rejected: { text: '已拒绝', cls: 'pill-down' },
  };

  const FEED_STATE = {
    ok: { text: '正常', cls: 'pill-up' },
    none: { text: '无 RSS', cls: 'pill-pending' },
    error: { text: '抓取失败', cls: 'pill-down' },
  };

  const fmtDate = (ts) => {
    if (!ts) return '—';
    const d = new Date(Number(ts));
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(
      d.getDate()
    ).padStart(2, '0')}`;
  };

  async function loadFriends() {
    try {
      const data = await api('/api/admin/friends');
      friends = data.links || [];
      renderFriendStats(data.stats || {});
      renderFriends();
    } catch (err) {
      toast(err.message, true);
    }
  }

  function renderFriendStats(stats) {
    const cells = [
      ['待审核', stats.pending || 0],
      ['已通过', stats.approved || 0],
      ['友圈文章', stats.posts || 0],
      ['抓取失败', stats.broken || 0],
    ];
    $('#friendStats').innerHTML = cells
      .map(
        ([label, value]) =>
          `<div class="stat-cell"><span>${label}</span><strong>${fmtNum(value)}</strong></div>`
      )
      .join('');
  }

  function renderFriends() {
    const tbody = $('#friendRows');
    const order = { pending: 0, approved: 1, rejected: 2 };
    const list = [...friends].sort(
      (a, b) => (order[a.status] ?? 3) - (order[b.status] ?? 3) || b.created_at - a.created_at
    );

    if (!list.length) {
      tbody.innerHTML =
        '<tr><td colspan="5" class="table-empty">还没有友链，点右上角「添加友链」开始</td></tr>';
      return;
    }

    tbody.innerHTML = list
      .map((f) => {
        const status = FRIEND_STATUS[f.status] || FRIEND_STATUS.pending;
        const feed = f.feed_state
          ? FEED_STATE[f.feed_state] || FEED_STATE.error
          : { text: '未抓取', cls: 'pill-pending' };

        const mutual =
          f.mutual === 1
            ? '<span class="pill pill-up">已互链</span>'
            : f.mutual === 0
              ? '<span class="pill pill-down">未互链</span>'
              : '<span class="pill pill-pending">未检测</span>';

        const actions = [];
        if (f.status === 'pending') {
          actions.push('<button class="btn btn-sm btn-primary" data-fact="approve">通过</button>');
          actions.push('<button class="btn btn-sm btn-danger" data-fact="reject">拒绝</button>');
        } else {
          if (f.status === 'rejected') {
            actions.push('<button class="btn btn-sm btn-primary" data-fact="approve">通过</button>');
          } else {
            actions.push('<button class="btn btn-sm" data-fact="check">检测</button>');
          }
          actions.push('<button class="btn btn-sm" data-fact="edit">编辑</button>');
          actions.push('<button class="btn btn-sm btn-danger" data-fact="del">删除</button>');
        }

        return `
          <tr data-fid="${f.id}">
            <td>
              <div class="cell-name">${esc(f.name)}</div>
              <a class="cell-url" href="${esc(f.url)}" target="_blank" rel="noopener">${esc(f.url)}</a>
            </td>
            <td><span class="pill ${status.cls}">${status.text}</span></td>
            <td>${mutual}</td>
            <td><span class="pill ${feed.cls}" title="${esc(f.feed_error || '')}">${feed.text}</span></td>
            <td class="col-actions"><div class="row-actions">${actions.join('')}</div></td>
          </tr>`;
      })
      .join('');
  }

  async function reviewFriend(id, status, note) {
    try {
      await api(`/api/admin/friends/${id}/review`, { method: 'POST', body: { status, note } });
      toast(status === 'approved' ? '已通过，稍后自动抓取' : '已处理');
      await Promise.all([loadFriends(), loadMoments()]);
    } catch (err) {
      toast(err.message, true);
    }
  }

  $('#friendRows').addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-fact]');
    if (!btn) return;
    const tr = btn.closest('tr');
    const id = Number(tr.dataset.fid);
    const link = friends.find((f) => f.id === id);
    if (!link) return;
    const act = btn.dataset.fact;

    if (act === 'approve') return reviewFriend(id, 'approved');

    if (act === 'reject') {
      const note = window.prompt(`拒绝「${link.name}」的申请，可以写一句理由（留空也行）`, '');
      if (note === null) return;
      return reviewFriend(id, 'rejected', note);
    }

    if (act === 'edit') return openFriendEditor(link);

    if (act === 'del') {
      if (!window.confirm(`确认删除「${link.name}」？他的友圈文章也会一起删掉。`)) return;
      try {
        await api(`/api/admin/friends/${id}`, { method: 'DELETE' });
        toast('已删除');
        await Promise.all([loadFriends(), loadMoments()]);
      } catch (err) {
        toast(err.message, true);
      }
      return;
    }

    if (act === 'check') {
      const original = btn.textContent;
      btn.disabled = true;
      btn.textContent = '检测中…';
      try {
        const result = await api(`/api/admin/friends/${id}/check`, { method: 'POST' });
        if (result.error) toast(`抓取失败：${result.error}`, true);
        else toast(`检测完成，抓到 ${result.posts} 篇`);
        await Promise.all([loadFriends(), loadMoments()]);
      } catch (err) {
        toast(err.message, true);
        btn.disabled = false;
        btn.textContent = original;
      }
    }
  });

  $('#friendFetchBtn').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.disabled = true;
    btn.textContent = '抓取中…';
    try {
      const result = await api('/api/admin/friends/fetch', { method: 'POST' });
      if (result.busy) toast('已经在抓了，稍等一下');
      else
        toast(
          `抓取完成 ${result.ok}/${result.total}` + (result.failed ? `，失败 ${result.failed} 个` : ''),
          result.failed > 0
        );
      await Promise.all([loadFriends(), loadMoments()]);
    } catch (err) {
      toast(err.message, true);
    } finally {
      btn.disabled = false;
      btn.textContent = '抓取全部';
    }
  });

  // ---------- 友链编辑 ----------

  function openFriendEditor(link = null) {
    $('#friendEditorTitle').textContent = link ? '编辑友链' : '添加友链';
    $('#friendId').value = link ? link.id : '';
    $('#friendName').value = link ? link.name : '';
    $('#friendUrl').value = link ? link.url : 'https://';
    $('#friendDesc').value = link ? link.desc || '' : '';
    $('#friendAvatar').value = link ? link.avatar || '' : '';
    $('#friendRss').value = link ? link.rss || '' : '';
    $('#friendEditor').hidden = false;
    document.body.style.overflow = 'hidden';
    setTimeout(() => $('#friendName').focus(), 60);
  }

  function closeFriendEditor() {
    $('#friendEditor').hidden = true;
    document.body.style.overflow = '';
  }

  $('#addFriendBtn').addEventListener('click', () => openFriendEditor());

  $('#friendEditor').addEventListener('click', (e) => {
    if (e.target.closest('[data-fclose]')) closeFriendEditor();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !$('#friendEditor').hidden) closeFriendEditor();
  });

  $('#friendForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = $('#friendId').value;
    const payload = {
      name: $('#friendName').value.trim(),
      url: $('#friendUrl').value.trim(),
      desc: $('#friendDesc').value.trim(),
      avatar: $('#friendAvatar').value.trim(),
      rss: $('#friendRss').value.trim(),
    };

    const btn = $('#friendSaveBtn');
    btn.disabled = true;
    btn.textContent = '保存中…';
    try {
      if (id) {
        await api(`/api/admin/friends/${id}`, { method: 'PUT', body: payload });
        toast('已保存');
      } else {
        await api('/api/admin/friends', { method: 'POST', body: { ...payload, status: 'approved' } });
        toast('已添加，正在抓取文章');
      }
      closeFriendEditor();
      await Promise.all([loadFriends(), loadMoments()]);
      if (!id) api('/api/admin/friends/fetch', { method: 'POST' }).catch(() => {});
    } catch (err) {
      toast(err.message, true);
    } finally {
      btn.disabled = false;
      btn.textContent = '保存';
    }
  });

  // ---------- 友圈文章 ----------

  async function loadMoments() {
    try {
      const data = await api('/api/admin/moments?limit=40');
      renderMoments(data.posts || []);
      $('#momentCount').textContent = `共 ${fmtNum(data.stats?.posts || 0)} 篇`;
    } catch (err) {
      toast(err.message, true);
    }
  }

  function renderMoments(posts) {
    const tbody = $('#momentRows');
    if (!posts.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="table-empty">还没有抓到文章</td></tr>';
      return;
    }

    tbody.innerHTML = posts
      .map(
        (p) => `
        <tr data-mid="${p.id}">
          <td><a class="moment-title" href="${esc(p.url)}" target="_blank" rel="noopener" title="${esc(
            p.title
          )}">${esc(p.title)}</a></td>
          <td>${esc(p.friend?.name || '')}</td>
          <td>${fmtDate(p.publishedAt)}</td>
          <td class="col-actions"><button class="btn btn-sm btn-danger" data-mact="del">删除</button></td>
        </tr>`
      )
      .join('');
  }

  $('#momentRows').addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-mact]');
    if (!btn) return;
    const tr = btn.closest('tr');
    const id = Number(tr.dataset.mid);
    if (!window.confirm('确认删除这条友圈文章？')) return;
    try {
      await api(`/api/admin/moments/${id}`, { method: 'DELETE' });
      toast('已删除');
      await loadMoments();
    } catch (err) {
      toast(err.message, true);
    }
  });

  // ---------- settings ----------

  async function loadSettings() {
    const { settings } = await api('/api/admin/settings');
    $('#siteTitleInput').value = settings.site_title || '';
    $('#siteDescInput').value = settings.site_description || '';
    $('#footerInput').value = settings.footer_text || '';
    $('#webhookInput').value = settings.webhook_url || '';
    $('#retentionInput').value = settings.retention_days || 90;
    $('#siteUrlInput').value = settings.site_url || '';
    $('#statEnabledInput').checked = settings.stat_enabled !== '0';
    $('#statShowPvInput').checked = settings.stat_show_pv !== '0';
    $('#friendApplyInput').checked = settings.friend_apply_enabled !== '0';
  }

  $('#saveSettingsBtn').addEventListener('click', async () => {
    const btn = $('#saveSettingsBtn');
    btn.disabled = true;
    btn.textContent = '保存中…';
    try {
      await api('/api/admin/settings', {
        method: 'PUT',
        body: {
          site_title: $('#siteTitleInput').value.trim() || '服务状态',
          site_description: $('#siteDescInput').value.trim(),
          footer_text: $('#footerInput').value.trim(),
          webhook_url: $('#webhookInput').value.trim(),
          retention_days: Number($('#retentionInput').value) || 90,
          site_url: $('#siteUrlInput').value.trim(),
          stat_enabled: $('#statEnabledInput').checked ? '1' : '0',
          stat_show_pv: $('#statShowPvInput').checked ? '1' : '0',
          friend_apply_enabled: $('#friendApplyInput').checked ? '1' : '0',
        },
      });
      toast('设置已保存');
    } catch (err) {
      toast(err.message, true);
    } finally {
      btn.disabled = false;
      btn.textContent = '保存设置';
    }
  });

  // ---------- 博客接入代码 ----------

  function renderSnippets() {
    const base = location.origin;
    const code = {
      snipDot: `<script src="${base}/status.js"></script>`,
      snipImg: `<a href="${base}/"><img src="${base}/dot.svg" alt="所有业务正常"></a>`,
      snipIframe: `<iframe src="${base}/?embed=1" loading="lazy"
  style="width:100%;height:460px;border:0;border-radius:12px"></iframe>`,
      snipEngage: `<script src="${base}/e.js" data-server="${base}" defer></script>`,
      snipEngageBtn: `<div class="post-actions">
  <button data-engage="like"></button>
  <button data-engage="collect"></button>
  <button data-engage="useful">有用</button>
  <button data-engage="useless">没用</button>
</div>`,
      snipEngageCount: `<span data-engage-count="like">0</span>`,
      snipMoments: `<div data-moments></div>`,
      snipFriendsWall: `<div data-friends data-apply></div>`,
      snipApply: `<div data-friend-apply
  data-title="申请友链"
  data-note="先把我加上，再来提交，我看到会尽快回访"></div>`,
      snipApplyPage: `${base}/apply`,
      snipFriends: `${base}/api/e/friends.json`,
    };
    for (const [id, text] of Object.entries(code)) {
      const el = document.getElementById(id);
      if (el) el.textContent = text;
    }
  }

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    const codeEl = document.getElementById(btn.dataset.copy);
    if (!codeEl) return;
    const text = codeEl.textContent;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
      } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
      }
      toast('已复制到剪贴板');
    } catch {
      toast('复制失败，请手动选中复制', true);
    }
  });

  renderSnippets();

  // ---------- boot ----------

  api('/api/admin/session')
    .then((s) => (s.authed ? showAdmin() : showLogin()))
    .catch(showLogin);
})();
