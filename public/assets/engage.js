/*!
 * Beacon Engage 0.2.0 — 博客侧唯一接入脚本
 * 一行引入：<script src="https://你的监控域名/e.js" data-server="https://你的监控域名" defer></script>
 *
 * 一个脚本，按页面现有元素自动装配，不需要在别处再引第二个文件：
 *   看到 [data-engage]          → 渲染点赞/收藏/有用/没用按钮
 *   看到 [data-engage-count]    → 只读数字
 *   看到 [data-moments] / #fcircle → 懒加载朋友圈模块
 *   看到 [data-friend-apply]    → 懒加载友链申请表单
 * 页面上没有对应元素就什么都不做，也不会白白下载模块。
 *
 * 约定：
 *   可点按钮  <button data-engage="like"></button>          （内部为空时自动渲染图标+数字）
 *   只读数字  <span data-engage-count="like">0</span>
 *   指定路径  data-engage-path="/2026/11/hello/"
 *   跟随主题  颜色一律用 currentColor，不写死
 */
(function () {
  'use strict';

  if (window.BeaconEngage) return;

  var script =
    document.currentScript ||
    (function () {
      var all = document.getElementsByTagName('script');
      for (var i = all.length - 1; i >= 0; i -= 1) {
        if (/\/e(n|ngage)?\.js(\?|$)/.test(all[i].src)) return all[i];
      }
      return null;
    })();

  var SERVER =
    (script && script.getAttribute('data-server')) ||
    (script && script.src ? new URL(script.src).origin : '') ||
    location.origin;

  var ICONS = {
    like: 'M12 20.3l-1.1-1C6.1 14.9 3 12.2 3 8.9 3 6.2 5.2 4 7.9 4c1.6 0 3.1.7 4.1 1.9C13 4.7 14.5 4 16.1 4 18.8 4 21 6.2 21 8.9c0 3.3-3 6-7.9 10.4l-1.1 1z',
    collect:
      'M12 3.6l2.6 5.3 5.9.9-4.3 4.1 1 5.9-5.2-2.8-5.2 2.8 1-5.9-4.3-4.1 5.9-.9z',
    useful: 'M4 12.6l5.1 5.1L20 6.4',
    useless: 'M6 6l12 12M18 6L6 18',
  };

  var LABELS = { like: '赞', collect: '收藏', useful: '有用', useless: '没用' };

  var memVid = '';
  function visitorId() {
    try {
      var saved = localStorage.getItem('bm_vid');
      if (saved) return saved;
      var fresh =
        window.crypto && crypto.randomUUID
          ? crypto.randomUUID().replace(/-/g, '')
          : Math.random().toString(36).slice(2) + Date.now().toString(36);
      localStorage.setItem('bm_vid', fresh);
      return fresh;
    } catch (err) {
      if (!memVid) memVid = Math.random().toString(36).slice(2) + Date.now().toString(36);
      return memVid;
    }
  }

  var STATE = {};
  var DEFAULT_PATH = normalize(location.pathname);

  function normalize(raw) {
    var value = String(raw || '/');
    try {
      value = decodeURIComponent(value);
    } catch (err) {
      /* 保持原样 */
    }
    if (value.length > 1) value = value.replace(/\/+$/, '') || '/';
    return value.startsWith('/') ? value : '/' + value;
  }

  function pathOf(el) {
    return normalize(el.getAttribute('data-engage-path') || DEFAULT_PATH);
  }

  function fmt(n) {
    var num = Number(n) || 0;
    return num >= 10000 ? (num / 10000).toFixed(1).replace(/\.0$/, '') + 'w' : String(num);
  }

  function injectStyle() {
    if (document.getElementById('beacon-engage-style')) return;
    var css =
      '.bme-btn{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:999px;' +
      'border:1px solid rgba(128,128,128,.32);background:transparent;color:inherit;font:inherit;' +
      'font-size:14px;line-height:1.5;cursor:pointer;transition:border-color .18s,color .18s,transform .12s;' +
      '-webkit-tap-highlight-color:transparent;user-select:none}' +
      '.bme-btn:hover{border-color:rgba(128,128,128,.58)}' +
      '.bme-btn:active{transform:scale(.97)}' +
      '.bme-btn[disabled]{opacity:.6;cursor:default}' +
      '.bme-btn .bme-i{width:15px;height:15px;flex:none;fill:none;stroke:currentColor;stroke-width:1.7;' +
      'stroke-linecap:round;stroke-linejoin:round}' +
      '.bme-btn[data-engage="like"] .bme-i,.bme-btn[data-engage="collect"] .bme-i{fill:none}' +
      '.bme-btn.is-active[data-engage="like"]{color:#e0245e;border-color:currentColor}' +
      '.bme-btn.is-active[data-engage="like"] .bme-i{fill:currentColor}' +
      '.bme-btn.is-active[data-engage="collect"]{color:#d97706;border-color:currentColor}' +
      '.bme-btn.is-active[data-engage="collect"] .bme-i{fill:currentColor}' +
      '.bme-btn.is-active[data-engage="useful"]{color:#16a34a;border-color:currentColor}' +
      '.bme-btn.is-active[data-engage="useless"]{color:#dc2626;border-color:currentColor}' +
      '.bme-btn .bme-n{font-variant-numeric:tabular-nums}';
    var style = document.createElement('style');
    style.id = 'beacon-engage-style';
    style.textContent = css;
    document.head.appendChild(style);
  }

  function decorate() {
    var buttons = document.querySelectorAll('[data-engage]');
    for (var i = 0; i < buttons.length; i += 1) {
      var el = buttons[i];
      var action = el.getAttribute('data-engage');
      if (!LABELS[action]) continue;
      el.classList.add('bme-btn');

      if (!el.textContent.trim()) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'bme-i');
        svg.setAttribute('viewBox', '0 0 24 24');
        var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', ICONS[action] || ICONS.like);
        svg.appendChild(path);

        var num = document.createElement('span');
        num.className = 'bme-n';
        num.setAttribute('data-engage-count', action);

        el.appendChild(svg);
        el.appendChild(num);

        if (el.tagName !== 'BUTTON') el.setAttribute('role', 'button');
        if (!el.hasAttribute('tabindex') && el.tagName !== 'BUTTON') el.tabIndex = 0;
      }
    }
  }

  function render() {
    var els = document.querySelectorAll('[data-engage-count]');
    for (var i = 0; i < els.length; i += 1) {
      var el = els[i];
      var action = el.getAttribute('data-engage-count');
      var data = STATE[pathOf(el)];
      if (!data) continue;
      el.textContent = fmt(data[action]);
    }

    var buttons = document.querySelectorAll('[data-engage]');
    for (var j = 0; j < buttons.length; j += 1) {
      var btn = buttons[j];
      var act = btn.getAttribute('data-engage');
      var mine = (STATE[pathOf(btn)] || {}).mine || {};
      btn.classList.toggle('is-active', Boolean(mine[act]));
      btn.setAttribute('aria-pressed', mine[act] ? 'true' : 'false');
    }
  }

  function trackPageview() {
    try {
      fetch(SERVER + '/api/e/collect', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ p: DEFAULT_PATH, t: document.title, vid: visitorId() }),
        mode: 'cors',
        credentials: 'omit',
        keepalive: true,
      }).catch(function () {});
    } catch (err) {
      /* 静默 */
    }
  }

  function wantedPaths() {
    var seen = {};
    var list = [];
    var els = document.querySelectorAll('[data-engage],[data-engage-count]');
    for (var i = 0; i < els.length; i += 1) {
      var p = pathOf(els[i]);
      if (!seen[p] && list.length < 50) {
        seen[p] = 1;
        list.push(p);
      }
    }
    if (!list.length) list.push(DEFAULT_PATH);
    return list;
  }

  function loadCounts(paths) {
    var query = paths
      .map(function (p) {
        return 'keys=' + encodeURIComponent(p);
      })
      .join('&');
    return fetch(SERVER + '/api/e/counts?' + query + '&vid=' + encodeURIComponent(visitorId()), {
      mode: 'cors',
      credentials: 'omit',
    })
      .then(function (res) {
        return res.ok ? res.json() : null;
      })
      .then(function (payload) {
        if (!payload || !payload.data) return;
        Object.keys(payload.data).forEach(function (key) {
          STATE[normalize(key)] = payload.data[key];
        });
        render();
      })
      .catch(function () {});
  }

  function submit(el, action, path) {
    if (el.disabled) return;
    el.disabled = true;
    fetch(SERVER + '/api/e/act', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ p: path, a: action, vid: visitorId() }),
      mode: 'cors',
      credentials: 'omit',
    })
      .then(function (res) {
        return res.json().then(function (body) {
          if (!res.ok) throw new Error(body && body.error ? body.error : '请求失败');
          return body;
        });
      })
      .then(function (body) {
        STATE[normalize(body.path || path)] = body.counts;
        render();
      })
      .catch(function () {})
      .then(function () {
        el.disabled = false;
      });
  }

  function onClick(event) {
    var el = event.target.closest ? event.target.closest('[data-engage]') : null;
    if (!el) return;
    var action = el.getAttribute('data-engage');
    if (!LABELS[action]) return;
    event.preventDefault();
    submit(el, action, pathOf(el));
  }

  function onKeydown(event) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    var el = event.target.closest ? event.target.closest('[data-engage]') : null;
    if (!el || el.tagName === 'BUTTON') return;
    event.preventDefault();
    submit(el, el.getAttribute('data-engage'), pathOf(el));
  }

  var LOADED = {};

  /** 按需加载子模块：用户只引一行 e.js，模块由这里补上 */
  function loadModule(name, file) {
    if (LOADED[name]) return;
    LOADED[name] = 1;
    var tag = document.createElement('script');
    tag.src = SERVER + file;
    tag.async = true;
    tag.setAttribute('data-beacon-module', name);
    tag.setAttribute('data-server', SERVER);
    document.head.appendChild(tag);
  }

  /** 页面上有什么元素，就启用什么能力 */
  function autoMount() {
    try {
      if (document.querySelector('[data-moments],[data-fcircle],#fcircle,.fcircle')) {
        loadModule('moments', '/e/moments.js');
      }
      if (document.querySelector('[data-friends]')) {
        loadModule('friends', '/e/friends.js');
      }
      if (document.querySelector('[data-friend-apply],[data-friendlink-apply]')) {
        loadModule('apply', '/e/apply.js');
      }
    } catch (err) {
      /* 选择器写错也不能拖垮全站 */
    }
  }

  function boot() {
    injectStyle();
    decorate();
    render();
    trackPageview();
    loadCounts(wantedPaths());
    autoMount();
    document.addEventListener('click', onClick);
    document.addEventListener('keydown', onKeydown);
  }

  window.BeaconEngage = {
    version: '0.2.0',
    server: SERVER,
    refresh: function () {
      return loadCounts(wantedPaths());
    },
    act: function (action, path) {
      return fetch(SERVER + '/api/e/act', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ p: path || DEFAULT_PATH, a: action, vid: visitorId() }),
        mode: 'cors',
        credentials: 'omit',
      }).then(function (r) {
        return r.json();
      });
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
