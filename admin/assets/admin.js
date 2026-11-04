/* CiciCMS 后台脚本 */
(function () {
  'use strict';

  /* 删除确认：<a data-confirm="确定删除？"> */
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-confirm]');
    if (!el) return;
    if (!window.confirm(el.getAttribute('data-confirm'))) {
      ev.preventDefault();
      ev.stopPropagation();
    }
  });

  /* 简易编辑器工具栏：在 textarea#content 光标处插入标记 */
  function insertAtCursor(el, before, after) {
    var start = el.selectionStart, end = el.selectionEnd;
    var val = el.value;
    var sel = val.substring(start, end) || '文本';
    el.value = val.substring(0, start) + before + sel + after + val.substring(end);
    el.focus();
    el.selectionStart = start + before.length;
    el.selectionEnd = start + before.length + sel.length;
  }

  /* 代码块：插入 ```lang 围栏，光标落在中间空行上，可以直接敲代码 */
  function insertCodeBlock(ta) {
    var lang = window.prompt('代码语言（php / sql / bash / js / html / css / json，留空则不高亮）', 'php');
    if (lang === null) return;
    lang = (lang || '').replace(/[^A-Za-z0-9_+#.\-]/g, '');

    var start = ta.selectionStart, end = ta.selectionEnd;
    var val = ta.value;
    var sel = val.substring(start, end);
    var block = '```' + lang + '\n' + sel + '\n```';
    ta.value = val.substring(0, start) + block + val.substring(end);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = start + 3 + lang.length + 1 + sel.length;
  }

  /* 光标是否落在某个 ``` 围栏内部（按行首围栏计数，奇数即在块内） */
  function inCodeFence(ta) {
    var before = ta.value.substring(0, ta.selectionStart);
    var m = before.match(/^[ \t]*`{3,}[ \t]*[A-Za-z0-9_+#.\-]*[ \t]*$/gm);
    return m ? m.length % 2 === 1 : false;
  }

  /* 围栏内 Tab 插 4 个空格而不是跳走；Shift+Tab 反缩进 */
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Tab') return;
    var ta = document.getElementById('content');
    if (!ta || ev.target !== ta) return;
    if (!inCodeFence(ta)) return;
    ev.preventDefault();

    var start = ta.selectionStart, end = ta.selectionEnd;
    var val = ta.value;

    if (ev.shiftKey) {
      var lineStart = val.lastIndexOf('\n', start - 1) + 1;
      var cut = val.substring(lineStart, start).match(/^ {1,4}/);
      if (cut) {
        var n = cut[0].length;
        ta.value = val.substring(0, lineStart) + val.substring(lineStart + n);
        ta.selectionStart = ta.selectionEnd = Math.max(lineStart, start - n);
      }
      return;
    }

    var pad = '    ';
    ta.value = val.substring(0, start) + pad + val.substring(end);
    ta.selectionStart = ta.selectionEnd = start + pad.length;
  });

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-insert]');
    if (!btn) return;
    var ta = document.getElementById('content');
    if (!ta) return;
    ev.preventDefault();
    var d = btn.getAttribute('data-insert');
    if (d === 'h2') insertAtCursor(ta, '<h2>', '</h2>');
    else if (d === 'bold') insertAtCursor(ta, '<strong>', '</strong>');
    else if (d === 'p') insertAtCursor(ta, '<p>', '</p>');
    else if (d === 'quote') insertAtCursor(ta, '<blockquote>', '</blockquote>');
    else if (d === 'code') {
      insertCodeBlock(ta);
    }
    else if (d === 'ul') insertAtCursor(ta, '<ul>\n  <li>', '</li>\n</ul>');
    else if (d === 'link') {
      var href = window.prompt('请输入链接地址', 'https://');
      if (href) insertAtCursor(ta, '<a href="' + href + '">', '</a>');
    } else if (d === 'img') {
      var src = window.prompt('请输入图片地址（可先在媒体库上传后复制）', '');
      if (src) insertAtCursor(ta, '<img src="' + src + '" alt="', '">');
    }
  });

  /* 切换静态/动态预览等无需额外脚本 */
})();
