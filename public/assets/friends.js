/*!
 * Beacon 友链墙 0.4.0 — 渲染后台里的友链，不用再维护 link.yml
 *
 * 设计原则：DOM 与主题的友链模板逐字对齐（mint / Butterfly 5.7.2 的 flink.ejs），
 * 所以样式、hover、暗色、响应式全部由主题的 flink.css 接管，这里一行样式都不重复写。
 * 只有确认页面上没有任何 flink 样式时，才注入一份等价兜底。
 *
 * 用法（放在友情链接页正文里）：
 *   <div data-friends data-apply></div>
 * 上面那一行 e.js 会自动发现并渲染。
 *
 * 可选参数：
 *   data-apply              底部「申请友链」按钮，指向 /apply
 *   data-group="推荐"        分组标题
 *   data-group-desc="..."   分组描述
 *   data-limit="60"         最多显示几个
 *   data-order="name"       按名称排，默认保持后台顺序
 *   data-layout="card"      16:9 封面卡版式（默认 list 横条卡）
 *   data-theme-css="/css/layout/flink.css"  强制引入某份主题样式表
 *   data-palette="#E6F1FB,#FBEAF0|#E1F5EE,#E6F1FB"  覆盖首字渐变配色
 */
(function () {
  'use strict';

  if (window.BeaconFriends) return;

  var script =
    document.currentScript ||
    (function () {
      var all = document.getElementsByTagName('script');
      for (var i = all.length - 1; i >= 0; i -= 1) {
        if (/\/e\/friends\.js(\?|$)/.test(all[i].src)) return all[i];
      }
      return null;
    })();

  var DATA = script && script.getAttribute('data-server');
  var SERVER = DATA
    ? DATA.replace(/\/+$/, '')
    : script && script.src
      ? new URL(script.src).origin
      : location.origin;

  var TARGET = (script && script.getAttribute('data-target')) || '[data-friends]';
  var STYLE_ID = 'beacon-friends-style';

  /* 主题 cover.palette 的默认值（低饱和双色，按站名哈希取一组） */
  var PALETTE = [
    ['#E6F1FB', '#FBEAF0'],
    ['#E1F5EE', '#E6F1FB'],
    ['#FBEAF0', '#FAEEDA'],
    ['#E6F1FB', '#E1F5EE'],
    ['#FAEEDA', '#E1F5EE'],
    ['#EEEDFE', '#E6F1FB'],
  ];

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /** 与 mint_cover_colors 完全一致：hash * 31 + code，>>> 0 后取模 */
  function coverColors(name, palette) {
    var key = String(name || '');
    var hash = 0;
    for (var i = 0; i < key.length; i += 1) hash = (hash * 31 + key.charCodeAt(i)) >>> 0;
    var pair = palette[hash % palette.length];
    return Array.isArray(pair) && pair.length >= 2 ? pair : palette[0];
  }

  function parsePalette(raw) {
    if (!raw) return PALETTE;
    var groups = String(raw).split('|');
    var out = [];
    for (var i = 0; i < groups.length; i += 1) {
      var pair = groups[i].split(',').map(function (s) {
        return s.trim();
      });
      if (pair.length >= 2 && pair[0] && pair[1]) out.push([pair[0], pair[1]]);
    }
    return out.length ? out : PALETTE;
  }

  /** 对方头像可能写成 /avatar.png，要按对方站点解析，不能按本站 */
  function absUrl(raw, base) {
    var value = String(raw || '').trim();
    if (!value) return '';
    if (/^data:/i.test(value)) return value;
    if (/^https?:\/\//i.test(value)) return value;
    if (value.indexOf('//') === 0) return 'https:' + value;
    try {
      return new URL(value, base).toString();
    } catch (err) {
      return '';
    }
  }

  /** 头像挂了换成首字图；仍是 <img>，保主题的圆形裁切与 hover 放大 */
  function initialImage(name, colors) {
    var ch = String(name || '?').trim().charAt(0).toUpperCase();
    var svg =
      '<svg xmlns="http://www.w3.org/2000/svg" width="88" height="88" viewBox="0 0 88 88">' +
      '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">' +
      '<stop offset="0" stop-color="' + colors[0] + '"/><stop offset="1" stop-color="' + colors[1] + '"/>' +
      '</linearGradient></defs><rect width="88" height="88" fill="url(#g)"/>' +
      '<text x="44" y="58" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif" ' +
      'font-size="40" font-weight="700" fill="rgba(42,45,74,.5)" text-anchor="middle">' +
      esc(ch) +
      '</text></svg>';
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  }

  // ---------- 主题样式探测 ----------

  function rulesHaveFlink(list) {
    if (!list) return false;
    for (var i = 0; i < list.length; i += 1) {
      var rule = list[i];
      try {
        if (rule.selectorText && rule.selectorText.indexOf('flink') > -1) return true;
        if (rule.cssRules && rulesHaveFlink(rule.cssRules)) return true;
      } catch (err) {
        /* 个别规则读不到就跳过 */
      }
    }
    return false;
  }

  function sheetsHaveFlink() {
    var sheets = document.styleSheets;
    for (var i = 0; i < sheets.length; i += 1) {
      var rules = null;
      try {
        rules = sheets[i].cssRules;
      } catch (err) {
        continue; // 跨域样式表读不到，交给探针
      }
      if (rulesHaveFlink(rules)) return true;
    }
    return false;
  }

  /** 样式表读不到时用探针：主题给 .flink-list 设了 grid */
  function probeFlink() {
    var box = document.createElement('div');
    box.className = 'flink-list';
    box.style.cssText = 'position:absolute;left:-9999px;top:0;width:10px;height:10px;';
    document.body.appendChild(box);
    var css = window.getComputedStyle(box);
    var hit = css.display !== 'block' || css.gap !== 'normal';
    box.parentNode.removeChild(box);
    return hit;
  }

  function detectTheme() {
    try {
      if (sheetsHaveFlink()) return 'theme';
    } catch (err) {
      /* 忽略 */
    }
    try {
      if (probeFlink()) return 'theme';
    } catch (err) {
      /* 忽略 */
    }
    return 'self';
  }

  /** 手动指定样式表时，先试着引一次；引不到就退到内置兜底 */
  function ensureThemeCss(href) {
    if (!href) return;
    if (document.querySelector('link[href*="' + href.replace(/["'\\]/g, '') + '"]')) return;
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    document.head.appendChild(link);
  }

  // ---------- 兜底样式 ----------

  /**
   * 只在确认页面上没有 flink 样式时才注入。
   * 数值与颜色尽量走主题 token（--bg-subtle / --radius-card / --line），
   * 所以即便主题没有 flink.css，观感也和站点其余卡片一致。
   */
  function injectFallbackStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var css =
      '.beacon-flink{margin-bottom:8px}' +
      '.beacon-flink .flink-group{margin-top:38px}' +
      '.beacon-flink .flink-group:first-child{margin-top:4px}' +
      '.beacon-flink .flink-group-title{display:flex;align-items:center;gap:8px;margin:0 0 12px;' +
      'font-size:20px;font-weight:600;line-height:1.5;color:var(--text-heading,inherit)}' +
      '.beacon-flink .flink-group-count{padding:1px 8px;border-radius:999px;' +
      'background:var(--brand-tint,rgba(128,128,128,.14));color:var(--brand,inherit);' +
      'font-size:12px;font-weight:500;line-height:1.7}' +
      '.beacon-flink .flink-anchor{color:var(--text-faint,rgba(128,128,128,.6));font-size:13px;' +
      'opacity:0;transition:opacity .2s}' +
      '.beacon-flink .flink-group-title:hover .flink-anchor{opacity:1}' +
      '.beacon-flink .flink-desc{margin:-4px 0 16px;font-size:13px;line-height:1.6;' +
      'color:var(--text-muted,rgba(128,128,128,.9))}' +
      '.beacon-flink .flink-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}' +
      '.beacon-flink .flink-item{position:relative;display:flex;align-items:center;gap:12px;' +
      'padding:12px 14px;border:1px solid transparent;border-radius:var(--radius-card,12px);' +
      'background:var(--bg-subtle,rgba(128,128,128,.07));color:inherit;text-decoration:none;' +
      'overflow:hidden;transition:background .25s,border-color .25s,box-shadow .25s,transform .25s}' +
      '.beacon-flink .flink-item:hover{background:var(--bg-card,#fff);' +
      'border-color:rgba(128,128,128,.3);transform:translateY(-2px);' +
      'box-shadow:0 6px 18px rgba(0,0,0,.08)}' +
      '.beacon-flink .flink-item-avatar{flex:0 0 44px;width:44px;height:44px;border-radius:50%;' +
      'overflow:hidden;display:block;background:var(--bg-card,#fff);' +
      'box-shadow:inset 0 0 0 1px var(--line,rgba(128,128,128,.2))}' +
      '.beacon-flink .flink-item-avatar img{display:block;width:100%;height:100%;object-fit:cover;' +
      'transition:transform .4s}' +
      '.beacon-flink .flink-item:hover .flink-item-avatar img{transform:scale(1.08)}' +
      '.beacon-flink .flink-item-body{flex:1 1 auto;min-width:0;display:flex;align-items:center;gap:12px}' +
      '.beacon-flink .flink-item-text{min-width:0;display:flex;flex-direction:column;gap:2px}' +
      '.beacon-flink .flink-item-name{font-size:14.5px;font-weight:600;line-height:1.5;' +
      'color:var(--text-heading,inherit);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
      '.beacon-flink .flink-item:hover .flink-item-name{color:var(--brand,inherit)}' +
      '.beacon-flink .flink-item-desc{font-size:12.5px;line-height:1.5;' +
      'color:var(--text-muted,rgba(128,128,128,.9));white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
      '.beacon-flink .flink-item-cover{display:none}' +
      '.beacon-flink .flink-item-mark{display:grid;place-items:center;width:100%;height:100%;' +
      'background-image:linear-gradient(135deg,var(--gen-a,#E9E6FF),var(--gen-b,#DCF5FA));' +
      'color:rgba(42,45,74,.45);font-size:16px;font-weight:700;line-height:1;user-select:none}' +
      '.beacon-flink .flink-list--card{grid-template-columns:repeat(auto-fill,minmax(240px,1fr))}' +
      '.beacon-flink .flink-list--card .flink-item{flex-direction:column;align-items:stretch;' +
      'gap:0;padding:0;background:var(--bg-card,#fff);border-color:var(--line,rgba(128,128,128,.18))}' +
      '.beacon-flink .flink-list--card .flink-item-cover{display:block;position:relative;' +
      'aspect-ratio:16 / 9;overflow:hidden}' +
      '.beacon-flink .flink-list--card .flink-item-cover .flink-item-mark{position:absolute;' +
      'inset:0;font-size:40px}' +
      '.beacon-flink .flink-list--card .flink-item-body{padding:10px 12px;gap:10px}' +
      '.beacon-flink .flink-list--card .flink-item-avatar{flex-basis:32px;width:32px;height:32px}' +
      '.beacon-flink .flink-list--card .flink-item-desc{display:none}' +
      '.beacon-flink .flink-apply{margin:26px 0 0;font-size:13px;' +
      'color:var(--text-muted,rgba(128,128,128,.9))}' +
      '.beacon-flink .flink-apply a{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;' +
      'border-radius:8px;border:1px solid var(--line,rgba(128,128,128,.34));' +
      'color:inherit;text-decoration:none;transition:border-color .2s}' +
      '.beacon-flink .flink-apply a:hover{border-color:var(--brand,rgba(128,128,128,.7))}' +
      '.beacon-flink .flink-empty{padding:22px 24px;border:1px dashed var(--line,rgba(128,128,128,.3));' +
      'border-radius:var(--radius-card,12px);background:var(--bg-subtle,rgba(128,128,128,.06));' +
      'color:var(--text-muted,rgba(128,128,128,.9));font-size:13.5px;line-height:1.9}' +
      '@media (max-width:689px){.beacon-flink .flink-list,' +
      '.beacon-flink .flink-list--card{grid-template-columns:minmax(0,1fr)}' +
      '.beacon-flink .flink-group-title{font-size:18px}}';
    var tag = document.createElement('style');
    tag.id = STYLE_ID;
    tag.textContent = css;
    document.head.appendChild(tag);
  }

  // ---------- 渲染 ----------

  /** 与 flink.ejs 逐字对齐：a.flink-item > cover / body / tag */
  function itemHtml(link, palette, withCover) {
    var href = String(link.url || '').trim();
    var name = String(link.name || '').trim() || href;
    var desc = String(link.desc || '').trim();
    var avatar = absUrl(link.avatar, href);
    var colors = coverColors(name, palette);
    var mark = esc(String(name).trim().charAt(0));

    var avatarHtml = avatar
      ? '<img src="' + esc(avatar) + '" alt="' + esc(name) + '" loading="lazy" decoding="async" ' +
        'referrerpolicy="no-referrer" data-name="' + esc(name) + '" data-gen="' + colors.join(',') + '">'
      : '<span class="flink-item-mark flink-item-mark--flat">' + mark + '</span>';

    return (
      '<a class="flink-item" href="' + esc(href) + '" target="_blank" ' +
      'rel="noopener external nofollow" ' +
      'style="--gen-a:' + esc(colors[0]) + ';--gen-b:' + esc(colors[1]) + '" ' +
      'title="' + esc(name + (desc ? ' · ' + desc : '')) + '">' +
      (withCover
        ? '<span class="flink-item-cover" aria-hidden="true">' +
          '<span class="flink-item-mark">' + mark + '</span></span>'
        : '') +
      '<span class="flink-item-body">' +
      '<span class="flink-item-avatar">' + avatarHtml + '</span>' +
      '<span class="flink-item-text">' +
      '<span class="flink-item-name">' + esc(name) + '</span>' +
      (desc ? '<span class="flink-item-desc">' + esc(desc) + '</span>' : '') +
      '</span></span></a>'
    );
  }

  function guardAvatars(root) {
    var imgs = root.querySelectorAll('.flink-item-avatar img');
    for (var i = 0; i < imgs.length; i += 1) {
      imgs[i].addEventListener('error', function () {
        if (this.getAttribute('data-failed')) return;
        this.setAttribute('data-failed', '1');
        var gen = String(this.getAttribute('data-gen') || '').split(',');
        this.src = initialImage(this.getAttribute('data-name'), [gen[0] || '#E6F1FB', gen[1] || '#FBEAF0']);
      });
    }
  }

  function mount(root) {
    if (root.getAttribute('data-beacon-ready')) return;
    root.setAttribute('data-beacon-ready', '1');

    var limit = Number(root.getAttribute('data-limit')) || 0;
    var byName = root.getAttribute('data-order') === 'name';
    var wantsApply = root.hasAttribute('data-apply');
    var layout = root.getAttribute('data-layout') === 'card' ? 'card' : 'list';
    var groupName = root.getAttribute('data-group') || '';
    var groupDesc = root.getAttribute('data-group-desc') || '';
    var palette = parsePalette(root.getAttribute('data-palette'));

    ensureThemeCss(root.getAttribute('data-theme-css'));
    if (detectTheme() !== 'theme') injectFallbackStyle();

    fetch(SERVER + '/api/e/friends.json', { mode: 'cors', credentials: 'omit' })
      .then(function (res) {
        return res.ok ? res.json() : null;
      })
      .then(function (payload) {
        var links = (payload && payload.links) || [];

        if (byName) {
          links = links.slice().sort(function (a, b) {
            return String(a.name || '').localeCompare(String(b.name || ''), 'zh-Hans-CN');
          });
        }
        if (limit > 0) links = links.slice(0, limit);

        if (!links.length) {
          root.innerHTML =
            '<div class="flink beacon-flink"><p class="flink-empty">还没有添加友链</p></div>';
          return;
        }

        var anchor = 'flink-' + (groupName ? groupName.replace(/[\p{P}\p{S}\s]+/gu, '-') : 'beacon');
        var html = '<div class="flink beacon-flink">';

        if (groupName || groupDesc) {
          html += '<section class="flink-group" id="' + esc(anchor) + '">';
          if (groupName) {
            html +=
              '<h2 class="flink-group-title">' +
              '<a class="flink-anchor" href="#' + esc(anchor) + '" aria-label="锚点">' +
              '<i class="ri-link" aria-hidden="true"></i></a>' +
              esc(groupName) +
              '<span class="flink-group-count">' + links.length + '</span></h2>';
          }
          if (groupDesc) html += '<p class="flink-desc">' + esc(groupDesc) + '</p>';
        }

        html +=
          '<div class="flink-list' + (layout === 'card' ? ' flink-list--card' : '') + '">' +
          links
            .map(function (l) {
              return itemHtml(l, palette, layout === 'card');
            })
            .join('') +
          '</div>';

        if (groupName || groupDesc) html += '</section>';
        html += '</div>';

        if (wantsApply) {
          html +=
            '<p class="flink-apply"><a href="' + esc(SERVER) + '/apply" target="_blank" rel="noopener">' +
            '<i class="ri-link" aria-hidden="true"></i>申请友链</a></p>';
        }

        root.innerHTML = html;
        guardAvatars(root);
        if (window.lazyLoadInstance && window.lazyLoadInstance.update) window.lazyLoadInstance.update();
      })
      .catch(function () {
        root.innerHTML =
          '<div class="flink beacon-flink"><p class="flink-empty">友链加载失败，刷新一下试试</p></div>';
      });
  }

  function boot() {
    var roots = document.querySelectorAll(TARGET);
    if (!roots.length) return;
    for (var i = 0; i < roots.length; i += 1) mount(roots[i]);
  }

  window.BeaconFriends = {
    version: '0.4.0',
    server: SERVER,
    style: detectTheme(),
    reload: boot,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
