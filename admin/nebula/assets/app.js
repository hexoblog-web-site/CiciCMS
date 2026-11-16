(function(){
"use strict";

/* ============================================================
 * Nebula ↔ CiciCMS 数据桥
 * 界面代码不关心存储，只调用这里的 load()/save()。
 * ============================================================ */
var NEBULA_API = (function(){
  var m = document.querySelector('meta[name="nebula-api"]');
  return m ? m.getAttribute('content') : 'admin/api.php';
})();
var NEBULA_TOKEN = (function(){
  var m = document.querySelector('meta[name="nebula-token"]');
  return m ? m.getAttribute('content') : '';
})();
var NEBULA_USER = (function(){
  var m = document.querySelector('meta[name="nebula-user"]');
  return m ? m.getAttribute('content') : 'admin';
})();
var _nbLoading = true;
var _nbTimer = null;
var _nbPending = null;

/* 同步拉取：保证后续 5000 行界面代码可以照旧同步访问 db */
function nbFetchState(){
  var xhr = new XMLHttpRequest();
  xhr.open('GET', NEBULA_API + '?r=state', false);
  xhr.setRequestHeader('Accept','application/json');
  xhr.send(null);
  if (xhr.status < 200 || xhr.status >= 300) throw new Error('HTTP ' + xhr.status);
  var j = JSON.parse(xhr.responseText);
  if (!j.ok) throw new Error(j.msg || '读取失败');
  return j;
}

/* 合并写入：多次 save 只发最后一次 */
function nbFlush(){
  if (_nbTimer){ clearTimeout(_nbTimer); _nbTimer = null; }
  if (!_nbPending) return;
  var payload = _nbPending;
  _nbPending = null;
  var body = JSON.stringify({ _token: NEBULA_TOKEN, db: payload });
  try{
    var xhr = new XMLHttpRequest();
    xhr.open('POST', NEBULA_API + '?r=save', true);
    xhr.setRequestHeader('Content-Type','application/json;charset=utf-8');
    xhr.onreadystatechange = function(){
      if (xhr.readyState !== 4) return;
      if (xhr.status < 200 || xhr.status >= 300){
        if (window.console) console.warn('[nebula] 保存失败', xhr.status, xhr.responseText);
      }
    };
    xhr.send(body);
  }catch(e){
    /* 关页时用 beacon 兜底 */
    try{ navigator.sendBeacon(NEBULA_API + '?r=save', new Blob([body],{type:'application/json'})); }catch(_e){}
  }
}
function nbQueue(dbObj){
  if (_nbLoading) return;
  _nbPending = JSON.parse(JSON.stringify(dbObj));
  if (_nbTimer) clearTimeout(_nbTimer);
  _nbTimer = setTimeout(nbFlush, 260);
}

/* ================= 数据层：localStorage ================= */
var KEY = 'nebula-cms-v1';
var PAGE_SIZE = 20; /* 文章列表与媒体列表每页固定 20 条 */
var STATUS = {publish:'已发布', draft:'草稿', pending:'待审核', trash:'回收站'};
var PILL = {publish:'pub', draft:'draft', pending:'pending', trash:'trash'};
var TRASH_DAYS = 30;         /* 回收站保留天数，超期自动清理 */

/* ================= 账号与角色 ================= */
var ROLE_LABEL = {admin:'站点管理员', editor:'内容编辑', author:'专栏作者'};
var ROLE_SUB = {admin:'全部权限', editor:'内容与发布', author:'仅本人草稿'};
var ROLE_COLOR = {admin:'#5b5bd6', editor:'#0e7490', author:'#b45309'};
var USERS_DEFAULT = [
  {id:1,name:'陆知远',user:'admin', pass:'nebula',role:'admin'},
  {id:2,name:'林一',  user:'editor',pass:'editor',role:'editor'},
  {id:3,name:'周游',  user:'author',pass:'author',role:'author'}
];
/* 能力 → 允许的角色。未列出的能力默认三种角色都可用 */
var CAP_ROLES = {
  settings:['admin'], data:['admin'], templates:['admin'], purge:['admin'], deploy:['admin','editor'],
  publish:['admin','editor'], media:['admin','editor'], taxonomy:['admin','editor'],
  trash:['admin','editor']
};
var SESSION = null;   /* {uid}，存在 sessionStorage；未登录时按管理员渲染只读骨架 */
function ensureAccounts(){
  if (!Array.isArray(db.users) || !db.users.length) db.users = USERS_DEFAULT.map(function(u){ return Object.assign({}, u); });
  if (!db.seq) db.seq = {};
  if (typeof db.seq.user !== 'number'){
    var mx = 0;
    db.users.forEach(function(u){ if (u.id > mx) mx = u.id; });
    db.seq.user = mx + 1;
  }
}
function sessionLoad(){
  /* 鉴权在 PHP 会话里完成；这里只把当前登录者映射到原型的 SESSION */
  var me = db.users.filter(function(u){ return u.user === NEBULA_USER; })[0];
  if (!me){ me = db.users[0]; }
  if (me) SESSION = { uid: me.id, at: nowStamp() };
  return SESSION;
}
function sessionSave(){
  try{ sessionStorage.setItem('nebula-cms-session', JSON.stringify(SESSION)); }catch(e){}
}
function sessionClear(){
  SESSION = null;
  try{ sessionStorage.removeItem('nebula-cms-session'); }catch(e){}
}
function currentUser(){
  var u = SESSION ? db.users.filter(function(x){ return x.id === SESSION.uid; })[0] : null;
  return u || db.users[0] || USERS_DEFAULT[0];
}
function userRole(){ return (currentUser() || {}).role || 'admin'; }
function can(cap){
  var allow = CAP_ROLES[cap];
  if (!allow) return true;
  return allow.indexOf(userRole()) > -1;
}
function roleLabel(r){ return ROLE_LABEL[r] || ROLE_LABEL.admin; }
function login(u, pass){
  var hit = db.users.filter(function(x){
    return (x.user === u || x.name === u) && x.pass === pass;
  })[0];
  if (!hit) return false;
  SESSION = {uid:hit.id, at:nowStamp()};
  sessionSave();
  return true;
}

/* ================= 回收站（统一四类） ================= */
function stampMs(s){
  if (!s) return 0;
  var t = Date.parse(String(s).replace(/\//g,'-'));
  return isNaN(t) ? 0 : t;
}
function trashDaysLeft(at){
  var ms = stampMs(at);
  if (!ms) return TRASH_DAYS;
  var used = (Date.now() - ms) / 86400000;
  return Math.max(0, Math.ceil(TRASH_DAYS - used));
}
function ensureTrashMarks(){
  var at = nowStamp();
  (db.posts || []).forEach(function(p){
    if (p.status === 'trash' && !p.trashedAt) p.trashedAt = at;
  });
  (db.pages || []).forEach(function(p){
    if (p.status === 'trash' && !p.trashedAt) p.trashedAt = at;
  });
  (db.media || []).forEach(function(m){
    if (m.trashed && !m.trashedAt) m.trashedAt = at;
  });
  if (!Array.isArray(db.catTrash)) db.catTrash = [];
}
function catSnapshot(name){
  var out = {};
  CAT_META_KEYS.forEach(function(k){
    if (db[k] && db[k][name] != null) out[k] = db[k][name];
  });
  return out;
}
function moveToTrash(kind, obj){
  var at = nowStamp();
  if (kind === 'post'){
    if (obj.status === 'trash') return;
    obj.prevStatus = obj.status; obj.status = 'trash'; obj.trashedAt = at;
  } else if (kind === 'page'){
    if (obj.status === 'trash') return;
    obj.prevStatus = obj.status; obj.status = 'trash'; obj.trashedAt = at;
  } else if (kind === 'media'){
    if (obj.trashed) return;
    obj.trashed = true; obj.trashedAt = at;
  } else if (kind === 'cat'){
    var name = String(obj);
    if (!db.catTrash.some(function(t){ return t.name === name; })){
      db.catTrash.unshift({name:name, at:at, meta:catSnapshot(name)});
    }
  }
}
function trashItems(){
  var out = [];
  (db.posts || []).forEach(function(p){
    if (p.status !== 'trash') return;
    out.push({kind:'post', id:p.id, title:p.title, sub:'栏目 · '+(p.cat||'未分类'), at:p.trashedAt||'', ref:p});
  });
  (db.pages || []).forEach(function(p){
    if (p.status !== 'trash') return;
    out.push({kind:'page', id:p.id, title:p.title, sub:'/ '+pagePath({id:p.id, title:p.title, slug:p.slug, parent:p.parent}) + '.html', at:p.trashedAt||'', ref:p});
  });
  (db.media || []).forEach(function(m){
    if (!m.trashed) return;
    out.push({kind:'media', id:m.id, title:m.file, sub:(m.type === 'image' ? '图片' : '压缩包')+' · '+m.size, at:m.trashedAt||'', ref:m});
  });
  (db.catTrash || []).forEach(function(t){
    out.push({kind:'cat', id:t.name, title:t.name, sub:'栏目 · 删除后文章归入未分类', at:t.at||'', ref:t});
  });
  out.sort(function(a,b){ return stampMs(b.at) - stampMs(a.at); });
  return out;
}
function restoreTrash(kind, id){
  if (kind === 'post'){
    var p = findPost(+id); if (!p) return false;
    p.status = p.prevStatus && p.prevStatus !== 'trash' ? p.prevStatus : 'draft';
    delete p.prevStatus; delete p.trashedAt;
    return true;
  }
  if (kind === 'page'){
    var g = findPage(+id); if (!g) return false;
    g.status = g.prevStatus && g.prevStatus !== 'trash' ? g.prevStatus : 'draft';
    delete g.prevStatus; delete g.trashedAt;
    return true;
  }
  if (kind === 'media'){
    var m = db.media.filter(function(x){ return x.id === +id; })[0]; if (!m) return false;
    delete m.trashed; delete m.trashedAt;
    return true;
  }
  if (kind === 'cat'){
    var t = db.catTrash.filter(function(x){ return x.name === id; })[0]; if (!t) return false;
    if (db.categories.indexOf(t.name) === -1) db.categories.push(t.name);
    Object.keys(t.meta || {}).forEach(function(k){
      var val = t.meta[k];
      if (val && typeof val === 'object') val = val[t.name];
      if (db[k] && val != null) db[k][t.name] = val;
    });
    db.catTrash = db.catTrash.filter(function(x){ return x.name !== id; });
    return true;
  }
  return false;
}
function purgeTrash(kind, id){
  if (kind === 'post'){
    var p = findPost(+id); if (!p) return false;
    db.posts = db.posts.filter(function(x){ return x.id !== p.id; });
    db.revisions = (db.revisions||[]).filter(function(r){ return r.postId !== p.id; });
    return true;
  }
  if (kind === 'page'){
    var g = findPage(+id); if (!g) return false;
    db.pages = db.pages.filter(function(x){ return x.id !== g.id; });
    db.pages.forEach(function(x){ if (x.parent === g.id) x.parent = 0; });
    return true;
  }
  if (kind === 'media'){
    var m = db.media.filter(function(x){ return x.id === +id; })[0]; if (!m) return false;
    db.media = db.media.filter(function(x){ return x.id !== m.id; });
    return true;
  }
  if (kind === 'cat'){
    var t = db.catTrash.filter(function(x){ return x.name === id; })[0]; if (!t) return false;
    db.catTrash = db.catTrash.filter(function(x){ return x.name !== id; });
    dropCatMeta(id);
    return true;
  }
  return false;
}
/* 清理超过保留期的条目，返回清理数量 */
function purgeExpired(){
  var killed = 0;
  trashItems().forEach(function(it){
    if (trashDaysLeft(it.at) > 0) return;
    if (purgeTrash(it.kind, it.id)){
      killed++;
      db.lastPurge = {at:nowStamp(), n:killed};
    }
  });
  return killed;
}

/* ================= 版本历史 ================= */
var AUTOSAVE_T = null;
var DRAFT_PREFIX = 'nebula-cms-draft-';
function draftKey(id){ return DRAFT_PREFIX + id; }
function draftRead(id){
  try{
    var raw = localStorage.getItem(draftKey(id));
    var d = raw ? JSON.parse(raw) : null;
    return d && d.at ? d : null;
  }catch(e){ return null; }
}
function draftWrite(id, data){
  try{
    data.at = nowStamp();
    localStorage.setItem(draftKey(id), JSON.stringify(data));
  }catch(e){}
}
function draftClear(id){
  try{ localStorage.removeItem(draftKey(id)); }catch(e){}
}
function ensureRevisions(){
  if (!Array.isArray(db.revisions)) db.revisions = [];
  if (!db.seq) db.seq = {};
  if (typeof db.seq.rev !== 'number'){
    var mx = 0;
    db.revisions.forEach(function(r){ if (r.id > mx) mx = r.id; });
    db.seq.rev = mx + 1;
  }
}
function postRevisions(pid){
  return db.revisions.filter(function(r){ return r.postId === pid; })
    .sort(function(a,b){ return stampMs(b.at) - stampMs(a.at); });
}
function pushRevision(p){
  if (!p || p.id == null) return;
  var last = postRevisions(p.id)[0];
  if (last && last.md === p.md && last.title === p.title && last.status === p.status) return;
  db.revisions.unshift({
    id: db.seq.rev++, postId: p.id, at: nowStamp(), by: currentUser().name,
    title: p.title, md: p.md, status: p.status, cat: p.cat, tags: (p.tags||[]).slice()
  });
  /* 每篇最多留 5 个历史版本，避免 localStorage 膨胀 */
  var mine = postRevisions(p.id);
  if (mine.length > 5){
    var drop = {};
    mine.slice(5).forEach(function(r){ drop[r.id] = 1; });
    db.revisions = db.revisions.filter(function(r){ return !drop[r.id]; });
  }
}

/* ================= 轻提示 ================= */
function toast(msg, kind){
  var wrap = document.getElementById('toastWrap');
  if (!wrap) return;
  var d = document.createElement('div');
  d.className = 'toast' + (kind ? ' ' + kind : '');
  d.textContent = msg;
  wrap.appendChild(d);
  setTimeout(function(){ if (d.parentNode) d.parentNode.removeChild(d); }, 2400);
}

function seed(){
  return {
    seq:{post:31, media:34, page:7},
    settings:{siteName:'Nebula 博客', slogan:'记录技术与设计', author:'陆知远',
      keywords:'Nebula, CMS, 静态博客, 设计系统, 前端',
      description:'Nebula 博客 —— 记录技术与设计、组件与文档的静态文章管理系统演示站点。',
      logoLight:'', logoDark:'', favicon:''},
    categories:['产品设计','技术分享','效率工具','团队协作','随笔'],
    posts:[
      {id:1,title:'如何设计一套可持续的设计系统',status:'publish',cat:'产品设计',tags:['设计系统','组件库'],date:'2026/11/08',updated:'2026/11/08',
       md:'# 如何设计一套可持续的设计系统\n\n设计系统不是一次性的交付物，而是**持续演化的产品**。本文从令牌、组件、文档三个层面拆解可持续性。\n\n## 三层结构\n\n1. **设计令牌**：颜色、字号、间距的最小单位\n2. **组件库**：由令牌组合而成的可复用单元\n3. **文档站**：让每个使用者都能自助找到答案\n\n> 没有文档的设计系统只是一堆色卡和按钮。\n\n## 演化节奏\n\n每个季度做一次「组件健康度评审」，下线使用率低于 `5%` 的组件，避免系统无限膨胀。'},
      {id:2,title:'WordPress 6.9 更新解读：新增 REST API 与性能优化',status:'publish',cat:'技术分享',tags:['WordPress','CMS'],date:'2026/11/07',updated:'2026/11/07',
       md:'# WordPress 6.9 更新解读\n\n这次更新的两个关键词：**REST API 扩展**与**性能优化**。\n\n## REST API\n\n- 新增 `wp/v2/templates` 端点\n- 支持批量请求合并\n\n## 性能\n\n缓存键策略重构后，大站点后台列表加载平均快了 `18%`。\n\n---\n\n升级前记得备份，尤其是使用了自定义模板的站点。'},
      {id:3,title:'从零搭建个人知识库的实践笔记',status:'publish',cat:'效率工具',tags:['笔记','PKM'],date:'2026/11/05',updated:'2026/11/05',
       md:'# 从零搭建个人知识库\n\n知识库的核心不是工具，而是**流程**。\n\n## 我的流程\n\n- 收集：随手记进收件箱\n- 整理：每周日 30 分钟归档\n- 输出：每月至少一篇长文\n\n> 记不住的东西就写下来，写下来的东西要定期回看。'},
      {id:4,title:'网页性能优化实战：首屏加载时间减半',status:'publish',cat:'技术分享',tags:['性能','Web'],date:'2026/11/03',updated:'2026/11/03',
       md:'# 网页性能优化实战\n\n首屏从 `3.2s` 降到 `1.4s`，我们只做了四件事。\n\n## 清单\n\n1. 图片全部转 WebP + 懒加载\n2. 关键 CSS 内联，其余异步\n3. 字体子集化，只保留常用 350 字\n4. 三方脚本全部 defer\n\n## 效果\n\nLCP 直接腰斩，跳出率下降了 `11%`。'},
      {id:5,title:'产品经理如何与工程师高效协作',status:'publish',cat:'团队协作',tags:['协作'],date:'2026/11/01',updated:'2026/11/01',
       md:'# 产品经理如何与工程师高效协作\n\n核心只有一句话：**把决策依据讲清楚，而不是只给结论**。\n\n## 三个习惯\n\n1. 需求文档写「为什么」而非只写「做什么」\n2. 原型标注交互边界与异常态\n3. 变更走书面确认，避免口头共识\n\n> 协作的成本不在沟通次数，而在信息损耗。'},
      {id:6,title:'静态博客迁移指南：从 WordPress 到 Hugo',status:'publish',cat:'技术分享',tags:['博客','Hugo'],date:'2026/10/28',updated:'2026/10/28',
       md:'# 静态博客迁移指南\n\n从动态 CMS 迁到 Hugo，本质上是从「数据库」走向「文件」。\n\n## 步骤\n\n1. 导出 XML，转成 Markdown\n2. 保留原始 slug，避免外链失效\n3. 图片迁移到 `static/` 并批量压缩\n4. 301 重定向兜底\n\n迁移完成后，整站构建只需要 `400ms`。'},
      {id:7,title:'设计评审的正确打开方式',status:'draft',cat:'产品设计',tags:['评审'],date:'2026/11/08',updated:'2026/11/08',
       md:'# 设计评审的正确打开方式\n\n（草稿）评审的目标是**对齐问题**，而不是审美投票。\n\n## 待补充\n\n- 评审前的准备清单\n- 如何应对「我觉得不好看」类反馈'},
      {id:8,title:'2026 内容行业趋势观察（草稿）',status:'draft',cat:'效率工具',tags:['趋势'],date:'2026/11/02',updated:'2026/11/02',
       md:'# 2026 内容行业趋势观察\n\n（草稿）几个正在发生的信号：\n\n- 静态站 + 动态片段的混合架构成为主流\n- 订阅制内容回潮\n- 搜索流量向 AI 问答分流'},
      {id:9,title:'品牌视觉规范 v2.0（待审）',status:'pending',cat:'产品设计',tags:['品牌'],date:'2026/10/30',updated:'2026/10/30',
       md:'# 品牌视觉规范 v2.0\n\n（待审）本版修订点：主色由靛蓝 `#5b5bd6` 调整为更深的 `#4040ad`，新增深色模式适配规范。'},
      {id:10,title:'一篇被移入回收站的旧文',status:'trash',cat:'随笔',tags:['旧文'],date:'2026/10/15',updated:'2026/10/15',
       md:'# 一篇旧文\n\n（已进入回收站）内容已过时，保留存档。'},
      {id:11,title:'组件库的命名规范：从 Button 到 ButtonGroup',status:'publish',cat:'产品设计',tags:['设计系统','命名'],date:'2026/10/24',updated:'2026/10/24',
       md:'# 组件库的命名规范\n\n命名一旦定下来，改起来的成本远高于设计本身。\n\n## 三条原则\n\n1. 用**结构**而非外观命名：`ButtonGroup` 而不是 `ThreeButtons`\n2. 变体用属性表达，不另起组件名\n3. 废弃组件保留别名，给出迁移期\n\n> 好名字让使用者不看文档也能猜对用法。'},
      {id:12,title:'暗色模式落地的 7 个坑',status:'publish',cat:'产品设计',tags:['暗色模式','配色'],date:'2026/10/22',updated:'2026/10/22',
       md:'# 暗色模式落地的 7 个坑\n\n不是把背景调黑、文字调白就完事了。\n\n- 纯黑 `#000` 会让阴影消失，建议用深灰\n- 品牌色在暗背景上饱和度过高，需要单独降一档\n- 图片与插图要有暗色版本，否则刺眼\n- 状态色必须重新验证对比度\n\n最容易被忽略的是**浮层层级**：暗色下靠亮度区分层级，而不是阴影。'},
      {id:13,title:'用 CSS 变量统一多主题样式',status:'publish',cat:'技术分享',tags:['CSS','前端'],date:'2026/10/20',updated:'2026/10/20',
       md:'# 用 CSS 变量统一多主题样式\n\n把颜色收敛成语义变量，主题切换就只是换一组值。\n\n```\n:root { --surface: #fff; --text: #1a1a1a; }\n[data-theme=dark] { --surface: #16161a; --text: #f2f2f5; }\n```\n\n组件里只写 `var(--surface)`，永远不写死色值。'},
      {id:14,title:'REST API 版本管理实践',status:'publish',cat:'技术分享',tags:['API','后端'],date:'2026/10/18',updated:'2026/10/18',
       md:'# REST API 版本管理实践\n\n推荐**路径版本号 + 长期兼容窗口**：`/api/v2/posts`。\n\n## 废弃流程\n\n1. 新版本上线，旧版本标记 deprecated\n2. 响应头给出 sunset 日期\n3. 保留至少两个发布周期再下线\n\n破坏性变更不要用可选参数硬塞进旧接口，那会让两边都难维护。'},
      {id:15,title:'Notion 与本地 Markdown 的双向同步',status:'publish',cat:'效率工具',tags:['Notion','Markdown'],date:'2026/10/16',updated:'2026/10/16',
       md:'# Notion 与本地 Markdown 的双向同步\n\n本地文件是**唯一真源**，Notion 只作为阅读与分享层。\n\n- 单向导出：本地 → Notion，冲突时以本地为准\n- 反向编辑先落到暂存区，人工确认再合并\n- 图片统一放 `assets/`，两边共用相对路径\n\n这样即便停止订阅，资料仍然完整可用。'},
      {id:16,title:'我的每周复盘模板（可直接复制）',status:'publish',cat:'效率工具',tags:['复盘','模板'],date:'2026/10/14',updated:'2026/10/14',
       md:'# 我的每周复盘模板\n\n固定四问，20 分钟写完：\n\n1. 这周做成了什么（只写可验证的结果）\n2. 哪些事反复卡住\n3. 下周只保留哪三件事\n4. 有什么可以删掉\n\n> 复盘不是写日记，是给下周减负。'},
      {id:17,title:'远程团队的异步沟通守则',status:'publish',cat:'团队协作',tags:['远程','沟通'],date:'2026/10/12',updated:'2026/10/12',
       md:'# 远程团队的异步沟通守则\n\n默认异步，会议是例外。\n\n- 每个请求写清背景、期望结论与截止时间\n- 决策落在文档里，不留在聊天记录里\n- 紧急事项才用即时消息，并补一份书面记录\n\n跨时区团队最大的浪费不是时差，是**等人回一句确认**。'},
      {id:18,title:'技术文档怎么写才有人读',status:'publish',cat:'团队协作',tags:['文档','写作'],date:'2026/10/10',updated:'2026/10/10',
       md:'# 技术文档怎么写才有人读\n\n先给可运行的最小示例，再讲原理。\n\n## 结构建议\n\n- 开头 30 秒能跑通\n- 中间按任务组织，不按模块组织\n- 结尾放常见错误与排查清单\n\n文档的目标不是完整，是让人**少问一个问题**。'},
      {id:19,title:'城市漫步：秋日的旧书店',status:'publish',cat:'随笔',tags:['生活','随想'],date:'2026/10/08',updated:'2026/10/08',
       md:'# 城市漫步：秋日的旧书店\n\n巷子深处那家书店，门口的藤椅还摆在原处。\n\n老板说现在来的人多半不买书，只是想找个安静的地方坐一会儿。这也挺好，书店本来就不只是卖书的地方。'},
      {id:20,title:'从 Figma 到代码的间距校验',status:'publish',cat:'产品设计',tags:['Figma','设计系统'],date:'2026/10/06',updated:'2026/10/06',
       md:'# 从 Figma 到代码的间距校验\n\n间距对不上，多半不是开发写错，而是**令牌没对齐**。\n\n- 设计侧只允许使用 4 的倍数\n- 代码侧用 `space-1 / space-2 / space-4` 之类的语义名\n- 走查时用叠图对比，不靠肉眼估\n\n把校验做在设计阶段，返工会少一大半。'},
      {id:21,title:'设计令牌命名草案 v0.3',status:'draft',cat:'产品设计',tags:['设计系统'],date:'2026/10/04',updated:'2026/10/04',
       md:'# 设计令牌命名草案 v0.3\n\n（草稿）本轮调整：把 `color-primary-*` 改为语义名 `color-action-*`。\n\n## 待确认\n\n- 层级名用 `level` 还是 `elevation`\n- 是否需要单独一组状态令牌'},
      {id:22,title:'2026 前端构建工具横评（草稿）',status:'draft',cat:'技术分享',tags:['构建工具'],date:'2026/10/02',updated:'2026/10/02',
       md:'# 2026 前端构建工具横评\n\n（草稿）计划对比冷启动、增量构建、产物体积三项。\n\n待测清单：Vite、Rspack、Turbopack、Parcel。数据还没跑完，先占位。'},
      {id:23,title:'读书笔记：《卡片笔记写作法》',status:'draft',cat:'随笔',tags:['读书'],date:'2026/09/30',updated:'2026/09/30',
       md:'# 读书笔记：卡片笔记写作法\n\n（草稿）核心不是记笔记，是让笔记之间产生连接。\n\n- 每条卡片只写一个想法\n- 用自己的话重写，不摘抄\n- 定期给卡片之间补链接'},
      {id:24,title:'会议效率清单（待补数据）',status:'draft',cat:'团队协作',tags:['会议'],date:'2026/09/28',updated:'2026/09/28',
       md:'# 会议效率清单\n\n（草稿）待补充近一个月的会议时长统计。\n\n初步结论：没有议程的会平均多花 18 分钟。'},
      {id:25,title:'品牌辅助图形使用规范（待审）',status:'pending',cat:'产品设计',tags:['品牌'],date:'2026/09/26',updated:'2026/09/26',
       md:'# 品牌辅助图形使用规范\n\n（待审）新增三种辅助图形的最小留白与禁用场景说明，等待品牌组确认。'},
      {id:26,title:'API 错误码统一方案（待审）',status:'pending',cat:'技术分享',tags:['API'],date:'2026/09/24',updated:'2026/09/24',
       md:'# API 错误码统一方案\n\n（待审）建议统一为 5 位数字，前两位标识模块，后三位标识具体错误。等待后端评审。'},
      {id:27,title:'外包协作保密条款说明（待审）',status:'pending',cat:'团队协作',tags:['协作'],date:'2026/09/22',updated:'2026/09/22',
       md:'# 外包协作保密条款说明\n\n（待审）补充素材交付与账号回收流程，等待法务确认措辞。'},
      {id:28,title:'2019 年旧版首页改版记录',status:'trash',cat:'随笔',tags:['旧文'],date:'2026/09/20',updated:'2026/09/20',
       md:'# 2019 年旧版首页改版记录\n\n（回收站）截图与方案均已失效，仅作存档。'},
      {id:29,title:'已下线的插件推荐清单',status:'trash',cat:'效率工具',tags:['旧文'],date:'2026/09/18',updated:'2026/09/18',
       md:'# 已下线的插件推荐清单\n\n（回收站）其中三个插件已停止维护，保留原文以免外链失效。'},
      {id:30,title:'废弃的配色方案 A',status:'trash',cat:'产品设计',tags:['旧文'],date:'2026/09/16',updated:'2026/09/16',
       md:'# 废弃的配色方案 A\n\n（回收站）对比度未达标，已被方案 B 替代。'}
    ],
    pages:[
      {id:1,title:'关于我们',slug:'about',template:'page',status:'publish',date:'2026/11/06 10:20:00',updated:'2026/11/06',
       parent:0,inNav:true,navOrder:1,visible:'public',hideTitle:false,wide:false,
       keywords:'关于, 团队, 星云笔记',excerpt:'Nebula 是一个专注写作与设计的内容团队，这里是我们的一点自我介绍。',
       md:'# 关于我们\n\nNebula 起步于 2021 年，最初只是几个朋友共用的写作角落。\n\n## 我们做什么\n\n- 写设计与工程之间的那些事\n- 维护一套开源的设计令牌与组件库\n- 每季度做一次公开的复盘\n\n## 联系我们\n\n合作与投稿请发邮件到 `hi@nebula.example`。'},
      {id:2,title:'联系我们',slug:'contact',template:'contact',status:'publish',date:'2026/11/05 15:42:10',updated:'2026/11/05',
       parent:0,inNav:true,navOrder:2,visible:'public',hideTitle:false,wide:false,
       keywords:'联系, 合作, 投稿',excerpt:'合作、投稿、纠错，都可以从这里找到我们。',
       md:'# 联系我们\n\n- 邮箱：`hi@nebula.example`\n- 微信公众号：Nebula 笔记\n- 地址：杭州市西湖区文三路 90 号\n\n一般会在 **2 个工作日**内回复。'},
      {id:3,title:'隐私政策',slug:'privacy',template:'page',status:'publish',date:'2026/11/03 09:00:00',updated:'2026/11/03',
       parent:0,inNav:false,navOrder:0,visible:'public',hideTitle:false,wide:false,
       keywords:'隐私, 政策, Cookie',excerpt:'本页说明我们收集哪些数据、为什么收集，以及你可以如何拒绝。',
       md:'# 隐私政策\n\n我们只收集两类数据：\n\n1. **访问统计**：匿名的页面浏览计数，不关联个人身份\n2. **订阅邮箱**：仅用于发送更新通知，随时可退订\n\n站内不使用第三方广告脚本，也不做跨站追踪。'},
      {id:4,title:'服务条款',slug:'terms',template:'page',status:'publish',date:'2026/11/02 11:30:00',updated:'2026/11/02',
       parent:0,inNav:false,navOrder:0,visible:'public',hideTitle:false,wide:false,
       keywords:'条款, 版权, 转载',excerpt:'关于内容版权、转载授权与免责说明。',
       md:'# 服务条款\n\n站内文章采用 **CC BY-NC 4.0** 协议共享：\n\n- 允许非商业转载，需注明作者与原文链接\n- 商业用途请先取得书面授权\n- 代码片段可自由使用，无需署名'},
      {id:5,title:'友情链接',slug:'links',template:'full',status:'draft',date:'2026/11/01 16:08:00',updated:'2026/11/01',
       parent:0,inNav:false,navOrder:0,visible:'public',hideTitle:false,wide:true,
       keywords:'友链, 链接',excerpt:'（草稿）整理中，等待对方提供站点简介与图标。',
       md:'# 友情链接\n\n（草稿）\n\n| 站点 | 简介 |\n| --- | --- |\n| 待补充 | 待补充 |'},
      {id:6,title:'团队成员',slug:'team',template:'page',status:'publish',date:'2026/10/30 09:15:00',updated:'2026/10/30',
       parent:1,inNav:false,navOrder:0,visible:'member',hideTitle:false,wide:false,
       keywords:'团队, 成员',excerpt:'关于我们之下的子页面示例：用父级页面把固定内容组织成层级。',
       md:'# 团队成员\n\n固定页面可以挂在父级之下，访问地址会变成 `/about/team.html`。\n\n- **林一** · 设计与前端\n- **周游** · 内容与运营\n- **陈默** · 工程与基础设施\n\n> 这一页设为「仅登录会员可见」，用于演示单页的访问控制。'}
    ],
    catTrash:[
      {name:'旧版专题', at:'2026/11/10 15:30:00', meta:{catSlugs:'legacy-topic', catDescs:'2024 年的专题栏目，内容已合并。'}}
    ],
    media:[
      {id:1,file:'hero-banner-2026.png',type:'image',dim:'1600 × 600',size:'486 KB',post:1,date:'2026/11/08',variant:'mount',c:['#6366f1','#8b8bf5','#c7d2fe']},
      {id:2,file:'design-tokens-sheet.jpg',type:'image',dim:'1280 × 960',size:'342 KB',post:1,date:'2026/11/07',variant:'grid',c:['#0ea5a5','#2dd4bf','#ccfbf1']},
      {id:3,file:'wp-69-release-cover.png',type:'image',dim:'1200 × 630',size:'218 KB',post:2,date:'2026/11/07',variant:'beam',c:['#3b82f6','#60a5fa','#dbeafe']},
      {id:4,file:'pkm-workflow-diagram.png',type:'image',dim:'1400 × 800',size:'195 KB',post:3,date:'2026/11/05',variant:'ring',c:['#d97706','#f59e0b','#fde9c8']},
      {id:5,file:'perf-lighthouse-report.jpg',type:'image',dim:'1100 × 740',size:'156 KB',post:4,date:'2026/11/03',variant:'wave',c:['#10b981','#34d399','#d1fae5']},
      {id:6,file:'team-offsite-photo.jpg',type:'image',dim:'1920 × 1080',size:'1.2 MB',post:5,date:'2026/11/01',variant:'mount',c:['#e0496b','#f87171','#fecdd3']},
      {id:7,file:'hugo-migration-steps.png',type:'image',dim:'1200 × 900',size:'176 KB',post:6,date:'2026/10/28',variant:'beam',c:['#7c3aed','#a78bfa','#e9d5ff']},
      {id:8,file:'screenshot-error.png',type:'image',dim:'900 × 620',size:'88 KB',post:null,date:'2026/11/06',variant:'wave',c:['#64748b','#94a3b8','#e2e8f0']},
      {id:9,file:'trend-report-charts.png',type:'image',dim:'1600 × 1000',size:'240 KB',post:8,date:'2026/11/02',variant:'grid',c:['#0891b2','#22d3ee','#cffafe']},
      {id:10,file:'brand-logo-pack.zip',type:'zip',dim:'12 个文件',size:'8.6 MB',post:9,date:'2026/10/30',c:['#d97706','#f59e0b']},
      {id:11,file:'assets-export-2026-10.zip',type:'zip',dim:'86 个文件',size:'24.3 MB',post:1,date:'2026/11/04',c:['#5b5bd6','#8b8bf5']},
      {id:12,file:'mockup-kit-v2.zip',type:'zip',dim:'34 个文件',size:'15.7 MB',post:7,date:'2026/11/05',c:['#0ea5a5','#2dd4bf']},
      {id:13,file:'keynote-slides.zip',type:'zip',dim:'48 个文件',size:'32.1 MB',post:5,date:'2026/11/01',c:['#e0496b','#f87171']},
      {id:14,file:'perf-audit-raw.zip',type:'zip',dim:'9 个文件',size:'4.9 MB',post:4,date:'2026/11/03',c:['#64748b','#94a3b8']},
      {id:15,file:'design-system-overview.png',type:'image',dim:'1600 × 900',size:'302 KB',post:11,date:'2026/10/26',variant:'grid',c:['#6366f1','#8b8bf5','#c7d2fe']},
      {id:16,file:'component-inventory.jpg',type:'image',dim:'1280 × 720',size:'264 KB',post:1,date:'2026/10/25',variant:'beam',c:['#0ea5a5','#2dd4bf','#ccfbf1']},
      {id:17,file:'rest-api-flowchart.png',type:'image',dim:'1440 × 900',size:'182 KB',post:14,date:'2026/10/24',variant:'ring',c:['#3b82f6','#60a5fa','#dbeafe']},
      {id:18,file:'wp-admin-screenshot.jpg',type:'image',dim:'1920 × 1080',size:'512 KB',post:2,date:'2026/10/23',variant:'wave',c:['#7c3aed','#a78bfa','#e9d5ff']},
      {id:19,file:'knowledge-inbox.png',type:'image',dim:'1200 × 800',size:'148 KB',post:15,date:'2026/10/22',variant:'mount',c:['#d97706','#f59e0b','#fde9c8']},
      {id:20,file:'weekly-review-template.jpg',type:'image',dim:'1024 × 768',size:'96 KB',post:16,date:'2026/10/21',variant:'grid',c:['#10b981','#34d399','#d1fae5']},
      {id:21,file:'lighthouse-before-after.png',type:'image',dim:'1600 × 800',size:'226 KB',post:4,date:'2026/10/20',variant:'beam',c:['#10b981','#34d399','#d1fae5']},
      {id:22,file:'webp-conversion-chart.jpg',type:'image',dim:'1280 × 960',size:'134 KB',post:13,date:'2026/10/19',variant:'ring',c:['#3b82f6','#60a5fa','#dbeafe']},
      {id:23,file:'collaboration-board.png',type:'image',dim:'1400 × 900',size:'208 KB',post:17,date:'2026/10/18',variant:'wave',c:['#e0496b','#f87171','#fecdd3']},
      {id:24,file:'hugo-directory-tree.png',type:'image',dim:'1000 × 700',size:'118 KB',post:6,date:'2026/10/17',variant:'grid',c:['#7c3aed','#a78bfa','#e9d5ff']},
      {id:25,file:'review-checklist.jpg',type:'image',dim:'1200 × 800',size:'172 KB',post:12,date:'2026/10/16',variant:'mount',c:['#d97706','#f59e0b','#fde9c8']},
      {id:26,file:'unattached-cover-draft.png',type:'image',dim:'1600 × 900',size:'288 KB',post:null,date:'2026/10/15',variant:'beam',c:['#6366f1','#8b8bf5','#c7d2fe']},
      {id:27,file:'design-tokens-export.zip',type:'zip',dim:'22 个文件',size:'6.4 MB',post:20,date:'2026/10/14',c:['#5b5bd6','#8b8bf5']},
      {id:28,file:'wp-69-dev-assets.zip',type:'zip',dim:'41 个文件',size:'12.8 MB',post:2,date:'2026/10/13',c:['#3b82f6','#60a5fa']},
      {id:29,file:'pkm-template-bundle.zip',type:'zip',dim:'15 个文件',size:'3.2 MB',post:3,date:'2026/10/12',c:['#d97706','#f59e0b']},
      {id:30,file:'perf-trace-logs.zip',type:'zip',dim:'63 个文件',size:'18.5 MB',post:4,date:'2026/10/11',c:['#64748b','#94a3b8']},
      {id:31,file:'brand-guidelines-v2.zip',type:'zip',dim:'28 个文件',size:'21.7 MB',post:9,date:'2026/10/10',c:['#d97706','#f59e0b']},
      {id:32,file:'archive-2026-q3.zip',type:'zip',dim:'120 个文件',size:'46.2 MB',post:null,date:'2026/10/09',c:['#64748b','#94a3b8']},
      {id:33,file:'old-hero-banner.png',type:'image',dim:'1600 × 600',size:'420 KB',post:null,date:'2026/10/01',
       variant:'mount',c:['#6366f1','#8b8bf5','#c7d2fe'],trashed:true,trashedAt:'2026/11/12 10:20:00',
       alt:'旧版首页横幅（已停用）'}
    ]
  };
}

var db;
/* 演示数据版本升级：把新增的示例条目补进旧的 localStorage 缓存。
   用户自建条目若恰好占用了新示例的 id，会被重新编号，不会被覆盖。 */
var SEED_VER = 5;
function migrate(){
  if (db.seedVer === SEED_VER) return;
  var s = seed();
  db.seq = db.seq || {post:11, media:15};
  [['posts','post'],['media','media'],['pages','page']].forEach(function(pair){
    var key = pair[0], seqKey = pair[1];
    var list = db[key] || (db[key] = []);
    var nameOf = function(x){ return key === 'media' ? (x.file || '') : (x.title || ''); };
    var seedNames = {};
    s[key].forEach(function(x){ seedNames[x.id] = nameOf(x); });
    var nextId = s.seq[seqKey], used = {};
    list.forEach(function(x){
      if (seedNames[x.id] !== undefined && seedNames[x.id] !== nameOf(x)){
        while (used[nextId] || seedNames[nextId] !== undefined) nextId++;
        x.id = nextId++;
      }
      used[x.id] = 1;
    });
    s[key].forEach(function(x){ if (!used[x.id]){ list.push(x); used[x.id] = 1; } });
    var max = 0;
    list.forEach(function(x){ if (x.id > max) max = x.id; });
    db.seq[seqKey] = Math.max(db.seq[seqKey] || 1, max + 1);
  });
  /* 老缓存的示例单页没有 层级/导航/访问控制 字段，按新种子补上（仅补缺失项，不覆盖用户设置） */
  (db.pages || []).forEach(function(p){
    var sp = s.pages.filter(function(x){ return x.id === p.id; })[0];
    if (!sp) return;
    ['parent','inNav','navOrder','visible','hideTitle','wide'].forEach(function(k){
      if (p[k] === undefined) p[k] = sp[k];
    });
    if (p.status === 'trash' && !p.trashedAt) p.trashedAt = sp.trashedAt || nowStamp();
  });
  /* 栏目回收站示例：老缓存首次升级时补一条，方便查看「栏目」类型的恢复流程 */
  if (!Array.isArray(db.catTrash)) db.catTrash = JSON.parse(JSON.stringify(s.catTrash || []));
  db.seedVer = SEED_VER;
  save();
}
/* 标签 ID：首次按名称排序分配，之后持久化且不复用（避免增删改后 ID 漂移） */
function ensureTagIds(){
  if (!db.tagIds) db.tagIds = {};
  var names = {};
  (db.posts || []).forEach(function(p){
    if (p.status === 'trash') return;
    (p.tags || []).forEach(function(t){ names[t] = 1; });
  });
  Object.keys(names).sort().forEach(function(t){
    if (db.tagIds[t] == null){
      var mx = 0;
      Object.keys(db.tagIds).forEach(function(k){ if (db.tagIds[k] > mx) mx = db.tagIds[k]; });
      db.tagIds[t] = mx + 1;
    }
  });
}
/* 栏目 ID：同样持久化、不复用 */
function ensureCatIds(){
  if (!db.catIds) db.catIds = {};
  var mx = 0;
  Object.keys(db.catIds).forEach(function(k){ if (db.catIds[k] > mx) mx = db.catIds[k]; });
  db.categories.forEach(function(c){ if (db.catIds[c] == null) db.catIds[c] = ++mx; });
}
/* 栏目扩展信息：ID / 背景图 / 别名 / 地址 / 关键词 / 简介，均持久化 */
var CAT_META_KEYS = ['catIds','catCovers','catSlugs','catUrls','catKeywords','catDescs'];
function ensureCatMeta(){
  CAT_META_KEYS.forEach(function(k){
    if (!db[k] || typeof db[k] !== 'object') db[k] = {};
  });
}
function moveCatMeta(from, to){
  CAT_META_KEYS.forEach(function(k){
    if (db[k] && db[k][from] != null){ db[k][to] = db[k][from]; delete db[k][from]; }
  });
}
function dropCatMeta(name){
  CAT_META_KEYS.forEach(function(k){ if (db[k]) delete db[k][name]; });
}
var CAT_SLUG_FALLBACK = {
  '产品设计':'design', '技术分享':'tech', '效率工具':'tools',
  '团队协作':'team', '随笔':'notes', '未分类':'uncategorized'
};
var CAT_URL_PREFIX = '/category/';
function cleanSlug(s){
  return String(s || '').trim().toLowerCase().replace(/[^a-z0-9-]+/g,'-')
    .replace(/^-+|-+$/g,'').replace(/-{2,}/g,'-');
}
function catSlug(name){
  var v = db.catSlugs && db.catSlugs[name];
  if (typeof v === 'string' && v) return v;
  return CAT_SLUG_FALLBACK[name] || ('cat-' + catId(name));
}
function catUrl(name){
  var u = db.catUrls && db.catUrls[name];
  if (typeof u === 'string' && u) return u;
  return CAT_URL_PREFIX + catSlug(name) + '.html';
}
function catKeywords(name){ return (db.catKeywords && db.catKeywords[name]) || ''; }
function catDesc(name){ return (db.catDescs && db.catDescs[name]) || ''; }
function setCatSlug(name, raw){
  var s = cleanSlug(raw);
  if (!s){ delete db.catSlugs[name]; return catSlug(name); }
  var taken = db.categories.some(function(c){ return c !== name && catSlug(c) === s; });
  if (taken) s = s + '-' + catId(name);
  db.catSlugs[name] = s;
  return s;
}
var CAT_COVERS = [
  {label:'星云紫', v:'mount', c:['#5b5bd6','#8b8bf5','#e2e4ff']},
  {label:'青蓝波', v:'wave',  c:['#0e7490','#22d3ee','#cffafe']},
  {label:'暖阳橙', v:'beam',  c:['#b45309','#fbbf24','#fef0cf']},
  {label:'薄荷绿', v:'grid',  c:['#047857','#34d399','#d1fae5']},
  {label:'暮色粉', v:'ring',  c:['#be185d','#f472b6','#fce7f3']},
  {label:'深海蓝', v:'grid',  c:['#1d4ed8','#60a5fa','#dbeafe']}
];
function catCoverIdx(name){
  var v = db.catCovers && db.catCovers[name];
  if (typeof v === 'number' && v >= 0 && v < CAT_COVERS.length) return v;
  return (catId(name) - 1) % CAT_COVERS.length;
}
function catCoverSrc(name){
  var v = db.catCovers && db.catCovers[name];
  if (typeof v === 'string' && v) return v;
  var k = CAT_COVERS[catCoverIdx(name)];
  return imgThumb({c:k.c, variant:k.v});
}
/* 添加栏目表单里，尚未落库的栏目按当前选择实时预览背景图 */
function catFormCoverSrc(sel, url){
  if (url) return url;
  var k = CAT_COVERS[sel === 'auto' ? 0 : (+sel || 0)] || CAT_COVERS[0];
  return imgThumb({c:k.c, variant:k.v});
}
/* 文章封面：p.cover 为空=按文章 ID 自动配色；数字=预设索引；字符串=自定义图片 */
function postCoverIdx(p){
  var v = p && p.cover;
  if (typeof v === 'number' && v >= 0 && v < CAT_COVERS.length) return v;
  return (((p && p.id) || 1) - 1) % CAT_COVERS.length;
}
function postCoverSrc(p){
  var v = p && p.cover;
  if (typeof v === 'string' && v) return coverSrc(v);
  var k = CAT_COVERS[postCoverIdx(p)] || CAT_COVERS[0];
  return imgThumb({c:k.c, variant:k.v});
}
/* 站点设置：新增字段的旧缓存补齐，并把设置应用到页面 head 与侧栏 */
var SETTINGS_DEFAULT = {
  siteName:'Nebula 博客', slogan:'', author:'站点管理员',
  keywords:'', description:'', logoLight:'', logoDark:'', favicon:'',
  genMode:'static', lastGenAt:''
};
function ensureSettings(){
  if (!db.settings || typeof db.settings !== 'object') db.settings = {};
  Object.keys(SETTINGS_DEFAULT).forEach(function(k){
    if (typeof db.settings[k] !== 'string') db.settings[k] = SETTINGS_DEFAULT[k];
  });
}
function siteAsset(kind){
  var v = db.settings && db.settings[kind];
  return typeof v === 'string' ? v.trim() : '';
}
function builtinSiteIcon(kind){
  var dark = kind === 'logoDark';
  var c1 = dark ? '#3b3f6b' : '#8b8bf5';
  var c2 = dark ? '#101320' : '#4040ad';
  var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">' +
    '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">' +
    '<stop offset="0" stop-color="'+c1+'"/><stop offset="1" stop-color="'+c2+'"/></linearGradient></defs>' +
    '<rect width="64" height="64" rx="16" fill="url(#g)"/>' +
    '<text x="32" y="44" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" ' +
    'font-size="38" font-weight="700" fill="#ffffff">N</text></svg>';
  return 'data:image/svg+xml,' + encodeURIComponent(svg);
}
function sitePreviewSrc(kind, raw){
  var v = raw == null ? siteAsset(kind) : String(raw).trim();
  return v || builtinSiteIcon(kind);
}
function assetTag(kind){ return siteAsset(kind) ? '自定义图片' : '内置图标（未设置）'; }
function setMetaTag(name, content){
  var el = document.querySelector('meta[name="'+name+'"]');
  if (!el){
    el = document.createElement('meta');
    el.setAttribute('name', name);
    document.head.appendChild(el);
  }
  el.setAttribute('content', content);
}
function applySiteMeta(){
  var s = db.settings || {};
  setMetaTag('keywords', s.keywords || '');
  setMetaTag('description', s.description || '');
  var fav = siteAsset('favicon');
  var link = document.querySelector('link[rel="icon"]');
  if (fav){
    if (!link){
      link = document.createElement('link');
      link.setAttribute('rel', 'icon');
      document.head.appendChild(link);
    }
    link.setAttribute('href', fav);
  } else if (link && link.parentNode){
    link.parentNode.removeChild(link);
  }
  /* 侧栏固定用明亮版 Logo；没配则回退暗夜版，再没有就用内置图标 */
  var logo = siteAsset('logoLight') || siteAsset('logoDark');
  var mark = document.getElementById('brandMark');
  var img = document.getElementById('brandLogo');
  if (mark && img){
    if (logo){
      img.setAttribute('src', logo);
      mark.className = mark.className.replace(/\s*has-logo\b/, '') + ' has-logo';
    } else {
      img.removeAttribute('src');
      mark.className = mark.className.replace(/\s*has-logo\b/, '');
    }
  }
}
/* 数据管理：备份记录、FTP 空间信息（旧缓存同样补齐） */
var FTP_DEFAULT = {
  host:'ftp.nebula-host.com', port:'21', user:'nebula_ftp', pass:'Nb2026ftp',
  root:'/www/wwwroot/nebula',
  status:'ok', quotaMB:2048, usedMB:128
};
var BK_PREFIX = 'nebula-cms-bk-';
function ensureDataTools(){
  if (!Array.isArray(db.backups)) db.backups = [];
  if (!db.ftp || typeof db.ftp !== 'object') db.ftp = {};
  Object.keys(FTP_DEFAULT).forEach(function(k){
    if (db.ftp[k] == null) db.ftp[k] = FTP_DEFAULT[k];
  });
  if (typeof db.touchedAt !== 'string') db.touchedAt = '';
}

/* 模板管理：内置模板文件（旧缓存同样补齐） */
var TPL_GROUPS = {layouts:'布局模板', partials:'公共片段', pages:'独立页面'};
var TPL_DEFAULT = [
  {path:'layouts/base.html', content:[
    '<!DOCTYPE html>',
    '<html lang="{{ .Site.Language }}">',
    '<head>',
    '  <meta charset="utf-8">',
    '  <meta name="viewport" content="width=device-width, initial-scale=1">',
    '  <title>{{ .Title }} · {{ .Site.Title }}</title>',
    '  <meta name="description" content="{{ .Site.Description }}">',
    '  <link rel="icon" href="{{ .Site.Params.favicon }}">',
    '  <link rel="stylesheet" href="/assets/theme.css">',
    '</head>',
    '<body>',
    '  {{ partial "header.html" . }}',
    '  <main class="wrap">',
    '    {{ block "main" . }}{{ end }}',
    '  </main>',
    '  {{ partial "footer.html" . }}',
    '</body>',
    '</html>'
  ].join('\n')},
  {path:'layouts/post.html', content:[
    '{{ define "main" }}',
    '<article class="post">',
    '  <h1>{{ .Title }}</h1>',
    '  <div class="post-meta">',
    '    <span>{{ .Site.Author }}</span>',
    '    <span>{{ .Date.Format "2006/01/02 15:04:05" }}</span>',
    '    <span>{{ .Params.category }}</span>',
    '  </div>',
    '  {{ with .Params.cover }}<img class="cover" src="{{ . }}" alt="{{ $.Title }}">{{ end }}',
    '  {{ with .Params.excerpt }}<p class="lede">{{ . }}</p>{{ end }}',
    '  {{ .Content }}',
    '</article>',
    '{{ end }}'
  ].join('\n')},
  {path:'layouts/list.html', content:[
    '{{ define "main" }}',
    '<section class="list">',
    '  <h1>{{ .Title }}</h1>',
    '  {{ range .Pages }}',
    '    <article class="item">',
    '      <h2><a href="{{ .Permalink }}">{{ .Title }}</a></h2>',
    '      <p class="excerpt">{{ .Params.excerpt }}</p>',
    '      <time>{{ .Date.Format "2006/01/02" }}</time>',
    '    </article>',
    '  {{ end }}',
    '</section>',
    '{{ end }}'
  ].join('\n')},
  {path:'partials/header.html', content:[
    '<header class="site-head">',
    '  <a class="brand" href="/">',
    '    {{ with .Site.Params.logo }}<img src="{{ . }}" alt="{{ $.Site.Title }}">{{ end }}',
    '    <span>{{ .Site.Title }}</span>',
    '  </a>',
    '  <nav>',
    '    {{ range .Site.Menus.main }}',
    '      <a href="{{ .URL }}">{{ .Name }}</a>',
    '    {{ end }}',
    '  </nav>',
    '</header>'
  ].join('\n')},
  {path:'partials/footer.html', content:[
    '<footer class="site-foot">',
    '  <p>© {{ now.Format "2006" }} {{ .Site.Title }} · {{ .Site.Params.slogan }}</p>',
    '  <p class="powered">Powered by CiciCms</p>',
    '</footer>'
  ].join('\n')},
  {path:'partials/sidebar.html', content:[
    '<aside class="side">',
    '  <h3>最新文章</h3>',
    '  <ul>',
    '    {{ range first 5 (where .Site.RegularPages "Section" "posts") }}',
    '      <li><a href="{{ .Permalink }}">{{ .Title }}</a></li>',
    '    {{ end }}',
    '  </ul>',
    '</aside>'
  ].join('\n')},
  {path:'pages/about.html', content:[
    '{{ define "main" }}',
    '<article class="page">',
    '  <h1>关于本站</h1>',
    '  <p>{{ .Site.Params.description }}</p>',
    '</article>',
    '{{ end }}'
  ].join('\n')}
];
function tplGroup(path){ return String(path).split('/')[0]; }
function tplFileName(path){ var a = String(path).split('/'); return a[a.length-1]; }
function tplStamp(v){ return v ? '更新于 ' + v : '未修改'; }
function ensureTemplates(){
  if (!db.templates || typeof db.templates !== 'object') db.templates = {};
  var t = db.templates;
  if (!Array.isArray(t.files) || !t.files.length){
    t.files = TPL_DEFAULT.map(function(f){ return {path:f.path, content:f.content, updated:''}; });
  } else {
    TPL_DEFAULT.forEach(function(d){
      var has = t.files.some(function(x){ return x && x.path === d.path; });
      if (!has) t.files.push({path:d.path, content:d.content, updated:''});
    });
  }
  var valid = t.files.some(function(f){ return f.path === t.active; });
  if (typeof t.active !== 'string' || !valid) t.active = t.files[0].path;
}
/* 单页：独立于文章流的固定页面（关于 / 联系 / 条款…）
   与文章的分工：文章进信息流、靠分类标签归档、有时效性；单页是站点骨架，
   靠层级 + 导航位置进入站点结构，没有分类/标签，多了父级、访问权限与布局开关。 */
var PAGE_TPLS = [
  {v:'page',    label:'默认 page.html'},
  {v:'full',    label:'全宽 full.html'},
  {v:'sidebar', label:'侧栏 sidebar.html'},
  {v:'contact', label:'联系表单 contact.html'}
];
var PAGE_VISIBLE = [
  {v:'public', label:'公开（所有人可访问）'},
  {v:'member', label:'仅登录会员可见'}
];
function ensurePages(){
  if (!Array.isArray(db.pages)) db.pages = [];
  db.pages.forEach(function(p){
    if (p.status !== 'publish' && p.status !== 'draft') p.status = 'draft';
    if (typeof p.slug !== 'string' || !p.slug) p.slug = 'page-' + p.id;
    if (typeof p.template !== 'string' || !p.template) p.template = 'page';
    if (typeof p.md !== 'string') p.md = '';
    if (typeof p.keywords !== 'string') p.keywords = '';
    if (typeof p.excerpt !== 'string') p.excerpt = '';
    if (typeof p.cover !== 'string' && typeof p.cover !== 'number') p.cover = '';
    if (typeof p.attach !== 'string') p.attach = '';
    if (!p.date) p.date = nowStamp();
    /* 单页专属字段 */
    if (typeof p.parent !== 'number') p.parent = 0;
    if (typeof p.inNav !== 'boolean') p.inNav = false;
    if (typeof p.navOrder !== 'number') p.navOrder = 0;
    if (p.visible !== 'public' && p.visible !== 'member') p.visible = 'public';
    if (typeof p.hideTitle !== 'boolean') p.hideTitle = false;
    if (typeof p.wide !== 'boolean') p.wide = false;
  });
  /* 父级指向已删除的页面时，回退为顶级，避免出现孤儿 */
  db.pages.forEach(function(p){
    if (p.parent && !db.pages.some(function(x){ return x.id === p.parent; })) p.parent = 0;
  });
  if (!db.seq || typeof db.seq !== 'object') db.seq = {post:1, media:1};
  var mx = 0;
  db.pages.forEach(function(p){ if (p.id > mx) mx = p.id; });
  if (typeof db.seq.page !== 'number' || db.seq.page <= mx) db.seq.page = mx + 1;
}
function findPage(id){ return db.pages.filter(function(p){ return p.id === id; })[0]; }
function pageTplLabel(v){
  var t = PAGE_TPLS.filter(function(x){ return x.v === v; })[0];
  return t ? t.label : PAGE_TPLS[0].label;
}
function pageVisibleLabel(v){
  var t = PAGE_VISIBLE.filter(function(x){ return x.v === v; })[0];
  return t ? t.label : PAGE_VISIBLE[0].label;
}
/* 单页的完整访问路径：把父级 slug 逐级拼到前面，遇到环则中断 */
function pagePath(p){
  if (!p) return '/';
  var parts = [p.slug], cur = p, seen = {}, guard = 0;
  seen[p.id] = 1;
  while (cur.parent && guard++ < 8){
    var par = findPage(cur.parent);
    if (!par || seen[par.id]) break;
    seen[par.id] = 1;
    parts.unshift(par.slug);
    cur = par;
  }
  return '/' + parts.join('/') + '.html';
}
/* 某页面的全部子孙 id：设置父级时用来排除自己与后代，防止层级成环 */
function pageDescendants(id){
  var out = [], stack = [id];
  while (stack.length){
    var cur = stack.pop();
    db.pages.forEach(function(p){
      if (p.parent === cur && out.indexOf(p.id) === -1){ out.push(p.id); stack.push(p.id); }
    });
  }
  return out;
}
function pageDepth(p){
  var d = 0, cur = p, seen = {}, guard = 0;
  while (cur.parent && guard++ < 8){
    var par = findPage(cur.parent);
    if (!par || seen[par.id]) break;
    seen[par.id] = 1; d++; cur = par;
  }
  return d;
}
/* 编辑区里实时预览访问地址：父级 slug 逐级拼在别名前面 */
function pathPreview(parentId, slug){
  var parts = [slug || 'page-?'], cur = findPage(+parentId || 0), seen = {}, guard = 0;
  while (cur && guard++ < 8){
    if (seen[cur.id]) break;
    seen[cur.id] = 1;
    parts.unshift(cur.slug);
    cur = findPage(cur.parent);
  }
  return '/' + parts.join('/') + '.html';
}
/* 父级下拉：排除自己与自己的后代，按访问路径排序并缩进显示层级 */
function pageParentOpts(selfId, parentId){
  var skip = selfId ? pageDescendants(selfId).concat([selfId]) : [];
  var list = db.pages.filter(function(x){ return skip.indexOf(x.id) === -1; }).slice()
    .sort(function(a,b){ var pa = pagePath(a), pb = pagePath(b); return pa < pb ? -1 : pa > pb ? 1 : 0; });
  var html = '<option value="0"'+(parentId ? '' : ' selected')+'>— 顶级页面 —</option>';
  html += list.map(function(x){
    var pad = new Array(pageDepth(x) + 1).join('— ');
    return '<option value="'+x.id+'"'+(parentId === x.id ? ' selected' : '')+'>' +
      (pad ? pad + ' ' : '') + esc(x.title) + '（' + esc(x.slug) + '）</option>';
  }).join('');
  return html;
}
/* 别名去重：与其它单页冲突时补 -2、-3… */
function pageSlugTaken(slug, selfId){
  return db.pages.some(function(p){ return p.id !== selfId && p.slug === slug; });
}
/* 单页别名：允许中文，空格转连字符，去掉路径符号 */
function cleanPageSlug(raw){
  return String(raw || '').trim().toLowerCase()
    .replace(/^\/+|\/+$/g,'')
    .replace(/\s+/g,'-')
    .replace(/[^\w\u4e00-\u9fa5-]+/g,'-')
    .replace(/-{2,}/g,'-')
    .replace(/^-+|-+$/g,'');
}
function uniquePageSlug(raw, selfId){
  var s = cleanPageSlug(raw);
  if (!s) return '';
  if (!pageSlugTaken(s, selfId)) return s;
  var i = 2;
  while (pageSlugTaken(s + '-' + i, selfId)) i++;
  return s + '-' + i;
}
function load(){
  var boot = document.getElementById('nebula-boot-msg');
  try{
    var res = nbFetchState();
    db = res.db;
    if (boot) boot.textContent = '正在初始化界面…';
  }catch(e){
    if (boot) boot.textContent = '载入失败：' + e.message + '（请检查登录状态）';
    throw e;
  }
  migrate();
  ensurePages();
  ensureTagIds();
  ensureCatIds();
  ensureCatMeta();
  ensureSettings();
  ensureDataTools();
  ensureTemplates();
  ensureAccounts();
  ensureTrashMarks();
  ensureRevisions();
  if (!db.touchedAt) db.touchedAt = nowStamp();
  purgeExpired();          /* 超过 30 天的回收站条目在加载时自动清理 */
  save(true);
}
/* keepStamp=true 时不动「最后写入」（仅读入数据时用），避免刷新页面就刷新时间 */
function save(keepStamp){
  if (!keepStamp) db.touchedAt = nowStamp();
  nbQueue(db);
}
load();
_nbLoading=false;
applySiteMeta();

/* ================= 工具 ================= */
function esc(s){
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function pad2(n){ return (n<10?'0':'')+n; }
function today(){
  var d = new Date();
  return d.getFullYear()+'/'+pad2(d.getMonth()+1)+'/'+pad2(d.getDate());
}
/* 年月日时分秒，如 2026/11/16 08:18:25 */
function nowStamp(){
  var d = new Date();
  return today()+' '+pad2(d.getHours())+':'+pad2(d.getMinutes())+':'+pad2(d.getSeconds());
}
function dateOnly(s){ return String(s||'').split(' ')[0]; }
function dateTime(s){ return String(s||'').split(' ')[1] || ''; }
function findPost(id){ return db.posts.filter(function(p){ return p.id === id; })[0]; }
/* 发布时间在库里是 2026/11/16 08:18:25 展示格式，datetime-local 控件只认 2026-11-16T08:18:25，进出各转一次 */
function dateToInput(d){
  var s = String(d||'').trim();
  if (!s) return '';
  s = s.replace(/\//g,'-').replace(' ','T');
  if (!/T\d{2}:\d{2}/.test(s)) return s + 'T00:00:00';
  if (/T\d{2}:\d{2}$/.test(s)) s += ':00';
  return s;
}
function dateFromInput(v){
  var s = String(v||'').trim();
  if (!s) return '';
  s = s.replace('T',' ').replace(/-/g,'/');
  if (/ \d{2}:\d{2}$/.test(s)) s += ':00';
  return s;
}
/* 列表里的日期单元格：上面日期，下面时分秒（旧数据没有时间就只显示日期） */
function dateCell(s){
  var t = dateTime(s);
  return esc(dateOnly(s)) + (t ? '<small>'+esc(t)+'</small>' : '');
}

/* 迷你 Markdown 渲染器 */
function inline(s){
  return s
    .replace(/!\[([^\]]*)\]\(([^)]+)\)/g,'<img alt="$1" src="$2">')
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g,'<a href="$2">$1</a>')
    .replace(/~~([^~]+)~~/g,'<del>$1</del>')
    .replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>')
    .replace(/\*([^*]+)\*/g,'<em>$1</em>')
    .replace(/`([^`]+)`/g,'<code>$1</code>');
}
function mdRow(L){
  return L.trim().replace(/^\|/,'').replace(/\|$/,'').split('|').map(function(c){ return c.trim(); });
}
function mdTable(head, rows){
  var h = '<thead><tr>' + head.map(function(c){ return '<th>'+inline(c)+'</th>'; }).join('') + '</tr></thead>';
  var b = rows.length ? '<tbody>' + rows.map(function(r){
    return '<tr>' + head.map(function(_, i2){ return '<td>'+inline(r[i2] || '')+'</td>'; }).join('') + '</tr>';
  }).join('') + '</tbody>' : '';
  return '<table>'+h+b+'</table>';
}
function md(src){
  var lines = esc(src).split('\n');
  var out = [], i = 0, inCode = false, codeBuf = [], codeLang = '', listType = null;
  function closeList(){
    if (listType){ out.push('</' + (listType === 'task' ? 'ul' : listType) + '>'); listType = null; }
  }
  for (; i < lines.length; i++){
    var L = lines[i];
    if (/^```/.test(L)){
      if (inCode){
        out.push('<pre><code'+(codeLang ? ' class="lang-'+codeLang+'"' : '')+'>'+codeBuf.join('\n')+'</code></pre>');
        codeBuf = []; inCode = false; codeLang = '';
      } else {
        closeList(); inCode = true;
        codeLang = L.replace(/^```/,'').trim().split(/\s+/)[0].replace(/[^\w+#.-]/g,'');
      }
      continue;
    }
    if (inCode){ codeBuf.push(L); continue; }
    var m;
    if ((m = L.match(/^(#{1,6})\s+(.*)$/))){
      closeList();
      var lv = m[1].length;
      out.push('<h'+lv+'>'+inline(m[2])+'</h'+lv+'>');
    } else if ((m = L.match(/^\s*[-*]\s+\[([ xX])\]\s+(.*)$/))){
      if (listType !== 'task'){ closeList(); out.push('<ul class="md-task">'); listType='task'; }
      out.push('<li><input type="checkbox" disabled'+(m[1].toLowerCase() === 'x' ? ' checked' : '')+'>'+
        '<span>'+inline(m[2])+'</span></li>');
    } else if (/^\s*[-*]\s+/.test(L)){
      if (listType !== 'ul'){ closeList(); out.push('<ul>'); listType='ul'; }
      out.push('<li>'+inline(L.replace(/^\s*[-*]\s+/,''))+'</li>');
    } else if (/^\s*\d+\.\s+/.test(L)){
      if (listType !== 'ol'){ closeList(); out.push('<ol>'); listType='ol'; }
      out.push('<li>'+inline(L.replace(/^\s*\d+\.\s+/,''))+'</li>');
    } else if (/^&gt;\s?/.test(L)){          /* esc() 已把 > 转义成 &gt;，这里按转义后匹配 */
      closeList();
      out.push('<blockquote>'+inline(L.replace(/^&gt;\s?/,''))+'</blockquote>');
    } else if (/^\s*(---+|\*\*\*+)\s*$/.test(L)){
      closeList(); out.push('<hr>');
    } else if (/^\s*\|/.test(L) && /^\s*\|?[\s:|-]*-[\s:|-]*\|?\s*$/.test(lines[i+1] || '')){
      closeList();
      var head = mdRow(L), rows = [];
      i++;                                   /* 跳过 |---| 分隔行 */
      while (i + 1 < lines.length && /^\s*\|/.test(lines[i+1])){ i++; rows.push(mdRow(lines[i])); }
      out.push(mdTable(head, rows));
    } else if (L.trim() === ''){
      closeList();
    } else {
      closeList();
      out.push('<p>'+inline(L)+'</p>');
    }
  }
  closeList();
  if (inCode) out.push('<pre><code'+(codeLang ? ' class="lang-'+codeLang+'"' : '')+'>'+codeBuf.join('\n')+'</code></pre>');
  return out.join('\n');
}

/* 媒体缩略图 */
function svgURI(svg){ return 'data:image/svg+xml,' + encodeURIComponent(svg); }
function imgThumb(m){
  var c = m.c, v = m.variant, W = 160, H = 120, shapes = '';
  if (v === 'mount'){
    shapes = '<circle cx="118" cy="34" r="16" fill="'+c[2]+'"/>'+
      '<path d="M0 92 L44 46 L76 84 L104 58 L160 96 L160 120 L0 120 Z" fill="'+c[0]+'" opacity=".85"/>'+
      '<path d="M0 106 L38 72 L82 108 L118 80 L160 108 L160 120 L0 120 Z" fill="'+c[1]+'" opacity=".9"/>';
  } else if (v === 'wave'){
    shapes = '<path d="M0 74 C30 52 52 92 84 70 C112 52 136 84 160 64 L160 120 L0 120 Z" fill="'+c[0]+'" opacity=".8"/>'+
      '<path d="M0 96 C34 78 60 112 96 92 C124 76 144 100 160 88 L160 120 L0 120 Z" fill="'+c[1]+'" opacity=".85"/>';
  } else if (v === 'grid'){
    for (var x=12; x<150; x+=26) for (var y=12; y<110; y+=26)
      shapes += '<rect x="'+x+'" y="'+y+'" width="16" height="16" rx="4" fill="'+c[1]+'" opacity="'+(0.25+((x+y)%90)/120).toFixed(2)+'"/>';
  } else if (v === 'ring'){
    shapes = '<circle cx="80" cy="60" r="34" fill="none" stroke="'+c[1]+'" stroke-width="14" opacity=".85"/>'+
      '<circle cx="80" cy="60" r="34" fill="none" stroke="'+c[0]+'" stroke-width="14" stroke-dasharray="64 150" stroke-linecap="round" transform="rotate(-90 80 60)"/>';
  } else {
    shapes = '<rect x="18" y="58" width="88" height="10" rx="5" fill="'+c[1]+'" opacity=".9"/>'+
      '<rect x="18" y="78" width="60" height="10" rx="5" fill="'+c[1]+'" opacity=".55"/>'+
      '<circle cx="122" cy="46" r="22" fill="'+c[0]+'" opacity=".8"/>';
  }
  return svgURI('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '+W+' '+H+'">'+
    '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="'+c[2]+'"/><stop offset="1" stop-color="'+c[1]+'"/></linearGradient></defs>'+
    '<rect width="'+W+'" height="'+H+'" fill="url(#g)"/>'+shapes+'</svg>');
}
function zipThumb(m){
  var c = m.c;
  return svgURI('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 120">'+
    '<defs><linearGradient id="z" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="'+c[1]+'"/><stop offset="1" stop-color="'+c[0]+'"/></linearGradient></defs>'+
    '<rect width="160" height="120" fill="url(#z)"/>'+
    '<rect x="46" y="26" width="68" height="76" rx="10" fill="#fff" opacity=".92"/>'+
    '<path d="M80 26 v10 m0 6 v10 m0 6 v10 m0 6 v10" stroke="'+c[0]+'" stroke-width="5" stroke-dasharray="8 6" stroke-linecap="round"/>'+
    '<rect x="60" y="94" width="40" height="8" rx="4" fill="'+c[0]+'"/>'+
    '<text x="80" y="70" font-family="Arial,sans-serif" font-size="17" font-weight="bold" fill="'+c[0]+'" text-anchor="middle">ZIP</text></svg>');
}

/* ================= 路由 ================= */
var view = document.getElementById('view');
var PSTATE = {status:'all', q:'', page:1, cat:'all', time:'all', sel:{}};
var PREV_HASH = '#/posts';

function nav(active){
  document.querySelectorAll('.nav-item').forEach(function(el){
    el.classList.toggle('is-active', el.dataset.view === active);
  });
}
document.getElementById('nav').addEventListener('click', function(e){
  var item = e.target.closest('.nav-item');
  if (item) location.hash = item.dataset.hash || ('#/' + item.dataset.view);
});

function route(){
  /* 离开一键更新页时中断未完成的构建动画 */
  if (typeof genTimer !== 'undefined' && genTimer){ clearInterval(genTimer); genTimer = null; }
  /* 离开编辑器时停止自动暂存定时器，避免在别的页面继续写草稿 */
  if (typeof AUTOSAVE_T !== 'undefined' && AUTOSAVE_T){ clearInterval(AUTOSAVE_T); AUTOSAVE_T = null; }
  var h = location.hash.replace(/^#\//, '') || 'dashboard';
  var parts = h.split('/');
  var name = parts[0], arg = parts[1];
  if (name === 'edit'){ nav(arg === 'new' ? 'new-post' : 'posts'); return renderEditor(arg); }
  if (name === 'page'){
    if (arg === 'new'){ nav('page-new'); return renderPageEditor('new'); }
    nav('page-edit'); return renderPageEditor(arg);
  }
  if (name === 'page-view'){ nav('page-edit'); return renderPageView(+arg); }
  if (name === 'article'){
    nav(PREV_HASH === '#/taxonomy/tags' ? 'tags' : 'posts');
    return renderArticle(+arg, PREV_HASH);
  }
  var fn = {dashboard:vDashboard, posts:vPosts, drafts:vDrafts, pages:vPages, media:vMedia, taxonomy:vTaxonomy, settings:vSettings,
    deploy:vDeploy, data:vData, trash:vTrash, templates:vTemplates}[name];
  /* 角色权限：受限页面直接拦回仪表盘 */
  var VIEW_CAP = {settings:'settings', data:'data', templates:'templates', deploy:'deploy', trash:'trash', media:'media'};
  if (fn && VIEW_CAP[name] && !can(VIEW_CAP[name])){
    toast('当前角色无权访问该页面', 'bad');
    nav('dashboard'); vDashboard(); return;
  }
  var taxActive = {add:'cat-add', edit:'cat-edit', tags:'tags'};
  var navAlias = {pages:'page-edit'};
  nav(fn ? (name === 'taxonomy' ? (taxActive[arg] || 'cat-edit')
    : (navAlias[name] || name)) : 'dashboard');
  if (fn) fn(name === 'taxonomy' ? (arg || 'edit') : arg);
  else vDashboard();
  PREV_HASH = location.hash || '#/dashboard';
}
window.addEventListener('hashchange', route);

/* ================= 视图：仪表盘 ================= */
function vDashboard(){
  var pub = cnt('publish'), draft = cnt('draft');
  var pending = db.posts.filter(function(p){return p.status==='pending'}).length;
  var imgs = db.media.filter(function(m){return m.type==='image'}).length;
  var zips = db.media.filter(function(m){return m.type==='zip'}).length;
  var unlinked = db.media.filter(function(m){return m.post==null}).length;
  var pgPub = livePages().filter(function(p){ return p.status === 'publish'; }).length;
  var pgDraft = livePages().length - pgPub;
  var navN = db.pages.filter(function(p){ return p.inNav; }).length;
  var tagSet = {};
  db.posts.forEach(function(p){ (p.tags||[]).forEach(function(t){ tagSet[t]=1; }); });
  var tagCount = Object.keys(tagSet).length;
  var sizeKB = (JSON.stringify(db).length/1024).toFixed(1);
  /* 回收站待清理：这里的内容到期会自动永久删除，是唯一不可逆的一项 */
  var trashAll = trashItems();
  var minLeft = 0;
  trashAll.forEach(function(t){
    var d = trashDaysLeft(t.at);
    if (!minLeft || d < minLeft) minLeft = d;
  });
  var trashSub = trashAll.length
    ? ('最早 '+minLeft+' 天后永久清除 · 保留 '+TRASH_DAYS+' 天')
    : '暂无待清理内容';
  /* 最近文章：按发布日期倒序取最新 10 篇，同日按 ID 倒序 */
  var recent = db.posts.slice().sort(function(a,b){
    var da = a.date || '', dbb = b.date || '';
    return da === dbb ? (b.id - a.id) : (da < dbb ? 1 : -1);
  }).slice(0,10);

  /* 待办事项 */
  var todos = [
    {cls:'warn', label:'草稿待发布', sub:'去草稿管理继续编辑', n:draft, hash:'#/drafts',
     svg:'<path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/>'},
    {cls:'info', label:'待审核文章', sub:'确认后即可发布', n:pending, hash:'#/drafts',
     svg:'<circle cx="12" cy="12" r="9"/><path d="M12 8v4l2.5 2.5"/>'},
    {cls:'ok', label:'未关联附件', sub:'媒体库中未挂到文章的文件', n:unlinked, hash:'#/media',
     svg:'<path d="M21.4 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.2-9.19a4 4 0 015.65 5.66l-8.48 8.48a2 2 0 01-2.83-2.83l7.78-7.78"/>'},
    {cls:'purge', label:'回收站待清理', sub:trashSub, n:trashAll.length, hash:'#/trash',
     svg:'<path d="M3 6h18"/><path d="M9 6V4h6v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/>'}
  ];
  var todoHtml = todos.map(function(t){
    return '<a class="stat todo-stat '+t.cls+'" href="'+t.hash+'">' +
      '<div class="ts-top"><div class="lb">'+t.label+'</div>' +
      '<span class="ts-ic"><svg viewBox="0 0 24 24">'+t.svg+'</svg></span></div>' +
      '<div class="v">'+t.n+(t.unit ? '<span style="font-size:14px;color:var(--muted)">'+t.unit+'</span>' : '')+'</div>' +
      '<div class="s">'+t.sub+' →</div></a>';
  }).join('');

  var hour = new Date().getHours();
  var greet = hour<6 ? '夜深了' : hour<12 ? '上午好' : hour<18 ? '下午好' : '晚上好';

  view.innerHTML =
    '<div class="page-head reveal"><div><h1>'+greet+'，'+esc(currentUser().name)+'</h1>' +
      '<p>'+esc(db.settings.siteName)+' · 今天是 '+new Date().toLocaleDateString('zh-CN')+' · 数据保存在浏览器本地</p></div>' +
      '<div class="head-actions">' +
        '<button class="btn" onclick="location.hash=\'#/deploy\'">一键更新</button>' +
        '<button class="btn primary" onclick="location.hash=\'#/edit/new\'">+ 撰写文章</button>' +
      '</div></div>' +
    '<div class="stats reveal" style="animation-delay:.04s">' +
      '<div class="stat"><div class="lb">文章总数</div><div class="v">'+livePosts().length+'</div><div class="s">已发布 '+pub+' · 草稿 '+draft+' · 待审 '+pending+'</div></div>' +
      '<div class="stat"><div class="lb">单页</div><div class="v">'+livePages().length+'</div><div class="s">已发布 '+pgPub+' · 草稿 '+pgDraft+' · 进导航 '+navN+'</div></div>' +
      '<div class="stat"><div class="lb">媒体文件</div><div class="v">'+db.media.length+'</div><div class="s">图片 '+imgs+' · 压缩包 '+zips+' · 未关联 '+unlinked+'</div></div>' +
      '<div class="stat"><div class="lb">本地存储</div><div class="v">'+sizeKB+'<span style="font-size:14px;color:var(--muted)"> KB</span></div><div class="s">栏目 '+db.categories.length+' · 标签 '+tagCount+' · 记录 '+(livePosts().length+db.pages.length+db.media.length)+'</div></div>' +
    '</div>' +
    '<div class="stats reveal" style="animation-delay:.06s">' +
      todoHtml +
    '</div>' +
    '<div class="card reveal" style="animation-delay:.10s;padding:16px 18px 8px">' +
      '<div class="card-head"><b>最近文章</b><a href="#/posts">管理文章 →</a></div>' +
        '<div class="table-wrap"><table class="recent-table">' +
        '<thead><tr><th class="col-id">ID</th><th class="col-file">标题</th><th>状态</th><th>栏目</th><th>发布时间</th><th>操作</th></tr></thead><tbody>' +
        (recent.length ? recent.map(function(p){
          return '<tr><td class="id-cell">'+p.id+'</td>' +
            '<td class="col-file"><a class="row-title" href="#/article/'+p.id+'">'+esc(p.title)+'</a></td>' +
            '<td><span class="pill '+(PILL[p.status]||'draft')+'">'+(STATUS[p.status]||p.status)+'</span></td>' +
            '<td>'+esc(p.cat)+'</td><td class="date-cell">'+dateCell(p.date)+'</td>' +
            '<td><span class="row-actions"><a href="#/edit/'+p.id+'">编辑</a><span class="sep">|</span><a href="#/article/'+p.id+'">预览</a></span></td></tr>';
        }).join('') : '<tr class="empty-row"><td colspan="6">还没有文章</td></tr>') +
        '</tbody></table></div>' +
    '</div>' +
    '<div class="foot-note"><span>CiciCms · 数据保存在服务器（约 '+sizeKB+' KB）</span><span>© 2026 CiciCms</span></div>';
}

/* ================= 视图：文章列表 ================= */
function livePosts(){ return db.posts.filter(function(p){ return p.status !== 'trash'; }); }
function inTimeRange(p, days){
  if (!days || days === 'all') return true;
  var ms = stampMs(p.date);
  if (!ms) return true;
  return (Date.now() - ms) <= (+days) * 86400000;
}
function filteredPosts(){
  return db.posts.filter(function(p){
    if (p.status === 'trash') return false;   /* 回收站文章只在「回收管理」里出现 */
    if (PSTATE.status !== 'all' && p.status !== PSTATE.status) return false;
    if (PSTATE.cat !== 'all' && p.cat !== PSTATE.cat) return false;
    if (!inTimeRange(p, PSTATE.time)) return false;
    if (PSTATE.q && p.title.toLowerCase().indexOf(PSTATE.q) === -1 && String(p.id).indexOf(PSTATE.q) === -1) return false;
    return true;
  });
}
function vPosts(){
  PSTATE.sel = {};                 /* 每次进入列表都从零开始选择 */
  var catOpts = '<option value="all">全部栏目</option>' +
    db.categories.map(function(c){ return '<option value="'+esc(c)+'"'+(PSTATE.cat===c?' selected':'')+'>'+esc(c)+'</option>'; }).join('');
  view.innerHTML =
    '<div class="page-head reveal"><div><h1>文章</h1><p>共 '+livePosts().length+' 篇 · 点击标题可前台预览</p></div>' +
    '<div class="head-actions"><button class="btn primary" onclick="location.hash=\'#/edit/new\'">+ 撰写文章</button></div></div>' +
    '<div class="filter-row reveal" style="animation-delay:.04s">' +
      '<div class="filter-tabs" id="ftabs">' +
        '<button class="ftab'+(PSTATE.status==='all'?' on':'')+'" data-st="all">全部 <span class="n">'+livePosts().length+'</span></button>' +
        '<button class="ftab'+(PSTATE.status==='publish'?' on':'')+'" data-st="publish">已发布 <span class="n">'+cnt('publish')+'</span></button>' +
        '<button class="ftab'+(PSTATE.status==='draft'?' on':'')+'" data-st="draft">草稿 <span class="n">'+cnt('draft')+'</span></button>' +
        '<button class="ftab'+(PSTATE.status==='pending'?' on':'')+'" data-st="pending">待审核 <span class="n">'+cnt('pending')+'</span></button>' +
      '</div>' +
      '<div class="filter-extra"><select id="pcat">'+catOpts+'</select>' +
        '<select id="ptime">' +
          [['all','全部时间'],['7','近 7 天'],['30','近 30 天'],['365','近一年']].map(function(t){
            return '<option value="'+t[0]+'"'+(String(PSTATE.time)===t[0]?' selected':'')+'>'+t[1]+'</option>';
          }).join('') +
        '</select></div>' +
      '<label class="search"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg><input id="pq" value="'+esc(PSTATE.q)+'" placeholder="搜索标题或 ID…"></label>' +
    '</div>' +
    '<div class="card reveal" style="animation-delay:.08s"><div class="table-wrap"><table class="posts-table">' +
      '<thead><tr><th class="col-check"><input type="checkbox" id="selAll" title="全选本页"></th>' +
        '<th class="col-id">ID</th><th class="col-file">标题</th><th>状态</th><th>栏目</th><th>标签</th><th>发布时间</th><th>操作</th></tr></thead>' +
      '<tbody id="pbody"></tbody></table></div><div class="pager" id="ppager"></div>' +
      '<div class="bm-bar" id="bmBar" hidden>' +
        '<span class="bm-n">已选 <b id="bmN">0</b> 项</span>' +
        '<div class="bm-right">' +
          '<button class="btn sm primary" data-bact="publish">发布</button>' +
          '<button class="btn sm" data-bact="pending">提交审核</button>' +
          '<button class="btn sm danger" data-bact="trash">移入回收站</button>' +
          '<select id="bmCat">' + db.categories.map(function(c){ return '<option value="'+esc(c)+'">'+esc(c)+'</option>'; }).join('') +
          '</select><button class="btn sm" data-bact="cat">改栏目</button>' +
          '<button class="btn sm" data-bact="clear">取消选择</button>' +
        '</div>' +
      '</div>' +
    '</div>';
  document.getElementById('pq').addEventListener('input', function(){
    PSTATE.q = this.value.trim().toLowerCase(); PSTATE.page = 1; PSTATE.sel = {}; renderRows();
  });
  document.getElementById('pcat').addEventListener('change', function(){
    PSTATE.cat = this.value; PSTATE.page = 1; PSTATE.sel = {}; renderRows();
  });
  document.getElementById('ptime').addEventListener('change', function(){
    PSTATE.time = this.value; PSTATE.page = 1; PSTATE.sel = {}; renderRows();
  });
  document.getElementById('ftabs').addEventListener('click', function(e){
    var b = e.target.closest('[data-st]'); if(!b) return;
    PSTATE.status = b.dataset.st; PSTATE.page = 1; PSTATE.sel = {};
    document.querySelectorAll('#ftabs .ftab').forEach(function(el){el.classList.remove('on');});
    b.classList.add('on'); renderRows();
  });
  /* 覆盖式绑定，避免反复进入文章页累积监听器 */
  view.onclick = rowActions;
  renderRows();
}
function cnt(s){ return db.posts.filter(function(p){return p.status===s}).length; }
function selectedIds(){
  return Object.keys(PSTATE.sel || {}).map(Number).filter(function(id){
    return !!findPost(id);
  });
}
function renderBatchBar(){
  var bar = document.getElementById('bmBar');
  if (!bar) return;
  var ids = selectedIds();
  bar.hidden = !ids.length;
  var n = document.getElementById('bmN');
  if (n) n.textContent = String(ids.length);
}
function renderRows(){
  var rows = filteredPosts();
  var pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
  if (PSTATE.page > pages) PSTATE.page = pages;
  var slice = rows.slice((PSTATE.page-1)*PAGE_SIZE, PSTATE.page*PAGE_SIZE);
  document.getElementById('pbody').innerHTML = slice.length ? slice.map(function(p){
    var acts = p.status === 'trash'
      ? '<a data-act="restore" data-id="'+p.id+'">恢复</a><span class="sep">|</span>' +
        '<a class="danger" data-act="purge" data-id="'+p.id+'">永久删除</a><span class="sep">|</span>' +
        '<a href="#/article/'+p.id+'">查看</a>'
      : '<a href="#/edit/'+p.id+'">编辑</a><span class="sep">|</span>' +
        '<a href="#/article/'+p.id+'">查看</a>' +
        (p.status!=='publish' ? '<span class="sep">|</span><a data-act="publish" data-id="'+p.id+'">发布</a>' : '');
    return '<tr><td class="col-check"><input type="checkbox" data-sel="'+p.id+'"'+
        (PSTATE.sel[p.id] ? ' checked' : '')+'></td>' +
      '<td class="id-cell">'+p.id+'</td>' +
      '<td class="col-file"><a class="row-title" href="#/article/'+p.id+'">'+esc(p.title)+'</a>' +
        (p.status!=='publish' ? '<span class="post-state">— '+STATUS[p.status]+'</span>' : '') + '</td>' +
      '<td><span class="pill '+PILL[p.status]+'">'+STATUS[p.status]+'</span></td>' +
      '<td>'+esc(p.cat)+'</td>' +
      '<td class="tag-cell">'+(p.tags.length?p.tags.map(function(t){return '<a>'+esc(t)+'</a>'}).join(''):'—')+'</td>' +
      '<td class="date-cell">'+dateCell(p.date)+'</td>' +
      '<td><span class="row-actions">'+acts+'</span></td></tr>';
  }).join('') : '<tr class="empty-row"><td colspan="8">没有找到符合条件的文章</td></tr>';
  var html = '<span class="pg-info">共 '+rows.length+' 篇' +
    (rows.length ? ' · 显示 '+((PSTATE.page-1)*PAGE_SIZE+1)+'–'+((PSTATE.page-1)*PAGE_SIZE+slice.length)+' 条' : '') + '</span>';
  html += '<button class="pg-nav" data-p="'+(PSTATE.page-1)+'"'+(PSTATE.page<=1?' disabled':'')+'>‹</button>';
  for (var i=1;i<=pages;i++) html += i===PSTATE.page ? '<span class="page-num">'+i+'</span>' : '<button data-p="'+i+'">'+i+'</button>';
  html += '<button class="pg-nav" data-p="'+(PSTATE.page+1)+'"'+(PSTATE.page>=pages?' disabled':'')+'>›</button>';
  document.getElementById('ppager').innerHTML = html;
  var all = document.getElementById('selAll');
  if (all){
    var every = slice.length && slice.every(function(p){ return PSTATE.sel[p.id]; });
    all.checked = !!every;
  }
  renderBatchBar();
}
function rowActions(e){
  var pg = e.target.closest('.pager button[data-p]');
  if (pg && !pg.disabled){ PSTATE.page = +pg.dataset.p; renderRows(); return; }

  /* 勾选 */
  var box = e.target.closest('[data-sel]');
  if (box){
    var id = +box.dataset.sel;
    var on = box.checked !== undefined ? !!box.checked : !PSTATE.sel[id];
    if (on) PSTATE.sel[id] = 1; else delete PSTATE.sel[id];
    renderBatchBar();
    var all = document.getElementById('selAll');
    if (all){
      var slice = filteredPosts().slice((PSTATE.page-1)*PAGE_SIZE, PSTATE.page*PAGE_SIZE);
      all.checked = slice.length && slice.every(function(p){ return PSTATE.sel[p.id]; });
    }
    return;
  }
  if (e.target.closest('#selAll')){
    var slice2 = filteredPosts().slice((PSTATE.page-1)*PAGE_SIZE, PSTATE.page*PAGE_SIZE);
    var checked = e.target.checked !== undefined ? !!e.target.checked : true;
    slice2.forEach(function(p){ if (checked) PSTATE.sel[p.id] = 1; else delete PSTATE.sel[p.id]; });
    renderRows();
    return;
  }

  /* 批量操作 */
  var b = e.target.closest('[data-bact]');
  if (b){
    e.preventDefault();
    var ids = selectedIds();
    var act = b.dataset.bact;
    if (act === 'clear'){ PSTATE.sel = {}; renderRows(); return; }
    if (!ids.length){ toast('请先勾选文章', 'bad'); return; }
    if ((act === 'publish' || act === 'pending') && !can('publish')){
      toast('当前角色无权发布，请用编辑或管理员账号', 'bad'); return;
    }
    if (act === 'publish'){
      ids.forEach(function(i){ var p = findPost(i); if (p) p.status = 'publish'; });
      toast('已发布 '+ids.length+' 篇', 'ok');
    } else if (act === 'pending'){
      ids.forEach(function(i){ var p = findPost(i); if (p) p.status = 'pending'; });
      toast('已提交 '+ids.length+' 篇待审核', 'ok');
    } else if (act === 'trash'){
      if (!confirm('把选中的 '+ids.length+' 篇文章移入回收站？可在回收管理中恢复。')) return;
      ids.forEach(function(i){ var p = findPost(i); if (p) moveToTrash('post', p); });
      toast('已移入回收站 '+ids.length+' 篇', 'ok');
    } else if (act === 'cat'){
      var c = document.getElementById('bmCat').value;
      ids.forEach(function(i){ var p = findPost(i); if (p) p.cat = c; });
      toast('已改为栏目「'+c+'」', 'ok');
    }
    PSTATE.sel = {};
    save(); renderRows();
    return;
  }

  var a = e.target.closest('[data-act]');
  if (!a) return;
  e.preventDefault();
  var p = findPost(+a.dataset.id); if(!p) return;
  var act2 = a.dataset.act;
  if (act2==='restore'){ p.status='draft'; }
  if (act2==='publish'){
    if (!can('publish')){ toast('当前角色无权发布', 'bad'); return; }
    p.status='publish';
  }
  if (act2==='purge'){
    if (!can('purge')){ toast('当前角色无权永久删除', 'bad'); return; }
    if (!confirm('永久删除《'+p.title+'》？此操作不可恢复。')) return;
    db.posts = db.posts.filter(function(x){return x.id!==p.id});
  }
  save(); renderRows();
}

/* ================= 视图：草稿管理 ================= */
var DSTATE = {tab:'draft'};
function draftPool(){
  return db.posts.filter(function(p){
    if (p.status === 'trash') return false;
    return p.status === 'draft' || p.status === 'pending';
  }).sort(function(a,b){ return a.id - b.id; });
}
function vDrafts(){
  var pool = draftPool();
  var tabN = {
    draft: pool.filter(function(p){ return p.status === 'draft'; }).length,
    pending: pool.filter(function(p){ return p.status === 'pending'; }).length,
    all: pool.length
  };
  var rows = pool.filter(function(p){ return DSTATE.tab === 'all' || p.status === DSTATE.tab; });
  view.innerHTML =
    '<div class="page-head reveal"><div><h1>草稿管理</h1><p>未发布的文章都集中在这里 · 可直接继续编辑或发布</p></div>' +
      '<div class="head-actions"><button class="btn primary" onclick="location.hash=\'#/edit/new\'">+ 撰写文章</button></div></div>' +
    '<div class="draft-tabs reveal" style="animation-delay:.03s" id="draftTabs">' +
      [['draft','草稿 '+tabN.draft],['pending','待审核 '+tabN.pending],['all','全部未发布 '+tabN.all]].map(function(t){
        return '<button data-dt="'+t[0]+'"'+(DSTATE.tab===t[0]?' class="on"':'')+'>'+t[1]+'</button>';
      }).join('') +
    '</div>' +
    (rows.length ? '<div class="draft-grid reveal" style="animation-delay:.05s">' + rows.map(function(p){
      var d = draftRead(p.id);
      return '<div class="draft-card" data-dcard="'+p.id+'">' +
        '<div class="dc-main">' +
          '<a class="dc-title" href="#/edit/'+p.id+'">'+esc(p.title || '（无标题）')+'</a>' +
          '<div class="dc-meta">' +
            '<span class="pill '+PILL[p.status]+'">'+STATUS[p.status]+'</span>' +
            '<span>'+esc(p.cat)+'</span>' +
            (p.tags.length ? '<span>#'+p.tags.map(function(t){return esc(t)}).join(' #')+'</span>' : '') +
            '<span>更新于 '+esc(p.updated || dateOnly(p.date))+'</span>' +
            '<span>'+p.md.length+' 字符</span>' +
            (d ? '<span style="color:var(--warn)">有自动暂存 '+esc(d.at.slice(11))+'</span>' : '') +
          '</div>' +
          (p.excerpt ? '<div class="dc-ex">'+esc(p.excerpt)+'</div>' : '') +
        '</div>' +
        '<div class="dc-act">' +
          '<button class="btn sm" data-dedit="'+p.id+'">继续编辑</button>' +
          '<button class="btn sm primary" data-dpub="'+p.id+'">'+(p.status==='pending' ? '通过并发布' : '发布')+'</button>' +
          '<button class="btn sm" data-dtrash="'+p.id+'">删除</button>' +
        '</div></div>';
    }).join('') + '</div>'
      : '<div class="draft-empty reveal">' +
          (DSTATE.tab === 'pending' ? '没有待审核的文章' : DSTATE.tab === 'draft' ? '没有草稿，所有文章都已发布' : '没有未发布的文章') +
          '<div style="margin-top:10px"><a href="#/posts" style="color:var(--accent);text-decoration:none">去文章列表看看 →</a></div></div>');
  document.getElementById('draftTabs').addEventListener('click', function(e){
    var b = e.target.closest('[data-dt]'); if (!b) return;
    DSTATE.tab = b.dataset.dt; vDrafts();
  });
  view.onclick = function(e){
    var ed = e.target.closest('[data-dedit]');
    if (ed){ location.hash = '#/edit/' + ed.dataset.dedit; return; }
    var pb = e.target.closest('[data-dpub]');
    if (pb){
      e.preventDefault();
      if (!can('publish')){ toast('当前角色无权发布，请用编辑或管理员账号', 'bad'); return; }
      var p = findPost(+pb.dataset.dpub); if (!p) return;
      p.status = 'publish'; p.date = p.date || nowStamp(); p.updated = today();
      draftClear(p.id);
      save(); toast('《'+p.title+'》已发布', 'ok'); vDrafts();
      return;
    }
    var tr = e.target.closest('[data-dtrash]');
    if (tr){
      e.preventDefault();
      var g = findPost(+tr.dataset.dtrash); if (!g) return;
      if (!confirm('把《'+g.title+'》移入回收站？')) return;
      moveToTrash('post', g); draftClear(g.id); save(); toast('已移入回收站', 'ok'); vDrafts();
    }
  };
}

/* ================= 视图：回收管理 ================= */
var TSTATE = {q:'', page:1, kind:'all'};
var TRASH_KIND = {post:'文章', page:'单页', media:'媒体', cat:'栏目'};
function trashList(){ return trashItems().filter(function(it){ return TSTATE.kind === 'all' || it.kind === TSTATE.kind; }); }
function trashKindCount(kind){
  return trashItems().filter(function(it){ return kind === 'all' || it.kind === kind; }).length;
}
function vTrash(){
  TSTATE.q = ''; TSTATE.page = 1;
  var all = trashItems();
  view.innerHTML =
    '<div class="page-head reveal"><div><h1>回收管理</h1><p>共 <span id="trashCount">'+all.length+'</span> 项 · 保留 '+TRASH_DAYS+' 天，到期自动清理</p></div>' +
      '<div class="head-actions"><button class="btn" onclick="location.hash=\'#/posts\'">返回文章列表</button></div></div>' +
    '<div class="filter-row reveal" style="animation-delay:.04s">' +
      '<div class="trash-tip"><span class="pill trash">回收站</span>' +
        '文章、单页、媒体与栏目删除后都先进这里，可随时恢复；超过 '+TRASH_DAYS+' 天由系统自动永久删除。</div>' +
      '<label class="search"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg><input id="tq" placeholder="搜索名称或 ID…"></label>' +
    '</div>' +
    '<div class="trash-filter reveal" style="animation-delay:.05s" id="trashFilter">' +
      ['all','post','page','media','cat'].map(function(k){
        return '<button data-tf="'+k+'"'+(k===TSTATE.kind?' class="on"':'')+'>'+(k==='all'?'全部':TRASH_KIND[k])+' '+
          '<span style="opacity:.6">'+trashKindCount(k)+'</span></button>';
      }).join('') +
    '</div>' +
    '<div class="card reveal" style="animation-delay:.08s"><div class="table-wrap"><table class="posts-table">' +
      '<thead><tr><th style="width:74px">类型</th><th class="col-file">名称</th><th>原属</th><th>删除时间</th><th>剩余</th><th>操作</th></tr></thead>' +
      '<tbody id="trashBody"></tbody></table></div><div class="pager" id="trashPager"></div></div>' +
    '<div class="trash-bar reveal" style="animation-delay:.1s">' +
      '<div class="tb-info" id="trashBarInfo"></div>' +
      '<button class="btn danger" id="trashAll">一键清空</button>' +
    '</div>';
  document.getElementById('tq').addEventListener('input', function(){
    TSTATE.q = this.value.trim().toLowerCase(); TSTATE.page = 1; renderTrash();
  });
  document.getElementById('trashFilter').addEventListener('click', function(e){
    var b = e.target.closest('[data-tf]'); if (!b) return;
    TSTATE.kind = b.dataset.tf; TSTATE.page = 1;
    document.querySelectorAll('#trashFilter button').forEach(function(el){ el.classList.remove('on'); });
    b.classList.add('on');
    renderTrash();
  });
  document.getElementById('trashAll').addEventListener('click', function(){
    var items = trashItems();
    if (!items.length) return;
    if (!can('purge')){ toast('当前角色无权永久删除，请用管理员账号', 'bad'); return; }
    if (!confirm('确定清空回收站里的 '+items.length+' 项？删除后无法恢复。')) return;
    items.forEach(function(it){ purgeTrash(it.kind, it.id); });
    save(); TSTATE.page = 1; vTrash();
  });
  /* 用 onclick 覆盖式绑定，避免多次进入页面累积监听器 */
  view.onclick = trashActions;
  renderTrash();
}
function renderTrash(){
  var body = document.getElementById('trashBody');
  if (!body) return;
  var all = trashItems();
  var rows = trashList().filter(function(it){
    if (!TSTATE.q) return true;
    return String(it.title).toLowerCase().indexOf(TSTATE.q) > -1 || String(it.id).toLowerCase().indexOf(TSTATE.q) > -1;
  });
  var pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
  if (TSTATE.page > pages) TSTATE.page = pages;
  var slice = rows.slice((TSTATE.page-1)*PAGE_SIZE, TSTATE.page*PAGE_SIZE);
  body.innerHTML = slice.length ? slice.map(function(it){
    var left = trashDaysLeft(it.at);
    return '<tr><td><span class="type-pill '+it.kind+'">'+TRASH_KIND[it.kind]+'</span></td>' +
      '<td class="col-file"><b style="font-weight:600;font-size:13.2px">'+esc(it.title)+'</b>' +
        '<span style="color:var(--muted);font-size:11.5px;margin-left:6px">#'+esc(it.id)+'</span></td>' +
      '<td style="color:var(--muted);font-size:12.3px">'+esc(it.sub)+'</td>' +
      '<td class="date-cell">'+esc(it.at || '—')+'</td>' +
      '<td>' + (left == null ? '<span class="days-left">长期保留</span>'
        : '<span class="days-left'+(left <= 7 ? ' warn' : '')+'">'+left+' 天</span>') + '</td>' +
      '<td><span class="row-actions">' +
        '<a data-tact="restore" data-tt="'+it.kind+'" data-tid="'+esc(it.id)+'">恢复</a><span class="sep">|</span>' +
        '<a class="danger" data-tact="purge" data-tt="'+it.kind+'" data-tid="'+esc(it.id)+'">永久删除</a>' +
      '</span></td></tr>';
  }).join('') : '<tr class="empty-row"><td colspan="6">' +
      (all.length ? '该分类下没有条目' : '回收站是空的，没有可恢复的内容') + '</td></tr>';

  var html = '<span class="pg-info">共 '+rows.length+' 项' +
    (rows.length ? ' · 显示 '+((TSTATE.page-1)*PAGE_SIZE+1)+'–'+((TSTATE.page-1)*PAGE_SIZE+slice.length)+' 条' : '') + '</span>';
  html += '<button class="pg-nav" data-tp="'+(TSTATE.page-1)+'"'+(TSTATE.page<=1?' disabled':'')+'>‹</button>';
  for (var i=1;i<=pages;i++) html += i===TSTATE.page ? '<span class="page-num">'+i+'</span>' : '<button data-tp="'+i+'">'+i+'</button>';
  html += '<button class="pg-nav" data-tp="'+(TSTATE.page+1)+'"'+(TSTATE.page>=pages?' disabled':'')+'>›</button>';
  document.getElementById('trashPager').innerHTML = html;

  var cntEl = document.getElementById('trashCount');
  if (cntEl) cntEl.textContent = String(all.length);
  var info = document.getElementById('trashBarInfo');
  if (info){
    var soon = all.filter(function(it){ return trashDaysLeft(it.at) <= 7; }).length;
    info.innerHTML = all.length
      ? '回收站共 <b>'+all.length+'</b> 项待清理' + (soon ? ' · 其中 <b>'+soon+'</b> 项将在 7 天内到期' : '') +
        ' · 一键清空不可撤销'
      : '回收站里没有内容，无需清理';
  }
  var allBtn = document.getElementById('trashAll');
  if (allBtn) allBtn.disabled = !all.length;
}
function trashActions(e){
  var pg = e.target.closest('#trashPager button[data-tp]');
  if (pg && !pg.disabled){ TSTATE.page = +pg.dataset.tp; renderTrash(); return; }
  var a = e.target.closest('[data-tact]');
  if (!a) return;
  e.preventDefault();
  var kind = a.dataset.tt, id = a.dataset.tid;
  if (a.dataset.tact === 'restore'){
    if (restoreTrash(kind, id)){ save(); toast('已恢复到原位置', 'ok'); }
    renderTrash(); return;
  }
  if (a.dataset.tact === 'purge'){
    if (!can('purge')){ toast('当前角色无权永久删除，请用管理员账号', 'bad'); return; }
    var it = trashItems().filter(function(x){ return x.kind === kind && String(x.id) === String(id); })[0];
    if (!it) return;
    if (!confirm('永久删除「'+it.title+'」？此操作不可恢复。')) return;
    purgeTrash(kind, id);
    save(); renderTrash();
  }
}

/* ================= 视图：模板管理 ================= */
function tplListHtml(activePath){
  var files = db.templates.files;
  return ['layouts', 'partials', 'pages'].filter(function(g){
    return files.some(function(f){ return tplGroup(f.path) === g; });
  }).map(function(g){
    return '<div class="tpl-grp">' + (TPL_GROUPS[g] || g) + '</div>' +
      files.filter(function(f){ return tplGroup(f.path) === g; }).map(function(f){
        return '<button class="tpl-item' + (f.path === activePath ? ' on' : '') + '"' +
          ' data-path="' + esc(f.path) + '" title="' + esc(f.path) + '">' +
          '<span class="tpl-nm">' + esc(tplFileName(f.path)) + '</span>' +
          '<span class="tpl-sz">' + fmtSize(byteLen(f.content)) + '</span></button>';
      }).join('');
  }).join('');
}
function vTemplates(){
  ensureTemplates();
  view.onclick = null; /* 清掉文章页/回收页残留的行内代理 */
  var files = db.templates.files;
  var active = files.filter(function(f){ return f.path === db.templates.active; })[0] || files[0];
  view.innerHTML =
    '<div class="page-head reveal"><div><h1>模板管理</h1><p>共 '+files.length+' 个模板文件 · 构建静态站点时按这组模板套版，保存后下次「一键更新」生效</p></div>' +
      '<div class="head-actions">' +
        '<button class="btn" id="tplDefault">恢复默认</button>' +
        '<button class="btn primary" id="tplSave">保存模板</button>' +
      '</div></div>' +
    '<div class="tpl-grid reveal" style="animation-delay:.04s">' +
      '<div class="card tpl-main">' +
        '<div class="tpl-bar"><span class="tpl-path" id="tplPath">'+esc(active.path)+'</span>' +
          '<span class="tpl-tip" id="tplTip">'+esc(tplStamp(active.updated))+'</span></div>' +
        '<div class="tpl-editor">' +
          '<div class="tpl-gutter" id="tplGutter"></div>' +
          '<textarea class="tpl-code" id="tplCode" spellcheck="false" wrap="off"></textarea>' +
        '</div>' +
      '</div>' +
      '<div class="card tpl-side">' +
        '<div class="tpl-side-h">模板文件<span>'+files.length+' 个</span></div>' +
        '<div class="tpl-list" id="tplList">'+tplListHtml(active.path)+'</div>' +
        '<div class="tpl-foot">左侧直接编辑选中文件，Tab 缩进两个空格；模板变量形如 {{ .Title }}。base 负责整体骨架，post/list 决定文章页与列表页的正文结构。</div>' +
      '</div>' +
    '</div>';

  var ta = document.getElementById('tplCode');
  var gutter = document.getElementById('tplGutter');
  var tipEl = document.getElementById('tplTip');
  ta.value = active.content;

  function syncGutter(){
    var n = ta.value.split('\n').length, h = '';
    for (var i = 1; i <= n; i++) h += '<div>' + i + '</div>';
    gutter.innerHTML = h;
    gutter.scrollTop = ta.scrollTop;
  }
  syncGutter();
  ta.addEventListener('input', syncGutter);
  ta.addEventListener('scroll', function(){ gutter.scrollTop = ta.scrollTop; });
  ta.addEventListener('keydown', function(e){
    if (e.key !== 'Tab') return;
    e.preventDefault();
    var s = this.selectionStart, en = this.selectionEnd;
    this.value = this.value.slice(0, s) + '  ' + this.value.slice(en);
    this.selectionStart = this.selectionEnd = s + 2;
    syncGutter();
  });

  function refreshList(){
    var box = document.getElementById('tplList');
    if (box) box.innerHTML = tplListHtml(active.path);
  }

  document.getElementById('tplSave').addEventListener('click', function(){
    active.content = ta.value;
    active.updated = nowStamp();
    save();
    tipEl.className = 'tpl-tip ok';
    tipEl.textContent = '已保存 · ' + nowStamp().slice(11);
    refreshList();
  });

  document.getElementById('tplDefault').addEventListener('click', function(){
    var d = null;
    TPL_DEFAULT.forEach(function(x){ if (x.path === active.path) d = x; });
    if (!d){ tipEl.className = 'tpl-tip'; tipEl.textContent = '该文件没有内置默认内容'; return; }
    if (!confirm('把 ' + active.path + ' 恢复为默认内容？当前修改将丢失。')) return;
    active.content = d.content;
    active.updated = nowStamp();
    save();
    ta.value = d.content;
    syncGutter();
    tipEl.className = 'tpl-tip ok';
    tipEl.textContent = '已恢复默认内容';
    refreshList();
  });

  document.getElementById('tplList').addEventListener('click', function(e){
    var b = e.target.closest('[data-path]');
    if (!b) return;
    var path = b.dataset.path;
    if (path === active.path) return;
    if (ta.value !== active.content &&
        !confirm('「' + active.path + '」有未保存的修改，切换后将丢失。继续？')) return;
    db.templates.active = path;
    save();
    vTemplates();
  });
}

/* ================= 视图：编辑器 ================= */
/* ================= Hexo Markdown 导入 ================= */
/* 只认 Hexo 常用的 Front Matter 字段，不引第三方 YAML 依赖，出错也不影响正文导入 */
function hexoUnquote(s){
  var v = String(s == null ? '' : s).trim();
  var a = v.charAt(0), b = v.charAt(v.length-1);
  if (v.length > 1 && (a === '"' || a === "'") && a === b) v = v.slice(1,-1);
  return v.trim();
}
/* 解析开头 --- ... --- 的 Front Matter：支持 key: value、key: [a, b]、key: 换行 - 列表 */
function hexoFrontMatter(text){
  var src = String(text||'').replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n');
  var m = src.match(/^---[ \t]*\n([\s\S]*?)\n---[ \t]*(?:\n|$)/);
  if (!m) return {data:{}, body:src};
  var data = {}, cur = '', lines = m[1].split('\n');
  for (var i = 0; i < lines.length; i++){
    var raw = lines[i];
    if (!raw.trim() || /^\s*#/.test(raw)) continue;
    var kv = raw.match(/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/);
    if (kv){
      var k = kv[1], v = kv[2].replace(/\s+#\s.*$/, '').trim();
      if (!v){ data[k] = []; cur = k; }                    /* 值在下面几行的 - 列表里 */
      else if (/^\[[^\]]*\]$/.test(v)){
        data[k] = v.slice(1,-1).split(',').map(hexoUnquote).filter(Boolean);
        cur = '';
      } else { data[k] = hexoUnquote(v); cur = ''; }
      continue;
    }
    var li = raw.match(/^\s*-\s+(.+)$/);                   /* 列表项（含嵌套缩进） */
    if (li && cur){
      if (!Array.isArray(data[cur])) data[cur] = [];
      data[cur].push(hexoUnquote(li[1]));
    }
  }
  return {data:data, body:src.slice(m[0].length).replace(/^\n+/, '')};
}
/* 2025-11-23 / 2025-11-23 14:03:06 / 2025-11-23T14:03:06+08:00 → 2025/11/23 14:03:06 */
function hexoStamp(v){
  var m = String(v||'').match(/(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?/);
  if (!m) return '';
  return m[1]+'/'+pad2(+m[2])+'/'+pad2(+m[3])+' '+pad2(+(m[4]||0))+':'+pad2(+(m[5]||0))+':'+pad2(+(m[6]||0));
}
/* 去掉 Markdown 记号，用来生成一句话摘要 */
function hexoPlain(s){
  return String(s||'')
    .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/[*_`>#]/g, '')
    .replace(/\s+/g, ' ').trim();
}
function hexoFirstPara(body){
  var lines = String(body||'').split('\n'), buf = [];
  for (var i = 0; i < lines.length; i++){
    var L = lines[i].trim();
    if (!L){ if (buf.length) break; continue; }
    if (/^(```|---|\*\*\*|<!--|!\[|#{1,6}\s)/.test(L)){ if (buf.length) break; continue; }  /* 跳过代码块、标题与纯图行 */
    buf.push(L);
    if (buf.join(' ').length > 90) break;
  }
  return hexoPlain(buf.join(' ')).slice(0, 88);
}
/* 这些键会被读进表单（或已明确无用），其余键在导入结果里列为「忽略字段」 */
var HEXO_KEYS = ['title','date','updated','categories','category','tags','tag','cover',
                 'description','desc','excerpt','keywords','published'];
/* 解析一篇 Hexo 文章 → 表单需要的字段；body 去掉 <!-- more --> 以便全文导入 */
function hexoParse(text, fileName){
  var fm = hexoFrontMatter(text), d = fm.data, body = fm.body;
  var cats = d.categories != null && String(d.categories).length ? d.categories : (d.category || []);
  if (typeof cats === 'string') cats = cats ? [cats] : [];
  var tags = d.tags != null && String(d.tags).length ? d.tags : (d.tag || []);
  if (typeof tags === 'string') tags = tags.split(/[,，]/);
  cats = cats.map(hexoUnquote).filter(Boolean);
  tags = tags.map(hexoUnquote).filter(Boolean);
  /* 正文里的 <!-- more --> 是 Hexo 的摘要分隔符：前半段当摘要，标记本身不留 */
  var moreRe = /<!--\s*more\s*-->/;
  var mi = body.search(moreRe);
  var head = mi > -1 ? body.slice(0, mi) : '';
  var mdBody = mi > -1 ? (head + body.slice(mi).replace(moreRe, '')).replace(/\n{3,}/g, '\n\n') : body;
  var base = String(fileName||'').replace(/\.(md|markdown|txt)$/i, '').replace(/^\d+[.\-_ ]*/, '').trim();
  /* 正文里的本地图片（Hexo 的 /img/xxx.jpg、./xxx.png）在这里没有对应文件，导入后需要换图 */
  var localImgs = [];
  mdBody.replace(/!\[[^\]]*\]\(([^)\s]+)[^)]*\)/g, function(all, src){
    if (!/^(https?:)?\/\//i.test(src) && !/^data:/i.test(src) && localImgs.indexOf(src) === -1) localImgs.push(src);
    return all;
  });
  var cover = String(d.cover||'').trim(), coverHint = '';
  if (cover && !/^(https?:)?\/\//i.test(cover) && !/^data:/i.test(cover)){
    coverHint = '封面「'+cover+'」是 Hexo 站内相对路径，本地打不开，已留空，可从媒体库重选';
    cover = '';
  }
  return {
    title: String(d.title || base || '未命名导入').trim(),
    date: hexoStamp(d.date),
    cat: cats[0] || '',
    extraCats: cats.slice(1),
    tags: tags,
    cover: cover,
    coverHint: coverHint,
    excerpt: String(d.description || d.desc || d.excerpt || '').trim() || hexoFirstPara(head || mdBody),
    keywords: String(d.keywords || '').trim() || tags.join(', '),
    status: String(d.published).toLowerCase() === 'false' ? 'draft' : '',
    md: mdBody,
    chars: mdBody.replace(/\s/g, '').length,
    localImgs: localImgs,
    ignored: Object.keys(d).filter(function(k){ return HEXO_KEYS.indexOf(k) === -1; }),
    hasFm: !!Object.keys(d).length
  };
}
function hexoReadFile(file, cb){
  if (!file) return;
  if (/\.(md|markdown|txt)$/i.test(file.name) === false){ toast('只支持 .md / .markdown / .txt 文件', 'bad'); return; }
  if (file.size > 2 * 1024 * 1024){ toast('文件超过 2MB，先精简后再导入', 'bad'); return; }
  var r = new FileReader();
  r.onload = function(){ cb(String(r.result || '')); };
  r.onerror = function(){ toast('读取文件失败，请重试', 'bad'); };
  r.readAsText(file, 'utf-8');
}

/* ================= Markdown 编辑器：工具栏 + 快捷键 + 状态栏 ================= */
var MD_TOOLS = [
  {a:'undo', t:'撤销（Ctrl/⌘+Z）', svg:'<path d="M8 8l-4 4 4 4"/><path d="M4 12h9a5 5 0 010 10h-3"/>'},
  {a:'redo', t:'重做（Ctrl/⌘+Shift+Z）', svg:'<path d="M16 8l4 4-4 4"/><path d="M20 12h-9a5 5 0 000 10h3"/>'},
  {sep:1},
  {a:'h1', txt:'H1', t:'一级标题'},
  {a:'h2', txt:'H2', t:'二级标题'},
  {a:'h3', txt:'H3', t:'三级标题'},
  {sep:1},
  {a:'bold', t:'加粗（Ctrl/⌘+B）', svg:'<path d="M7 5h6a3.5 3.5 0 010 7H7z"/><path d="M7 12h7a3.5 3.5 0 010 7H7z"/>'},
  {a:'italic', t:'斜体（Ctrl/⌘+I）', svg:'<path d="M15 5h-4M13 19H9M14 5l-4 14"/>'},
  {a:'strike', t:'删除线', svg:'<path d="M5 12h14"/><path d="M16.5 8A3.5 3.5 0 0013 5.5h-1.4a2.9 2.9 0 000 5.8h2.6a2.9 2.9 0 010 5.8H13A3.5 3.5 0 019.5 14.6"/>'},
  {a:'code', t:'行内代码（Ctrl/⌘+E）', svg:'<path d="M9 8l-4 4 4 4M15 8l4 4-4 4"/>'},
  {sep:1},
  {a:'quote', t:'引用', svg:'<path d="M6 8h3.5v3.6c0 2.1-1.8 3.2-3.5 4M14.5 8H18v3.6c0 2.1-1.8 3.2-3.5 4"/>'},
  {a:'ul', t:'无序列表', svg:'<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.6" cy="6" r="1.3"/><circle cx="4.6" cy="12" r="1.3"/><circle cx="4.6" cy="18" r="1.3"/>'},
  {a:'ol', t:'有序列表', svg:'<path d="M10 6h10M10 12h10M10 18h10"/><path d="M4 5.6h1V10M4 10h2"/><path d="M4 14h2v1.7L4 18h2"/>'},
  {a:'task', t:'任务清单', svg:'<path d="M11 6h9M11 12h9M11 18h9"/><path d="M3.4 6l1.5 1.5L7.6 5M3.4 12l1.5 1.5L7.6 11M3.4 18l1.5 1.5L7.6 17"/>'},
  {sep:1},
  {a:'link', t:'链接（Ctrl/⌘+K）', svg:'<path d="M10.6 13.4a3.6 3.6 0 005.1 0l2.5-2.5a3.6 3.6 0 00-5.1-5.1l-1.2 1.2"/><path d="M13.4 10.6a3.6 3.6 0 00-5.1 0l-2.5 2.5a3.6 3.6 0 005.1 5.1l1.2-1.2"/>'},
  {a:'image', t:'图片（媒体库可选）', svg:'<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.4" cy="10" r="1.5"/><path d="M4 17l5-4.6 3.6 3.4 2.6-1.8L20 17"/>'},
  {a:'table', t:'表格', svg:'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M9.5 10v9M15 10v9"/>'},
  {a:'codeblock', t:'代码块', svg:'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M9.5 10L7.5 12l2 2M14.5 10l2 2-2 2"/>'},
  {a:'hr', t:'分隔线', svg:'<path d="M4 12h16" stroke-dasharray="3 3"/>'},
  {sp:1},
  {a:'preview', txt:'预览', t:'只看预览 / 回到双栏'},
  {a:'full', t:'全屏编辑（Esc 退出）', svg:'<path d="M4 9V4h5M20 15v5h-5M15 4h5v5M9 20H4v-5"/>'}
];
function mdBarHtml(){
  return MD_TOOLS.map(function(t){
    if (t.sep) return '<span class="md-sep"></span>';
    if (t.sp) return '<span class="md-sp"></span>';
    return '<button type="button" tabindex="-1" class="md-tool'+(t.txt?' txt':'')+'" data-md="'+t.a+'" title="'+esc(t.t)+'">' +
      (t.txt ? t.txt : '<svg viewBox="0 0 24 24">'+t.svg+'</svg>') + '</button>';
  }).join('');
}
function mdEditorHtml(o){
  return '<div class="editor-grid md-editor reveal" id="'+o.ta+'Box" style="animation-delay:'+(o.delay||'.06s')+'">' +
    '<div class="editor-pane md-src-pane"><div class="md-frame">' +
      '<div class="md-toolbar" id="'+o.ta+'Bar">'+mdBarHtml() +
        (o.headRight ? '<span class="md-save">'+o.headRight+'</span>' : '') + '</div>' +
      '<textarea class="md-input" id="'+o.ta+'" spellcheck="false" placeholder="开始写正文…  支持 Markdown、表格与任务清单">'+esc(o.value||'')+'</textarea>' +
      '<div class="md-status" id="'+o.ta+'Stat"></div>' +
    '</div></div>' +
    '<div class="editor-pane md-prev-pane"><div class="md-frame">' +
      '<div class="md-bar">实时预览<span class="mb-hint">左边写，右边就是前台效果</span></div>' +
      '<div class="md-preview" id="'+o.prev+'"></div>' +
    '</div></div>' +
  '</div>';
}
/* 挂载：绑定输入、工具栏、快捷键、滚动同步、字数统计 */
function mdMount(o){
  var ta = document.getElementById(o.ta), prev = document.getElementById(o.prev);
  var noop = {render:function(){}};
  if (!ta || !prev) return noop;
  var grid = document.getElementById(o.ta+'Box');
  var bar = document.getElementById(o.ta+'Bar');
  var stat = document.getElementById(o.ta+'Stat');

  function setSel(a, b){
    var to = (b == null ? a : b);
    if (ta.setSelectionRange) ta.setSelectionRange(a, to);
    else { ta.selectionStart = a; ta.selectionEnd = to; }
  }
  function selStart(){ return ta.selectionStart || 0; }
  function selEnd(){ return ta.selectionEnd || 0; }
  function lineRange(){
    var v = ta.value || '', s = selStart(), e = selEnd();
    var ls = v.lastIndexOf('\n', s - 1) + 1;
    var le = v.indexOf('\n', e);
    if (le < 0) le = v.length;
    return {ls:ls, le:le, lines:v.slice(ls, le).split('\n')};
  }
  /* 用 execCommand 写入，尽量保住浏览器原生撤销栈；不支持时回退成直接改 value */
  function apply(start, end, text, selA, selB){
    var ok = false;
    setSel(start, end);
    try{ if (ta.focus) ta.focus(); }catch(e){}
    try{ ok = !!(document.execCommand && document.execCommand('insertText', false, text)); }catch(e){ ok = false; }
    if (!ok){
      var v = ta.value || '';
      ta.value = v.slice(0, start) + text + v.slice(end);
    }
    if (selA != null) setSel(selA, selB);
    render();
  }
  function statText(v){
    var chars = v.replace(/\s+/g, '').length;
    var lines = v ? v.split('\n').length : 1;
    return chars.toLocaleString('en-US') + ' 字 · ' + lines + ' 行 · 约 ' + Math.max(1, Math.round(chars/400)) + ' 分钟';
  }
  function caretText(){
    var v = ta.value || '', s = selStart();
    var before = v.slice(0, s).split('\n');
    return '行 ' + before.length + ' 列 ' + (before[before.length-1].length + 1);
  }
  function syncStat(){
    if (stat) stat.innerHTML = '<span>'+statText(ta.value || '')+'</span>' +
      '<span class="ms-hint">'+caretText()+' · Ctrl+B 粗体 · Ctrl+K 链接</span>';
  }
  function syncState(){
    if (!bar || !bar.querySelectorAll) return;
    var v = ta.value || '', s = selStart();
    var ls = v.lastIndexOf('\n', s - 1) + 1;
    var line = v.slice(ls, v.indexOf('\n', s) < 0 ? v.length : v.indexOf('\n', s));
    var on = {};
    var h = line.match(/^(#{1,6})\s/); if (h) on['h' + h[1].length] = 1;
    if (/^>\s?/.test(line)) on.quote = 1;
    if (/^\s*[-*]\s+\[[ xX]\]/.test(line)) on.task = 1;
    else if (/^\s*[-*]\s+/.test(line)) on.ul = 1;
    else if (/^\s*\d+\.\s+/.test(line)) on.ol = 1;
    Array.prototype.forEach.call(bar.querySelectorAll('[data-md]'), function(b){
      b.classList.toggle('on', !!on[b.getAttribute('data-md')]);
    });
  }
  function render(){
    /* 预览里把 /uploads/x 解析成本地媒体数据；构建产物仍保留原路径 */
    var html = md(ta.value || '');
    if (typeof coverSrc === 'function'){
      html = html.replace(/src="(\/uploads\/[^"]+)"/g, function(_, u){ return 'src="' + coverSrc(u) + '"'; });
    }
    prev.innerHTML = html;
    syncStat(); syncState();
  }

  /* —— 编辑动作 —— */
  function wrap(before, after, ph){
    var v = ta.value || '', s = selStart(), e = selEnd();
    var inner = v.slice(s, e) || ph || '';
    apply(s, e, before + inner + after, s + before.length, s + before.length + inner.length);
  }
  function heading(lv){
    var r = lineRange(), hashes = new Array(lv + 1).join('#');
    var allOn = r.lines.every(function(L){ return !L.trim() || new RegExp('^#{' + lv + '}\\s').test(L); });
    var text = r.lines.map(function(L){
      if (!L.trim()) return L;
      if (allOn) return L.replace(/^#{1,6}\s+/, '');
      return hashes + ' ' + L.replace(/^#{1,6}\s+/, '');
    }).join('\n');
    apply(r.ls, r.le, text, r.ls, r.ls + text.length);
  }
  function prefix(kind){
    var r = lineRange();
    var on = r.lines.every(function(L){
      if (!L.trim()) return true;
      if (kind === 'quote') return /^>\s?/.test(L);
      if (kind === 'ul') return /^\s*[-*]\s+/.test(L) && !/^\s*[-*]\s+\[[ xX]\]/.test(L);
      if (kind === 'task') return /^\s*[-*]\s+\[[ xX]\]\s+/.test(L);
      return /^\s*\d+\.\s+/.test(L);
    });
    var text = r.lines.map(function(L, idx){
      if (kind === 'quote') return on ? L.replace(/^>\s?/, '') : (L.trim() ? '> ' + L : L);
      if (kind === 'task'){
        if (on) return L.replace(/^(\s*)[-*]\s+\[[ xX]\]\s+/, '$1');
        if (/^\s*[-*]\s+\[[ xX]\]\s+/.test(L)) return L;
        if (/^\s*[-*]\s+/.test(L)) return L.replace(/^(\s*)[-*]\s+/, '$1- [ ] ');
        return L.trim() ? '- [ ] ' + L : L;
      }
      if (kind === 'ul'){
        if (on) return L.replace(/^\s*[-*]\s+/, '');
        if (/^\s*[-*]\s+/.test(L)) return L;
        return L.trim() ? '- ' + L : L;
      }
      if (on) return L.replace(/^\s*\d+\.\s+/, '');
      if (/^\s*\d+\.\s+/.test(L)) return L;
      return L.trim() ? (idx + 1) + '. ' + L : L;
    }).join('\n');
    apply(r.ls, r.le, text, r.ls, r.ls + text.length);
  }
  function insertBlock(text, a, b){
    var v = ta.value || '', s = selStart(), e = selEnd();
    var pre = (s === 0 || v.charAt(s - 1) === '\n') ? '' : '\n\n';
    var post = (text.slice(-1) === '\n' || v.charAt(e) === '\n') ? '' : '\n';
    var from = s + pre.length + (a || 0), to = s + pre.length + (b || 0);
    apply(s, e, pre + text + post, from, to);
  }
  function link(isImg){
    var v = ta.value || '', s = selStart(), e = selEnd();
    var inner = v.slice(s, e) || (isImg ? '图片描述' : '链接文字');
    var url = 'https://';
    var text = (isImg ? '!' : '') + '[' + inner + '](' + url + ')';
    if (e > s) apply(s, e, text, s, s + text.length);
    else {
      var at = s + (isImg ? 2 : 1) + inner.length + 2;
      apply(s, e, text, at, at + url.length);
    }
  }
  function togglePreview(){
    if (!grid) return;
    var on = grid.classList.toggle('only-prev');
    var btn = bar && bar.querySelector ? bar.querySelector('[data-md="preview"]') : null;
    if (btn) btn.textContent = on ? '双栏' : '预览';
    if (btn) btn.classList.toggle('on', on);
  }
  function toggleFull(){
    if (!grid) return;
    var on = grid.classList.toggle('md-full');
    var btn = bar && bar.querySelector ? bar.querySelector('[data-md="full"]') : null;
    if (btn) btn.classList.toggle('on', on);
  }
  function indent(back){
    var v = ta.value || '', s = selStart(), e = selEnd();
    if (v.slice(s, e).indexOf('\n') === -1){                 /* 单行：插/删两个空格 */
      if (!back){ apply(s, e, '  ', s + 2, s + 2); return; }
      var ls = v.lastIndexOf('\n', s - 1) + 1, line = v.slice(ls, s);
      var cut = /  $/.test(line) ? 2 : (line.slice(-1) === ' ' ? 1 : 0);
      if (cut) apply(s - cut, s, '', s - cut, s - cut);
      return;
    }
    var r = lineRange();
    var text = r.lines.map(function(L){ return back ? L.replace(/^ {1,2}/, '') : '  ' + L; }).join('\n');
    apply(r.ls, r.le, text, r.ls, r.ls + text.length);
  }
  /* 回车自动续列表，空条目回车退出列表 */
  function listEnter(){
    var v = ta.value || '', s = selStart(), e = selEnd();
    if (s !== e) return false;
    var ls = v.lastIndexOf('\n', s - 1) + 1;
    var line = v.slice(ls, s);
    var m = line.match(/^(\s*)((?:[-*+]|\d+\.)\s+(?:\[[ xX]\]\s+)?)(.*)$/);
    if (!m) return false;
    if (!m[3].trim()){ apply(ls, s, '', ls, ls); return true; }
    var marker = m[2].replace(/\[[xX]\]/, '[ ]').replace(/^(\d+)\./, function(_, n){ return (parseInt(n, 10) + 1) + '.'; });
    var ins = '\n' + m[1] + marker;
    apply(s, e, ins, s + ins.length, s + ins.length);
    return true;
  }
  function run(a){
    if (a === 'undo' || a === 'redo'){
      if (ta.focus) ta.focus();
      try{ document.execCommand(a === 'undo' ? 'undo' : 'redo'); }catch(e){}
      render(); syncStat();
      return;
    }
    if (a === 'bold') return wrap('**', '**', '加粗文字');
    if (a === 'italic') return wrap('*', '*', '斜体文字');
    if (a === 'strike') return wrap('~~', '~~', '删除文字');
    if (a === 'code') return wrap('`', '`', '代码');
    if (a === 'h1') return heading(1);
    if (a === 'h2') return heading(2);
    if (a === 'h3') return heading(3);
    if (a === 'quote') return prefix('quote');
    if (a === 'ul') return prefix('ul');
    if (a === 'ol') return prefix('ol');
    if (a === 'task') return prefix('task');
    if (a === 'link') return link(false);
    if (a === 'image'){
      if (typeof o.onImage === 'function'){
        return o.onImage(function(alt, src){
          var v = ta.value || '', s = selStart(), e = selEnd();
          var txt = '![' + (v.slice(s, e) || alt || '图片') + '](' + src + ')';
          apply(s, e, txt, s + txt.length, s + txt.length);
        });
      }
      return link(true);
    }
    if (a === 'hr') return insertBlock('---\n', 0, 0);
    if (a === 'table') return insertBlock('| 表头 | 表头 |\n| --- | --- |\n| 单元格 | 单元格 |', 2, 4);
    if (a === 'codeblock'){
      var v = ta.value || '', s = selStart(), e = selEnd();
      var sel = v.slice(s, e);
      var body = '```\n' + (sel || '') + '\n```';
      return apply(s, e, body, s + 4, s + 4 + (sel || '').length);
    }
    if (a === 'preview') return togglePreview();
    if (a === 'full') return toggleFull();
  }

  if (bar && bar.addEventListener){
    bar.addEventListener('click', function(e){
      var b = e.target && e.target.closest ? e.target.closest('[data-md]') : null;
      if (!b) return;
      e.preventDefault();
      run(b.getAttribute('data-md'));
    });
    bar.addEventListener('mousedown', function(e){ if (e.preventDefault) e.preventDefault(); });  /* 别把焦点从文本框抢走 */
  }
  ta.addEventListener('input', render);
  ta.addEventListener('keyup', function(){ syncStat(); syncState(); });
  ta.addEventListener('click', function(){ syncStat(); syncState(); });
  ta.addEventListener('keydown', function(e){
    var meta = e.metaKey || e.ctrlKey;
    var k = (e.key || '').toLowerCase();
    if (meta && !e.altKey){
      if (k === 'b'){ e.preventDefault(); return run('bold'); }
      if (k === 'i'){ e.preventDefault(); return run('italic'); }
      if (k === 'k'){ e.preventDefault(); return run('link'); }
      if (k === 'e'){ e.preventDefault(); return run('code'); }
      if (k === 's' && o.onSave){ e.preventDefault(); return o.onSave(); }
    }
    if (e.key === 'Tab'){ e.preventDefault(); return indent(!!e.shiftKey); }
    if (e.key === 'Enter' && !e.shiftKey && !meta){ if (listEnter()) e.preventDefault(); }
  });
  /* 源码滚动带动预览，避免两边脱节 */
  ta.addEventListener('scroll', function(){
    var max = (ta.scrollHeight || 0) - (ta.clientHeight || 0);
    if (max <= 0) return;
    var pmax = (prev.scrollHeight || 0) - (prev.clientHeight || 0);
    if (pmax > 0) prev.scrollTop = (ta.scrollTop / max) * pmax;
  });

  render();
  return {render:render, run:run, el:ta, preview:prev};
}
/* Esc 退出全屏（只挂一次） */
document.addEventListener('keydown', function(e){
  if (e.key !== 'Escape') return;
  var list = document.querySelectorAll('.md-editor.md-full');
  Array.prototype.forEach.call(list, function(el){ el.classList.remove('md-full'); });
});

function renderEditor(id){
  var isNew = (id === 'new' || id === undefined);
  var p = isNew ? {id:null, title:'', status:'draft', cat:db.categories[0]||'未分类', tags:[], md:'',
                   keywords:'', excerpt:'', cover:'', attach:'', date:nowStamp(), author:currentUser().name}
                : findPost(+id);
  if (!p){ location.hash='#/posts'; return; }
  var dateVal = p.date || nowStamp();
  var dateInitVal = dateToInput(dateVal);
  var coverSel = typeof p.cover === 'number' ? String(p.cover) : 'auto';
  var coverUrl = typeof p.cover === 'string' ? p.cover : '';
  var coverOpts = '<option value="auto"'+(coverSel==='auto'?' selected':'')+'>自动配色（按文章 ID）</option>' +
    CAT_COVERS.map(function(k,i){ return '<option value="'+i+'"'+(coverSel===String(i)?' selected':'')+'>'+k.label+'</option>'; }).join('');
  var draft = draftRead(isNew ? 'new' : p.id);
  var draftDiff = draft && (draft.md !== p.md || draft.title !== (p.title||''));
  view.innerHTML =
    '<div class="page-head reveal"><div><h1>'+(isNew?'撰写文章':'编辑文章 #'+p.id)+'</h1><p>支持 Markdown · 每 8 秒自动暂存 · 右侧实时预览</p></div>' +
    '<div class="head-actions">' +
      (isNew ? '' : '<button class="btn" id="verBtn">版本历史</button>') +
      '<button class="btn" onclick="location.hash=\'#/posts\'">返回列表</button>' +
      '<button class="btn primary" id="saveBtn">保存文章</button>' +
    '</div></div>' +
    (isNew ? '<div class="hexo-bar reveal" id="hexoBar" style="animation-delay:.02s" title="把 Hexo 导出的 .md 拖到这里">' +
      '<span class="hb-ic"><svg viewBox="0 0 24 24"><path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg></span>' +
      '<div class="hb-txt"><b>导入 Hexo / Markdown 文件</b>' +
        '<span>拖入或选择 .md，自动填好标题、日期、栏目、标签、封面与正文</span>' +
        '<div class="hb-notes" id="hexoNotes" hidden></div></div>' +
      '<button class="btn sm" id="hexoPick" type="button">选择 .md 文件</button>' +
      '<input type="file" id="hexoFile" accept=".md,.markdown,.txt,text/markdown" hidden>' +
    '</div>' : '') +
    '<div class="rev-banner" id="revBanner"'+(draftDiff ? '' : ' hidden')+'>' +
      '<span>发现 '+(draft ? esc(draft.at) : '')+' 的自动暂存，与当前内容不同。</span>' +
      '<button class="btn sm" id="asRestore">恢复暂存</button>' +
      '<button class="btn sm" id="asDrop">丢弃</button></div>' +
    '<div class="field-grid reveal" style="animation-delay:.03s">' +
      '<div class="field"><label>标题</label><input id="fTitle" value="'+esc(p.title)+'" placeholder="文章标题"></div>' +
      '<div class="field"><label>栏目</label><select id="fCat">' +
        db.categories.map(function(c){return '<option'+(c===p.cat?' selected':'')+'>'+esc(c)+'</option>'}).join('') +
      '</select></div>' +
      '<div class="field"><label>标签（逗号分隔）</label><input id="fTags" value="'+esc(p.tags.join(', '))+'" placeholder="设计, 前端"></div>' +
      '<div class="field"><label>状态</label><select id="fStatus">' +
        [{v:'draft',t:'草稿'},{v:'pending',t:'待审核'},{v:'publish',t:'发布'}].map(function(o){return '<option value="'+o.v+'"'+(p.status===o.v?' selected':'')+'>'+o.t+'</option>'}).join('') +
      '</select></div>' +
    '</div>' +
    '<div class="post-meta reveal" style="animation-delay:.04s">' +
      '<div class="field"><label>文章关键词</label><input id="fKeywords" value="'+esc(p.keywords||'')+'" placeholder="用英文逗号分隔，如 设计系统, 组件, 前端"></div>' +
      '<div class="field"><label>发布时间（默认当前）</label><input id="fDate" type="datetime-local" step="1" value="'+esc(dateInitVal)+'"></div>' +
      '<div class="field wide"><label>内容简介</label><textarea id="fExcerpt" placeholder="一两句话概括文章内容，用于列表摘要与分享卡片">'+esc(p.excerpt||'')+'</textarea></div>' +
    '</div>' +
    '<div class="post-extra reveal" style="animation-delay:.05s">' +
      '<div class="field"><label>封面图</label>' +
        '<div class="cover-line">' +
          '<select id="fCover">'+coverOpts+'</select>' +
          '<input id="fCoverUrl" value="'+esc(coverUrl)+'" placeholder="或填图片 URL">' +
          '<button class="btn" id="fCoverPick">媒体库</button>' +
          '<img id="fCoverPv" class="cover-pv" alt="封面预览" src="'+esc(postCoverSrc(p))+'">' +
        '</div></div>' +
      '<div class="field"><label>附件链接</label>' +
        '<div class="attach-line">' +
          '<input id="fAttach" value="'+esc(p.attach||'')+'" placeholder="如 /files/nebula-kit.zip，可留空">' +
          '<a class="btn attach-open" id="fAttachOpen" target="_blank" rel="noopener">打开 ↗</a>' +
        '</div></div>' +
    '</div>' +
    mdEditorHtml({ta:'fMd', prev:'mdPrev', delay:'.06s', value:p.md,
      headRight:'<span class="autosave-dot" id="asDot"></span><span id="asTxt">自动暂存开启</span>'});

  var ta = document.getElementById('fMd'), prev = document.getElementById('mdPrev');
  var MDED = mdMount({ta:'fMd', prev:'mdPrev',
    onSave: function(){ document.getElementById('saveBtn').click(); },
    onImage: function(insert){
      openMediaPicker(function(m){ insert(m.alt || m.file, '/uploads/' + m.file); }, {postId: p.id});
    }});
  function refresh(){ MDED.render(); }

  var coverEl = document.getElementById('fCover'), coverUrlEl = document.getElementById('fCoverUrl');
  var coverPv = document.getElementById('fCoverPv');
  function syncCover(){
    var u = coverUrlEl.value.trim();
    coverPv.src = u ? coverSrc(u) : catFormCoverSrc(coverEl.value, '');
  }
  coverEl.addEventListener('change', syncCover);
  coverUrlEl.addEventListener('input', syncCover);

  var attachEl = document.getElementById('fAttach'), attachOpen = document.getElementById('fAttachOpen');
  function syncAttach(){
    var v = attachEl.value.trim();
    if (v){
      attachOpen.setAttribute('href', v);
      attachOpen.className = 'btn attach-open';
    } else {
      attachOpen.removeAttribute('href');
      attachOpen.className = 'btn attach-open off';
    }
  }
  attachEl.addEventListener('change', syncAttach);
  syncAttach();

  /* 从媒体库选封面 */
  document.getElementById('fCoverPick').addEventListener('click', function(){
    openMediaPicker(function(m){
      coverEl.value = 'auto';
      coverUrlEl.value = '/uploads/' + m.file;
      coverPv.src = mediaUrl(m);
      toast('已选用媒体库图片：'+m.file, 'ok');
    }, {postId: p.id});
  });

  /* 版本历史 */
  if (!isNew){
    document.getElementById('verBtn').addEventListener('click', function(){
      openVersions(p, function(){ renderEditor(p.id); });
    });
  }

  /* ---------- 自动暂存 ---------- */
  clearInterval(AUTOSAVE_T);
  var asTxt = document.getElementById('asTxt');
  function snapshot(){
    return {
      title: document.getElementById('fTitle').value,
      cat: document.getElementById('fCat').value,
      tags: document.getElementById('fTags').value,
      status: document.getElementById('fStatus').value,
      keywords: document.getElementById('fKeywords').value,
      excerpt: document.getElementById('fExcerpt').value,
      md: ta.value, date: document.getElementById('fDate').value,
      cover: coverUrlEl.value, attach: attachEl.value
    };
  }
  function persistDraft(silent){
    var d = snapshot();
    if (!d.title && !d.md) return;
    draftWrite(isNew ? 'new' : p.id, d);
    if (asTxt) asTxt.textContent = '已自动暂存 ' + dateTime(nowStamp());
    if (!silent) toast('已自动暂存', 'ok');
  }
  AUTOSAVE_T = setInterval(function(){ persistDraft(true); }, 8000);

  var banner = document.getElementById('revBanner');
  if (banner && draftDiff){
    document.getElementById('asRestore').addEventListener('click', function(){
      var d = draftRead(isNew ? 'new' : p.id);
      if (!d) return;
      document.getElementById('fTitle').value = d.title || '';
      document.getElementById('fCat').value = d.cat || p.cat;
      document.getElementById('fTags').value = d.tags || '';
      document.getElementById('fStatus').value = d.status || p.status;
      document.getElementById('fKeywords').value = d.keywords || '';
      document.getElementById('fExcerpt').value = d.excerpt || '';
      coverUrlEl.value = d.cover || '';
      attachEl.value = d.attach || '';
      ta.value = d.md || '';
      syncCover(); syncAttach(); refresh();
      banner.hidden = true;
      toast('已恢复 '+d.at+' 的暂存内容，记得保存', 'ok');
    });
    document.getElementById('asDrop').addEventListener('click', function(){
      draftClear(isNew ? 'new' : p.id);
      banner.hidden = true;
      toast('已丢弃暂存', 'ok');
    });
  }

  /* ---------- 导入 Hexo Markdown（仅新建文章） ---------- */
  var hexoBar = document.getElementById('hexoBar');
  if (hexoBar){
    var hexoFileEl = document.getElementById('hexoFile');
    var hexoNotesEl = document.getElementById('hexoNotes');
    var hexoPickBtn = document.getElementById('hexoPick');

    function applyHexo(payload, fileName){
      var notes = [];
      document.getElementById('fTitle').value = payload.title;
      if (payload.date) document.getElementById('fDate').value = dateToInput(payload.date);
      document.getElementById('fTags').value = payload.tags.join(', ');
      if (payload.keywords) document.getElementById('fKeywords').value = payload.keywords;
      document.getElementById('fExcerpt').value = payload.excerpt || '';
      if (payload.status) document.getElementById('fStatus').value = payload.status;

      var catEl = document.getElementById('fCat');
      if (payload.cat && db.categories.indexOf(payload.cat) === -1){
        if (confirm('栏目「'+payload.cat+'」还不存在，导入时一并新建？\n\n点「取消」则先归入当前栏目「'+catEl.value+'」。')){
          db.categories.push(payload.cat);
          save();
          catEl.innerHTML = db.categories.map(function(c){ return '<option>'+esc(c)+'</option>'; }).join('');
          notes.push('已新建栏目「'+payload.cat+'」');
        }
      }
      if (payload.cat && db.categories.indexOf(payload.cat) > -1) catEl.value = payload.cat;
      /* Hexo 的多级分类在扁平栏目里放不下，其余几级转成标签 */
      if (payload.extraCats.length){
        document.getElementById('fTags').value = payload.tags.concat(payload.extraCats).join(', ');
        notes.push('多级分类「'+payload.extraCats.join(' / ')+'」已并入标签');
      }
      if (payload.cover){
        coverEl.value = 'auto'; coverUrlEl.value = payload.cover; syncCover();
        notes.push('封面已填入远程地址');
      } else if (payload.coverHint){
        notes.push('⚠ '+payload.coverHint);
      }
      if (payload.localImgs && payload.localImgs.length){
        notes.push('⚠ 正文有 '+payload.localImgs.length+' 张本地图片（如 '+payload.localImgs[0]+
          '），换成本站媒体库地址或外链才能正常显示');
      }
      ta.value = payload.md;
      refresh();
      persistDraft(true);                    /* 导入即暂存，误刷新也能找回 */

      notes.unshift('已导入 '+fileName+' · 正文 '+payload.chars+' 字'+
        (payload.date ? ' · 发布于 '+payload.date.slice(0,16) : '') +
        (payload.hasFm ? '' : ' · 未发现 Front Matter，仅导入正文'));
      if (payload.ignored.length){
        notes.push('忽略字段：'+payload.ignored.slice(0,6).join('、')+(payload.ignored.length > 6 ? ' 等' : ''));
      }
      hexoBar.classList.add('done');
      hexoPickBtn.textContent = '重新导入';
      hexoNotesEl.hidden = false;
      hexoNotesEl.innerHTML = notes.map(function(n, i){
        var warn = n.charAt(0) === '⚠';
        return (warn ? '<em>'+esc(n)+'</em>' : esc(n)) + (i === notes.length-1 ? '' : '<br>');
      }).join('');
      toast('已按 Hexo 文件填充表单，确认后点「保存文章」', 'ok');
    }

    function handleHexoFile(f){
      hexoReadFile(f, function(text){ applyHexo(hexoParse(text, f.name), f.name); });
    }

    hexoBar.addEventListener('click', function(e){
      if (e.target.closest('#hexoFile')) return;
      e.preventDefault();
      hexoFileEl.click();
    });
    hexoFileEl.addEventListener('change', function(){
      var f = this.files && this.files[0];
      if (f) handleHexoFile(f);
      this.value = '';                       /* 同一个文件可以再导一次 */
    });
    /* 整个编辑区都能接住拖拽；用 on* 赋值避免反复进页面累积监听 */
    view.ondragover = function(e){
      if (!e.dataTransfer) return;
      if (Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') === -1) return;
      e.preventDefault();                    /* 别让浏览器直接打开文件把页面顶掉 */
      var bar = document.getElementById('hexoBar');
      if (!bar) return;
      e.dataTransfer.dropEffect = 'copy';
      bar.classList.add('drag');
    };
    view.ondragleave = function(e){
      var bar = document.getElementById('hexoBar');
      if (bar && !view.contains(e.relatedTarget)) bar.classList.remove('drag');
    };
    view.ondrop = function(e){
      var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (!f) return;
      e.preventDefault();
      var bar = document.getElementById('hexoBar');
      if (!bar) return;
      bar.classList.remove('drag');
      if (e.dataTransfer.files.length > 1) toast('一次导入一篇，已取第一个文件');
      handleHexoFile(f);
    };
  }

  document.getElementById('saveBtn').addEventListener('click', function(){
    var title = document.getElementById('fTitle').value.trim();
    if (!title){ alert('标题不能为空'); return; }
    if (!isNew && p.status === 'publish' && !can('publish')){
      toast('当前角色不能修改已发布文章', 'bad'); return;
    }
    if (!isNew) pushRevision(p);          /* 保存前先留一版旧内容 */
    p.title = title;
    p.cat = document.getElementById('fCat').value;
    p.tags = document.getElementById('fTags').value.split(/[,，]/).map(function(s){return s.trim()}).filter(Boolean);
    p.status = document.getElementById('fStatus').value;
    if (p.status === 'publish' && !can('publish')){
      toast('当前角色无权直接发布，已存为待审核', 'bad');
      p.status = 'pending';
    }
    p.md = ta.value;
    p.keywords = document.getElementById('fKeywords').value.trim();
    p.excerpt = document.getElementById('fExcerpt').value.trim();
    var dateEl = document.getElementById('fDate');
    var prevDate = p.date, rawDate = dateEl.value, untouched = rawDate === dateInitVal;
    p.date = dateFromInput(rawDate) || prevDate || nowStamp();
    /* 新建且没手动改过时间：以点保存的时刻为准 */
    if (isNew && untouched) p.date = nowStamp();
    /* 老文章本来只到年月日，没改时间就别补成 00:00:00 */
    else if (!isNew && untouched && !dateTime(prevDate)) p.date = prevDate;
    var cvUrl = coverUrlEl.value.trim();
    p.cover = cvUrl ? cvUrl : (coverEl.value === 'auto' ? '' : +coverEl.value);
    p.attach = attachEl.value.trim();
    if (isNew){
      p.id = db.seq.post++;
      if (!p.author) p.author = currentUser().name;
      db.posts.unshift(p);
    } else {
      p.updated = today();
    }
    draftClear(isNew ? 'new' : p.id);
    clearInterval(AUTOSAVE_T);
    save();
    location.hash = '#/posts';
  });
}

/* ================= 视图：单页（添加 / 编辑） ================= */
var PGSTATE = {status:'all', q:'', page:1};
function filteredPages(){
  return db.pages.filter(function(p){
    if (p.status === 'trash') return false;   /* 回收站单页只在「回收管理」里出现 */
    if (PGSTATE.status !== 'all' && p.status !== PGSTATE.status) return false;
    if (PGSTATE.q){
      var hay = (p.title + ' ' + pagePath(p) + ' ' + p.id).toLowerCase();
      if (hay.indexOf(PGSTATE.q.toLowerCase()) === -1) return false;
    }
    return true;
  }).sort(function(a,b){ return b.id - a.id; });
}
function livePages(){ return db.pages.filter(function(p){ return p.status !== 'trash'; }); }
function pgCnt(s){ return db.pages.filter(function(p){ return p.status === s; }).length; }
function vPages(){
  view.innerHTML =
    '<div class="page-head reveal"><div><h1>单页</h1><p>共 '+livePages().length+' 个 · 固定页面不进入文章流，靠层级与导航位置组织「关于」「联系」等内容</p></div>' +
    '<div class="head-actions"><button class="btn primary" onclick="location.hash=\'#/page/new\'">+ 撰写单页</button></div></div>' +
    '<div class="filter-row reveal" style="animation-delay:.04s">' +
      '<div class="filter-tabs" id="pgtabs">' +
        '<button class="ftab'+(PGSTATE.status==='all'?' on':'')+'" data-pgs="all">全部 <span class="n">'+livePages().length+'</span></button>' +
        '<button class="ftab'+(PGSTATE.status==='publish'?' on':'')+'" data-pgs="publish">已发布 <span class="n">'+pgCnt('publish')+'</span></button>' +
        '<button class="ftab'+(PGSTATE.status==='draft'?' on':'')+'" data-pgs="draft">草稿 <span class="n">'+pgCnt('draft')+'</span></button>' +
      '</div>' +
      '<label class="search"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg><input id="pgq" value="'+esc(PGSTATE.q)+'" placeholder="搜索标题、路径或 ID…"></label>' +
    '</div>' +
    '<div class="card reveal" style="animation-delay:.08s"><div class="table-wrap"><table class="posts-table">' +
      '<thead><tr><th class="col-id">ID</th><th class="col-file">标题</th><th>状态</th><th>模板</th><th>访问路径</th><th>发布时间</th><th>操作</th></tr></thead>' +
      '<tbody id="pgbody"></tbody></table></div><div class="pager" id="pgpager"></div></div>';
  document.getElementById('pgq').addEventListener('input', function(){
    PGSTATE.q = this.value.trim(); PGSTATE.page = 1; renderPageRows();
  });
  document.getElementById('pgtabs').addEventListener('click', function(e){
    var b = e.target.closest('[data-pgs]'); if(!b) return;
    PGSTATE.status = b.dataset.pgs; PGSTATE.page = 1;
    document.querySelectorAll('#pgtabs .ftab').forEach(function(el){el.classList.remove('on');});
    b.classList.add('on'); renderPageRows();
  });
  view.onclick = pageActions;
  renderPageRows();
}
function renderPageRows(){
  var rows = filteredPages();
  var pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
  if (PGSTATE.page > pages) PGSTATE.page = pages;
  if (PGSTATE.page < 1) PGSTATE.page = 1;
  var start = (PGSTATE.page-1)*PAGE_SIZE;
  var slice = rows.slice(start, start+PAGE_SIZE);
  document.getElementById('pgbody').innerHTML = slice.length ? slice.map(function(p){
    return '<tr><td class="id-cell">'+p.id+'</td>' +
      '<td class="col-file"><a class="row-title" href="#/page-view/'+p.id+'">'+esc(p.title)+'</a>' +
        (p.inNav ? '<span class="pg-node">导航 '+p.navOrder+'</span>' : '') +
        (p.status!=='publish' ? '<span class="post-state">— '+STATUS[p.status]+'</span>' : '') + '</td>' +
      '<td><span class="pill '+PILL[p.status]+'">'+STATUS[p.status]+'</span></td>' +
      '<td class="pg-tpl">'+esc(pageTplLabel(p.template))+'</td>' +
      '<td class="pg-slug">'+esc(pagePath(p))+'</td>' +
      '<td class="date-cell">'+dateCell(p.date)+'</td>' +
      '<td><span class="row-actions">' +
        '<a href="#/page/'+p.id+'">编辑</a><span class="sep">|</span>' +
        '<a href="#/page-view/'+p.id+'">预览</a><span class="sep">|</span>' +
        '<a class="danger" data-pgdel="'+p.id+'">删除</a>' +
      '</span></td></tr>';
  }).join('') : '<tr class="empty-row"><td colspan="7">没有找到符合条件的单页</td></tr>';
  var html = '<span class="pg-info">共 '+rows.length+' 个' +
    (rows.length ? ' · 显示 '+(start+1)+'–'+(start+slice.length)+' 条' : '') + '</span>';
  html += '<button class="pg-nav" data-pgp="'+(PGSTATE.page-1)+'"'+(PGSTATE.page<=1?' disabled':'')+'>‹</button>';
  for (var i=1;i<=pages;i++) html += i===PGSTATE.page ? '<span class="page-num">'+i+'</span>' : '<button data-pgp="'+i+'">'+i+'</button>';
  html += '<button class="pg-nav" data-pgp="'+(PGSTATE.page+1)+'"'+(PGSTATE.page>=pages?' disabled':'')+'>›</button>';
  document.getElementById('pgpager').innerHTML = html;
}
function pageActions(e){
  var pg = e.target.closest('.pager button[data-pgp]');
  if (pg && !pg.disabled){ PGSTATE.page = +pg.dataset.pgp; renderPageRows(); return; }
  var a = e.target.closest('[data-pgdel]');
  if (!a) return;
  e.preventDefault();
  var p = findPage(+a.dataset.pgdel); if (!p) return;
  if (!can('trash')){ toast('当前角色无权删除单页', 'bad'); return; }
  var kids = db.pages.filter(function(x){ return x.parent === p.id && x.status !== 'trash'; });
  if (!confirm('把单页《'+p.title+'》移入回收站？' +
      (kids.length ? '\n\n它的 '+kids.length+' 个子页面会被上提为顶级页面。' : '') +
      '\n\n可在「回收管理」中恢复，超过 '+TRASH_DAYS+' 天自动永久删除。')) return;
  moveToTrash('page', p);
  db.pages.forEach(function(x){ if (x.parent === p.id) x.parent = 0; });
  save(); vPages();
}
function renderPageEditor(id){
  var isNew = (id === 'new' || id == null);
  var p = isNew ? {id:null, title:'', slug:'', parent:0, template:'page', status:'draft', md:'',
                   keywords:'', excerpt:'', cover:'', attach:'', date:nowStamp(),
                   inNav:false, navOrder:0, visible:'public', hideTitle:false, wide:false}
                : findPage(+id);
  if (!p){ location.hash='#/pages'; return; }
  var dateVal = p.date || nowStamp();
  var dateInitVal = dateToInput(dateVal);
  var coverSel = typeof p.cover === 'number' ? String(p.cover) : 'auto';
  var coverUrl = typeof p.cover === 'string' ? p.cover : '';
  var coverOpts = '<option value="auto"'+(coverSel==='auto'?' selected':'')+'>自动配色（按页面 ID）</option>' +
    CAT_COVERS.map(function(k,i){ return '<option value="'+i+'"'+(coverSel===String(i)?' selected':'')+'>'+k.label+'</option>'; }).join('');
  var tplOpts = PAGE_TPLS.map(function(t){
    return '<option value="'+t.v+'"'+(p.template===t.v?' selected':'')+'>'+esc(t.label)+'</option>';
  }).join('');
  var visOpts = PAGE_VISIBLE.map(function(v){
    return '<option value="'+v.v+'"'+(p.visible===v.v?' selected':'')+'>'+esc(v.label)+'</option>';
  }).join('');
  var slugVal = p.slug || '';
  var parentId = p.parent || 0;
  view.innerHTML =
    '<div class="page-head reveal"><div><h1>'+(isNew?'撰写单页':'编辑单页 #'+p.id)+'</h1>' +
      '<p>固定页面不进入文章流 · 靠层级与导航位置进入站点结构 · 右侧实时预览</p></div>' +
    '<div class="head-actions">' +
      '<button class="btn" onclick="location.hash=\'#/pages\'">返回列表</button>' +
      '<button class="btn primary" id="pgSave">保存单页</button>' +
    '</div></div>' +
    '<div class="field-grid reveal" style="animation-delay:.03s">' +
      '<div class="field"><label>页面标题</label><input id="fPgTitle" value="'+esc(p.title)+'" placeholder="如：关于我们"></div>' +
      '<div class="field"><label>页面别名（URL）</label><input id="fPgSlug" value="'+esc(slugVal)+'" placeholder="如 about，留空自动生成">' +
        '<div class="set-hint" id="pgSlugHint">访问地址：'+esc(pathPreview(parentId, slugVal))+'</div></div>' +
      '<div class="field"><label>上级页面</label><select id="fPgParent">'+pageParentOpts(isNew ? 0 : p.id, parentId)+'</select></div>' +
      '<div class="field"><label>页面模板</label><select id="fPgTpl">'+tplOpts+'</select></div>' +
    '</div>' +
    '<div class="pg-row reveal" style="animation-delay:.04s">' +
      '<div class="field"><label>状态</label><select id="fPgStatus">' +
        ['draft','publish'].map(function(s){return '<option value="'+s+'"'+(p.status===s?' selected':'')+'>'+(s==='draft'?'草稿':'发布')+'</option>'}).join('') +
      '</select></div>' +
      '<div class="field"><label>访问权限</label><select id="fPgVisible">'+visOpts+'</select></div>' +
      '<div class="field"><label>导航排序</label>' +
        '<input id="fPgOrder" type="number" min="0" step="1" value="'+(p.navOrder||0)+'">' +
        '<div class="set-hint" id="pgOrderHint">数字小的排在前面</div></div>' +
    '</div>' +
    '<div class="post-meta reveal" style="animation-delay:.05s">' +
      '<div class="field"><label>页面关键词</label><input id="fPgKeywords" value="'+esc(p.keywords||'')+'" placeholder="用英文逗号分隔，如 关于, 团队"></div>' +
      '<div class="field"><label>发布时间（默认当前）</label><input id="fPgDate" type="datetime-local" step="1" value="'+esc(dateInitVal)+'"></div>' +
      '<div class="field wide"><label>页面描述（SEO description）</label><textarea id="fPgExcerpt" placeholder="一两句话说明这个页面的内容，搜索结果与分享卡片会用这段文字">'+esc(p.excerpt||'')+'</textarea></div>' +
    '</div>' +
    '<div class="post-extra reveal" style="animation-delay:.06s">' +
      '<div class="field"><label>页面头图</label>' +
        '<div class="cover-line">' +
          '<select id="fPgCover">'+coverOpts+'</select>' +
          '<input id="fPgCoverUrl" value="'+esc(coverUrl)+'" placeholder="或填图片 URL">' +
          '<button class="btn" id="fPgCoverPick">媒体库</button>' +
          '<img id="fPgCoverPv" class="cover-pv" alt="头图预览" src="'+esc(postCoverSrc(p))+'">' +
        '</div></div>' +
      '<div class="field"><label>附件链接</label>' +
        '<div class="attach-line">' +
          '<input id="fPgAttach" value="'+esc(p.attach||'')+'" placeholder="如 /files/price-list.pdf，可留空">' +
          '<a class="btn attach-open" id="fPgAttachOpen" target="_blank" rel="noopener">打开 ↗</a>' +
        '</div></div>' +
    '</div>' +
    '<div class="pg-switches reveal" style="animation-delay:.07s">' +
      '<label class="pg-sw"><input type="checkbox" id="fPgNav"'+(p.inNav?' checked':'')+'>' +
        '<span><b>加入导航菜单</b><em>在站点主菜单里给这一页留一个入口</em></span></label>' +
      '<label class="pg-sw"><input type="checkbox" id="fPgHideTitle"'+(p.hideTitle?' checked':'')+'>' +
        '<span><b>隐藏页面标题</b><em>正文自带标题时避免重复（落地页常用）</em></span></label>' +
      '<label class="pg-sw"><input type="checkbox" id="fPgWide"'+(p.wide?' checked':'')+'>' +
        '<span><b>通栏宽度</b><em>取消正文两侧留白，适合表格与图墙</em></span></label>' +
    '</div>' +
    mdEditorHtml({ta:'fPgMd', prev:'pgPrev', delay:'.08s', value:p.md});

  var ta = document.getElementById('fPgMd'), prev = document.getElementById('pgPrev');
  var PGED = mdMount({ta:'fPgMd', prev:'pgPrev',
    onSave: function(){ document.getElementById('pgSave').click(); },
    onImage: function(insert){
      openMediaPicker(function(m){ insert(m.alt || m.file, '/uploads/' + m.file); }, {postId: p.id});
    }});
  function refresh(){ PGED.render(); }

  var slugEl = document.getElementById('fPgSlug'), slugHint = document.getElementById('pgSlugHint');
  var parentEl = document.getElementById('fPgParent');
  function syncPath(){
    slugHint.textContent = '访问地址：' + pathPreview(parentEl.value, cleanPageSlug(slugEl.value));
  }
  slugEl.addEventListener('input', syncPath);
  parentEl.addEventListener('change', syncPath);

  var orderEl = document.getElementById('fPgOrder'), orderHint = document.getElementById('pgOrderHint');
  var navEl = document.getElementById('fPgNav');
  function syncOrder(){
    orderEl.disabled = !navEl.checked;
    orderHint.textContent = navEl.checked ? '数字小的排在前面' : '未加入导航，排序不生效';
  }
  navEl.addEventListener('change', syncOrder);
  syncOrder();

  var coverEl = document.getElementById('fPgCover'), coverUrlEl = document.getElementById('fPgCoverUrl');
  var coverPv = document.getElementById('fPgCoverPv');
  function syncCover(){
    var u = coverUrlEl.value.trim();
    coverPv.src = u ? coverSrc(u) : catFormCoverSrc(coverEl.value, '');
  }
  coverEl.addEventListener('change', syncCover);
  coverUrlEl.addEventListener('input', syncCover);
  document.getElementById('fPgCoverPick').addEventListener('click', function(){
    openMediaPicker(function(m){
      coverEl.value = 'auto';
      coverUrlEl.value = '/uploads/' + m.file;
      syncCover();
      toast('已选用媒体库图片：'+m.file, 'ok');
    }, {postId: p.id});
  });

  var attachEl = document.getElementById('fPgAttach'), attachOpen = document.getElementById('fPgAttachOpen');
  function syncAttach(){
    var v = attachEl.value.trim();
    if (v){ attachOpen.setAttribute('href', v); attachOpen.className = 'btn attach-open'; }
    else { attachOpen.removeAttribute('href'); attachOpen.className = 'btn attach-open off'; }
  }
  attachEl.addEventListener('change', syncAttach);
  syncAttach();

  document.getElementById('pgSave').addEventListener('click', function(){
    var title = document.getElementById('fPgTitle').value.trim();
    if (!title){ alert('页面标题不能为空'); return; }
    var wantParent = +parentEl.value || 0;
    /* 兜底：不允许把自己设成父级，也不允许挂到自己的后代下面 */
    if (!isNew && (wantParent === p.id || pageDescendants(p.id).indexOf(wantParent) > -1)) wantParent = 0;
    p.title = title;
    p.template = document.getElementById('fPgTpl').value;
    p.status = document.getElementById('fPgStatus').value;
    p.parent = wantParent;
    p.visible = document.getElementById('fPgVisible').value;
    p.inNav = navEl.checked;
    p.navOrder = Math.max(0, parseInt(orderEl.value, 10) || 0);
    p.hideTitle = document.getElementById('fPgHideTitle').checked;
    p.wide = document.getElementById('fPgWide').checked;
    p.md = ta.value;
    p.keywords = document.getElementById('fPgKeywords').value.trim();
    p.excerpt = document.getElementById('fPgExcerpt').value.trim();
    var dateEl = document.getElementById('fPgDate');
    var prevDate = p.date, rawDate = dateEl.value, untouched = rawDate === dateInitVal;
    p.date = dateFromInput(rawDate) || prevDate || nowStamp();
    if (isNew && untouched) p.date = nowStamp();
    else if (!isNew && untouched && !dateTime(prevDate)) p.date = prevDate;
    var cvUrl = coverUrlEl.value.trim();
    p.cover = cvUrl ? cvUrl : (coverEl.value === 'auto' ? '' : +coverEl.value);
    p.attach = attachEl.value.trim();
    if (isNew){
      p.id = db.seq.page++;
      p.slug = uniquePageSlug(slugEl.value, null) || uniquePageSlug('page-' + p.id, null);
      db.pages.unshift(p);
    } else {
      var oldUrl = pagePath(p);
      p.slug = uniquePageSlug(slugEl.value, p.id) || p.slug;
      p.updated = today();
      var newUrl = pagePath(p);
      if (newUrl !== oldUrl){
        toast('别名已变更，旧地址 '+oldUrl+' 将失效', 'warn');
      }
    }
    save();
    location.hash = '#/pages';
  });
}
function renderPageView(id){
  var p = findPage(id);
  if (!p){ view.innerHTML = '<div class="page-head"><h1>单页不存在</h1></div>'; return; }
  var hasCover = (typeof p.cover === 'string' && p.cover) || typeof p.cover === 'number';
  var parent = p.parent ? findPage(p.parent) : null;
  view.innerHTML =
    '<div class="preview-wrap'+(p.wide?' wide':'')+' reveal"><div class="article">' +
      (parent ? '<div class="crumb"><a href="#/page-view/'+parent.id+'">'+esc(parent.title)+'</a>' +
        '<span>/</span><span>'+esc(p.title)+'</span></div>' : '') +
      (p.hideTitle ? '' : '<h1>'+esc(p.title)+'</h1>') +
      (hasCover ? '<img class="article-cover" alt="'+esc(p.title)+' 头图" src="'+esc(postCoverSrc(p))+'">' : '') +
      '<div class="meta"><span>'+esc(p.author || db.settings.author)+'</span><span>·</span><span>'+esc(p.date)+'</span><span>·</span>' +
        '<span class="pill pub">'+esc(pageTplLabel(p.template))+'</span>' +
        '<span class="pill pub">'+esc(pagePath(p))+'</span>' +
        (p.attach ? '<a class="pill attach-pill" href="'+esc(p.attach)+'" target="_blank" rel="noopener">附件下载 ↗</a>' : '') +
        (p.visible === 'member' ? '<span class="pill pending">仅会员可见</span>' : '') +
        (p.status!=='publish' ? '<span class="pill '+PILL[p.status]+'">'+STATUS[p.status]+'（仅后台可见）</span>' : '') +
        '<span style="margin-left:auto"><a href="#/pages" style="color:var(--accent);text-decoration:none">← 返回列表</a></span></div>' +
      (p.excerpt ? '<p class="article-lede">'+esc(p.excerpt)+'</p>' : '') +
      md(p.md) +
    '</div></div>' +
    '<div class="foot-note"><span>'+esc(db.settings.siteName)+' · '+esc(db.settings.slogan)+'</span><span>CiciCms</span></div>';
}

/* ================= 视图：媒体库 ================= */
var MSTATE = {page:1, type:'all', q:''};
var UP_MAX = 400 * 1024;   /* 单张图片上限，与演示存储配额匹配 */
function mediaUrl(m){ return (m && m.data) ? m.data : '/uploads/' + (m ? m.file : ''); }
function mediaThumbSrc(m){
  if (m && m.data) return m.data;
  return m && m.type === 'image' ? imgThumb(m) : zipThumb(m);
}
/* 把 /uploads/xxx.png 这类虚拟地址解析成本地媒体数据（未上传的真实 URL 原样返回） */
function coverSrc(v){
  if (typeof v !== 'string' || !v) return '';
  var m = (db.media || []).filter(function(x){
    return !x.trashed && ('/uploads/' + x.file === v || x.data === v);
  })[0];
  return m ? mediaUrl(m) : v;
}
/* 该媒体被哪些文章 / 单页引用 */
function mediaRefs(m){
  var keys = [m.data, '/uploads/' + m.file, m.file].filter(Boolean);
  var out = [];
  function hit(bag){
    var s = String(bag || '');
    return keys.some(function(k){ return k && s.indexOf(k) > -1; });
  }
  (db.posts || []).forEach(function(p){
    if (p.status === 'trash') return;
    if (hit([p.cover, p.attach, p.md, p.excerpt].join(' '))) out.push({kind:'文章', id:p.id, title:p.title});
  });
  (db.pages || []).forEach(function(g){
    if (g.status === 'trash') return;
    if (hit([g.cover, g.attach, g.md, g.excerpt].join(' '))) out.push({kind:'单页', id:g.id, title:g.title});
  });
  return out;
}
function filteredMedia(){
  return db.media.filter(function(m){
    if (m.trashed) return false;                 /* 已删除的在回收站里 */
    if (MSTATE.type !== 'all' && m.type !== MSTATE.type) return false;
    if (MSTATE.q){
      var p = m.post != null ? findPost(m.post) : null;
      var hay = (m.file + ' ' + m.dim + ' ' + (m.alt||'') + ' ' + (m.post != null ? m.post : '') + ' ' + (p ? p.title : '')).toLowerCase();
      if (hay.indexOf(MSTATE.q) === -1) return false;
    }
    return true;
  });
}
function mediaRow(m){
  var post = m.post != null ? findPost(m.post) : null;
  return '<tr><td class="id-cell">'+m.id+'</td>' +
    '<td class="col-file"><div style="display:flex;align-items:center;justify-content:flex-start;gap:11px">' +
      '<div style="width:66px;height:50px;flex:0 0 66px;border-radius:9px;overflow:hidden;border:1px solid var(--line)">' +
        '<img alt="'+esc(m.alt||'')+'" src="'+mediaThumbSrc(m)+'" style="width:100%;height:100%;object-fit:cover;display:block"></div>' +
      '<div style="text-align:left;min-width:0"><div style="font-weight:600;font-size:13.5px">'+esc(m.file)+'</div>' +
      '<div style="color:var(--muted);font-size:11.5px">'+esc(m.dim)+' · '+String(m.file).split('.').pop().toUpperCase()+'</div>' +
      (m.alt ? '<span class="m-alt" title="'+esc(m.alt)+'">alt：'+esc(m.alt)+'</span>' : '') +
      '</div></div></td>' +
    '<td><span class="pill '+(m.type==='image'?'pub':'pending')+'">'+(m.type==='image'?'图片':'压缩包')+'</span></td>' +
    '<td class="id-cell">'+m.size+'</td>' +
    '<td class="id-cell">'+(m.post!=null ? m.post : '—')+'</td>' +
    '<td class="col-post-title">'+(post ? esc(post.title) : '—')+'</td>' +
    '<td class="date-cell">'+m.date+'</td>' +
    '<td><span class="row-actions"><a data-malt="'+m.id+'">alt</a><span class="sep">|</span>' +
      '<a class="danger" data-mdel="'+m.id+'">删除</a></span></td></tr>';
}
/* 页码窗口：超过 7 页时用省略号收拢首尾 */
function pageList(cur, total){
  var out = [], i;
  if (total <= 7){ for (i=1;i<=total;i++) out.push(i); return out; }
  out.push(1);
  var s = Math.max(2, cur-1), e = Math.min(total-1, cur+1);
  if (s > 2) out.push('…');
  for (i=s;i<=e;i++) out.push(i);
  if (e < total-1) out.push('…');
  out.push(total);
  return out;
}
function renderMediaRows(){
  var rows = filteredMedia(), per = PAGE_SIZE;
  var pages = Math.max(1, Math.ceil(rows.length / per));
  if (MSTATE.page > pages) MSTATE.page = pages;
  if (MSTATE.page < 1) MSTATE.page = 1;
  var start = (MSTATE.page-1)*per;
  var slice = rows.slice(start, start+per);
  document.getElementById('mbody').innerHTML = slice.length ? slice.map(mediaRow).join('')
    : '<tr class="empty-row"><td colspan="8">没有找到符合条件的文件</td></tr>';
  var html = '<span class="pg-info">共 '+rows.length+' 个文件' +
    (rows.length ? ' · 显示 '+(start+1)+'–'+(start+slice.length)+' 条' : '') + '</span>' +
    '<span class="pg-size">每页 '+per+' 条</span>' +
    '<button class="pg-nav" data-mp="'+(MSTATE.page-1)+'"'+(MSTATE.page<=1?' disabled':'')+'>‹</button>';
  pageList(MSTATE.page, pages).forEach(function(i){
    html += i === '…' ? '<span class="pg-dots">…</span>'
      : i === MSTATE.page ? '<span class="page-num">'+i+'</span>'
      : '<button data-mp="'+i+'">'+i+'</button>';
  });
  html += '<button class="pg-nav" data-mp="'+(MSTATE.page+1)+'"'+(MSTATE.page>=pages?' disabled':'')+'>›</button>';
  document.getElementById('mpager').innerHTML = html;
}
function vMedia(){
  var allImg = db.media.filter(function(m){return m.type==='image' && !m.trashed}).length;
  var allZip = db.media.filter(function(m){return m.type==='zip' && !m.trashed}).length;
  var live = allImg + allZip;
  view.innerHTML =
    '<div class="page-head reveal"><div><h1>媒体</h1><p>共 '+live+' 个文件 · 图片 '+allImg+' / 压缩包 '+allZip+' · 均为文章编辑时上传，删除后进入回收站</p></div></div>' +
    '<div class="filter-row reveal" style="animation-delay:.02s">' +
      '<div class="filter-tabs" id="mtabs">' +
        '<button class="ftab'+(MSTATE.type==='all'?' on':'')+'" data-mt="all">全部 <span class="n">'+live+'</span></button>' +
        '<button class="ftab'+(MSTATE.type==='image'?' on':'')+'" data-mt="image">图片 <span class="n">'+allImg+'</span></button>' +
        '<button class="ftab'+(MSTATE.type==='zip'?' on':'')+'" data-mt="zip">压缩包 <span class="n">'+allZip+'</span></button>' +
      '</div>' +
      '<label class="search"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>' +
        '<input id="mq" value="'+esc(MSTATE.q)+'" placeholder="搜索文件名 / alt / 文章…"></label>' +
    '</div>' +
    '<div class="card reveal" id="mediaCard" style="animation-delay:.06s"><div class="table-wrap"><table class="media-table">' +
      '<thead><tr><th class="col-id">ID</th><th class="col-file">文件</th><th>类型</th><th>大小</th><th>文章 ID</th><th class="col-post-title">文章标题</th><th>上传日期</th><th>操作</th></tr></thead>' +
      '<tbody id="mbody"></tbody></table></div>' +
      '<div class="pager media-pager" id="mpager"></div></div>';

  document.getElementById('mtabs').addEventListener('click', function(e){
    var b = e.target.closest('[data-mt]'); if (!b) return;
    MSTATE.type = b.dataset.mt; MSTATE.page = 1;
    document.querySelectorAll('#mtabs .ftab').forEach(function(el){ el.classList.remove('on'); });
    b.classList.add('on'); renderMediaRows();
  });
  document.getElementById('mq').addEventListener('input', function(){
    MSTATE.q = this.value.trim().toLowerCase(); MSTATE.page = 1; renderMediaRows();
  });
  /* 事件代理绑在媒体卡片上，卡片每次重建时旧监听器随之释放，不会累积 */
  var card = document.getElementById('mediaCard');
  card.addEventListener('click', function(e){
    var pg = e.target.closest('button[data-mp]');
    if (pg && !pg.disabled){ MSTATE.page = +pg.dataset.mp; renderMediaRows(); return; }
    var al = e.target.closest('[data-malt]');
    if (al){
      var mm = db.media.filter(function(x){ return x.id === +al.dataset.malt; })[0];
      if (!mm) return;
      var v = prompt('替代文本（alt）', mm.alt || '');
      if (v === null) return;
      mm.alt = v.trim(); save(); vMedia(); toast('alt 已更新', 'ok');
      return;
    }
    var d = e.target.closest('[data-mdel]');
    if (d){
      if (!can('media')){ toast('当前角色无权删除媒体文件', 'bad'); return; }
      var m = db.media.filter(function(x){ return x.id === +d.dataset.mdel; })[0];
      if (!m) return;
      var refs = mediaRefs(m);
      var msg = '把「'+m.file+'」移入回收站？';
      if (refs.length){
        msg += '\n\n有 '+refs.length+' 处内容正在引用它：\n' +
          refs.slice(0, 5).map(function(r){ return '· '+r.kind+'《'+r.title+'》'; }).join('\n') +
          (refs.length > 5 ? '\n· …' : '') +
          '\n\n删除后这些位置会出现空图或失效链接。';
      }
      if (!confirm(msg)) return;
      moveToTrash('media', m); save(); vMedia();
      toast('已移入回收站，可恢复', 'ok');
    }
  });
  renderMediaRows();
}

/* 文章编辑时的图片上传：媒体库唯一的入库入口 */
function uploadMedia(file, opts, done){
  opts = opts || {};
  if (!file){ if (done) done(null); return; }
  var isImg = /^image\//.test(file.type) || /\.(jpe?g|png|webp|gif)$/i.test(file.name);
  var isZip = /zip/.test(file.type) || /\.zip$/i.test(file.name);
  if (!isImg && !isZip){ alert('只支持图片与 zip 压缩包：' + file.name); if (done) done(null); return; }
  if (isImg && file.size > UP_MAX){ alert(file.name + ' 超过 400 KB，请先压缩后再上传。'); if (done) done(null); return; }

  function finish(data, dim){
    var addBytes = data ? Math.round(data.length * 0.75) : 0;
    if (byteLen(JSON.stringify(db)) + addBytes > 4.2 * 1024 * 1024){
      alert('本地存储空间不足，请先清理回收站或旧备份');
      if (done) done(null);
      return;
    }
    var pal = [['#6366f1','#8b8bf5','#c7d2fe'],['#0ea5a5','#2dd4bf','#ccfbf1'],['#d97706','#f59e0b','#fde9c8'],['#3b82f6','#60a5fa','#dbeafe']][db.media.length % 4];
    var m = {
      id: db.seq.media++, file: file.name, type: isImg ? 'image' : 'zip', dim: dim,
      size: fmtSize(file.size), alt: opts.alt || '', data: data || '',
      post: opts.post || null, date: today(),
      variant: ['mount','wave','grid','ring','beam'][db.media.length % 5],
      c: isImg ? pal : [pal[0], pal[1]]
    };
    db.media.unshift(m);
    save();
    if (done) done(m);
  }

  if (!isImg) return finish('', '压缩包');
  if (typeof FileReader === 'undefined') return finish('', '图片');
  var r = new FileReader();
  r.onload = function(){
    var data = r.result;
    if (typeof Image === 'undefined') return finish(data, '图片');
    var im = new Image();
    im.onload = function(){ finish(data, im.width + ' × ' + im.height); };
    im.onerror = function(){ finish(data, '未知尺寸'); };
    im.src = data;
  };
  r.readAsDataURL(file);
}

/* ================= 浮层：媒体选择器 ================= */
function ovClose(box){
  if (box && box.parentNode) box.parentNode.removeChild(box);
}
function openMediaPicker(onPick, opts){
  opts = opts || {};
  var imgs = db.media.filter(function(m){ return m.type === 'image' && !m.trashed; });
  var box = document.createElement('div');
  box.className = 'ov';
  box.innerHTML = '<div class="ov-card">' +
    '<div class="ov-head"><h3>插入图片</h3><span class="ov-sub">媒体库 '+imgs.length+' 张</span>' +
      '<label class="btn sm" id="ovUpLab" style="margin-left:10px;cursor:pointer">+ 上传新图' +
        '<input type="file" id="ovUp" accept="image/*" hidden></label>' +
      '<button class="ov-x" data-ovx>×</button></div>' +
    '<div class="ov-body">' +
      (imgs.length ? '<div class="pick-grid">' + imgs.map(function(m, i){
        return '<button class="pick-item" data-pick="'+i+'">' +
          '<img alt="'+esc(m.alt||'')+'" src="'+mediaThumbSrc(m)+'">' +
          '<span class="pi-meta"><span class="pi-name">'+esc(m.file)+'</span>' +
          '<span class="pi-alt">'+esc(m.alt || m.dim)+'</span></span></button>';
      }).join('') + '</div>'
        : '<div class="pick-empty">还没有图片。点上方「上传新图」，随文章一起入库。</div>') +
    '</div>' +
    '<div class="ov-foot"><span style="margin-right:auto;font-size:11.5px;color:var(--muted)">选中后插入 /uploads/文件名，并记录所属文章</span>' +
      '<button class="btn sm" data-ovx>取消</button></div>' +
    '</div>';
  document.body.appendChild(box);

  var upEl = box.querySelector('#ovUp');
  if (upEl) upEl.addEventListener('change', function(){
    var f = this.files && this.files[0];
    this.value = '';
    if (!f) return;
    uploadMedia(f, {post: opts.postId || null}, function(m){
      if (!m) return;
      ovClose(box);
      if (onPick) onPick(m);
      toast('已上传并选用 ' + m.file, 'ok');
    });
  });
  box.addEventListener('click', function(e){
    if (e.target === box || e.target.closest('[data-ovx]')){ ovClose(box); return; }
    if (e.target.closest('#ovUpLab')) return;
    var pick = e.target.closest('[data-pick]');
    if (pick){
      var m = imgs[+pick.dataset.pick];
      ovClose(box);
      if (m && onPick) onPick(m);
    }
  });
}

/* ================= 浮层：版本历史 ================= */
function openVersions(p, onRestore){
  var list = postRevisions(p.id);
  var box = document.createElement('div');
  box.className = 'ov';
  box.innerHTML = '<div class="ov-card">' +
    '<div class="ov-head"><h3>版本历史</h3><span class="ov-sub">《'+esc(p.title)+'》· 保留最近 5 版</span>' +
      '<button class="ov-x" data-ovx>×</button></div>' +
    '<div class="ov-body"><div class="rev-list">' +
      (list.length ? list.map(function(r){
        return '<div class="rev-item" data-rv="'+r.id+'">' +
          '<div class="ri-main"><div class="ri-t">'+esc(r.at)+' · '+esc(r.by||'—')+'</div>' +
            '<div class="ri-m">'+esc(STATUS[r.status]||r.status)+' · '+r.md.length+' 字符 · '+
              (r.md.split('\n').length)+' 行</div></div>' +
          '<div class="ri-act"><button class="btn sm" data-rvp="'+r.id+'">预览</button>' +
            '<button class="btn sm primary" data-rvr="'+r.id+'">恢复此版</button></div>' +
          '<div class="rev-preview" data-prev="'+r.id+'" hidden></div></div>';
      }).join('') : '<div class="pick-empty">还没有历史版本，保存一次后就会记录。</div>') +
    '</div></div>' +
    '<div class="ov-foot"><span style="margin-right:auto;font-size:11.5px;color:var(--muted)">恢复会先把当前内容存为一版，不会丢字</span>' +
      '<button class="btn sm" data-ovx>关闭</button></div></div>';
  document.body.appendChild(box);
  box.addEventListener('click', function(e){
    if (e.target === box || e.target.closest('[data-ovx]')){ ovClose(box); return; }
    var pv = e.target.closest('[data-rvp]');
    if (pv){
      var cell = box.querySelector('[data-prev="'+pv.dataset.rvp+'"]');
      var rev = list.filter(function(r){ return r.id === +pv.dataset.rvp; })[0];
      if (cell && rev){
        cell.hidden = !cell.hidden;
        cell.textContent = rev.md.slice(0, 1200) + (rev.md.length > 1200 ? '\n…' : '');
      }
      return;
    }
    var rv = e.target.closest('[data-rvr]');
    if (rv){
      var r = list.filter(function(x){ return x.id === +rv.dataset.rvr; })[0];
      if (!r) return;
      if (!confirm('恢复到 '+r.at+' 的版本？当前内容会先存为一个历史版本。')) return;
      pushRevision(p);
      p.title = r.title; p.md = r.md; p.status = r.status;
      if (r.cat) p.cat = r.cat;
      if (Array.isArray(r.tags)) p.tags = r.tags.slice();
      p.updated = today();
      save();
      ovClose(box);
      toast('已恢复到 '+r.at+' 的版本', 'ok');
      if (onRestore) onRestore();
    }
  });
}

/* ================= 浮层：全局搜索 ⌘K ================= */
function searchIndex(){
  var out = [];
  livePosts().forEach(function(p){
    out.push({g:'文章', ic:'文', t:p.title, s:'#'+p.id+' · '+p.cat, href:'#/edit/'+p.id,
      hay:(p.title+' '+p.cat+' '+p.tags.join(' ')+' '+(p.keywords||'')).toLowerCase()});
  });
  livePages().forEach(function(p){
    out.push({g:'单页', ic:'页', t:p.title, s:pagePath(p), href:'#/page/'+p.id,
      hay:(p.title+' '+pagePath(p)+' '+(p.keywords||'')).toLowerCase()});
  });
  db.media.filter(function(m){ return !m.trashed; }).forEach(function(m){
    out.push({g:'媒体', ic:'媒', t:m.file, s:m.size+' · '+(m.alt||'无 alt'), href:'#/media',
      hay:(m.file+' '+(m.alt||'')).toLowerCase()});
  });
  db.categories.forEach(function(c){
    out.push({g:'栏目', ic:'栏', t:c, s:catUrl(c), href:'#/taxonomy/edit',
      hay:(c+' '+catSlug(c)).toLowerCase()});
  });
  return out;
}
function openSearch(){
  if (document.getElementById('cmdkBox')) return;
  var all = searchIndex();
  var box = document.createElement('div');
  box.className = 'cmdk'; box.id = 'cmdkBox';
  box.innerHTML = '<div class="cmdk-card">' +
    '<div class="cmdk-in"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>' +
      '<input id="cmdkInput" placeholder="搜索文章、单页、媒体、栏目…" autocomplete="off"><kbd>ESC</kbd></div>' +
    '<div class="cmdk-res" id="cmdkRes"></div>' +
    '<div class="cmdk-foot"><span>↑ ↓ 选择</span><span>Enter 打开</span><span>⌘K 唤起</span></div></div>';
  document.body.appendChild(box);
  var input = document.getElementById('cmdkInput');
  var res = document.getElementById('cmdkRes');
  var items = [], active = 0;
  function paint(){
    var q = (input.value || '').trim().toLowerCase();
    items = q ? all.filter(function(x){ return x.hay.indexOf(q) > -1; }).slice(0, 24) : all.slice(0, 12);
    if (active >= items.length) active = 0;
    if (!items.length){
      res.innerHTML = '<div class="cmdk-empty">没有找到「'+esc(q)+'」相关的条目</div>';
      return;
    }
    var lastG = '';
    res.innerHTML = items.map(function(x, i){
      var head = x.g !== lastG ? '<div class="cmdk-group">'+x.g+'</div>' : '';
      lastG = x.g;
      return head + '<a class="cmdk-item'+(i===active?' on':'')+'" data-ci="'+i+'" href="'+x.href+'">' +
        '<span class="ci-ic">'+x.ic+'</span><span class="ci-t">'+esc(x.t)+'</span>' +
        '<span class="ci-s">'+esc(x.s)+'</span></a>';
    }).join('');
  }
  function go(i){
    var it = items[i]; if (!it) return;
    ovClose(box);
    location.hash = it.href;
  }
  input.addEventListener('input', function(){ active = 0; paint(); });
  input.addEventListener('keydown', function(e){
    if (e.key === 'Escape'){ ovClose(box); return; }
    if (e.key === 'ArrowDown'){ e.preventDefault(); active = Math.min(items.length-1, active+1); paint(); }
    if (e.key === 'ArrowUp'){ e.preventDefault(); active = Math.max(0, active-1); paint(); }
    if (e.key === 'Enter'){ e.preventDefault(); go(active); }
  });
  box.addEventListener('click', function(e){
    if (e.target === box){ ovClose(box); return; }
    var a = e.target.closest('[data-ci]');
    if (a){ e.preventDefault(); go(+a.dataset.ci); }
  });
  paint();
  input.focus();
}

/* ================= 浮层：账号 ================= */
function openAccount(){
  var me = currentUser();
  var box = document.createElement('div');
  box.className = 'ov';
  box.innerHTML = '<div class="ov-card" style="width:min(460px,100%)">' +
    '<div class="ov-head"><h3>当前账号</h3><button class="ov-x" data-ovx>×</button></div>' +
    '<div class="ov-body">' +
      '<div style="display:flex;align-items:center;gap:12px;padding:12px;border:1px solid var(--line);border-radius:12px;background:var(--panel-soft)">' +
        '<div class="av" style="background:'+ROLE_COLOR[me.role]+'">'+esc(me.name.slice(0,1))+'</div>' +
        '<div style="flex:1"><b style="font-size:14px">'+esc(me.name)+'</b>' +
          '<div style="font-size:11.5px;color:var(--muted);margin-top:2px">'+rolabel(me.role)+' · '+esc(me.user)+'</div></div>' +
        '<span class="type-pill post">'+roleLabel(me.role)+'</span>' +
      '</div>' +
      '<div style="margin:16px 0 8px;font-size:11.5px;color:var(--muted)">会话信息</div>' +
      '<div class="rev-list"><div class="rev-item"><div class="ri-main">' +
        '<div class="ri-t">已通过服务器验证</div>' +
        '<div class="ri-m">数据实时保存到数据库 · 退出后需重新登录</div>' +
      '</div></div></div>' +
    '</div>' +
    '<div class="ov-foot"><button class="btn sm danger" data-logout>退出登录</button>' +
      '<button class="btn sm" data-ovx>关闭</button></div></div>';
  document.body.appendChild(box);
  box.addEventListener('click', function(e){
    if (e.target === box || e.target.closest('[data-ovx]')){ ovClose(box); return; }
    var sw = e.target.closest('[data-sw]');
    if (sw){
      SESSION = {uid:+sw.dataset.sw, at:nowStamp()};
      sessionSave(); ovClose(box);
      applyAccount(); route();
      toast('已切换为 '+currentUser().name, 'ok');
      return;
    }
    if (e.target.closest('[data-logout]')){
      nbFlush();
      var f = document.createElement('form');
      f.method = 'post'; f.action = '';
      var t = document.createElement('input'); t.type='hidden'; t.name='_token'; t.value=NEBULA_TOKEN;
      var d = document.createElement('input'); d.type='hidden'; d.name='do'; d.value='logout';
      f.appendChild(t); f.appendChild(d); document.body.appendChild(f); f.submit();
    }
  });
}
function rolabel(r){ return ROLE_SUB[r] || ''; }

/* ================= 登录门 / 账号应用 ================= */
function applyAccount(){
  var u = currentUser();
  var av = document.getElementById('acctAv'), nm = document.getElementById('acctName'), rl = document.getElementById('acctRole');
  if (av){ av.textContent = u.name.slice(0,1); av.style.background = ROLE_COLOR[u.role] || '#5b5bd6'; }
  if (nm) nm.textContent = u.name;
  if (rl) rl.textContent = roleLabel(u.role) + (SESSION ? '' : ' · 未登录');
  var show = {
    'settings':['admin'], 'data':['admin'], 'templates':['admin'],
    'deploy':['admin','editor'], 'trash':['admin','editor'], 'media':['admin','editor'],
    'cat-add':['admin','editor'], 'cat-edit':['admin','editor'], 'tags':['admin','editor']
  };
  document.querySelectorAll('.nav-item').forEach(function(el){
    var need = show[el.dataset.view];
    el.classList.toggle('role-hidden', !!need && need.indexOf(u.role) === -1);
  });
}
function showLogin(){
  if (document.getElementById('loginGate')) return;
  var gate = document.createElement('div');
  gate.className = 'login-gate'; gate.id = 'loginGate';
  gate.innerHTML = '<div class="lg-card">' +
    '<div class="lg-intro">' +
      '<div class="lg-logo"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M11 17a1 1 0 001.447.894l4-2A1 1 0 0017 15V9.236a1 1 0 00-1.447-.894l-4 2a1 1 0 00-.553.894V17zM15.211 6.276a1 1 0 000-1.788l-4.764-2.382a1 1 0 00-.894 0L4.789 4.488a1 1 0 000 1.788l4.764 2.382a1 1 0 00.894 0l4.764-2.382zM4.447 8.342A1 1 0 003 9.236V15a1 1 0 00.553.894l4 2A1 1 0 009 17v-5.764a1 1 0 00-.553-.894l-4-2z"/></svg></div>' +
      '<h2>CiciCms</h2>' +
      '<p>内容管理后台 · 演示环境<br>数据由服务器保存，退出登录后需重新登录。</p>' +
      ['admin','editor','author'].map(function(r){
        return '<div class="lg-role"><span class="lg-dot" style="background:'+ROLE_COLOR[r]+'"></span>' +
          '<div><b>'+ROLE_LABEL[r]+'</b><span>'+(r==='admin'?'站点设置、数据与模板，全部权限'
            : r==='editor'?'文章与单页的编辑发布，媒体与栏目维护'
            : '只能写自己的草稿并提交审核')+'</span></div></div>';
      }).join('') +
    '</div>' +
    '<div class="lg-form">' +
      '<h3>登录后台</h3><p class="lg-hint">当前会话已登录</p>' +
      '<div class="lg-accts" id="lgAccts">' + db.users.map(function(u){
        return '<button class="lg-acct" data-lg="'+u.id+'">' +
          '<span class="la-av" style="background:'+ROLE_COLOR[u.role]+'">'+esc(u.name.slice(0,1))+'</span>' +
          '<span class="la-n">'+esc(u.name)+'</span><span class="la-r">'+roleLabel(u.role)+'</span></button>';
      }).join('') + '</div>' +
      '<div class="lg-field"><label>账号</label><input id="lgUser" value="admin"></div>' +
      '<div class="lg-field"><label>密码</label><input id="lgPass" type="password" value="nebula"></div>' +
      '<div class="lg-err" id="lgErr"></div>' +
      '<button class="btn primary" id="lgGo">登录</button>' +
      '<div class="lg-note">演示账号：<code>admin / nebula</code>、<code>editor / editor</code>、<code>author / author</code></div>' +
    '</div></div>';
  document.body.appendChild(gate);
  var uEl = document.getElementById('lgUser'), pEl = document.getElementById('lgPass'), errEl = document.getElementById('lgErr');
  gate.addEventListener('click', function(e){
    var card = e.target.closest('[data-lg]');
    if (card){
      var u = db.users.filter(function(x){ return x.id === +card.dataset.lg; })[0];
      if (!u) return;
      document.querySelectorAll('.lg-acct').forEach(function(el){ el.classList.remove('on'); });
      card.classList.add('on');
      uEl.value = u.user; pEl.value = u.pass; errEl.textContent = '';
    }
  });
  function submit(){
    if (login(uEl.value.trim(), pEl.value)){
      ovClose(gate);
      applyAccount();
      route();
      toast('欢迎回来，'+currentUser().name, 'ok');
    } else {
      errEl.textContent = '账号或密码不正确';
    }
  }
  document.getElementById('lgGo').addEventListener('click', submit);
  pEl.addEventListener('keydown', function(e){ if (e.key === 'Enter') submit(); });
}

/* ================= 视图：分类标签（栏目 / 标签） ================= */
var TAXFOCUS = 'edit';
var TAXMETA = {
  add:  {title:'添加栏目', sub:'按行填写栏目信息 · 别名决定栏目地址，背景图可实时预览', card:'cat', input:null},
  edit: {title:'编辑栏目', sub:'栏目即文章分类 · 删除栏目后其文章归入「未分类」', card:'cat', input:null},
  tags: {title:'标签管理', sub:'标签与文章的关联明细 · 每行一条关联，不含回收站文章', card:'tag', input:null}
};
var TAXQ = '';
var TAXEDIT = null;

/* ============ 编辑栏目：暂用媒体管理页版式 ============ */
var CSTATE = {page:1, tab:'all', q:''};
var CFORM = false, CEDIT = null;

function catId(name){
  if (db.catIds[name] == null){
    var mx = 0;
    Object.keys(db.catIds).forEach(function(k){ if (db.catIds[k] > mx) mx = db.catIds[k]; });
    db.catIds[name] = mx + 1; save();
  }
  return db.catIds[name];
}
function catRows(){
  return db.categories.map(function(c){
    var ps = db.posts.filter(function(p){ return p.cat === c && p.status !== 'trash'; });
    return {id:catId(c), name:c, n:ps.length, pids:ps.map(function(p){ return p.id; }),
      slug:catSlug(c), url:catUrl(c)};
  });
}
function filteredCats(){
  var q = CSTATE.q.trim().toLowerCase();
  return catRows().filter(function(r){
    if (CSTATE.tab === 'used' && r.n === 0) return false;
    if (CSTATE.tab === 'empty' && r.n !== 0) return false;
    if (q && r.name.toLowerCase().indexOf(q) === -1 && String(r.id).indexOf(q) === -1 &&
        r.slug.toLowerCase().indexOf(q) === -1 && r.url.toLowerCase().indexOf(q) === -1) return false;
    return true;
  });
}
function catRow(r){
  var name = esc(r.name);
  return '<tr><td class="id-cell">'+r.id+'</td>' +
    '<td class="col-cover"><img class="cat-cover" alt="" src="'+esc(catCoverSrc(r.name))+'"></td>' +
    '<td class="col-file"><span class="row-title">'+name+'</span></td>' +
    '<td class="col-slug"><code>'+esc(r.slug)+'</code></td>' +
    '<td class="col-url"><a class="cat-url" data-ccopy="'+esc(r.url)+'" title="点击复制地址">'+esc(r.url)+'</a></td>' +
    '<td class="col-num id-cell">'+r.n+' 篇</td>' +
    '<td class="col-act"><a data-cedit="'+name+'">编辑</a></td>' +
    '<td class="col-act"><a data-cview="'+name+'">预览</a></td>' +
    '<td class="col-act"><a class="danger" data-cdel2="'+name+'">删除</a></td></tr>';
}
/* 栏目预览：大图 + 该栏目下的文章列表，点文章可直接查看 */
function openCatPreview(name){
  var ps = db.posts.filter(function(p){ return p.cat === name && p.status !== 'trash'; })
    .sort(function(a,b){ return (b.date||'').localeCompare(a.date||'') || b.id - a.id; });
  var old = document.getElementById('catModal'); if (old) old.remove();
  var box = document.createElement('div');
  box.className = 'cat-preview'; box.id = 'catModal';
  box.innerHTML =
    '<div class="cp-card">' +
      '<div class="cp-banner"><img alt="" src="'+esc(catCoverSrc(name))+'">' +
        '<button class="cp-close" data-cpclose aria-label="关闭">✕</button></div>' +
      '<div class="cp-body"><h2>'+esc(name)+'</h2>' +
        '<div class="cp-meta">栏目 ID '+catId(name)+' · 别名 '+esc(catSlug(name))+' · '+esc(catUrl(name)) +
          ' · 共 '+ps.length+' 篇文章（不含回收站）</div>' +
        (catKeywords(name) ? '<div class="cp-kw">关键词：'+esc(catKeywords(name))+'</div>' : '') +
        (catDesc(name) ? '<div class="cp-desc">'+esc(catDesc(name))+'</div>' : '') +
        '<div class="cp-list">' + (ps.length ? ps.map(function(p){
          return '<a href="#/article/'+p.id+'" data-cpclose><span class="cp-id">'+p.id+'</span>' +
            '<span class="cp-title">'+esc(p.title)+'</span>' +
            '<span class="pill '+PILL[p.status]+'">'+STATUS[p.status]+'</span>' +
            '<span class="cp-date">'+esc(dateOnly(p.date))+'</span></a>';
        }).join('') : '<div class="cp-empty">这个栏目还没有文章</div>') +
        '</div></div></div>';
  document.body.appendChild(box);
  function close(){ box.remove(); document.removeEventListener('keydown', onKey); }
  function onKey(e){ if (e.key === 'Escape') close(); }
  document.addEventListener('keydown', onKey);
  box.addEventListener('click', function(e){
    if (e.target === box || e.target.closest('[data-cpclose]')) close();
  });
}
function renderCatRows(){
  var rows = filteredCats(), per = PAGE_SIZE;
  var pages = Math.max(1, Math.ceil(rows.length / per));
  if (CSTATE.page > pages) CSTATE.page = pages;
  if (CSTATE.page < 1) CSTATE.page = 1;
  var start = (CSTATE.page-1)*per, slice = rows.slice(start, start+per);
  document.getElementById('cbody').innerHTML = slice.length ? slice.map(catRow).join('')
    : '<tr class="empty-row"><td colspan="9">没有找到符合条件的栏目</td></tr>';
  var html = '<span class="pg-info">共 '+rows.length+' 个栏目' +
    (rows.length ? ' · 显示 '+(start+1)+'–'+(start+slice.length)+' 条' : '') + '</span>' +
    '<span class="pg-size">每页 '+per+' 条</span>' +
    '<button class="pg-nav" data-cp="'+(CSTATE.page-1)+'"'+(CSTATE.page<=1?' disabled':'')+'>‹</button>';
  pageList(CSTATE.page, pages).forEach(function(i){
    html += i === '…' ? '<span class="pg-dots">…</span>'
      : i === CSTATE.page ? '<span class="page-num">'+i+'</span>'
      : '<button data-cp="'+i+'">'+i+'</button>';
  });
  html += '<button class="pg-nav" data-cp="'+(CSTATE.page+1)+'"'+(CSTATE.page>=pages?' disabled':'')+'>›</button>';
  document.getElementById('cpager').innerHTML = html;
}
function vCatTable(){
  var meta = TAXMETA[TAXFOCUS];
  var all = catRows();
  var used = all.filter(function(r){ return r.n > 0; }).length;
  var postTotal = all.reduce(function(a,r){ return a + r.n; }, 0);
  var curCover = CEDIT ? db.catCovers[CEDIT] : null;
  var coverIdx = typeof curCover === 'number' ? curCover : -1;
  var coverUrl = typeof curCover === 'string' ? curCover : '';
  var curSlug = CEDIT ? catSlug(CEDIT) : '';
  var coverOpts = '<option value="auto"'+(coverIdx < 0 && !coverUrl ? ' selected':'')+'>自动配色</option>' +
    CAT_COVERS.map(function(k,i){
      return '<option value="'+i+'"'+(coverIdx === i ? ' selected':'')+'>'+k.label+'</option>';
    }).join('');
  var svg = '<svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>';
  view.innerHTML =
    '<div class="page-head reveal"><div><h1>'+meta.title+'</h1><p>共 '+all.length+' 个栏目 · 关联 '+postTotal+' 篇文章（不含回收站）</p></div>' +
      '<div class="head-actions"><button class="btn primary" id="addCatBtn">+ 添加栏目</button></div></div>' +
    '<div class="filter-row reveal" style="animation-delay:.02s">' +
      '<div class="filter-tabs" id="ctabs">' +
        '<button class="ftab'+(CSTATE.tab==='all'?' on':'')+'" data-ct="all">全部 <span class="n">'+all.length+'</span></button>' +
        '<button class="ftab'+(CSTATE.tab==='used'?' on':'')+'" data-ct="used">有文章 <span class="n">'+used+'</span></button>' +
        '<button class="ftab'+(CSTATE.tab==='empty'?' on':'')+'" data-ct="empty">空栏目 <span class="n">'+(all.length-used)+'</span></button>' +
      '</div>' +
      '<label class="search">'+svg+'<input id="cq" value="'+esc(CSTATE.q)+'" placeholder="搜索栏目名称 / 别名 / 地址…"></label>' +
    '</div>' +
    '<div class="card reveal" id="catForm" style="display:'+(CFORM?'block':'none')+';animation:none;padding:14px;margin-bottom:14px">' +
      '<div class="field-grid" style="margin-bottom:0">' +
        '<div class="field"><label id="cFormTitle">'+(CEDIT?'编辑栏目':'新建栏目')+'</label>' +
          '<input id="cName" value="'+esc(CEDIT || '')+'" placeholder="如 产品设计"></div>' +
        '<div class="field"><label>栏目别名</label>' +
          '<input id="cSlug" value="'+esc(curSlug)+'" placeholder="英文，留空自动生成"></div>' +
        '<div class="field"><label>栏目背景图</label><select id="cCover">'+coverOpts+'</select></div>' +
        '<div class="field"><label>自定义图片 URL</label>' +
          '<input id="cCoverUrl" value="'+esc(coverUrl)+'" placeholder="填了则优先使用"></div>' +
      '</div>' +
      '<div class="cat-form-hint">栏目地址由别名自动生成：'+CAT_URL_PREFIX+'&lt;别名&gt;.html · 别名仅支持小写字母、数字与连字符</div>' +
      '<div style="display:flex;gap:8px;justify-content:flex-end">' +
        '<button class="btn sm" id="cCancel">取消</button>' +
        '<button class="btn sm primary" id="cSave">'+(CEDIT?'保存':'添加')+'</button></div>' +
    '</div>' +
    '<div class="card reveal" id="catCard" style="animation-delay:.06s"><div class="table-wrap">' +
      '<table class="media-table cat-table">' +
        '<thead><tr><th class="col-id">栏目 ID</th><th>栏目背景图</th><th class="col-file">栏目名称</th>' +
          '<th>栏目别名</th><th>栏目地址</th><th class="col-num">文章数量</th>' +
          '<th>栏目编辑</th><th>栏目预览</th><th>栏目删除</th></tr></thead>' +
        '<tbody id="cbody"></tbody></table></div>' +
      '<div class="pager media-pager" id="cpager"></div></div>';

  document.getElementById('addCatBtn').addEventListener('click', function(){
    CEDIT = null; CFORM = true; vCatTable();
  });
  document.getElementById('cCancel').addEventListener('click', function(){
    CEDIT = null; CFORM = false; vCatTable();
  });
  document.getElementById('cSave').addEventListener('click', function(){
    var v = document.getElementById('cName').value.trim();
    if (!v){ alert('请填写栏目名称'); return; }
    if (db.categories.indexOf(v) !== -1 && v !== CEDIT){ alert('栏目「'+v+'」已存在'); return; }
    var cs = document.getElementById('cSlug').value.trim();
    var cv = document.getElementById('cCover').value;
    var cu = document.getElementById('cCoverUrl').value.trim();
    if (CEDIT){
      db.categories = db.categories.map(function(c){ return c === CEDIT ? v : c; });
      db.posts.forEach(function(p){ if (p.cat === CEDIT) p.cat = v; });
      moveCatMeta(CEDIT, v);
    } else {
      db.categories.push(v); catId(v);
      CSTATE.page = 1; CSTATE.tab = 'all'; CSTATE.q = '';
    }
    setCatSlug(v, cs);
    delete db.catCovers[v];
    if (cu) db.catCovers[v] = cu;
    else if (cv !== 'auto') db.catCovers[v] = +cv;
    CEDIT = null; CFORM = false; save(); vCatTable();
  });
  document.getElementById('ctabs').addEventListener('click', function(e){
    var b = e.target.closest('[data-ct]'); if (!b) return;
    CSTATE.tab = b.dataset.ct; CSTATE.page = 1;
    document.querySelectorAll('#ctabs .ftab').forEach(function(el){ el.classList.remove('on'); });
    b.classList.add('on'); renderCatRows();
  });
  document.getElementById('cq').addEventListener('input', function(){
    CSTATE.q = this.value.trim().toLowerCase(); CSTATE.page = 1; renderCatRows();
  });
  /* 事件代理绑在卡片上，卡片重建时旧监听器随之释放 */
  var card = document.getElementById('catCard');
  card.addEventListener('click', function(e){
    var pg = e.target.closest('button[data-cp]');
    if (pg && !pg.disabled){ CSTATE.page = +pg.dataset.cp; renderCatRows(); return; }
    var cy = e.target.closest('[data-ccopy]');
    if (cy){
      var u = cy.dataset.ccopy, label = cy.textContent;
      try{
        var ta = document.createElement('textarea');
        ta.value = u; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); ta.remove();
        cy.textContent = '已复制 ✓';
        setTimeout(function(){ cy.textContent = label; }, 1200);
      }catch(err){}
      return;
    }
    var ed = e.target.closest('[data-cedit]');
    if (ed){ CEDIT = ed.dataset.cedit; CFORM = true; vCatTable(); return; }
    var vw = e.target.closest('[data-cview]');
    if (vw){ openCatPreview(vw.dataset.cview); return; }
    var dl = e.target.closest('[data-cdel2]');
    if (dl){
      var c = dl.dataset.cdel2;
      if (!can('taxonomy')){ toast('当前角色无权删除栏目', 'bad'); return; }
      if (db.categories.length <= 1){ alert('至少保留一个栏目'); return; }
      var hit = db.posts.filter(function(p){ return p.cat === c && p.status !== 'trash'; }).length;
      if (!confirm('把栏目「'+c+'」移入回收站？' + (hit ? '该栏目下的 '+hit+' 篇文章将归入「未分类」' : '该栏目下没有文章') +
          '。\n\n可在「回收管理」中恢复栏目与其设置，超过 '+TRASH_DAYS+' 天自动永久删除。')) return;
      moveToTrash('cat', c);
      db.categories = db.categories.filter(function(x){ return x !== c; });
      dropCatMeta(c);
      db.posts.forEach(function(p){ if (p.cat === c) p.cat = '未分类'; });
      if (db.categories.indexOf('未分类') === -1 && db.posts.some(function(p){ return p.cat === '未分类'; })) db.categories.push('未分类');
      save(); vCatTable();
    }
  });
  renderCatRows();
}

function tagId(name){
  if (db.tagIds[name] == null){
    var mx = 0;
    Object.keys(db.tagIds).forEach(function(k){ if (db.tagIds[k] > mx) mx = db.tagIds[k]; });
    db.tagIds[name] = mx + 1;
    save();
  }
  return db.tagIds[name];
}
/* 一行 = 一条「标签 ↔ 文章」关联；同一标签挂多篇文章时展开成多行 */
function tagRows(){
  var map = {};
  db.posts.forEach(function(p){
    if (p.status === 'trash') return;
    (p.tags || []).forEach(function(t){ (map[t] || (map[t] = [])).push(p); });
  });
  var rows = [];
  Object.keys(map).sort().forEach(function(t){
    var tid = tagId(t);
    map[t].sort(function(a,b){ return a.id - b.id; }).forEach(function(p){
      rows.push({tid:tid, tag:t, pid:p.id, title:p.title});
    });
  });
  return rows;
}
function allTags(){
  var m = {};
  db.posts.forEach(function(p){ (p.tags || []).forEach(function(t){ m[t] = 1; }); });
  return Object.keys(m);
}
function tagCountOf(rows){
  var m = {};
  rows.forEach(function(r){ m[r.tag] = 1; });
  return Object.keys(m).length;
}
function tagBodyHtml(rows, q){
  if (!rows.length) return '<tr class="empty-row"><td colspan="7">'+
    (q ? '没有匹配的标签关联' : '暂无标签关联，标签会随文章添加自动产生')+'</td></tr>';
  return rows.map(function(r){
    return '<tr><td class="id-cell">'+r.tid+'</td>' +
      '<td><span class="tag-pill">'+esc(r.tag)+'</span></td>' +
      '<td class="id-cell">'+r.pid+'</td>' +
      '<td class="col-post-title">'+esc(r.title)+'</td>' +
      '<td><a class="op" href="#/article/'+r.pid+'">查看</a></td>' +
      '<td><a class="op" data-tedit="'+esc(r.tag)+'">编辑</a></td>' +
      '<td><a class="op danger" data-trowdel="'+esc(r.tag)+'" data-pid="'+r.pid+'">删除</a></td></tr>';
  }).join('');
}
function filteredTagRows(){
  var q = TAXQ.trim().toLowerCase();
  var rows = tagRows();
  return {q:q, all:rows, list: q ? rows.filter(function(r){
    return r.tag.toLowerCase().indexOf(q) !== -1 || String(r.pid).indexOf(q) !== -1 ||
           r.title.toLowerCase().indexOf(q) !== -1;
  }) : rows};
}

/* 标签管理：7 列关联表格 —— 标签只能随文章产生，这里只做查看 / 重命名 / 移除关联 */
var TAGPAGE = 1;
function vTagsTable(meta){
  var editBar = TAXEDIT ?
    '<div class="tag-edit-bar"><b>重命名标签「'+esc(TAXEDIT)+'」</b>' +
      '<input id="tagEditVal" value="'+esc(TAXEDIT)+'">' +
      '<button class="btn sm primary" data-tesave="1">保存</button>' +
      '<button class="btn sm" data-tecancel="1">取消</button></div>' : '';

  view.innerHTML =
    '<div class="page-head reveal"><div><h1>'+meta.title+'</h1><p>'+meta.sub+'</p></div>' +
      '<div class="head-actions"><span class="pill pub" id="tagTotal">—</span></div></div>' +
    '<div class="filter-row reveal" style="animation-delay:.02s">' +
      '<span id="tagInfo" style="font-size:12.5px;color:var(--muted)"></span>' +
      '<label class="search"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>' +
        '<input id="tagq" value="'+esc(TAXQ)+'" placeholder="搜索标签 / 文章标题 / ID"></label>' +
    '</div>' +
    '<div class="card reveal" id="taxRoot" style="animation-delay:.06s">' +
      editBar +
      '<div class="table-wrap"><table class="tags-table">' +
        '<thead><tr><th>标签 ID</th><th>标签名称</th><th>文章 ID</th><th class="col-post-title">文章标题</th>' +
          '<th class="col-op">查看</th><th class="col-op">编辑</th><th class="col-op">删除</th></tr></thead>' +
        '<tbody id="tagBody"></tbody></table></div>' +
      '<div class="pager media-pager" id="tagPager"></div>' +
    '</div>';

  var root = document.getElementById('taxRoot');

  function paint(){
    var r = filteredTagRows();
    var pages = Math.max(1, Math.ceil(r.list.length / PAGE_SIZE));
    if (TAGPAGE > pages) TAGPAGE = pages;
    if (TAGPAGE < 1) TAGPAGE = 1;
    var start = (TAGPAGE - 1) * PAGE_SIZE;
    var slice = r.list.slice(start, start + PAGE_SIZE);
    document.getElementById('tagBody').innerHTML = tagBodyHtml(slice, r.q);
    document.getElementById('tagInfo').textContent = '共 ' + tagCountOf(r.all) + ' 个标签 · 匹配 ' +
      r.list.length + ' / ' + r.all.length + ' 条关联';
    document.getElementById('tagTotal').textContent = r.all.length + ' 条关联';

    var html = '<span class="pg-info">共 ' + r.list.length + ' 条关联' +
      (r.list.length ? ' · 显示 ' + (start + 1) + '–' + (start + slice.length) + ' 条' : '') + '</span>' +
      '<span class="pg-size">每页 ' + PAGE_SIZE + ' 条</span>' +
      '<button class="pg-nav" data-tp="' + (TAGPAGE - 1) + '"' + (TAGPAGE <= 1 ? ' disabled' : '') + '>‹</button>';
    pageList(TAGPAGE, pages).forEach(function(i){
      html += i === '…' ? '<span class="pg-dots">…</span>'
        : i === TAGPAGE ? '<span class="page-num">' + i + '</span>'
        : '<button data-tp="' + i + '">' + i + '</button>';
    });
    html += '<button class="pg-nav" data-tp="' + (TAGPAGE + 1) + '"' + (TAGPAGE >= pages ? ' disabled' : '') + '>›</button>';
    document.getElementById('tagPager').innerHTML = html;
  }
  paint();

  document.getElementById('tagq').addEventListener('input', function(){
    TAXQ = this.value; TAGPAGE = 1; paint();
  });
  root.addEventListener('click', function(e){
    var pg = e.target.closest('[data-tp]');
    if (pg && !pg.disabled){ TAGPAGE = +pg.dataset.tp; paint(); return; }
    var ed = e.target.closest('[data-tedit]');
    if (ed){ TAXEDIT = ed.dataset.tedit; vTaxonomy(); return; }
    if (e.target.closest('[data-tecancel]')){ TAXEDIT = null; vTaxonomy(); return; }
    if (e.target.closest('[data-tesave]')){
      var nv = document.getElementById('tagEditVal').value.trim();
      if (!nv){ alert('标签名称不能为空'); return; }
      if (nv !== TAXEDIT && allTags().indexOf(nv) !== -1){ alert('标签「'+nv+'」已存在'); return; }
      db.posts.forEach(function(p){ p.tags = p.tags.map(function(t){ return t === TAXEDIT ? nv : t; }); });
      db.tagIds[nv] = db.tagIds[TAXEDIT];
      delete db.tagIds[TAXEDIT];
      TAXEDIT = null; save(); vTaxonomy(); return;
    }
    var dl = e.target.closest('[data-trowdel]');
    if (dl){
      var t = dl.dataset.trowdel, p = findPost(+dl.dataset.pid);
      if (!confirm('从文章《'+(p ? p.title : dl.dataset.pid)+'》中移除标签「'+t+'」？')) return;
      if (p) p.tags = p.tags.filter(function(x){ return x !== t; });
      save(); vTaxonomy();
    }
  });
}

/* 三个栏目菜单各用独立页面 */
function vTaxonomy(focus){
  if (focus && TAXMETA[focus]) TAXFOCUS = focus;
  var meta = TAXMETA[TAXFOCUS];
  if (TAXFOCUS === 'tags') return vTagsTable(meta);
  if (TAXFOCUS === 'add')  return vCatAdd();
  return vCatTable();
}

/* ============ 添加栏目：6 行表单 ============ */
var CAT_ADD_FIELDS = ['aName','aSlug','aUrl','aKeywords','aCoverUrl','aDesc'];
function catAddRow(id, label, control, hint){
  return '<div class="form-row"><label for="'+id+'">'+label+'</label><div>' + control +
    (hint ? '<div class="form-hint">'+hint+'</div>' : '') + '</div></div>';
}
function vCatAdd(){
  var $ = function(id){ return document.getElementById(id); };
  var opts = '<option value="auto" selected>自动配色（按栏目 ID）</option>' +
    CAT_COVERS.map(function(k,i){ return '<option value="'+i+'">'+k.label+'</option>'; }).join('');
  var urlTouched = false;

  view.innerHTML =
    '<div class="page-head reveal"><div><h1>添加栏目</h1>' +
      '<p>按行填写栏目信息 · 别名决定栏目地址，背景图可实时预览</p></div>' +
      '<div class="head-actions"><a class="btn" href="#/taxonomy/edit" style="text-decoration:none">查看栏目列表</a></div></div>' +
    '<div class="card reveal" style="animation-delay:.04s">' +
      '<div class="cat-add-form">' +
        catAddRow('aName', '栏目名称', '<input id="aName" placeholder="如 产品设计">', '必填，不能与已有栏目重名') +
        catAddRow('aSlug', '栏目别名', '<input id="aSlug" placeholder="英文小写，留空自动生成">',
          '仅支持小写字母、数字与连字符；与其他栏目重名会自动加后缀') +
        catAddRow('aUrl', '栏目地址', '<input id="aUrl" placeholder="由别名自动生成，如 /category/design.html">',
          '随栏目别名自动更新；手动修改后以填写值为准') +
        catAddRow('aKeywords', '栏目关键词', '<input id="aKeywords" placeholder="多个关键词用逗号分隔">',
          '用于 SEO 描述，会显示在栏目预览中') +
        catAddRow('aCover', '栏目背景图',
          '<div class="cover-line"><select id="aCover">'+opts+'</select>' +
            '<input id="aCoverUrl" placeholder="或填自定义图片 URL">' +
            '<img id="aCoverPreview" class="cover-preview" alt="" src="'+esc(catFormCoverSrc('auto',''))+'"></div>',
          '留空 URL 时按所选预设生成；不选预设则按栏目 ID 自动配色') +
        catAddRow('aDesc', '栏目简介',
          '<textarea id="aDesc" placeholder="一句话介绍这个栏目的内容定位…"></textarea>',
          '会显示在栏目预览中') +
        '<div class="form-actions"><button class="btn" id="aReset">清空</button>' +
          '<button class="btn primary" id="aSubmit">添加栏目</button></div>' +
        '<div class="cat-add-ok" id="aOk" style="display:none"></div>' +
      '</div></div>';

  function syncUrl(){
    if (urlTouched) return;
    var s = cleanSlug($('aSlug').value) || CAT_SLUG_FALLBACK[$('aName').value.trim()] || '';
    $('aUrl').value = s ? CAT_URL_PREFIX + s + '.html' : '';
  }
  function syncCover(){
    $('aCoverPreview').src = catFormCoverSrc($('aCover').value, $('aCoverUrl').value.trim());
  }
  function reset(){
    CAT_ADD_FIELDS.forEach(function(id){ $(id).value = ''; });
    $('aCover').value = 'auto';
    $('aOk').style.display = 'none';
    urlTouched = false;
    syncCover();
  }
  function submit(){
    var name = $('aName').value.trim();
    if (!name){ alert('请填写栏目名称'); return; }
    if (db.categories.indexOf(name) !== -1){ alert('栏目「'+name+'」已存在'); return; }
    var urlV = $('aUrl').value.trim(), kw = $('aKeywords').value.trim(), desc = $('aDesc').value.trim();
    var cv = $('aCover').value, cu = $('aCoverUrl').value.trim();
    db.categories.push(name); catId(name);
    setCatSlug(name, $('aSlug').value.trim() || CAT_SLUG_FALLBACK[name] || '');
    var autoUrl = CAT_URL_PREFIX + catSlug(name) + '.html';
    if (urlV && urlV !== autoUrl) db.catUrls[name] = urlV; else delete db.catUrls[name];
    if (kw) db.catKeywords[name] = kw; else delete db.catKeywords[name];
    if (desc) db.catDescs[name] = desc; else delete db.catDescs[name];
    delete db.catCovers[name];
    if (cu) db.catCovers[name] = cu;
    else if (cv !== 'auto') db.catCovers[name] = +cv;
    save();
    var added = name, address = catUrl(name);
    reset();
    $('aOk').style.display = 'block';
    $('aOk').innerHTML = '✓ 栏目「'+esc(added)+'」已添加 · 地址 '+esc(address) +
      ' · <a href="#/taxonomy/edit">去栏目列表查看 →</a>';
  }

  $('aName').addEventListener('input', syncUrl);
  $('aSlug').addEventListener('input', syncUrl);
  $('aUrl').addEventListener('input', function(){ urlTouched = true; });
  $('aCover').addEventListener('change', syncCover);
  $('aCoverUrl').addEventListener('input', syncCover);
  $('aReset').addEventListener('click', reset);
  $('aSubmit').addEventListener('click', submit);
  $('aName').focus();
}

/* ================= 视图：数据管理 ================= */
function byteLen(str){
  var n = 0;
  for (var i = 0; i < str.length; i++){
    var c = str.charCodeAt(i);
    n += c < 0x80 ? 1 : c < 0x800 ? 2 : 3;
  }
  return n;
}
function fmtSize(b){
  b = +b || 0;
  if (b < 1024) return b + ' B';
  if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
  return (b / 1048576).toFixed(2) + ' MB';
}
/* 快照不含 backups 自身，避免备份套备份 */
function dmSnapshotText(){
  var copy = {};
  Object.keys(db).forEach(function(k){ if (k !== 'backups') copy[k] = db[k]; });
  return JSON.stringify(copy, null, 2);
}
function dmDownload(name, text){
  var blob = new Blob([text], {type:'application/json'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  a.click();
  URL.revokeObjectURL(a.href);
}
function dmBackupName(){
  return 'nebula-cms-' + nowStamp().replace(/[/:]/g,'-').replace(/ /g,'_') + '.json';
}
/* 备份：快照写独立 key，元信息进 db.backups，最多留 5 份 */
function dmMakeBackup(kind){
  var text = dmSnapshotText();
  var id = (new Date()).getTime() + Math.floor(Math.random() * 1000);
  /* 同一毫秒内连续备份可能撞号，撞了就把 id +1，保证快照 key 唯一 */
  while (db.backups.some(function(b){ return b.id === id; })) id++;
  var rec = {id:id, name:dmBackupName(), kind:kind || 'manual', bytes:byteLen(text), time:nowStamp()};
  try{ localStorage.setItem(BK_PREFIX + id, text); }
  catch(e){ return null; }
  db.backups.unshift(rec);
  while (db.backups.length > 5){
    var old = db.backups.pop();
    try{ localStorage.removeItem(BK_PREFIX + old.id); }catch(e){}
  }
  save();
  return rec;
}
function dmRestoreData(data, label){
  if (!data || typeof data !== 'object' || !Array.isArray(data.posts) ||
      !Array.isArray(data.categories) || !data.settings){
    alert('备份文件结构不正确：缺少 posts / categories / settings');
    return false;
  }
  if (!confirm('恢复「' + label + '」会覆盖当前全部文章、单页、栏目与设置，确定继续？')) return false;
  dmMakeBackup('auto');              /* 恢复前先给当前数据留一份，误操作可回退 */
  var backups = db.backups;
  db = data;
  db.backups = backups;
  ensurePages(); ensureTagIds(); ensureCatIds(); ensureCatMeta(); ensureSettings(); ensureDataTools();
  save(); applySiteMeta(); route();
  return true;
}
function dmRestoreText(text, label){
  var data = null;
  try{ data = JSON.parse(String(text)); }
  catch(e){ alert('不是合法的 JSON 文件，无法恢复'); return false; }
  return dmRestoreData(data, label);
}
function copyText(text, btn){
  var done = function(){
    if (!btn) return;
    var old = btn.textContent;
    btn.textContent = '已复制';
    setTimeout(function(){ btn.textContent = old; }, 1200);
  };
  try{
    if (typeof navigator !== 'undefined' && navigator.clipboard && navigator.clipboard.writeText){
      navigator.clipboard.writeText(String(text)).then(done, done);
      return;
    }
  }catch(e){}
  done();
}

function vData(){
  ensureDataTools();
  var ftp = db.ftp;
  var dbBytes = byteLen(JSON.stringify(db));
  var bkBytes = db.backups.reduce(function(n, b){ return n + (+b.bytes || 0); }, 0);
  var tagN = Object.keys(db.tagIds || {}).length;
  var pub = db.posts.filter(function(p){ return p.status === 'publish'; }).length;
  var quota = Math.max(1, +ftp.quotaMB || 2048);
  var used = Math.max(0, +ftp.usedMB || 0);
  var qpct = Math.min(100, used / quota * 100);
  var quotaTxt = quota >= 1024 ? (quota / 1024).toFixed(0) + ' GB' : quota + ' MB';
  var usedTxt = used >= 1024 ? (used / 1024).toFixed(2) + ' GB' : used + ' MB';
  var freeTxt = (quota - used) >= 1024 ? ((quota - used) / 1024).toFixed(2) + ' GB' : (quota - used) + ' MB';
  var backups = db.backups.slice().sort(function(a, b){ return b.id - a.id; });

  var rows = backups.length ? backups.map(function(b){
    return '<tr><td class="col-file">' + esc(b.name) + '</td>' +
      '<td><span class="pill ' + (b.kind === 'auto' ? 'draft' : 'pub') + '">' +
        (b.kind === 'auto' ? '自动' : '手动') + '</span></td>' +
      '<td>' + fmtSize(b.bytes) + '</td>' +
      '<td class="date-cell">' + esc(b.time) + '</td>' +
      '<td><span class="row-actions">' +
        '<a data-act="dl" data-id="' + b.id + '">下载</a><span class="sep">|</span>' +
        '<a data-act="rs" data-id="' + b.id + '">恢复</a><span class="sep">|</span>' +
        '<a class="danger" data-act="rm" data-id="' + b.id + '">删除</a>' +
      '</span></td></tr>';
  }).join('') : '<tr class="empty-row"><td colspan="5">还没有备份记录，点上方「立即备份」生成第一份快照</td></tr>';

  view.innerHTML =
    '<div class="page-head reveal"><div><h1>数据管理</h1><p>数据库备份与恢复、数据库信息与空间 FTP</p></div></div>' +

    '<div class="dm-cols reveal" id="dmCols">' +
    '<div class="card dm-col-bk">' +
      '<div class="dm-bk">' +
      '<div class="set-sec">数据库备份</div>' +
      '<div class="gen-intro">把 <b>' + db.posts.length + ' 篇文章</b>、<b>' + db.pages.length +
        ' 个单页</b>、<b>' + db.media.length + ' 条媒体记录</b>、栏目、标签与站点设置打包成一个 JSON 快照，可下载保存，' +
        '恢复时整站内容回到备份时的状态。</div>' +
      '<div class="dm-actions">' +
        '<button class="btn primary" id="dmBk">立即备份</button>' +
        '<button class="btn" id="dmDl">下载完整快照</button>' +
        '<span class="dm-tip" id="dmTip"></span>' +
      '</div>' +
      '<div class="dm-chips"><span class="dm-chip">文章 ' + db.posts.length + '</span>' +
        '<span class="dm-chip">单页 ' + db.pages.length + '</span>' +
        '<span class="dm-chip">媒体 ' + db.media.length + '</span>' +
        '<span class="dm-chip">栏目 ' + db.categories.length + '</span>' +
        '<span class="dm-chip">标签 ' + tagN + '</span>' +
        '<span class="dm-chip">站点设置</span>' +
        '<span class="dm-chip">当前体积 ' + fmtSize(dbBytes) + '</span></div>' +
      '<div class="set-sec">数据库恢复</div>' +
      '<div class="gen-intro">把导出的 JSON 快照拖到下面，或点击选择文件。' +
        '恢复前会自动留存当前数据，误操作也能回退。</div>' +
      '<div class="dm-drop" id="dmDrop">' +
        '<svg viewBox="0 0 24 24"><path d="M12 16V4m0 0L8 8m4-4l4 4"/>' +
          '<path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>' +
        '<b>拖拽备份文件到这里，或点击选择</b>' +
        '<span>支持 .json 快照，建议不超过 2 MB</span>' +
        '<input type="file" id="dmFile" accept=".json,application/json">' +
      '</div>' +
      '<div class="dm-pick" id="dmPicked">' +
        '<span class="nm" id="dmPickedName"></span>' +
        '<span class="sz" id="dmPickedSize"></span>' +
        '<button class="btn sm primary" id="dmDoRestore">开始恢复</button></div>' +
      '</div>' +
    '</div>' +

      '<div class="card"><div class="dm-info">' +
        '<h3>数据库信息</h3><p class="dm-sub">当前站点内容存储概况</p>' +
        '<div class="dm-row"><span class="k">数据库类型</span><span class="v">浏览器本地存储 <em>localStorage</em></span></div>' +
        '<div class="dm-row"><span class="k">存储引擎</span><span class="v">JSON 文档存储</span></div>' +
        '<div class="dm-row"><span class="k">存储键名</span><span class="v mono">' + KEY + '</span></div>' +
        '<div class="dm-row"><span class="k">字符集</span><span class="v">UTF-8</span></div>' +
        '<div class="dm-row"><span class="k">数据表（集合）</span><span class="v" title="posts / pages / media / categories / settings / tagIds / backups">7 <em>个</em></span></div>' +
        '<div class="dm-row"><span class="k">记录总数</span><span class="v">' +
          (db.posts.length + db.pages.length + db.media.length + db.categories.length + tagN) + ' <em>条</em></span></div>' +
        '<div class="dm-row"><span class="k">内容分布</span><span class="v wrap" style="font-weight:500">文章 ' +
          db.posts.length + ' · 单页 ' + db.pages.length + ' · 媒体 ' + db.media.length +
          ' · 栏目 ' + db.categories.length + ' · 标签 ' + tagN + '</span></div>' +
        '<div class="dm-row"><span class="k">数据体积</span><span class="v">' + fmtSize(dbBytes) + '</span></div>' +
        '<div class="dm-row"><span class="k">备份占用</span><span class="v">' + backups.length + ' 份 · ' + fmtSize(bkBytes) + '</span></div>' +
        '<div class="dm-row"><span class="k">最后写入</span><span class="v">' + esc(db.touchedAt || '—') + '</span></div>' +
      '</div></div>' +

      '<div class="card"><div class="dm-info">' +
        '<h3>空间 FTP 信息 <span class="dm-tag">示例</span></h3>' +
        '<p class="dm-sub">上传静态文件与远程备份用，正式部署时替换为空间服务商下发的账号</p>' +
        '<div class="dm-row"><span class="k">空间主机</span><span class="v mono">' + esc(ftp.host) + '</span>' +
          '<button class="dm-mini" data-copy="' + esc(ftp.host) + '">复制</button></div>' +
        '<div class="dm-row"><span class="k">端口</span><span class="v mono">' + esc(ftp.port) + '</span></div>' +
        '<div class="dm-row"><span class="k">用户名</span><span class="v mono">' + esc(ftp.user) + '</span>' +
          '<button class="dm-mini" data-copy="' + esc(ftp.user) + '">复制</button></div>' +
        '<div class="dm-row"><span class="k">密码</span><span class="v mono" id="dmPass">••••••••</span>' +
          '<button class="dm-mini" id="dmPassShow">显示</button></div>' +
        '<div class="dm-row"><span class="k">登录目录</span><span class="v mono">' + esc(ftp.root) + '</span></div>' +
        '<div class="dm-row"><span class="k">连接状态</span>' +
          '<span class="v"><span class="dm-dot' + (ftp.status === 'ok' ? '' : ' off') + '" id="dmDot"></span>' +
          '<span id="dmState">' + (ftp.status === 'ok' ? '已连接' : '未连接') + '</span></span>' +
          '<button class="dm-mini" id="dmPing">测试连接</button></div>' +
        '<div class="dm-quota">' +
          '<div class="qh"><span>空间容量</span><b>' + usedTxt + ' / ' + quotaTxt + '</b></div>' +
          '<div class="qbar"><i style="width:' + qpct.toFixed(1) + '%"></i></div>' +
          '<div class="qfoot">已用 ' + qpct.toFixed(1) + '% · 剩余 ' + freeTxt + '（FTP 与静态文件共用）</div>' +
        '</div>' +
      '</div></div>' +
    '</div>' +

    '<div class="card reveal" style="animation-delay:.09s">' +
      '<div class="dm-head"><b>备份记录</b><span>最多保留 5 份，超出后自动清理最早的一份</span></div>' +
      '<div class="table-wrap" id="dmTable"><table class="dm-table">' +
        '<thead><tr><th class="col-file">文件名</th><th>类型</th><th>大小</th><th>创建时间</th><th>操作</th></tr></thead>' +
        '<tbody id="dmRows">' + rows + '</tbody></table></div>' +
    '</div>';

  var $ = function(id){ return document.getElementById(id); };
  var picked = null;
  var passShown = false;

  $('dmBk').addEventListener('click', function(){
    var rec = dmMakeBackup('manual');
    if (!rec){ alert('备份失败：浏览器存储空间不足'); return; }
    route();
    var tip = $('dmTip');
    tip.textContent = '已生成快照 ' + fmtSize(rec.bytes) + '，可在下方备份记录中下载';
  });
  $('dmDl').addEventListener('click', function(){
    dmDownload(dmBackupName(), dmSnapshotText());
  });

  function pickFile(file){
    if (!file) return;
    picked = file;
    $('dmPickedName').textContent = file.name || '未命名快照';
    $('dmPickedSize').textContent = fmtSize(file.size || 0);
    $('dmPicked').className = 'dm-pick show';
  }
  $('dmFile').addEventListener('change', function(){ pickFile(this.files && this.files[0]); });
  $('dmDrop').addEventListener('dragover', function(e){
    e.preventDefault(); this.className = 'dm-drop over';
  });
  $('dmDrop').addEventListener('dragleave', function(e){
    e.preventDefault(); this.className = 'dm-drop';
  });
  $('dmDrop').addEventListener('drop', function(e){
    e.preventDefault(); this.className = 'dm-drop';
    var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    pickFile(f);
  });
  $('dmDoRestore').addEventListener('click', function(){
    if (!picked) return;
    if (typeof FileReader === 'undefined'){ alert('当前环境不支持读取本地文件，请改用备份记录的「恢复」'); return; }
    var fr = new FileReader();
    fr.onload = function(){
      if (dmRestoreText(String(fr.result), picked.name || '本地文件')) alert('已恢复：' + picked.name);
    };
    fr.readAsText(picked);
  });

  $('dmTable').addEventListener('click', function(e){
    var a = e.target.closest('[data-act]');
    if (!a) return;
    e.preventDefault();
    var id = +a.dataset.id, act = a.dataset.act, rec = null;
    db.backups.forEach(function(b){ if (b.id === id) rec = b; });
    if (!rec) return;
    var text = null;
    try{ text = localStorage.getItem(BK_PREFIX + rec.id); }catch(e){}
    if (act === 'dl'){
      if (!text){ alert('快照内容已丢失，无法下载'); return; }
      dmDownload(rec.name, text);
      return;
    }
    if (act === 'rs'){
      if (!text){ alert('快照内容已丢失，无法恢复'); return; }
      if (dmRestoreText(text, rec.name)) alert('已恢复到「' + rec.name + '」');
      return;
    }
    if (act === 'rm'){
      if (!confirm('删除备份「' + rec.name + '」？')) return;
      try{ localStorage.removeItem(BK_PREFIX + rec.id); }catch(e){}
      db.backups = db.backups.filter(function(b){ return b.id !== id; });
      save(); route();
    }
  });

  $('dmPassShow').addEventListener('click', function(){
    passShown = !passShown;
    $('dmPass').textContent = passShown ? String(ftp.pass) : '••••••••';
    this.textContent = passShown ? '隐藏' : '显示';
  });
  $('dmPing').addEventListener('click', function(){
    var btn = this;
    btn.disabled = true; btn.textContent = '检测中…';
    $('dmState').textContent = '连接中';
    $('dmDot').className = 'dm-dot off';
    setTimeout(function(){
      ftp.status = 'ok';
      save();
      btn.disabled = false; btn.textContent = '测试连接';
      $('dmState').textContent = '已连接';
      $('dmDot').className = 'dm-dot';
    }, 700);
  });

  /* 复制按钮统一代理（主机 / 用户名） */
  $('dmCols').addEventListener('click', function(e){
    var b = e.target.closest('[data-copy]');
    if (!b) return;
    copyText(b.dataset.copy, b);
  });
}

/* ================= 构建引擎：Go 模板子集 + 产物生成 ================= */
/* 只实现默认模板用到的语法：{{ .Field }} / partial / block+define / with / range / if，
   遇到不支持的表达式（如 first 5 (where …)）返回空值并记一条警告，不会中断构建。 */
var SITE_ORIGIN = 'https://nebula.example';
var LAST_BUILD = null;
function guessMime(p){
  if (/\.html?$/.test(p)) return 'text/html;charset=utf-8';
  if (/\.css$/.test(p))  return 'text/css;charset=utf-8';
  if (/\.js$/.test(p))   return 'text/javascript;charset=utf-8';
  if (/\.xml$/.test(p))  return 'application/xml;charset=utf-8';
  if (/\.json$/.test(p)) return 'application/json;charset=utf-8';
  if (/\.php$/.test(p))  return 'application/x-httpd-php';
  return 'text/plain;charset=utf-8';
}

function tplTagOf(body){
  if (!body) return {t:'text', v:''};
  var kw = body.split(/\s+/)[0];
  if (kw === 'end' || kw === 'else') return {t:kw};
  var m;
  if (kw === 'partial' && (m = body.match(/^partial\s+"([^"]+)"\s*(.*)$/)))
    return {t:'partial', name:m[1], expr:(m[2] || '.').trim()};
  if (kw === 'define' && (m = body.match(/^define\s+"([^"]+)"\s*(.*)$/)))
    return {t:'define', name:m[1], expr:(m[2] || '.').trim()};
  if (kw === 'block' && (m = body.match(/^block\s+"([^"]+)"\s*(.*)$/)))
    return {t:'block', name:m[1], expr:(m[2] || '.').trim()};
  if (kw === 'with' || kw === 'range' || kw === 'if')
    return {t:kw, expr:body.slice(kw.length).trim()};
  return {t:'var', expr:body};
}
function tplParse(src){
  var re = /\{\{([\s\S]*?)\}\}/g, last = 0, m, root = {t:'root', kids:[]}, stack = [root];
  function add(node){
    var top = stack[stack.length - 1];
    if (!top.kids) top.kids = [];
    if (top.inElse) (top.elseKids = top.elseKids || []).push(node);
    else top.kids.push(node);
    if (node.t === 'with' || node.t === 'range' || node.t === 'if' ||
        node.t === 'define' || node.t === 'block'){ node.kids = []; stack.push(node); }
  }
  while ((m = re.exec(src))){
    if (m.index > last) add({t:'text', v:src.slice(last, m.index)});
    last = re.lastIndex;
    var tag = tplTagOf(m[1].trim());
    if (tag.t === 'end'){ if (stack.length > 1) stack.pop(); continue; }
    if (tag.t === 'else'){ stack[stack.length - 1].inElse = true; continue; }
    add(tag);
  }
  if (last < src.length) add({t:'text', v:src.slice(last)});
  return root;
}
/* 取模板文件：先精确匹配路径，再按文件名后缀兜底（partial "header.html"） */
function tplSource(env, path){
  if (env.files[path] != null) return env.files[path];
  var name = String(path).split('/').pop(), hit = null;
  Object.keys(env.files).forEach(function(p){
    if (!hit && p.split('/').pop() === name) hit = env.files[p];
  });
  return hit;
}
/* 表达式求值：支持 . / $. / 嵌套字段 / 字符串字面量 / now.Format / .Date.Format */
function tplEval(expr, ctx, root){
  expr = String(expr == null ? '' : expr).trim();
  if (expr === '' || expr === '.') return ctx;
  var lit = expr.match(/^"([\s\S]*)"$/);
  if (lit) return lit[1];
  if (/^now(\.Format\s+"[^"]*")?$/.test(expr)) return String(new Date().getFullYear());
  if (expr.charAt(0) === '(') return [];            /* where / first 之类暂不支持 */
  var fm = expr.match(/^([\w.$]+?)\.Format\s+"([^"]*)"$/);
  var layout = '';
  if (fm){ expr = fm[1]; layout = fm[2]; }
  var base = expr.charAt(0) === '$' ? root : ctx;
  var keys = expr.replace(/^\$?\.?/, '').split('.').filter(Boolean);
  var cur = base;
  for (var i = 0; i < keys.length && cur != null; i++) cur = cur[keys[i]];
  if (layout && cur != null) return layout.indexOf('15:04') > -1 ? String(cur) : String(cur).slice(0, 10);
  return cur;
}
function tplTruthy(v){
  if (v == null || v === false || v === '' || v === 0) return false;
  if (Array.isArray(v)) return v.length > 0;
  return true;
}
function tplRender(nodes, ctx, root, env){
  var out = '';
  for (var i = 0; i < nodes.length; i++) out += tplNode(nodes[i], ctx, root, env);
  return out;
}
function tplNode(n, ctx, root, env){
  if (n.t === 'text') return n.v;
  if (n.t === 'var'){
    var v = tplEval(n.expr, ctx, root);
    return v == null ? '' : String(v);
  }
  if (n.t === 'define'){ env.defines[n.name] = n; return ''; }
  if (n.t === 'block'){
    var d = env.defines[n.name];
    return d ? tplRender(d.kids, tplEval(n.expr, ctx, root), root, env)
             : tplRender(n.kids, ctx, root, env);
  }
  if (n.t === 'partial'){
    var src = tplSource(env, n.name);
    if (src == null){ env.warns.push('缺少片段 ' + n.name); return ''; }
    return tplRender(tplParse(src).kids, tplEval(n.expr, ctx, root), root, env);
  }
  if (n.t === 'with' || n.t === 'if'){
    var w = tplEval(n.expr, ctx, root);
    return tplTruthy(w) ? tplRender(n.kids, w === true ? ctx : w, root, env)
                        : tplRender(n.elseKids || [], ctx, root, env);
  }
  if (n.t === 'range'){
    var list = tplEval(n.expr, ctx, root);
    if (!Array.isArray(list) || !list.length) return tplRender(n.elseKids || [], ctx, root, env);
    return list.map(function(item){ return tplRender(n.kids, item, root, env); }).join('');
  }
  return '';
}

/* 站点级上下文 */
function buildSiteCtx(){
  var s = db.settings;
  return {
    Title: s.siteName, Language: 'zh-CN', Author: s.author,
    Description: s.description || s.slogan || '',
    Params: {favicon: s.favicon || '/assets/favicon.ico', logo: s.logoLight || '',
             slogan: s.slogan || '', description: s.description || ''},
    Menus: {main: buildMenu()},
    RegularPages: [], Pages: []
  };
}
function buildMenu(){
  var items = [{Name:'首页', URL:'/index.html'}];
  db.pages.filter(function(p){ return p.inNav && p.status === 'publish'; })
    .sort(function(a,b){ return (a.navOrder||0) - (b.navOrder||0) || a.id - b.id; })
    .forEach(function(p){ items.push({Name:p.title, URL:pagePath(p)}); });
  return items;
}
function buildPostCtx(p, site){
  return {
    Title: p.title, Date: p.date, Content: md(p.md),
    Permalink: '/article/' + p.id + '.html',
    Params: {category: p.cat, cover: p.cover ? postCoverSrc(p) : '',
             excerpt: p.excerpt || '', tags: (p.tags || []).join(' · '),
             keywords: p.keywords || '', attach: p.attach || ''},
    Site: site
  };
}
function buildListItem(p){
  return {Title: p.title, Permalink: '/article/' + p.id + '.html', Date: p.date,
          Params: {excerpt: p.excerpt || '', category: p.cat}};
}
function buildListCtx(title, items, site){
  return {Title: title, Pages: items, Site: site};
}

/* 主题样式：构建产物自带的极简样式表 */
function buildThemeCss(){
  return [
    ':root{--fg:#0f172a;--muted:#6b7590;--line:#e6e9f4;--accent:#5b5bd6;--bg:#f7f8fc}',
    '*{box-sizing:border-box}',
    'body{margin:0;background:var(--bg);color:var(--fg);line-height:1.75;',
    '  font-family:"Inter","Segoe UI",system-ui,"PingFang SC","Microsoft YaHei",sans-serif}',
    '.wrap{max-width:760px;margin:0 auto;padding:40px 22px 72px}',
    '.site-head{display:flex;align-items:center;gap:16px;flex-wrap:wrap;',
    '  padding:16px 22px;border-bottom:1px solid var(--line);background:#fff}',
    '.site-head .brand{display:flex;align-items:center;gap:10px;font-weight:700;',
    '  color:inherit;text-decoration:none}',
    '.site-head .brand img{height:28px;width:auto;display:block}',
    '.site-head nav{margin-left:auto;display:flex;gap:16px;flex-wrap:wrap}',
    '.site-head nav a{color:var(--muted);text-decoration:none;font-size:14px}',
    '.site-head nav a:hover{color:var(--accent)}',
    'article.post h1,.list h1{font-size:28px;letter-spacing:-.4px;margin:0 0 12px}',
    'article.post h2{font-size:20px;margin:28px 0 10px;letter-spacing:-.2px}',
    'article.post h3{font-size:16.5px;margin:22px 0 8px}',
    'article.post h4{font-size:15px;margin:18px 0 6px}',
    'article.post h5,article.post h6{font-size:14px;margin:16px 0 6px;color:var(--muted)}',
    'article.post p{margin:10px 0}',
    'article.post ul,article.post ol{padding-left:22px;margin:10px 0}',
    'article.post li{margin:4px 0}',
    'article.post table{width:100%;border-collapse:collapse;margin:18px 0;font-size:13.5px}',
    'article.post th,article.post td{border:1px solid var(--line);padding:8px 11px;text-align:left;vertical-align:top}',
    'article.post th{background:#f7f8fc;font-weight:600}',
    'article.post del{color:var(--muted)}',
    'article.post ul.md-task{list-style:none;padding-left:0}',
    'article.post ul.md-task li{display:flex;align-items:flex-start;gap:8px}',
    'article.post ul.md-task input{margin:8px 0 0;accent-color:var(--accent)}',
    '.post-meta{display:flex;gap:12px;flex-wrap:wrap;color:var(--muted);font-size:13px;margin-bottom:22px}',
    '.cover{width:100%;max-height:320px;object-fit:cover;border-radius:14px;margin-bottom:22px}',
    '.lede{padding:10px 16px;border-left:3px solid var(--accent);background:#eef0fe;',
    '  color:var(--muted);border-radius:0 10px 10px 0;margin:0 0 22px}',
    '.list .item{padding:18px 0;border-bottom:1px solid var(--line)}',
    '.list .item h2{margin:0 0 6px;font-size:19px}',
    '.list .item h2 a{color:var(--fg);text-decoration:none}',
    '.list .item h2 a:hover{color:var(--accent)}',
    '.list .excerpt{margin:0 0 6px;color:var(--muted);font-size:14px}',
    '.list time{font-size:12.5px;color:var(--muted)}',
    'pre{background:#0b0e17;color:#dbe3f5;padding:16px 18px;border-radius:12px;overflow-x:auto}',
    'pre code{background:none;color:inherit;font-size:13px}',
    'code{font-family:Consolas,monospace;font-size:13px;background:#eef0f7;padding:1px 5px;border-radius:5px}',
    'blockquote{margin:18px 0;padding:10px 16px;border-left:3px solid var(--accent);',
    '  background:#fff;color:var(--muted)}',
    'img{max-width:100%}',
    'a{color:var(--accent)}',
    '.site-foot{padding:24px 22px 40px;text-align:center;color:var(--muted);font-size:12.5px;',
    '  border-top:1px solid var(--line)}',
    '.para{overflow-wrap:anywhere}'
  ].join('\n');
}

/* 构建：返回 {files:[{path,text}], log:[], warns:[], ms} */
function buildOutput(mode){
  var t0 = Date.now();
  var env = {files:{}, defines:{}, warns:[]};
  db.templates.files.forEach(function(f){ env.files[f.path] = f.content; });
  var site = buildSiteCtx();
  var files = [], log = [];
  function push(path, text){ files.push({path:path, text:text}); }
  function renderWith(tplPath, ctx){
    env.defines = {};
    var pageSrc = tplSource(env, tplPath);
    if (pageSrc == null){ env.warns.push('缺少模板 ' + tplPath + '，该页已跳过'); return ''; }
    tplRender(tplParse(pageSrc).kids, ctx, ctx, env);   /* 先注册 define */
    var baseSrc = tplSource(env, 'layouts/base.html');
    if (baseSrc == null){ env.warns.push('缺少 layouts/base.html，已用内置骨架'); baseSrc = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>{{ .Title }}</title></head><body>{{ block "main" . }}{{ end }}</body></html>'; }
    return tplRender(tplParse(baseSrc).kids, ctx, ctx, env)
      .replace(/<img[^>]*src=""[^>]*>/g, '');   /* 没配 logo 时不留空图 */
  }
  var pub = db.posts.filter(function(p){ return p.status === 'publish'; })
    .sort(function(a,b){ return (a.date || '') < (b.date || '') ? 1 : -1; });

  if (mode === 'dynamic'){
    push('data.json', JSON.stringify({
      site: {title: db.settings.siteName, slogan: db.settings.slogan, author: db.settings.author,
             description: db.settings.description},
      posts: pub.map(function(p){
        return {id:p.id, title:p.title, slug:'article-' + p.id, category:p.cat, tags:p.tags,
                date:p.date, excerpt:p.excerpt || '', keywords:p.keywords || '',
                cover: p.cover ? postCoverSrc(p) : '', attach: p.attach || '', html: md(p.md)};
      }),
      pages: db.pages.filter(function(p){ return p.status === 'publish'; }).map(function(p){
        return {id:p.id, title:p.title, path:pagePath(p), template:p.template, parent:p.parent,
                menu:{inNav:p.inNav, order:p.navOrder}, visible:p.visible,
                date:p.date, description:p.excerpt || '', html:md(p.md)};
      }),
      categories: db.categories
    }, null, 2));
    push('api.php', [
      '<?php',
      '/* CiciCms 动态接口：按请求返回站点的 JSON 数据 */',
      'declare(strict_types=1);',
      'header("Content-Type: application/json; charset=utf-8");',
      'header("Access-Control-Allow-Origin: *");',
      '$file = __DIR__ . "/data.json";',
      'if (!is_file($file)) { http_response_code(404); echo "{\\"error\\":\\"data.json 缺失\\"}"; exit; }',
      '$data = json_decode((string)file_get_contents($file), true);',
      '$path = trim((string)($_GET["path"] ?? ""), "/");',
      'if ($path === "") { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }',
      'foreach ($data["posts"] as $post) {',
      '    if ($post["slug"] === $path) { echo json_encode($post, JSON_UNESCAPED_UNICODE); exit; }',
      '}',
      'http_response_code(404);',
      'echo json_encode(["error" => "未找到内容"], JSON_UNESCAPED_UNICODE);'
    ].join('\n'));
    log.push('导出 data.json（文章 ' + pub.length + ' · 单页 ' + db.pages.filter(function(p){ return p.status === 'publish'; }).length + '）');
    log.push('生成 api.php（支持 ?path=article-1 取单篇）');
  } else {
    /* 首页 / 归档 */
    push('index.html', renderWith('layouts/list.html',
      buildListCtx('首页', pub.map(buildListItem), site)));
    log.push('套用 templates：layouts/base.html + layouts/list.html → index.html');

    /* 文章页 */
    pub.forEach(function(p){
      push('article/' + p.id + '.html', renderWith('layouts/post.html', buildPostCtx(p, site)));
    });
    log.push('套用 layouts/post.html → article/*.html（' + pub.length + ' 篇）');

    /* 栏目页 */
    db.categories.forEach(function(c){
      var items = pub.filter(function(p){ return p.cat === c; }).map(buildListItem);
      var slug = (db.catSlugs && db.catSlugs[c]) || CAT_SLUG_FALLBACK[c] || ('cat-' + (db.categories.indexOf(c) + 1));
      push('category/' + slug + '.html', renderWith('layouts/list.html', buildListCtx(c, items, site)));
    });
    log.push('生成栏目页（' + db.categories.length + ' 个，套用 layouts/list.html）');

    /* 单页 */
    var pubPages = db.pages.filter(function(p){ return p.status === 'publish'; });
    pubPages.forEach(function(p){
      var path = pagePath(p).replace(/^\//, '');
      push(path, renderWith('layouts/post.html', {
        Title: p.title, Date: p.date, Content: md(p.md), Permalink: pagePath(p),
        Params: {category: '单页', cover: p.cover ? postCoverSrc(p) : '',
                 excerpt: p.excerpt || '', tags: '', keywords: p.keywords || ''},
        Site: site
      }));
    });
    log.push('生成单页（' + pubPages.length + ' 个，含层级路径）');

    /* 404 */
    push('404.html', renderWith('layouts/list.html',
      buildListCtx('页面不存在', [], site)));
    /* sitemap */
    var urls = ['/index.html'].concat(
      pub.map(function(p){ return '/article/' + p.id + '.html'; }),
      pubPages.map(pagePath)
    );
    push('sitemap.xml', '<?xml version="1.0" encoding="UTF-8"?>\n' +
      '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' +
      urls.map(function(u){
        return '  <url><loc>' + SITE_ORIGIN + u + '</loc></url>';
      }).join('\n') + '\n</urlset>\n');
    log.push('生成 sitemap.xml（' + urls.length + ' 条 URL）');
    /* rss */
    push('rss.xml', '<?xml version="1.0" encoding="UTF-8"?>\n<rss version="2.0">\n<channel>\n' +
      '  <title>' + esc(db.settings.siteName) + '</title>\n' +
      '  <link>' + SITE_ORIGIN + '/</link>\n' +
      '  <description>' + esc(db.settings.description || db.settings.slogan || '') + '</description>\n' +
      pub.slice(0, 20).map(function(p){
        return '  <item><title>' + esc(p.title) + '</title>' +
          '<link>' + SITE_ORIGIN + '/article/' + p.id + '.html</link>' +
          '<pubDate>' + esc(p.date) + '</pubDate>' +
          '<description>' + esc(p.excerpt || '') + '</description></item>';
      }).join('\n') + '\n</channel>\n</rss>\n');
    log.push('生成 rss.xml（最近 ' + Math.min(20, pub.length) + ' 篇）');
    /* 主题样式 */
    push('assets/theme.css', buildThemeCss());
    log.push('写入 assets/theme.css');
  }
  return {files:files, log:log, warns:env.warns, ms:Date.now() - t0};
}

/* ---------- store-only ZIP（不压缩，零依赖） ---------- */
function utf8Bytes(str){
  if (typeof TextEncoder !== 'undefined') return new TextEncoder().encode(str);
  var out = [];
  for (var i = 0; i < str.length; i++){
    var c = str.charCodeAt(i);
    if (c < 0x80) out.push(c);
    else if (c < 0x800) out.push(0xc0 | (c >> 6), 0x80 | (c & 63));
    else out.push(0xe0 | (c >> 12), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63));
  }
  return new Uint8Array(out);
}
var CRC_TABLE = (function(){
  var t = new Int32Array(256);
  for (var n = 0; n < 256; n++){
    var c = n;
    for (var k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c;
  }
  return t;
})();
function crc32(bytes){
  var c = -1;
  for (var i = 0; i < bytes.length; i++) c = CRC_TABLE[(c ^ bytes[i]) & 0xff] ^ (c >>> 8);
  return (c ^ -1) >>> 0;
}
function zipStore(files){
  var parts = [], central = [], offset = 0;
  files.forEach(function(f){
    var name = utf8Bytes(f.path), data = utf8Bytes(f.text), crc = crc32(data);
    var lh = new Uint8Array(30 + name.length), lv = new DataView(lh.buffer);
    lv.setUint32(0, 0x04034b50, true);
    lv.setUint16(4, 20, true); lv.setUint16(6, 0x0800, true); lv.setUint16(8, 0, true);
    lv.setUint16(10, 0, true); lv.setUint16(12, 0, true);
    lv.setUint32(14, crc, true); lv.setUint32(18, data.length, true); lv.setUint32(22, data.length, true);
    lv.setUint16(26, name.length, true); lv.setUint16(28, 0, true);
    lh.set(name, 30);
    parts.push(lh, data);

    var cd = new Uint8Array(46 + name.length), cv = new DataView(cd.buffer);
    cv.setUint32(0, 0x02014b50, true);
    cv.setUint16(4, 20, true); cv.setUint16(6, 20, true); cv.setUint16(8, 0x0800, true);
    cv.setUint16(10, 0, true); cv.setUint16(12, 0, true); cv.setUint16(14, 0, true);
    cv.setUint32(16, crc, true); cv.setUint32(20, data.length, true); cv.setUint32(24, data.length, true);
    cv.setUint16(28, name.length, true); cv.setUint16(30, 0, true); cv.setUint16(32, 0, true);
    cv.setUint16(34, 0, true); cv.setUint16(36, 0, true); cv.setUint32(38, 0, true);
    cv.setUint32(42, offset, true);
    cd.set(name, 46);
    central.push(cd);
    offset += lh.length + data.length;
  });
  var cdSize = central.reduce(function(a, b){ return a + b.length; }, 0);
  var eocd = new Uint8Array(22), ev = new DataView(eocd.buffer);
  ev.setUint32(0, 0x06054b50, true);
  ev.setUint16(8, files.length, true); ev.setUint16(10, files.length, true);
  ev.setUint32(12, cdSize, true); ev.setUint32(16, offset, true);
  var all = parts.concat(central, [eocd]);
  var total = all.reduce(function(a, b){ return a + b.length; }, 0);
  var out = new Uint8Array(total), pos = 0;
  all.forEach(function(chunk){ out.set(chunk, pos); pos += chunk.length; });
  return out;
}
function fileDownload(name, data, mime){
  var blob = new Blob([data], {type: mime || 'application/octet-stream'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  a.click();
  URL.revokeObjectURL(a.href);
}

/* ================= 视图：一键更新 ================= */
var genTimer = null;
function vDeploy(){
  var s = db.settings;
  var mode = s.genMode === 'dynamic' ? 'dynamic' : 'static';
  var total = db.posts.length;
  var pub = db.posts.filter(function(p){ return p.status === 'publish'; }).length;
  var catN = db.categories.length;
  var pageN = db.pages.length;
  var pgPub = db.pages.filter(function(p){ return p.status === 'publish'; }).length;
  var nStatic = pub + catN + pgPub + 5;   // 文章页 + 栏目页 + 单页 + index/404/sitemap/rss/theme.css
  var nDynamic = 2;               // data.json + api.php
  var lastText = s.lastGenAt
    ? '上次更新：' + esc(s.lastGenAt) + ' · ' + (s.genMode === 'dynamic' ? '动态' : '静态')
    : '尚未更新过';

  view.innerHTML =
    '<div class="page-head reveal"><div><h1>一键更新</h1><p>把后台内容重新构建成可发布的站点文件</p></div></div>' +
    '<div class="card reveal" style="animation-delay:.04s">' +
      '<div class="gen-form">' +
      '<div class="set-sec">一键更新文章</div>' +
      '<div class="gen-intro">更新会把当前的 <b>'+total+' 篇文章</b>（已发布 '+pub+' 篇）、<b>'+catN+' 个栏目</b>、' +
        '<b>'+pageN+' 个单页</b>（已发布 '+pgPub+' 个）与媒体引用一次性重新构建，两种方式<b>只能选一个</b>：<br>' +
        '· <b>一键生成静态文件</b>：套用「模板管理」里的布局与片段，输出可直接托管的 <code>HTML / CSS</code> 与 <code>sitemap / rss</code>，不依赖数据库，访问最快；<br>' +
        '· <b>一键生成动态文件</b>：输出 <code>data.json</code> 与 <code>api.php</code>，由服务器按请求实时读取，适合需要评论、会员等交互的站点。</div>' +
      '<div class="gen-opts" id="genOpts">' +
        '<label class="gen-opt'+(mode === 'static' ? ' on' : '')+'" id="optStatic" data-mode="static">' +
          '<input type="radio" id="inStatic" name="genMode" value="static"'+(mode === 'static' ? ' checked' : '')+'>' +
          '<span class="gen-dot"></span>' +
          '<span class="gen-opt-t">一键生成静态文件 <em>推荐</em></span>' +
          '<span class="gen-opt-f">index.html · article/*.html · sitemap.xml · rss.xml（'+nStatic+' 个文件）</span>' +
        '</label>' +
        '<label class="gen-opt'+(mode === 'dynamic' ? ' on' : '')+'" id="optDynamic" data-mode="dynamic">' +
          '<input type="radio" id="inDynamic" name="genMode" value="dynamic"'+(mode === 'dynamic' ? ' checked' : '')+'>' +
          '<span class="gen-dot"></span>' +
          '<span class="gen-opt-t">一键生成动态文件</span>' +
          '<span class="gen-opt-f">data.json · api.php（'+nDynamic+' 个文件 · 需 PHP 环境）</span>' +
        '</label>' +
      '</div>' +
      '<div class="gen-act">' +
        '<button class="btn primary" id="genBtn">一键更新</button>' +
        '<span class="gen-last" id="genLast">'+lastText+'</span>' +
      '</div>' +
      '<div class="gen-bar" id="genBar"><i id="genFill"></i></div>' +
      '<div class="gen-meta">' +
        '<span class="gen-state" id="genState"></span>' +
        '<b id="genPct">0%</b>' +
      '</div>' +
      '<div class="gen-foot">更新只重新生成输出文件，不会改动已有文章、单页与媒体；中途离开页面会中断本次更新，再次点击「一键更新」即可重新开始。构建完成后可逐个下载产物，或打包成 zip 直接上传到空间。</div>' +
      '<div class="gen-result" id="genResult" hidden>' +
        '<div class="gr-head"><b>构建产物</b><span id="genTotal"></span>' +
          '<button class="btn sm primary" id="genZip">下载全部 .zip</button></div>' +
        '<div class="gr-list" id="genFiles"></div>' +
        '<div class="gr-warn" id="genWarn" hidden></div>' +
      '</div>' +
      '</div>' +
    '</div>';

  var $ = function(id){ return document.getElementById(id); };
  var running = false;

  function idleText(){
    var st = s.genMode !== 'dynamic';
    return '等待开始 · 将生成 ' + (st ? nStatic + ' 个静态文件' : nDynamic + ' 个动态文件') +
           '（' + (st ? '静态' : '动态') + '模式）';
  }
  function applyMode(m, persist){
    var st = m !== 'dynamic';
    $('optStatic').classList.toggle('on', st);
    $('optDynamic').classList.toggle('on', !st);
    $('inStatic').checked = st;
    $('inDynamic').checked = !st;
    if (persist){
      s.genMode = st ? 'static' : 'dynamic';
      save();
    }
    if (!running){
      $('genState').textContent = idleText();
      $('genPct').textContent = '0%';
      $('genPct').className = '';
      $('genFill').style.width = '0%';
      $('genBar').className = 'gen-bar';
    }
  }
  $('genOpts').addEventListener('click', function(e){
    var opt = e.target.closest('.gen-opt');
    if (!opt || running) return;
    applyMode(opt.dataset.mode, true);
  });
  $('inStatic').addEventListener('change', function(){ if (this.checked && !running) applyMode('static', true); });
  $('inDynamic').addEventListener('change', function(){ if (this.checked && !running) applyMode('dynamic', true); });
  applyMode(mode, false);

  /* 构建产物：文件清单 + 单个下载 + 打包下载 */
  function showResult(product){
    LAST_BUILD = product;
    var box = $('genResult');
    if (!box) return;
    var bytes = product.files.reduce(function(a, f){ return a + byteLen(f.text); }, 0);
    $('genTotal').textContent = '共 ' + product.files.length + ' 个文件 · ' +
      fmtSize(bytes) + ' · 构建用时 ' + product.ms + ' ms';
    $('genFiles').innerHTML = product.files.map(function(f){
      return '<div class="gr-row"><span class="gr-path">' + esc(f.path) + '</span>' +
        '<span class="gr-size">' + fmtSize(byteLen(f.text)) + '</span>' +
        '<a class="gr-dl" data-dl="' + esc(f.path) + '">下载</a></div>';
    }).join('');
    var warns = product.warns || [];
    $('genWarn').hidden = !warns.length;
    $('genWarn').textContent = warns.length ? '模板提示：' + warns.join('；') : '';
    box.hidden = false;
  }
  $('genFiles').addEventListener('click', function(e){
    var a = e.target.closest('[data-dl]');
    if (!a || !LAST_BUILD) return;
    var f = LAST_BUILD.files.filter(function(x){ return x.path === a.dataset.dl; })[0];
    if (!f) return;
    fileDownload(f.path.split('/').pop(), f.text, guessMime(f.path));
  });
  $('genZip').addEventListener('click', function(){
    if (!LAST_BUILD) return;
    var d = new Date(), pad = function(n){ return (n < 10 ? '0' : '') + n; };
    var name = 'nebula-site-' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '.zip';
    fileDownload(name, zipStore(LAST_BUILD.files), 'application/zip');
  });

  function run(){
    if (running) return;
    running = true;
    /* 先把当前编辑状态落盘，避免构建用到旧数据 */
    nbFlush();
    /* 前端产物照旧生成，用于「下载全部 .zip」 */
    var product = null;
    try{ product = buildOutput(s.genMode === 'dynamic' ? 'dynamic' : 'static'); }catch(e){}
    var n = product ? product.files.length : 0;
    var steps = ['保存内容到数据库', '渲染文章页与栏目页', '写入静态资源', '生成 sitemap 与索引', '完成更新'];
    var pct = 0;
    $('genBtn').disabled = true;
    $('genBtn').textContent = '正在更新…';
    $('genBar').className = 'gen-bar run';
    $('genPct').className = '';
    $('genPct').textContent = '0%';
    $('genFill').style.width = '0%';
    $('genState').textContent = steps[0] + '…';

    var tick = 0, failed = false;
    function poll(){
      var body = JSON.stringify({ _token: NEBULA_TOKEN });
      var xhr = new XMLHttpRequest();
      xhr.open('POST', NEBULA_API + '?r=build', true);
      xhr.setRequestHeader('Content-Type','application/json;charset=utf-8');
      xhr.onreadystatechange = function(){
        if (xhr.readyState !== 4) return;
        var j = null;
        try{ j = JSON.parse(xhr.responseText); }catch(e){}
        tick++;
        if (!j || !j.ok){
          failed = true;
          $('genBar').className = 'gen-bar';
          $('genState').textContent = '更新失败：' + (j && j.msg ? j.msg : '服务器无响应');
          $('genBtn').disabled = false;
          $('genBtn').textContent = '一键更新';
          running = false;
          return;
        }
        pct = Math.min(96, 12 + tick * 17);
        $('genFill').style.width = pct + '%';
        $('genPct').textContent = pct + '%';
        $('genState').textContent = steps[Math.min(steps.length-2, tick)] + '…';
        if (j.finished){
          clearInterval(genTimer); genTimer = null;
          pct = 100;
          $('genFill').style.width = '100%';
          $('genPct').textContent = '100%';
          $('genPct').className = 'ok';
          $('genBar').className = 'gen-bar done';
          s.lastGenAt = nowStamp();
          save();
          $('genBtn').disabled = false;
          $('genBtn').textContent = '一键更新';
          var made = typeof j.files === 'number' ? j.files : n;
          $('genState').textContent = '更新完成 · 服务器已写入 ' + made + ' 个文件';
          $('genLast').textContent = '上次更新：' + esc(s.lastGenAt) + ' · 服务器静态文件';
          if (product) showResult(product);
          running = false;
          return;
        }
        if (tick > 120){
          $('genState').textContent = '更新超时，请到「数据管理」检查';
          $('genBtn').disabled = false;
          $('genBtn').textContent = '一键更新';
          running = false;
        }
      };
      xhr.send(body);
    }
    if (genTimer){ clearInterval(genTimer); genTimer = null; }
    genTimer = setInterval(function(){ if (!failed && tick === 0) poll(); }, 180);
    poll();
  }

  $('genBtn').addEventListener('click', run);
}

/* ================= 视图：站点设置 ================= */
function vSettings(){
  var s = db.settings;
  var pv = function(kind){ return esc(sitePreviewSrc(kind)); };
  view.innerHTML =
    '<div class="page-head reveal"><div><h1>站点设置</h1><p>基本信息、站点形象与数据管理</p></div></div>' +
    '<div class="card reveal" style="animation-delay:.04s">' +
      '<div class="set-form">' +
      '<div class="set-sec">基本信息</div>' +
      '<div class="settings-grid">' +
        '<div class="field"><label>站点名称</label><input id="sName" value="'+esc(s.siteName)+'"></div>' +
        '<div class="field"><label>副标题</label><input id="sSlogan" value="'+esc(s.slogan)+'"></div>' +
        '<div class="field"><label>作者名</label><input id="sAuthor" value="'+esc(s.author)+'"></div>' +
        '<div class="field"><label>列表每页条数（固定）</label><input value="'+PAGE_SIZE+' 条 / 页" disabled></div>' +
        '<div class="field col-span"><label>站点关键词</label><input id="sKeywords" value="'+esc(s.keywords)+'" placeholder="用英文逗号分隔，如 设计系统, 前端, 静态博客"></div>' +
        '<div class="field col-span"><label>站点描述</label><textarea id="sDesc" class="set-textarea" placeholder="用于搜索结果与分享卡片的一段简介">'+esc(s.description)+'</textarea></div>' +
      '</div>' +
      '<div class="set-sec">站点形象</div>' +
      '<div class="logo-list">' +
        '<div class="logo-row">' +
          '<div class="logo-fld"><label>站点明亮 Logo（图片 URL）' +
            '<span class="logo-tag" id="pvLightTag">'+assetTag('logoLight')+'</span></label>' +
            '<input id="sLogoLight" value="'+esc(s.logoLight)+'" placeholder="留空使用内置图标"></div>' +
          '<div class="logo-thumb light"><img id="pvLight" alt="明亮 Logo 预览" src="'+pv('logoLight')+'"></div>' +
        '</div>' +
        '<div class="logo-row">' +
          '<div class="logo-fld"><label>站点暗夜 Logo（图片 URL）' +
            '<span class="logo-tag" id="pvDarkTag">'+assetTag('logoDark')+'</span></label>' +
            '<input id="sLogoDark" value="'+esc(s.logoDark)+'" placeholder="留空使用内置图标"></div>' +
          '<div class="logo-thumb dark"><img id="pvDark" alt="暗夜 Logo 预览" src="'+pv('logoDark')+'"></div>' +
        '</div>' +
        '<div class="logo-row">' +
          '<div class="logo-fld"><label>站点图标 favicon（图片 URL）' +
            '<span class="logo-tag" id="pvFaviconTag">'+assetTag('favicon')+'</span></label>' +
            '<input id="sFavicon" value="'+esc(s.favicon)+'" placeholder="留空使用内置图标"></div>' +
          '<div class="logo-thumb"><img id="pvFavicon" class="site-ico" alt="站点图标预览" src="'+pv('favicon')+'"></div>' +
        '</div>' +
      '</div>' +
      '</div>' +
      '<div class="set-hint">留空即使用内置图标；填写图片 URL 会即时预览。保存后关键词与描述写入页面 meta，图标写入浏览器标签，明亮 Logo 显示在左上角侧栏。</div>' +
      '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;flex-wrap:wrap">' +
        '<button class="btn" id="expBtn">导出 JSON 备份</button>' +
        '<button class="btn danger" id="resetBtn">重置演示数据</button>' +
        '<button class="btn primary" id="sSave">保存设置</button>' +
      '</div>' +
      '</div>' +
    '</div>';

  var $ = function(id){ return document.getElementById(id); };
  function syncPreview(p, raw){
    var url = String(raw || '').trim();
    var img = $(p.img), tag = $(p.tag);
    img.onerror = null;
    tag.className = 'logo-tag';
    if (url){
      img.onerror = function(){
        img.onerror = null;
        img.setAttribute('src', builtinSiteIcon(p.kind));
        tag.textContent = '图片无法加载，已回退内置图标';
        tag.className = 'logo-tag warn';
      };
      img.setAttribute('src', url);
      tag.textContent = '自定义图片';
    } else {
      img.setAttribute('src', builtinSiteIcon(p.kind));
      tag.textContent = '内置图标（未设置）';
    }
  }
  [
    {input:'sLogoLight', img:'pvLight',    tag:'pvLightTag',    kind:'logoLight'},
    {input:'sLogoDark',  img:'pvDark',     tag:'pvDarkTag',     kind:'logoDark'},
    {input:'sFavicon',   img:'pvFavicon',  tag:'pvFaviconTag',  kind:'favicon'}
  ].forEach(function(p){
    syncPreview(p, siteAsset(p.kind));
    $(p.input).addEventListener('input', function(){ syncPreview(p, this.value); });
  });
  $('sSave').addEventListener('click', function(){
    s.siteName = $('sName').value.trim() || s.siteName;
    s.slogan = $('sSlogan').value.trim();
    s.author = $('sAuthor').value.trim() || s.author;
    s.keywords = $('sKeywords').value.trim();
    s.description = $('sDesc').value.trim();
    s.logoLight = $('sLogoLight').value.trim();
    s.logoDark = $('sLogoDark').value.trim();
    s.favicon = $('sFavicon').value.trim();
    save(); applySiteMeta(); alert('设置已保存');
  });
  $('expBtn').addEventListener('click', function(){
    var blob = new Blob([JSON.stringify(db, null, 2)], {type:'application/json'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'nebula-cms-backup.json';
    a.click(); URL.revokeObjectURL(a.href);
  });
  $('resetBtn').addEventListener('click', function(){
    if (!confirm('重置为演示数据？当前所有内容将被覆盖。')) return;
    localStorage.removeItem(KEY); load(); applySiteMeta(); route(); alert('已重置');
  });
}

function renderArticle(id, back){
  var p = findPost(id);
  if (!p){ view.innerHTML = '<div class="page-head"><h1>文章不存在</h1></div>'; return; }
  var backHash = back || '#/posts';
  var hasCover = (typeof p.cover === 'string' && p.cover) || typeof p.cover === 'number';
  view.innerHTML =
    '<div class="preview-wrap reveal"><div class="article">' +
      '<h1>'+esc(p.title)+'</h1>' +
      (hasCover ? '<img class="article-cover" alt="'+esc(p.title)+' 封面" src="'+esc(postCoverSrc(p))+'">' : '') +
      '<div class="meta"><span>'+esc(p.author || db.settings.author)+'</span><span>·</span><span>'+esc(p.date)+'</span><span>·</span>' +
        '<span>'+esc(p.cat)+'</span>' + p.tags.map(function(t){return '<span class="pill pub">'+esc(t)+'</span>'}).join('') +
        (p.attach ? '<a class="pill attach-pill" href="'+esc(p.attach)+'" target="_blank" rel="noopener">附件下载 ↗</a>' : '') +
        (p.status!=='publish' ? '<span class="pill '+PILL[p.status]+'">'+STATUS[p.status]+'（仅后台可见）</span>' : '') +
        '<span style="margin-left:auto"><a href="'+backHash+'" style="color:var(--accent);text-decoration:none">← 返回列表</a></span></div>' +
      (p.excerpt ? '<p class="article-lede">'+esc(p.excerpt)+'</p>' : '') +
      md(p.md) +
    '</div></div>' +
    '<div class="foot-note"><span>'+esc(db.settings.siteName)+' · '+esc(db.settings.slogan)+'</span><span>CiciCms</span></div>';
}

/* ================= 启动 ================= */
sessionLoad();
applyAccount();
route();

/* 关掉加载遮罩；离开页面前把未落盘的改动刷出去 */
(function(){
  var boot = document.getElementById('nebula-boot');
  if (boot){
    boot.style.transition = 'opacity .18s';
    boot.style.opacity = '0';
    setTimeout(function(){ if (boot.parentNode) boot.parentNode.removeChild(boot); }, 200);
  }
  window.addEventListener('beforeunload', function(){
    if (!_nbPending) return;
    var body = JSON.stringify({ _token: NEBULA_TOKEN, db: _nbPending });
    _nbPending = null;
    try{ navigator.sendBeacon(NEBULA_API + '?r=save', new Blob([body],{type:'application/json'})); }catch(e){}
  });
})();

/* 侧栏搜索、账号菜单与全局快捷键 */
(function wireChrome(){
  var ns = document.getElementById('navSearch');
  if (ns){
    ns.addEventListener('focus', function(){ this.blur(); openSearch(); });
    ns.addEventListener('click', function(){ openSearch(); });
  }
  var ab = document.getElementById('acctBtn');
  if (ab) ab.addEventListener('click', openAccount);
  document.addEventListener('keydown', function(e){
    var k = (e.key || '').toLowerCase();
    if ((e.metaKey || e.ctrlKey) && k === 'k'){
      e.preventDefault(); openSearch(); return;
    }
    if (k === 'escape'){
      var box = document.getElementById('cmdkBox');
      if (box){ ovClose(box); }
    }
  });
  if (!SESSION) showLogin();
})();
})();