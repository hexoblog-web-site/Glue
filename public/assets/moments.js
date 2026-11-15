/*!
 * Beacon 朋友圈 0.2.0 — 把友链的最新文章渲染成卡片墙
 * 通常不需要单独引入：写 <div data-moments></div> 后，e.js 会自动加载本文件。
 * 单独用法：<div id="fcircle"></div>
 *          <script src="https://你的监控域名/e/moments.js" data-target="#fcircle" data-server="https://你的监控域名" defer></script>
 * 样式全部带 bmfc- 前缀，颜色跟随主题，不污染博客样式
 */
(function () {
  'use strict';

  if (window.BeaconMoments) return;

  var script =
    document.currentScript ||
    (function () {
      var all = document.getElementsByTagName('script');
      for (var i = all.length - 1; i >= 0; i -= 1) {
        if (/\/e\/moments\.js(\?|$)/.test(all[i].src)) return all[i];
      }
      return null;
    })();

  var DATA = script && script.getAttribute('data-server');
  var SERVER = DATA
    ? DATA.replace(/\/+$/, '')
    : script && script.src
      ? new URL(script.src).origin
      : location.origin;

  var TARGET = (script && script.getAttribute('data-target')) || '[data-moments],#fcircle,.fcircle';

  function attrNum(root, name, fallback) {
    var fromRoot = root.getAttribute('data-' + name);
    var fromScript = script && script.getAttribute('data-' + name);
    var n = Number(fromRoot || fromScript);
    return n > 0 ? n : fallback;
  }

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function timeAgo(ts) {
    if (!ts) return '';
    var diff = Date.now() - Number(ts);
    if (diff < 0) diff = 0;
    var min = Math.floor(diff / 60000);
    if (min < 1) return '刚刚';
    if (min < 60) return min + ' 分钟前';
    var hour = Math.floor(min / 60);
    if (hour < 24) return hour + ' 小时前';
    var day = Math.floor(hour / 24);
    if (day < 30) return day + ' 天前';
    var d = new Date(Number(ts));
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  function pad(n) {
    return n < 10 ? '0' + n : String(n);
  }

  function hueOf(text) {
    var hash = 0;
    var str = String(text || '');
    for (var i = 0; i < str.length; i += 1) hash = (hash * 31 + str.charCodeAt(i)) % 360;
    return hash;
  }

  function avatarOf(friend) {
    if (friend.avatar) {
      return (
        '<img class="bmfc-avatar" src="' +
        esc(friend.avatar) +
        '" alt="" loading="lazy" referrerpolicy="no-referrer" ' +
        'onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'grid\'">' +
        '<span class="bmfc-avatar bmfc-avatar-text" style="display:none;background:hsl(' +
        hueOf(friend.name) +
        ',52%,62%)">' +
        esc(String(friend.name || '?').slice(0, 1)) +
        '</span>'
      );
    }
    return (
      '<span class="bmfc-avatar bmfc-avatar-text" style="background:hsl(' +
      hueOf(friend.name) +
      ',52%,62%)">' +
      esc(String(friend.name || '?').slice(0, 1)) +
      '</span>'
    );
  }

  var STYLE =
    '.bmfc{--bmfc-border:rgba(128,128,128,.22);--bmfc-soft:rgba(128,128,128,.07);color:inherit;' +
    'font-size:14px;line-height:1.65}' +
    '.bmfc *{box-sizing:border-box}' +
    '.bmfc a{color:inherit;text-decoration:none}' +
    '.bmfc-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:26px 0 12px}' +
    '.bmfc-title h3{margin:0;font-size:15px;font-weight:600;letter-spacing:.01em}' +
    '.bmfc-title small{font-size:12px;opacity:.55;font-weight:400}' +
    '.bmfc-btn{height:28px;padding:0 12px;border-radius:999px;border:1px solid var(--bmfc-border);' +
    'background:transparent;color:inherit;font:inherit;font-size:12px;cursor:pointer;opacity:.8;transition:.2s}' +
    '.bmfc-btn:hover{opacity:1;border-color:rgba(128,128,128,.5)}' +
    '.bmfc-fish{border:1px solid var(--bmfc-border);border-radius:14px;padding:14px 16px;background:var(--bmfc-soft)}' +
    '.bmfc-fish a{color:inherit;font-weight:600;text-decoration:none}' +
    '.bmfc-fish a:hover{text-decoration:underline}' +
    '.bmfc-fish p{margin:6px 0 0;font-size:13px;opacity:.68;display:-webkit-box;-webkit-line-clamp:2;' +
    '-webkit-box-orient:vertical;overflow:hidden}' +
    '.bmfc-fish small{display:block;margin-top:8px;font-size:11.5px;opacity:.45}' +
    '.bmfc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(268px,1fr));gap:12px}' +
    '.bmfc-card{border:1px solid var(--bmfc-border);border-radius:14px;padding:14px 16px;' +
    'background:var(--bmfc-soft);transition:border-color .2s,transform .2s;display:flex;flex-direction:column;gap:10px}' +
    '.bmfc-card:hover{border-color:rgba(128,128,128,.42);transform:translateY(-2px)}' +
    '.bmfc-card-head{display:flex;align-items:center;gap:10px;min-width:0}' +
    '.bmfc-avatar{width:34px;height:34px;border-radius:50%;object-fit:cover;flex:none}' +
    '.bmfc-avatar-text{display:grid;place-items:center;color:#fff;font-size:15px;font-weight:600}' +
    '.bmfc-meta{min-width:0;display:flex;flex-direction:column;gap:1px}' +
    '.bmfc-name{font-size:13.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
    '.bmfc-desc{font-size:11.5px;opacity:.5;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
    '.bmfc-posts{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:7px}' +
    '.bmfc-posts li{display:flex;align-items:baseline;gap:8px;min-width:0}' +
    '.bmfc-posts a{color:inherit;text-decoration:none;font-size:13px;overflow:hidden;' +
    'text-overflow:ellipsis;white-space:nowrap;flex:1 1 auto;min-width:0}' +
    '.bmfc-posts a:hover{text-decoration:underline}' +
    '.bmfc-posts time{flex:none;font-size:11px;opacity:.42;font-variant-numeric:tabular-nums}' +
    '.bmfc-empty{padding:28px;text-align:center;font-size:13px;opacity:.5;border:1px dashed var(--bmfc-border);' +
    'border-radius:14px}' +
    '@media (max-width:520px){.bmfc-grid{grid-template-columns:1fr}}';

  function injectStyle() {
    if (document.getElementById('bmfc-style')) return;
    var tag = document.createElement('style');
    tag.id = 'bmfc-style';
    tag.textContent = STYLE;
    document.head.appendChild(tag);
  }

  function renderShell(root) {
    root.innerHTML =
      '<section class="bmfc-fish" data-fish></section>' +
      '<div class="bmfc-title"><h3>朋友圈 <small data-count></small></h3>' +
      '<button class="bmfc-btn" type="button" data-refresh>换一篇</button></div>' +
      '<div class="bmfc-grid" data-grid></div>';
  }

  function renderFish(root, posts) {
    var box = root.querySelector('[data-fish]');
    if (!box) return;
    if (!posts.length) {
      box.innerHTML = '<p style="opacity:.5;margin:0">还没有抓到友链文章</p>';
      return;
    }
    var pick = posts[Math.floor(Math.random() * posts.length)];
    box.innerHTML =
      '<a href="' +
      esc(pick.url) +
      '" target="_blank" rel="noopener">' +
      esc(pick.title || '无标题') +
      '</a>' +
      (pick.summary ? '<p>' + esc(pick.summary) + '</p>' : '') +
      '<small>' +
      esc(pick.friend && pick.friend.name ? pick.friend.name : '') +
      ' · ' +
      esc(timeAgo(pick.publishedAt)) +
      '</small>';
  }

  function renderGrid(root, cards) {
    var grid = root.querySelector('[data-grid]');
    var count = root.querySelector('[data-count]');
    if (count) count.textContent = cards.length ? cards.length + ' 位朋友' : '';

    if (!cards.length) {
      grid.innerHTML = '<div class="bmfc-empty">还没有朋友的文章，等站长添加友链后会自动出现</div>';
      return;
    }

    grid.innerHTML = cards
      .map(function (card) {
        var rows = card.posts
          .map(function (post) {
            return (
              '<li><a href="' +
              esc(post.url) +
              '" target="_blank" rel="noopener" title="' +
              esc(post.title) +
              '">' +
              esc(post.title || '无标题') +
              '</a><time>' +
              esc(timeAgo(post.ts)) +
              '</time></li>'
            );
          })
          .join('');

        return (
          '<article class="bmfc-card">' +
          '<div class="bmfc-card-head">' +
          avatarOf(card) +
          '<div class="bmfc-meta">' +
          '<a class="bmfc-name" href="' +
          esc(card.url) +
          '" target="_blank" rel="noopener">' +
          esc(card.name) +
          '</a>' +
          (card.desc ? '<span class="bmfc-desc">' + esc(card.desc) + '</span>' : '') +
          '</div></div>' +
          '<ul class="bmfc-posts">' +
          rows +
          '</ul>' +
          '</article>'
        );
      })
      .join('');
  }

  function mount(root) {
    if (root.getAttribute('data-bmfc-ready')) return;
    root.setAttribute('data-bmfc-ready', '1');
    root.classList.add('bmfc');

    var per = attrNum(root, 'per', 3);
    var limit = attrNum(root, 'limit', 30);
    var state = { posts: [] };

    renderShell(root);

    root.addEventListener('click', function (event) {
      var btn = event.target.closest('[data-refresh]');
      if (!btn) return;
      renderFish(root, state.posts);
    });

    fetch(
      SERVER + '/api/e/moments.json?per=' + encodeURIComponent(per) + '&limit=' + encodeURIComponent(limit),
      { mode: 'cors', credentials: 'omit' }
    )
      .then(function (res) {
        return res.ok ? res.json() : null;
      })
      .then(function (payload) {
        if (!payload) throw new Error('empty');
        state.posts = payload.posts || [];
        renderFish(root, state.posts);
        renderGrid(root, payload.friends || []);
      })
      .catch(function () {
        renderGrid(root, []);
      });
  }

  function boot() {
    var roots = document.querySelectorAll(TARGET);
    if (!roots.length) return;
    injectStyle();
    for (var i = 0; i < roots.length; i += 1) mount(roots[i]);
  }

  window.BeaconMoments = {
    version: '0.2.0',
    server: SERVER,
    reload: boot,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
