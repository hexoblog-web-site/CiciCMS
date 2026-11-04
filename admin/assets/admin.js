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

  /* 复制：<button data-copy="#cron-url">，取输入框的 value 或元素文本 */
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-copy]');
    if (!el) return;
    var src = document.querySelector(el.getAttribute('data-copy'));
    if (!src) return;
    var text = src.value !== undefined ? src.value : src.textContent;
    function done() {
      if (window.cmsToast) { window.cmsToast('已复制到剪贴板'); }
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () {
        src.select();
        document.execCommand('copy');
        done();
      });
    } else {
      src.select();
      document.execCommand('copy');
      done();
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

  /* 整段插入（不替换选区），用于 <img> 这类没有包裹文本的标签 */
  function insertHtml(el, html) {
    var start = el.selectionStart, end = el.selectionEnd;
    el.value = el.value.substring(0, start) + html + el.value.substring(end);
    el.focus();
    el.selectionStart = el.selectionEnd = start + html.length;
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

  /* ================= 图片插入弹窗 ================= */

  var META_TOKEN = 'cms-token';
  var META_ENDPOINT = 'cms-upload';

  function meta(name) {
    var el = document.querySelector('meta[name="' + name + '"]');
    return el ? el.getAttribute('content') : '';
  }

  function escAttr(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  var dlg = null;          // 弹窗根元素
  var state = { ta: null, url: '', busy: false, tab: 'upload' };

  var el = {};             // 弹窗内的元素引用

  function buildDialog() {
    if (dlg) return dlg;

    var wrap = document.createElement('div');
    wrap.className = 'cms-modal';
    wrap.hidden = true;
    wrap.innerHTML =
      '<div class="cms-modal-mask" data-close></div>' +
      '<div class="cms-modal-panel" role="dialog" aria-modal="true" aria-labelledby="cms-img-title" tabindex="-1">' +
        '<div class="cms-modal-head">' +
          '<h3 id="cms-img-title">插入图片</h3>' +
          '<button type="button" class="cms-modal-x" data-close aria-label="关闭">&times;</button>' +
        '</div>' +
        '<div class="cms-modal-tabs">' +
          '<button type="button" data-tab="upload" class="on">上传图片</button>' +
          '<button type="button" data-tab="url">图片地址</button>' +
        '</div>' +
        '<div class="cms-modal-body">' +
          '<div class="cms-pane" data-pane="upload">' +
            '<label class="cms-drop" tabindex="0">' +
              '<input type="file" accept="image/*" hidden>' +
              '<span class="cms-drop-main">点击选择图片，或把文件拖到这里</span>' +
              '<span class="cms-drop-sub">jpg / png / gif / webp，单张不超过 5MB</span>' +
            '</label>' +
            '<div class="cms-bar" hidden><span></span></div>' +
          '</div>' +
          '<div class="cms-pane" data-pane="url" hidden>' +
            '<input class="input" type="url" placeholder="https://example.com/a.jpg" autocomplete="off">' +
            '<label class="cms-check"><input type="checkbox" checked> 下载到本站媒体库（原站删图也不会失效）</label>' +
          '</div>' +
          '<div class="cms-preview" hidden>' +
            '<img alt="">' +
            '<div class="cms-preview-meta"></div>' +
          '</div>' +
          '<div class="cms-field">' +
            '<label>替代文字（alt，选填）</label>' +
            '<input class="input" type="text" maxlength="120" placeholder="图片内容的简短说明，利于无障碍与搜索">' +
          '</div>' +
          '<div class="cms-err" hidden></div>' +
        '</div>' +
        '<div class="cms-modal-foot">' +
          '<button type="button" class="btn" data-close>取消</button>' +
          '<button type="button" class="btn btn-primary" data-ok disabled>插入</button>' +
        '</div>' +
      '</div>';

    document.body.appendChild(wrap);

    el.file = wrap.querySelector('input[type="file"]');
    el.drop = wrap.querySelector('.cms-drop');
    el.bar = wrap.querySelector('.cms-bar');
    el.url = wrap.querySelector('.cms-pane[data-pane="url"] input[type="url"]');
    el.grab = wrap.querySelector('.cms-pane[data-pane="url"] input[type="checkbox"]');
    el.preview = wrap.querySelector('.cms-preview');
    el.previewImg = wrap.querySelector('.cms-preview img');
    el.previewMeta = wrap.querySelector('.cms-preview-meta');
    el.alt = wrap.querySelector('.cms-field input');
    el.err = wrap.querySelector('.cms-err');
    el.ok = wrap.querySelector('[data-ok]');

    wrap.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-close]')) {
        closeDialog();
        return;
      }
      var tab = ev.target.closest('[data-tab]');
      if (tab) {
        switchTab(tab.getAttribute('data-tab'));
        return;
      }
      if (ev.target.closest('[data-ok]')) {
        submitInsert();
      }
    });

    /* 选文件 → 立刻上传；清空 value 是为了同一个文件也能再选一次 */
    el.file.addEventListener('change', function () {
      if (!el.file.files || !el.file.files[0]) return;
      var f = el.file.files[0];
      el.file.value = '';
      uploadFile(f);
    });

    /* 拖拽上传 */
    ['dragenter', 'dragover'].forEach(function (t) {
      el.drop.addEventListener(t, function (ev) {
        ev.preventDefault();
        el.drop.classList.add('over');
      });
    });
    ['dragleave', 'drop'].forEach(function (t) {
      el.drop.addEventListener(t, function (ev) {
        ev.preventDefault();
        el.drop.classList.remove('over');
      });
    });
    el.drop.addEventListener('drop', function (ev) {
      var f = ev.dataTransfer && ev.dataTransfer.files ? ev.dataTransfer.files[0] : null;
      if (f) uploadFile(f);
    });

    el.url.addEventListener('input', refreshOk);
    el.grab.addEventListener('change', refreshOk);

    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && dlg && !dlg.hidden) closeDialog();
    });

    dlg = wrap;
    return wrap;
  }

  function switchTab(name) {
    state.tab = name;
    var tabs = dlg.querySelectorAll('[data-tab]');
    for (var i = 0; i < tabs.length; i++) {
      tabs[i].classList.toggle('on', tabs[i].getAttribute('data-tab') === name);
    }
    var panes = dlg.querySelectorAll('[data-pane]');
    for (var j = 0; j < panes.length; j++) {
      panes[j].hidden = panes[j].getAttribute('data-pane') !== name;
    }
    setErr('');
    refreshOk();
    if (name === 'url') {
      el.url.focus();
    }
  }

  function setErr(msg) {
    el.err.textContent = msg || '';
    el.err.hidden = !msg;
  }

  function setBusy(on, label) {
    state.busy = on;
    dlg.classList.toggle('busy', on);
    el.bar.hidden = !on;
    el.drop.style.pointerEvents = on ? 'none' : '';
    if (on) {
      el.ok.textContent = label || '处理中…';
    } else {
      el.ok.textContent = '插入';
    }
    refreshOk();
  }

  function refreshOk() {
    if (!el.ok) return;
    if (state.busy) {
      el.ok.disabled = true;
      return;
    }
    if (state.tab === 'upload') {
      el.ok.disabled = !state.url;
    } else {
      el.ok.disabled = el.url.value.trim() === '';
    }
  }

  function showResult(r) {
    state.url = r.url;
    el.previewImg.src = r.url;
    var bits = [];
    if (r.width && r.height) bits.push(r.width + ' × ' + r.height);
    if (r.size) bits.push(r.size);
    el.previewMeta.textContent = bits.join(' · ');
    el.preview.hidden = false;
    refreshOk();
  }

  function postForm(fd) {
    var endpoint = meta(META_ENDPOINT) || 'upload.php';
    return fetch(endpoint, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (res) {
      return res.json().catch(function () {
        return { ok: false, msg: '服务器返回异常（HTTP ' + res.status + '）' };
      });
    });
  }

  function uploadFile(file) {
    if (state.busy) return;
    setErr('');

    if (file.size > 5 * 1024 * 1024) {
      setErr('图片不能超过 5MB');
      return;
    }

    var fd = new FormData();
    fd.append('_token', meta(META_TOKEN));
    fd.append('mode', 'file');
    fd.append('file', file);

    setBusy(true, '上传中…');
    postForm(fd).then(function (r) {
      setBusy(false);
      if (!r.ok) {
        setErr(r.msg || '上传失败');
        return;
      }
      showResult(r);
    }).catch(function () {
      setBusy(false);
      setErr('网络异常，上传失败');
    });
  }

  function grabUrl(url) {
    var fd = new FormData();
    fd.append('_token', meta(META_TOKEN));
    fd.append('mode', 'url');
    fd.append('url', url);

    setBusy(true, '下载中…');
    return postForm(fd).then(function (r) {
      setBusy(false);
      if (!r.ok) {
        setErr((r.msg || '下载失败') + '。可以取消勾选「下载到本站」后直接插入原地址。');
        return false;
      }
      showResult(r);
      return true;
    }).catch(function () {
      setBusy(false);
      setErr('网络异常，下载失败');
      return false;
    });
  }

  function submitInsert() {
    if (state.busy) return;
    setErr('');

    if (state.tab === 'url') {
      var raw = el.url.value.trim();
      if (!raw) return;
      if (el.grab.checked) {
        grabUrl(raw).then(function (ok) {
          if (ok) commit();
        });
        return;
      }
      state.url = raw;
    }

    commit();
  }

  function commit() {
    if (!state.ta || !state.url) return;
    var alt = el.alt.value.trim();
    var ta = state.ta;
    // 前后补换行，避免 <img> 和正文粘在同一行，读源码时不好找
    var lead = ta.selectionStart > 0 && ta.value.charAt(ta.selectionStart - 1) !== '\n' ? '\n' : '';
    var tail = ta.selectionEnd < ta.value.length && ta.value.charAt(ta.selectionEnd) !== '\n' ? '\n' : '';
    insertHtml(ta, lead + '<img src="' + escAttr(state.url) + '" alt="' + escAttr(alt) + '">' + tail);
    closeDialog();
  }

  function openDialog(ta) {
    buildDialog();
    state.ta = ta;
    state.url = '';
    state.busy = false;

    el.file.value = '';
    el.url.value = '';
    el.alt.value = '';
    el.preview.hidden = true;
    el.previewImg.removeAttribute('src');
    el.previewMeta.textContent = '';
    el.bar.hidden = true;
    dlg.classList.remove('busy');
    setErr('');
    switchTab('upload');

    dlg.hidden = false;
    document.body.classList.add('cms-modal-open');
    /* 焦点落在面板上，Tab 依次进入控件；不抢到输入框，避免让人以为要先填文字 */
    dlg.querySelector('.cms-modal-panel').focus();
  }

  function closeDialog() {
    if (!dlg) return;
    dlg.hidden = true;
    document.body.classList.remove('cms-modal-open');
    state.ta = null;
    state.url = '';
    state.busy = false;
    el.ok.disabled = true;
    el.ok.textContent = '插入';
  }

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
    else if (d === 'code' || d === 'codeblock') {
      insertCodeBlock(ta);
    }
    else if (d === 'ul') insertAtCursor(ta, '<ul>\n  <li>', '</li>\n</ul>');
    else if (d === 'link') {
      var href = window.prompt('请输入链接地址', 'https://');
      if (href) insertAtCursor(ta, '<a href="' + escAttr(href) + '">', '</a>');
    } else if (d === 'img') {
      openDialog(ta);
    }
  });

  /* ================= 轻提示 ================= */

  /**
   * 右下角提示条
   *
   * @param {string} msg
   * @param {object} opts sticky=true 不自动消失；href 给了就整条可点
   */
  function cmsToast(msg, opts) {
    opts = opts || {};
    var box = document.createElement('div');
    box.className = 'cms-toast';

    if (opts.href) {
      var a = document.createElement('a');
      a.href = opts.href;
      a.textContent = msg;
      box.appendChild(a);
    } else {
      var span = document.createElement('span');
      span.textContent = msg;
      box.appendChild(span);
    }

    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'cms-toast-x';
    x.setAttribute('aria-label', '关闭');
    x.innerHTML = '&times;';
    x.addEventListener('click', function () {
      if (box.parentNode) box.parentNode.removeChild(box);
    });
    box.appendChild(x);

    document.body.appendChild(box);
    if (!opts.sticky) {
      setTimeout(function () {
        if (box.parentNode) box.parentNode.removeChild(box);
      }, 6000);
    }
    return box;
  }

  window.cmsToast = cmsToast;

  /* 耗时按钮：<form data-busy="正在更新…">，提交后禁用按钮并改文案，防止连点 */
  document.addEventListener('submit', function (ev) {
    var f = ev.target.closest('form[data-busy]');
    if (!f) return;
    var btn = f.querySelector('button[type="submit"]');
    if (!btn) return;
    setTimeout(function () {
      btn.disabled = true;
      btn.textContent = f.getAttribute('data-busy') || '处理中…';
    }, 0);
  });

  /* 切换静态/动态预览等无需额外脚本 */
})();
