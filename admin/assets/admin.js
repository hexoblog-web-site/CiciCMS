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
            '<label class="cms-check"><input type="checkbox" checked> 下载到本站（原站删图也不会失效）</label>' +
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
    state.lastFocus = document.activeElement;
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
    if (state.lastFocus && state.lastFocus.focus) { state.lastFocus.focus(); }
    state.lastFocus = null;
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
    box.setAttribute('role', 'status');
    box.setAttribute('aria-live', 'polite');
    box.setAttribute('aria-atomic', 'true');

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

  /* 封面预览：<div data-cover-field> 里放地址输入框 + .cover-pick 缩略图 */
  Array.prototype.forEach.call(document.querySelectorAll('[data-cover-field]'), function (box) {
    var input = box.querySelector('input[type="text"]');
    var img = box.querySelector('.cover-pick img');
    var empty = box.querySelector('.cover-empty');
    var clear = box.querySelector('.cover-clear');
    if (!input || !img) return;

    function show(src) {
      img.hidden = false;
      if (img.getAttribute('src') !== src) { img.src = src; }
      if (empty) { empty.hidden = true; empty.textContent = '暂无封面'; }
      box.classList.add('has-img');
    }
    function hide() {
      img.hidden = true;
      img.removeAttribute('src');
      if (empty) { empty.hidden = false; }
      box.classList.remove('has-img');
    }
    function sync() {
      var v = (input.value || '').trim();
      if (v === '') { hide(); } else { show(v); }
    }

    input.addEventListener('input', sync);
    input.addEventListener('change', sync);
    img.addEventListener('error', function () {
      hide();
      if (empty) { empty.hidden = false; empty.textContent = '图片打不开'; }
    });
    if (clear) {
      clear.addEventListener('click', function () {
        input.value = '';
        hide();
        input.focus();
      });
    }
    sync();
  });

  /* 附件：上传到 admin/upload.php（mode=attach），返回的站内地址写回输入框 */
  Array.prototype.forEach.call(document.querySelectorAll('[data-attach-field]'), function (box) {
    var input = box.querySelector('input[type="text"]');
    var files = box.querySelectorAll('input[type="file"]');
    var file = files.length ? files[0] : null;
    var pick = box.querySelector('[data-attach-pick]');
    var clear = box.querySelector('.attach-clear');
    if (!input || !file || !pick) return;

    var formEl = box.closest('form');
    var tokenEl = formEl ? formEl.querySelector('input[name="_token"]') : null;
    var nameEl = document.getElementById('attach_name');
    var sizeEl = document.getElementById('attach_size');
    var showEl = formEl ? formEl.querySelector('input[name="attach_show"]') : null;
    var info = document.querySelector('[data-attach-info]');
    var pickLabel = pick.textContent;

    function sync() {
      box.classList.toggle('has-file', input.value.trim() !== '');
    }
    function setInfo(text) {
      if (info) { info.textContent = text; }
    }

    input.addEventListener('input', sync);
    pick.addEventListener('click', function () { file.click(); });

    file.addEventListener('change', function () {
      if (!file.files || !file.files.length) return;
      var fd = new FormData();
      if (tokenEl) { fd.append('_token', tokenEl.value); }
      fd.append('mode', 'attach');
      fd.append('file', file.files[0]);

      pick.disabled = true;
      pick.textContent = '上传中…';

      fetch('upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.ok) { throw new Error((d && d.msg) || '上传失败'); }
          input.value = d.path;
          if (nameEl && nameEl.value.trim() === '') { nameEl.value = d.name; }
          if (sizeEl) { sizeEl.value = d.bytes; }
          setInfo('已上传：' + d.name + '（' + d.size + '）。勾上「显示下载按钮」才会出现在文章页。');
          sync();
        })
        .catch(function (e) {
          setInfo('上传失败：' + (e && e.message ? e.message : '请重试'));
        })
        .then(function () {
          pick.disabled = false;
          pick.textContent = pickLabel;
          file.value = '';
        });
    });

    if (clear) {
      clear.addEventListener('click', function () {
        input.value = '';
        if (sizeEl) { sizeEl.value = '0'; }
        if (showEl) { showEl.checked = false; }
        setInfo('附件已移除，点「保存并生成」后生效。');
        sync();
        input.focus();
      });
    }

    sync();
  });

  /* ================= 侧栏分组折叠 ================= */

  var NAV_KEY = 'cici-nav-collapsed';

  function navCollapsed() {
    try {
      var raw = localStorage.getItem(NAV_KEY) || '';
      return raw ? raw.split(',') : [];
    } catch (e) { return []; }
  }
  function navStore(list) {
    try { localStorage.setItem(NAV_KEY, list.join(',')); } catch (e) {}
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-nav-toggle]');
    if (!btn || btn.disabled) { return; }
    var group = btn.closest('.nav-group');
    if (!group) { return; }
    var items = group.querySelector('.nav-group-items');
    if (!items) { return; }
    var key = group.getAttribute('data-nav-group');
    var hide = !items.hidden;
    items.hidden = hide;
    btn.setAttribute('aria-expanded', hide ? 'false' : 'true');
    var list = navCollapsed(), at = list.indexOf(key);
    if (hide && at < 0) { list.push(key); }
    if (!hide && at >= 0) { list.splice(at, 1); }
    navStore(list);
  });

  /* ================= 编辑器快捷键 ================= */

  /* Ctrl / ⌘ + S：直接保存并留在编辑页，写长文时不用滚到最下面 */
  document.addEventListener('keydown', function (ev) {
    if (!(ev.ctrlKey || ev.metaKey) || ev.altKey) { return; }
    if ((ev.key || '').toLowerCase() !== 's') { return; }
    if (document.querySelector('.cms-modal:not([hidden])')) { return; }
    var form = document.querySelector('form[data-edit-form]');
    if (!form) { return; }
    var primary = form.querySelector('.edit-action-bar button[type="submit"][value="stay"]');
    if (!primary) { return; }
    ev.preventDefault();
    if (form.requestSubmit) { form.requestSubmit(primary); }
    else { primary.click(); }
  });

  /* ================= 显示设置：主题与密度 ================= */

  var THEME_KEY = 'cici-theme', DENSITY_KEY = 'cici-density';

  function store(key, val) { try { localStorage.setItem(key, val); } catch (e) {} }
  function read(key) { try { return localStorage.getItem(key); } catch (e) { return null; } }

  function syncDisplayButtons() {
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    var compact = document.documentElement.getAttribute('data-density') === 'compact';
    Array.prototype.forEach.call(document.querySelectorAll('[data-theme-toggle]'), function (b) {
      b.setAttribute('aria-label', dark ? '切换到浅色主题' : '切换到深色主题');
      b.setAttribute('title', dark ? '切换到浅色主题' : '切换到深色主题');
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-density-toggle]'), function (b) {
      b.setAttribute('aria-pressed', compact ? 'true' : 'false');
      b.setAttribute('title', compact ? '切回舒适密度' : '切换到紧凑密度');
    });
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-theme-toggle]');
    if (t) {
      var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      store(THEME_KEY, next);
      syncDisplayButtons();
      return;
    }
    var d = ev.target.closest('[data-density-toggle]');
    if (d) {
      var nextD = document.documentElement.getAttribute('data-density') === 'compact' ? 'comfortable' : 'compact';
      document.documentElement.setAttribute('data-density', nextD);
      store(DENSITY_KEY, nextD);
      syncDisplayButtons();
    }
  });

  /* 用户没手动选过主题时，跟随系统切换 */
  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    var onSys = function (e) {
      if (read(THEME_KEY)) { return; }
      document.documentElement.setAttribute('data-theme', e.matches ? 'dark' : 'light');
      syncDisplayButtons();
    };
    if (mq.addEventListener) { mq.addEventListener('change', onSys); }
    else if (mq.addListener) { mq.addListener(onSys); }
  }
  syncDisplayButtons();

  /* ================= 弹窗焦点陷阱（所有 .cms-modal 通用） ================= */

  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]),' +
                  'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"]),' +
                  'iframe, [contenteditable="true"]';

  function visibleModals() {
    return Array.prototype.filter.call(document.querySelectorAll('.cms-modal'), function (m) {
      return !m.hidden && getComputedStyle(m).display !== 'none';
    });
  }

  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Tab') { return; }
    var list = visibleModals();
    if (!list.length) { return; }
    var modal = list[list.length - 1];
    var panel = modal.querySelector('.cms-modal-panel') || modal;
    var nodes = Array.prototype.filter.call(panel.querySelectorAll(FOCUSABLE), function (n) {
      return n.offsetParent !== null || n === panel;
    });
    if (!nodes.length) { ev.preventDefault(); panel.focus(); return; }
    var first = nodes[0], last = nodes[nodes.length - 1];
    var active = document.activeElement;
    if (ev.shiftKey && (active === first || active === panel)) {
      ev.preventDefault(); last.focus();
    } else if (!ev.shiftKey && active === last) {
      ev.preventDefault(); first.focus();
    }
  });

  /* ================= 内容预览弹窗（后台窗口内） ================= */

  var pvModal = null, pvLastFocus = null;

  function closePreview() {
    if (!pvModal) { return; }
    if (pvModal.parentNode) { pvModal.parentNode.removeChild(pvModal); }
    pvModal = null;
    if (!visibleModals().length) { document.body.classList.remove('cms-modal-open'); }
    if (pvLastFocus && pvLastFocus.focus) { pvLastFocus.focus(); }
    pvLastFocus = null;
  }

  function hiddenField(name, value) {
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = name; i.value = value;
    return i;
  }

  function openPreview(btn) {
    var form = btn.closest('form');
    if (!form) { return; }
    if (pvModal) { closePreview(); }

    var type = btn.getAttribute('data-preview') === 'page' ? 'page' : 'article';
    var staticUrl = btn.getAttribute('data-static-url') || '';
    pvLastFocus = btn;

    var wrap = document.createElement('div');
    wrap.className = 'cms-modal preview-modal';
    wrap.innerHTML =
      '<div class="cms-modal-mask" data-pv-close></div>' +
      '<div class="cms-modal-panel" role="dialog" aria-modal="true" aria-labelledby="cms-pv-title" tabindex="-1">' +
        '<div class="cms-modal-head">' +
          '<h3 id="cms-pv-title">内容预览' +
            '<span class="preview-head-note">按编辑器里的当前内容渲染，未保存也能看</span></h3>' +
          '<div class="preview-tools">' +
            (staticUrl ? '<a class="btn btn-sm" target="_blank" rel="noopener" href="' + escAttr(staticUrl) + '">打开静态页 ↗</a>' : '') +
            '<button type="button" class="cms-modal-x" data-pv-close aria-label="关闭预览">&times;</button>' +
          '</div>' +
        '</div>' +
        '<div class="cms-modal-body">' +
          '<iframe class="preview-frame" name="cms-preview-frame" title="内容预览"></iframe>' +
        '</div>' +
      '</div>';
    document.body.appendChild(wrap);
    document.body.classList.add('cms-modal-open');
    pvModal = wrap;

    wrap.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-pv-close]')) { closePreview(); }
    });
    wrap.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { ev.preventDefault(); closePreview(); }
    });
    wrap.querySelector('.cms-modal-panel').focus();

    /* 把编辑表单原样提交到 preview.php，结果落进 iframe。
       这样草稿、未保存的改动都能预览，且不产生任何公开动态地址。 */
    var pf = document.createElement('form');
    pf.method = 'POST';
    pf.action = 'preview.php';
    pf.target = 'cms-preview-frame';
    pf.style.display = 'none';
    var data = new FormData(form);
    data.forEach(function (value, key) {
      if (value instanceof File) { return; }
      pf.appendChild(hiddenField(key, value));
    });
    pf.appendChild(hiddenField('type', type));
    document.body.appendChild(pf);
    pf.submit();
    setTimeout(function () { if (pf.parentNode) { pf.parentNode.removeChild(pf); } }, 0);
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-preview]');
    if (!btn) { return; }
    ev.preventDefault();
    openPreview(btn);
  });

  /* 焦点若落进 iframe，keydown 不会冒泡到弹窗，这里在 document 上兜一层 */
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && pvModal) { ev.preventDefault(); closePreview(); }
  });

  /* 预览弹窗属后台功能，页面离开时不用留着 */
  window.addEventListener('beforeunload', closePreview);
})();
