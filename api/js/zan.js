/*!
 * zan.js 2.0.5 - Sugar-Box：点赞 + 网站统计（内置样式版）
 * 接入：<script src="https://你的域名/api/js/zan.js" data-server="https://你的域名" defer></script>
 * 点赞：<div data-zan data-zan-scope="content"></div>（内容页专用；列表页请勿加 scope）
 * 阅读：<span data-zan-view></span>（显示这一页的浏览次数）
 *        <span data-zan-view data-zan-scope="site"></span>（显示全站累计）
 *
 * 2.0.5
 *   - 点赞响应被浏览器拦下时（跨域白名单没配对），用只读接口复核数字，
 *     不再把「已经写进数据库」的点赞误报成网络错误
 *
 * 2.0.2
 *   - 统计不再由容器触发：脚本一加载就给当前页记一条浏览，首页 / 归档 / 标签页都算
 *   - 写入模型照 Umami：每次浏览一条事件，访客 / 访问次数由服务端按 session / visit 推导
 *   - 点赞仍然只允许内容页：容器 key 必须等于当前页路径，其余页面只读数字
 *   - 去掉 data-zan-scope="any"（会破坏「只在内容页点赞」这条规则）
 *
 * 属性
 *   脚本：data-server / data-site / data-once="false" / data-stats="off"
 *         data-scope="read" 整站只读（点赞按钮全部禁用）
 *   点赞：data-zan / data-zan-key / data-zan-variant / data-zan-size / data-zan-color
 *         data-zan-once="false" / data-zan-read / data-zan-scope="content"
 *   阅读：data-zan-view / data-zan-key / data-zan-label / data-zan-scope="site"
 */
(function () {
  'use strict';

  var VERSION = '2.0.5';

  var script = document.currentScript || (function () {
    var all = document.querySelectorAll('script[src*="zan.js"]');
    return all.length ? all[all.length - 1] : null;
  })();
  var DS = script && script.dataset ? script.dataset : {};

  function serverFromSrc() {
    var src = (script && script.getAttribute('src')) || '';
    var i = src.indexOf('/api/js/zan.js');
    return i > 0 ? src.slice(0, i) : '';
  }

  /* 留空即同源：脚本和接口部署在同一个站点时不用写 data-server */
  var SERVER = (DS.server || serverFromSrc()).replace(/\/+$/, '');
  var SITE = DS.site || '';
  var VARIANT = DS.variant || 'heart';
  /* 单次点击：true（默认）点过就锁；false 允许重复点，服务端仍按访客去重 */
  var ONCE = DS.once !== 'false';
  /* 整站只读（脚本上的 data-scope="read"），容器级属性优先于它 */
  var SCRIPT_SCOPE = DS.scope || '';
  /* 统计开关：data-stats="off" 只关统计，不影响点赞 */
  var STATS_ON = DS.stats !== 'off';

  var LS_VID = 'zan:vid';
  var LS_LIKED = 'zan:liked';
  /* 当前文档的路径：点赞用它判断容器是不是这一页自己的 */
  var PAGE_KEY = normKey();

  var ICON = '<svg class="zan-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
    '<path d="M12 20.4S3.9 15.6 2.4 10.9C1.3 7.4 3.8 4.6 6.9 4.6c2 0 3.4 1.1 4.4 2.6 1-1.5 2.4-2.6 4.4-2.6 3.1 0 5.6 2.8 4.5 6.3C19.4 15.6 12 20.4 12 20.4z"/></svg>';
  var SIZE = { sm: '13px', md: '14px', lg: '17px' };

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function store(key, val) {
    try {
      if (val === undefined) return localStorage.getItem(key);
      localStorage.setItem(key, val);
      return val;
    } catch (e) {
      return null;
    }
  }

  /* 点赞用的访客标识：只有点赞需要「一个人只能点一次」，统计不用它 */
  function vid() {
    var s = store(LS_VID);
    if (s) return s;
    s = 'z' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    store(LS_VID, s);
    return s;
  }

  function likedAll() {
    try {
      return JSON.parse(store(LS_LIKED) || '{}') || {};
    } catch (e) {
      return {};
    }
  }

  function likedSet(key) {
    var m = likedAll();
    m[key] = 1;
    store(LS_LIKED, JSON.stringify(m));
  }

  function normKey(raw) {
    var p = raw || location.pathname;
    p = String(p).replace(/[?#].*$/, '');
    p = p.replace(/^[a-z]+:\/\/[^/]+/i, '');
    p = p.replace(/\/index\.html?$/i, '/');
    p = p.replace(/\/{2,}/g, '/');
    if (p.length > 1) p = p.replace(/\/+$/, '');
    return p || '/';
  }

  function currentKey() {
    return normKey(location.pathname);
  }

  function keyOf(el) {
    return normKey(el.getAttribute('data-zan-key') || el.getAttribute('data-key') || '');
  }

  function scopeOf(el) {
    return el.getAttribute('data-zan-scope') || SCRIPT_SCOPE;
  }

  /* 容器上的 data-zan-once 优先于脚本上的 data-once */
  function onceOf(el) {
    var v = el.getAttribute('data-zan-once');
    return v === null ? ONCE : v !== 'false';
  }

  /* 能否点赞：只有「内容页自己的容器」可以，其余一律只读 */
  function canWrite(el) {
    if (el.hasAttribute('data-zan-read')) return false;
    if (SCRIPT_SCOPE === 'read') return false;
    if (PAGE_KEY === '/') return false;
    /* 只有模板显式声明 data-zan-scope="content" 的容器才可写。
       光看「key 等于当前路径」不够：列表页上的裸容器 key 也等于当前路径，
       那样首页、归档、标签页的卡片又能被点起来了。 */
    return scopeOf(el) === 'content';
  }

  function isEmpty(el) {
    if (el.children.length) return false;
    return !el.textContent.replace(/\s+/g, '');
  }

  function render(el, variant, writable) {
    /* 只读容器用 span：不是按钮，键盘和读屏都不会当成可操作控件 */
    var btn = document.createElement(writable ? 'button' : 'span');
    if (writable) btn.type = 'button';
    btn.className = 'zan-btn';
    if (writable) btn.setAttribute('aria-pressed', 'false');
    btn.innerHTML = ICON
      + (variant === 'pill' ? '<span class="zan-label">点赞</span>' : '')
      + '<span class="zan-count" data-zan-count>0</span>';
    el.className = el.className ? el.className + ' zan' : 'zan';
    el.appendChild(btn);
  }

  function findCount(el) {
    return el.querySelector('[data-zan-count]')
      || el.querySelector('.zan-number')
      || el.querySelector('.number')
      || el.querySelector('[data-count]')
      || guessCount(el);
  }

  function guessCount(el) {
    var nodes = el.querySelectorAll('*');
    var found = null;
    for (var i = 0; i < nodes.length; i++) {
      var t = (nodes[i].textContent || '').replace(/\s+/g, '');
      if (/^\d{1,7}$/.test(t) && !nodes[i].children.length) found = nodes[i];
    }
    return found;
  }

  function triggerOf(el) {
    return el.querySelector('[data-zan-trigger]') || el.querySelector('button, a') || el;
  }

  function adopt(el) {
    var t = triggerOf(el);
    if (t === el) {
      if (!el.hasAttribute('role')) el.setAttribute('role', 'button');
      if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex', '0');
      el.style.cursor = 'pointer';
    }
  }

  function paint(g) {
    for (var i = 0; i < g.items.length; i++) {
      var it = g.items[i];
      if (it.el.classList) it.el.classList.toggle('is-liked', g.liked);
      else it.el.className = it.el.className.replace(' is-liked', '') + (g.liked ? ' is-liked' : '');
      if (it.count) it.count.textContent = String(g.likes);
      if (!it.writable) continue;
      it.trigger.setAttribute('aria-pressed', g.liked ? 'true' : 'false');
      it.trigger.setAttribute('aria-label', (g.liked ? '已点赞，共 ' : '点赞，共 ') + g.likes + ' 个赞');
    }
  }

  function busy(g, on) {
    for (var i = 0; i < g.items.length; i++) {
      if (g.items[i].el.classList) g.items[i].el.classList.toggle('is-loading', on);
    }
  }

  function pop(g) {
    for (var i = 0; i < g.items.length; i++) {
      var el = g.items[i].el;
      el.classList.add('is-pop');
      (function (n) {
        setTimeout(function () { n.classList.remove('is-pop'); }, 340);
      })(el);
    }
  }

  function fire(g) {
    try {
      document.dispatchEvent(new CustomEvent('zan:change', {
        detail: { key: g.key, likes: g.likes, liked: g.liked }
      }));
    } catch (e) {}
  }

  function apiQuery(keys, done) {
    if (!keys.length) return done({});
    var q = 'action=query&site=' + encodeURIComponent(SITE);
    for (var i = 0; i < keys.length; i++) {
      q += '&keys[]=' + encodeURIComponent(keys[i]);
    }
    fetch(SERVER + '/api/zan.php?' + q, { credentials: 'omit' })
      .then(function (r) { return r.json(); })
      .then(function (d) { done(d && d.code === 0 && d.data ? d.data : {}); })
      .catch(function () { done({}); });
  }

  function apiLike(key, force) {
    var body = 'action=like&site=' + encodeURIComponent(SITE)
      + '&key=' + encodeURIComponent(key)
      + '&path=' + encodeURIComponent(PAGE_KEY)
      + (force ? '&scope=content' : '')
      + '&vid=' + encodeURIComponent(vid());
    return fetch(SERVER + '/api/zan.php', {
      method: 'POST',
      credentials: 'omit',
      keepalive: true,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body
    }).then(function (r) { return r.json(); });
  }

  function markError(g) {
    for (var i = 0; i < g.items.length; i++) {
      var el = g.items[i].el;
      el.classList.add('is-error');
      (function (n) {
        setTimeout(function () { n.classList.remove('is-error'); }, 1800);
      })(el);
    }
  }

  function vote(g) {
    if (g.busy) return;
    if (g.liked && g.once) return;
    g.busy = true;
    busy(g, true);
    var prev = g.likes;
    g.likes = prev + 1;
    paint(g);
    pop(g);

    function settle() {
      g.busy = false;
      busy(g, false);
      paint(g);
      fire(g);
    }

    function done(likes) {
      g.likes = typeof likes === 'number' ? likes : prev;
      g.liked = true;
      likedSet(g.key);
    }

    apiLike(g.key, g.force).then(function (d) {
      if (d && d.code === 0 && d.data) {
        done(d.data.likes);
      } else if (d && d.code === 2) {
        done(d.data && typeof d.data.likes === 'number' ? d.data.likes : prev);
      } else if (d && d.code === 403) {
        g.likes = prev;
        try { console.warn('[Sugar-Box] ' + (d.message || '只能在内容页点赞')); } catch (e) {}
      } else {
        g.likes = prev;
        throw new Error('failed');
      }
      settle();
    }).catch(function () {
      /* 跨域白名单没配全时浏览器会拦住响应，可请求早就到了服务端、也已经写库。
         直接报错，用户看到的就是「提示网络错误，刷新却多了一个赞」。
         用只读接口复核一次：数字比点之前大，说明真的写进去了，按成功收尾。 */
      apiQuery([g.key], function (data) {
        var row = data && data[g.key];
        var n = row && typeof row.likes === 'number' ? row.likes : null;
        if (n !== null && n > prev) {
          done(n);
        } else {
          g.likes = prev;
          markError(g);
        }
        settle();
      });
    });
  }

  function mount(el, writable) {
    if (isEmpty(el)) render(el, el.getAttribute('data-zan-variant') || VARIANT, writable);
    if (writable) {
      adopt(el);
    } else {
      el.classList.add('is-zan-read');
    }
  }

  function injectCSS() {
    if (DS.css === 'off') return;
    if (document.getElementById('zan-css')) return;
    var link = document.createElement('link');
    link.id = 'zan-css';
    link.rel = 'stylesheet';
    link.href = SERVER + '/api/style/zan.css?v=' + VERSION;
    document.head.appendChild(link);
  }

  /* ---------------------------------------------------------------- 阅读数 */

  var pageEls = [];   /* 当前页自己的容器 */
  var otherEls = {};  /* 指向别页的容器：只查询，不写 */
  var siteEls = [];   /* 全站计数器 */
  var lastSent = '';

  function renderView(el, label) {
    if (!isEmpty(el)) return;
    var n = document.createElement('span');
    n.className = 'zan-view-count';
    n.textContent = '0';
    el.appendChild(n);
    if (label !== '') {
      var s = document.createElement('span');
      s.className = 'zan-view-label';
      s.textContent = label;
      el.appendChild(s);
    }
    el.className = el.className ? el.className + ' zan-views' : 'zan-views';
  }

  function paintViews(items, views) {
    for (var i = 0; i < items.length; i++) {
      var n = items[i].querySelector('.zan-view-count');
      if (n) n.textContent = String(views);
    }
  }

  function collectViews() {
    var list = document.querySelectorAll('[data-zan-view]');
    for (var i = 0; i < list.length; i++) {
      var el = list[i];
      if (el.getAttribute('data-zan-viewed')) continue;
      el.setAttribute('data-zan-viewed', '1');

      var scope = el.getAttribute('data-zan-scope') || '';
      var label = el.getAttribute('data-zan-label');
      if (label === null) label = scope === 'site' ? '浏览' : '次浏览';
      renderView(el, label);

      if (scope === 'site') {
        siteEls.push(el);
        continue;
      }
      var key = normKey(el.getAttribute('data-zan-key') || el.getAttribute('data-key') || '');
      if (key === PAGE_KEY) {
        pageEls.push(el);
      } else {
        if (!otherEls[key]) otherEls[key] = [];
        otherEls[key].push(el);
      }
    }
  }

  /* 每次打开（含 SPA 路由切换）只报一次当前页；统计不依赖容器 */
  function sendPageview() {
    if (!STATS_ON) return;
    var url = location.pathname + location.search;
    if (url === lastSent) return;
    lastSent = url;

    var key = currentKey();
    var wantSite = siteEls.length ? '1' : '';
    var body = 'action=pageview&site=' + encodeURIComponent(SITE)
      + '&url=' + encodeURIComponent(url)
      + '&title=' + encodeURIComponent(document.title || '')
      + '&referrer=' + encodeURIComponent(document.referrer || '')
      + '&screen=' + encodeURIComponent((window.screen ? screen.width + 'x' + screen.height : ''))
      + '&language=' + encodeURIComponent(navigator.language || '')
      + '&hostname=' + encodeURIComponent(location.hostname || '')
      + (wantSite ? '&want_site=1' : '');

    fetch(SERVER + '/api/zan.php', {
      method: 'POST',
      credentials: 'omit',
      keepalive: true,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body
    }).then(function (r) {
      return r.json();
    }).then(function (d) {
      if (!d || d.code !== 0 || !d.data) return;
      if (typeof d.data.views === 'number') {
        paintViews(pageEls, d.data.views);
      }
      if (d.data.site && typeof d.data.site.views === 'number') {
        paintViews(siteEls, d.data.site.views);
      }
    }).catch(function () {});
  }

  function bootStats() {
    collectViews();
    if (!STATS_ON) return;

    /* 指向别页的容器：只读数字，不发写请求 */
    var keys = [];
    for (var k in otherEls) {
      if (Object.prototype.hasOwnProperty.call(otherEls, k)) keys.push(k);
    }
    if (keys.length) {
      apiQuery(keys, function (data) {
        for (var i = 0; i < keys.length; i++) {
          var d = data[keys[i]];
          paintViews(otherEls[keys[i]], d && typeof d.views === 'number' ? d.views : 0);
        }
      });
    }

    function run() {
      sendPageview();
    }

    /* 后台标签页和预渲染先不报，等真正被看到 */
    if (document.visibilityState === 'hidden') {
      var once = function () {
        if (document.visibilityState !== 'hidden') {
          document.removeEventListener('visibilitychange', once);
          run();
        }
      };
      document.addEventListener('visibilitychange', once);
    } else {
      run();
    }

    /* SPA：pushState / replaceState / 后退都补一条浏览 */
    function hookHistory(name) {
      var orig = history[name];
      if (typeof orig !== 'function') return;
      history[name] = function () {
        var r = orig.apply(this, arguments);
        setTimeout(run, 0);
        return r;
      };
    }
    hookHistory('pushState');
    hookHistory('replaceState');
    window.addEventListener('popstate', function () { setTimeout(run, 0); });
  }

  /* ---------------------------------------------------------------- 点赞 */

  function bootLikes() {
    var list = document.querySelectorAll('[data-zan]');
    if (!list.length) return;

    var i;
    for (i = 0; i < list.length; i++) {
      if (isEmpty(list[i])) { injectCSS(); break; }
    }

    var groups = {};
    var order = [];

    for (i = 0; i < list.length; i++) {
      var el = list[i];
      if (el.getAttribute('data-zan-ready')) continue;
      el.setAttribute('data-zan-ready', '1');

      var size = el.getAttribute('data-zan-size');
      if (size && SIZE[size]) el.style.setProperty('--zan-size', SIZE[size]);
      var color = el.getAttribute('data-zan-color');
      if (color) el.style.setProperty('--zan-color', color);

      var key = keyOf(el);
      var writable = canWrite(el);
      mount(el, writable);

      var g = groups[key];
      if (!g) {
        g = groups[key] = { key: key, likes: 0, liked: false, busy: false, once: onceOf(el), items: [] };
        order.push(key);
      }
      /* 容器显式声明 content：写请求带上去，后端据此跳过路径比对 */
      if (scopeOf(el) === 'content') g.force = true;
      var trigger = triggerOf(el);
      g.items.push({ el: el, trigger: trigger, count: findCount(el), writable: writable });

      /* 只读容器不绑点击：首页 / 列表页的卡片只显示数字 */
      if (writable) {
        (function (group, trg) {
          trg.addEventListener('click', function (e) {
            if (trg.tagName === 'A') e.preventDefault();
            vote(group);
          });
          trg.addEventListener('keydown', function (e) {
            if (trg.tagName === 'BUTTON' || trg.tagName === 'A') return;
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
              e.preventDefault();
              vote(group);
            }
          });
        })(g, trigger);
      }
    }

    var marks = likedAll();
    for (var k = 0; k < order.length; k++) {
      if (marks[order[k]]) groups[order[k]].liked = true;
      paint(groups[order[k]]);
    }

    apiQuery(order, function (data) {
      for (var j = 0; j < order.length; j++) {
        var key = order[j];
        var g = groups[key];
        if (data[key] && typeof data[key].likes === 'number') g.likes = data[key].likes;
        paint(g);
      }
    });

    try {
      if (window.__ZAN_DEBUG__) console.log('[Sugar-Box] ' + VERSION + ' keys=' + order.length);
    } catch (e) {}
  }

  /* 暴露给主题或站长脚本 */
  window.Zan = {
    version: VERSION,
    server: SERVER,
    vid: vid,
    key: normKey,
    pageview: sendPageview
  };
  window.SugarBox = window.Zan;

  ready(function () {
    bootStats();
    bootLikes();
  });
})();
