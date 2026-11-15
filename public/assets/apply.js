/*!
 * Beacon 友链申请 0.1.0 — 在博客里嵌一个申请表单
 *
 * 两种用法，二选一：
 *   1) 嵌进现有页面：<div data-friend-apply></div>  然后，e.js 会自动加载本文件
 *   2) 直接用托管页：https://你的监控域名/apply
 *
 * 可选参数（写在容器上）：
 *   data-title="申请友链"          表单标题，留空则不显示
 *   data-note="先加我，再加你"      标题下面的一行说明
 *   data-required-email            强制要求填邮箱
 * 样式全部带 bma- 前缀，颜色跟随主题，不污染博客样式
 */
(function () {
  'use strict';

  if (window.BeaconApply) return;

  var script =
    document.currentScript ||
    (function () {
      var all = document.getElementsByTagName('script');
      for (var i = all.length - 1; i >= 0; i -= 1) {
        if (/\/e\/apply\.js(\?|$)/.test(all[i].src)) return all[i];
      }
      return null;
    })();

  var DATA = script && script.getAttribute('data-server');
  var SERVER = DATA
    ? DATA.replace(/\/+$/, '')
    : script && script.src
      ? new URL(script.src).origin
      : location.origin;

  var TARGET = (script && script.getAttribute('data-target')) || '[data-friend-apply],[data-friendlink-apply]';

  var FIELDS = [
    {
      name: 'name',
      label: '站点名称',
      placeholder: '比如：某某的小站',
      maxlength: 40,
      required: true,
    },
    {
      name: 'url',
      label: '站点地址',
      placeholder: 'https://example.com',
      type: 'url',
      maxlength: 200,
      required: true,
    },
    {
      name: 'avatar',
      label: '头像地址',
      placeholder: 'https://example.com/avatar.png（选填）',
      maxlength: 300,
    },
    {
      name: 'desc',
      label: '一句话介绍',
      placeholder: '这个站在写什么（选填）',
      maxlength: 120,
    },
    {
      name: 'email',
      label: '邮箱',
      placeholder: '只有站长能看到（选填）',
      type: 'email',
      maxlength: 120,
    },
  ];

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function injectStyle() {
    if (document.getElementById('beacon-apply-style')) return;
    var css =
      '.bma{max-width:560px;font-size:14px;line-height:1.6;color:inherit}' +
      '.bma-title{margin:0 0 6px;font-size:17px;font-weight:600}' +
      '.bma-note{margin:0 0 18px;font-size:13px;opacity:.62}' +
      '.bma-row{margin-bottom:13px}' +
      '.bma-row label{display:block;margin-bottom:5px;font-size:12.5px;opacity:.72}' +
      '.bma-row label b{color:#e0245e;font-weight:400;margin-left:2px}' +
      '.bma-row input{width:100%;box-sizing:border-box;padding:9px 12px;border-radius:9px;' +
      'border:1px solid rgba(128,128,128,.3);background:rgba(128,128,128,.06);color:inherit;' +
      'font:inherit;font-size:13.5px;outline:none;transition:border-color .18s,background .18s}' +
      '.bma-row input:focus{border-color:rgba(128,128,128,.62);background:rgba(128,128,128,.1)}' +
      '.bma-row input::placeholder{color:rgba(128,128,128,.72)}' +
      '.bma-foot{display:flex;align-items:center;gap:12px;margin-top:16px;flex-wrap:wrap}' +
      // 别用 background:currentColor —— 同一规则里又设了 color 时，currentColor
      // 会解析成刚设的那个颜色，结果白底白字，按钮直接隐形。
      '.bma-btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 20px;' +
      'border-radius:9px;border:1px solid rgba(128,128,128,.4);background:rgba(128,128,128,.18);' +
      'color:inherit;font:inherit;font-size:13.5px;font-weight:500;cursor:pointer;' +
      'transition:background .18s,border-color .18s,transform .12s}' +
      '.bma-btn:hover{background:rgba(128,128,128,.3);border-color:rgba(128,128,128,.58)}' +
      '.bma-btn:active{transform:scale(.98)}' +
      '.bma-btn[disabled]{opacity:.5;cursor:default}' +
      '.bma-msg{font-size:12.5px;line-height:1.5}' +
      '.bma-msg.is-error{color:#dc2626}' +
      '.bma-msg.is-ok{color:#16a34a}' +
      '.bma-done{padding:22px 18px;border-radius:12px;border:1px dashed rgba(128,128,128,.34);text-align:center}' +
      '.bma-done strong{display:block;margin-bottom:6px;font-size:15px}' +
      '.bma-done span{font-size:13px;opacity:.66}';
    var tag = document.createElement('style');
    tag.id = 'beacon-apply-style';
    tag.textContent = css;
    document.head.appendChild(tag);
  }

  function formHtml(root) {
    // 用 hasAttribute 判断：写了 data-title="" 表示「我自己的页面已经有标题了，别再画一个」
    var title = root.hasAttribute('data-title') ? root.getAttribute('data-title') : '申请友链';
    var note = root.hasAttribute('data-note') ? root.getAttribute('data-note') : '';
    var needEmail = root.hasAttribute('data-required-email');

    var rows = FIELDS.map(function (f) {
      var required = f.required || (f.name === 'email' && needEmail);
      return (
        '<div class="bma-row">' +
        '<label for="bma-' +
        f.name +
        '">' +
        esc(f.label) +
        (required ? '<b>*</b>' : '') +
        '</label>' +
        '<input id="bma-' +
        f.name +
        '" name="' +
        f.name +
        '" type="' +
        (f.type || 'text') +
        '" maxlength="' +
        f.maxlength +
        '" placeholder="' +
        esc(f.placeholder) +
        '" autocomplete="off"' +
        (required ? ' required' : '') +
        '>' +
        '</div>'
      );
    }).join('');

    return (
      (title ? '<h3 class="bma-title">' + esc(title) + '</h3>' : '') +
      (note ? '<p class="bma-note">' + esc(note) + '</p>' : '') +
      rows +
      '<div class="bma-foot">' +
      '<button class="bma-btn" type="submit">提交申请</button>' +
      '<span class="bma-msg" data-msg></span>' +
      '</div>'
    );
  }

  function mount(root) {
    if (root.getAttribute('data-bma-ready')) return;
    root.setAttribute('data-bma-ready', '1');
    root.classList.add('bma');

    var form = document.createElement('form');
    form.noValidate = true;
    form.innerHTML = formHtml(root);
    root.appendChild(form);

    var button = form.querySelector('.bma-btn');
    var msg = form.querySelector('[data-msg]');

    function fail(text) {
      msg.className = 'bma-msg is-error';
      msg.textContent = text;
    }

    function read() {
      var data = {};
      FIELDS.forEach(function (f) {
        var el = form.querySelector('[name="' + f.name + '"]');
        data[f.name] = el ? el.value.trim() : '';
      });
      return data;
    }

    function validate(data) {
      if (data.name.length < 2) return '站点名称至少 2 个字';
      if (!/^https?:\/\/.+\..+/.test(data.url)) return '站点地址要带 http:// 或 https://';
      if (root.hasAttribute('data-required-email') && !data.email) return '请填写邮箱';
      if (data.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) return '邮箱格式不对';
      return '';
    }

    function done(message) {
      form.innerHTML =
        '<div class="bma-done"><strong>✓ 已提交</strong><span>' +
        esc(message || '站长审核通过后，你的站点就会出现在友链和朋友圈里') +
        '</span></div>';
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (button.disabled) return;

      var data = read();
      var bad = validate(data);
      if (bad) {
        fail(bad);
        return;
      }

      button.disabled = true;
      button.textContent = '提交中…';
      msg.className = 'bma-msg';
      msg.textContent = '';

      fetch(SERVER + '/api/e/friend-apply', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify(data),
        mode: 'cors',
        credentials: 'omit',
      })
        .then(function (res) {
          return res.json().then(function (body) {
            if (!res.ok) throw new Error(body && body.error ? body.error : '提交失败');
            return body;
          });
        })
        .then(function (body) {
          done(body && body.message);
        })
        .catch(function (err) {
          button.disabled = false;
          button.textContent = '提交申请';
          fail(err.message || '网络不给力，稍后再试');
        });
    });
  }

  function boot() {
    var roots = document.querySelectorAll(TARGET);
    if (!roots.length) return;
    injectStyle();
    for (var i = 0; i < roots.length; i += 1) mount(roots[i]);
  }

  window.BeaconApply = {
    version: '0.1.0',
    server: SERVER,
    reload: boot,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
