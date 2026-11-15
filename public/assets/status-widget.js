/*!
 * Beacon Monitor — 博客底部状态圆点
 * 用法：<script src="https://你的状态页域名/api/status.js" async></script>
 * 可选：data-href / data-monitor / data-text-ok / data-target="self"
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script) {
    var all = document.getElementsByTagName('script');
    script = all[all.length - 1];
  }
  if (!script || script.getAttribute('data-bm-mounted') === '1') return;
  script.setAttribute('data-bm-mounted', '1');

  var src = script.src || '';
  var selfUrl;
  try {
    selfUrl = new URL(src, location.href);
  } catch (e) {
    return;
  }
  if (!/^https?:/.test(selfUrl.protocol)) return;

  // 默认用脚本所在域名；反向代理挂子目录时可用 data-base 指定
  var base = (script.getAttribute('data-base') || selfUrl.origin).replace(/\/+$/, '');

  var monitor = script.getAttribute('data-monitor') || '';
  var href = script.getAttribute('data-href') || base + '/';
  var openSelf = script.getAttribute('data-target') === 'self';

  var THEME = {
    ok: { dot: '#22c55e', ring: 'rgba(34,197,94,.18)' },
    slow: { dot: '#f59e0b', ring: 'rgba(245,158,11,.18)' },
    down: { dot: '#ef4444', ring: 'rgba(239,68,68,.22)' },
    unknown: { dot: '#c2c7ce', ring: 'rgba(148,163,175,.18)' },
  };

  var TEXTS = {
    ok: script.getAttribute('data-text-ok') || '所有业务正常',
    slow: script.getAttribute('data-text-slow') || '部分服务较慢',
    down: script.getAttribute('data-text-down') || '{n} 项服务异常',
    unknown: script.getAttribute('data-text-unknown') || '状态未知',
    pending: script.getAttribute('data-text-pending') || '正在检测',
  };

  // ---------- 骨架 ----------

  var host = document.querySelector('[data-beacon-status]');
  var anchor;
  var dotEl;
  var textEl;

  function build() {
    var a = document.createElement('a');
    a.className = 'bm-status';
    a.href = href;
    if (!openSelf) {
      a.target = '_blank';
      a.rel = 'noopener';
    }
    a.setAttribute('aria-live', 'polite');

    var dot = document.createElement('span');
    dot.className = 'bm-dot';

    var txt = document.createElement('span');
    txt.className = 'bm-text';

    a.appendChild(dot);
    a.appendChild(txt);
    return { a: a, dot: dot, txt: txt };
  }

  if (host && host.tagName === 'A') {
    anchor = host;
    anchor.href = anchor.getAttribute('href') || href;
    if (!openSelf && !anchor.target) {
      anchor.target = '_blank';
      anchor.rel = 'noopener';
    }
    anchor.classList.add('bm-status');
    anchor.innerHTML = '<span class="bm-dot"></span><span class="bm-text"></span>';
    dotEl = anchor.querySelector('.bm-dot');
    textEl = anchor.querySelector('.bm-text');
  } else {
    var parts = build();
    anchor = parts.a;
    dotEl = parts.dot;
    textEl = parts.txt;

    if (host) host.appendChild(anchor);
    else if (script.parentNode) script.parentNode.insertBefore(anchor, script);
    else document.body.appendChild(anchor);
  }

  // ---------- 样式（自带，不受博客主题影响） ----------

  var css = [
    '.bm-status{display:inline-flex;align-items:center;gap:6px;text-decoration:none;',
    'color:inherit;font-size:12px;line-height:1;vertical-align:middle;white-space:nowrap;',
    'opacity:.92;transition:opacity .2s ease}',
    '.bm-status:hover{opacity:1;text-decoration:none}',
    '.bm-status .bm-dot{width:7px;height:7px;border-radius:50%;flex:0 0 auto;',
    'background:#c2c7ce;box-shadow:0 0 0 3px rgba(148,163,175,.18);',
    'transition:background .35s ease,box-shadow .35s ease}',
    '.bm-status .bm-text{color:inherit}',
  ].join('');

  var style = document.createElement('style');
  style.textContent = css;
  (document.head || document.documentElement).appendChild(style);

  // ---------- 渲染 ----------

  function paint(state, text) {
    var theme = THEME[state] || THEME.unknown;
    dotEl.style.background = theme.dot;
    dotEl.style.boxShadow = '0 0 0 3px ' + theme.ring;
    if (textEl.textContent !== text) textEl.textContent = text;
  }

  function apply(data) {
    if (!data) return;
    var state = data.state || 'unknown';
    var text = data.text;
    if (!text) {
      var tpl = TEXTS[state] || TEXTS.unknown;
      text = tpl.replace('{n}', String(data.down || 0));
    }
    paint(state, text);
    anchor.title = data.title || text;
  }

  function load() {
    var url = base + '/api/summary.json' + (monitor ? '?monitor=' + encodeURIComponent(monitor) : '');
    fetch(url, { cache: 'no-cache', credentials: 'omit' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(apply)
      .catch(function () {});
  }

  if (host && host !== anchor && !host.textContent.trim()) {
    // 占位元素本身可能带着博客的样式，清掉重复的间隔
    host.style.display = host.style.display || 'inline-flex';
  }

  paint('unknown', TEXTS.unknown);
  load();
  setInterval(load, 60000);
})();
