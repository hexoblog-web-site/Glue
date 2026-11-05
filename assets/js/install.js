/* 安装页小脚本：显示/隐藏密码、两次密码一致性提示 */
(function () {
  'use strict';

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-reveal]');
    if (!btn) return;
    var input = document.getElementById(btn.getAttribute('data-reveal'));
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? '隐藏' : '显示';
  });

  var p1 = document.getElementById('pass1');
  var p2 = document.getElementById('pass2');
  if (p1 && p2) {
    var check = function () {
      p2.setCustomValidity(p2.value && p1.value !== p2.value ? '两次输入的密码不一致' : '');
    };
    p1.addEventListener('input', check);
    p2.addEventListener('input', check);
  }
})();
