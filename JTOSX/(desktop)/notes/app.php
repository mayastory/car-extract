<?php
require_once __DIR__ . '/../../core/app_frame.php';
osx_app_header('Notes');
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(osx_base_path() . '/public/css/github-markdown.css'); ?>" />

<style>
  /* Notes (alanagoyal-inspired) */
  :root{
    --n-bg: rgba(255,255,255,.72);
    --n-bg2: rgba(255,255,255,.86);
    --n-sb: rgba(245,245,247,.78);
    --n-border: rgba(0,0,0,.10);
    --n-muted: rgba(0,0,0,.55);
    --n-text: rgba(0,0,0,.90);
    --n-accent: #ffcc00;
    --n-blue: #0a84ff;
  }
  .notes-root{height:100%; display:flex; background: var(--n-bg); color: var(--n-text); overflow:hidden;}
  .notes-sidebar{width: 320px; min-width: 260px; max-width: 420px; background: var(--n-sb); border-right: 1px solid var(--n-border); display:flex; flex-direction:column;}
  .notes-sb-top{padding: 10px 12px 8px; border-bottom: 1px solid rgba(0,0,0,.06);}
  .notes-sb-toprow{display:flex; align-items:center; gap:10px;}
  .notes-sb-title{font-weight:800; font-size: 15px; letter-spacing:.2px;}
  .notes-sb-actions{margin-left:auto; display:flex; gap:8px;}
  .notes-iconbtn{height: 28px; width: 28px; display:grid; place-items:center; border: 1px solid rgba(0,0,0,.10); background: rgba(255,255,255,.55); border-radius: 10px; cursor:pointer;}
  .notes-iconbtn:hover{background: rgba(255,255,255,.78);}
  .notes-search{margin-top: 10px; display:flex; align-items:center; gap:8px; padding: 8px 10px; border-radius: 12px; background: rgba(255,255,255,.75); border: 1px solid rgba(0,0,0,.08);}
  .notes-search input{border:0; outline:none; background:transparent; width:100%; font-size: 13px;}
  .notes-search .k{font-size: 12px; color: var(--n-muted);}

  .notes-sb-list{flex:1; min-height:0; overflow:auto; padding: 10px 10px 14px;}
  .notes-section{margin-top: 12px;}
  .notes-section:first-child{margin-top: 0;}
  .notes-section-title{font-size: 11px; font-weight: 700; color: var(--n-muted); letter-spacing: .4px; text-transform: uppercase; padding: 6px 8px;}

  .note-row{display:flex; gap:10px; padding: 10px 10px; border-radius: 12px; cursor:pointer; align-items:flex-start; position:relative;}
  .note-row:hover{background: rgba(0,0,0,.05);} 
  .note-row.active{background: rgba(255,204,0,.26); border: 1px solid rgba(255,204,0,.35);}
  .note-emoji{width: 22px; flex:0 0 22px; font-size: 18px; line-height: 22px; margin-top: 1px; filter: saturate(1.1);}
  .note-meta{min-width:0; flex:1;}
  .note-title{font-weight: 750; font-size: 13px; white-space: nowrap; overflow:hidden; text-overflow: ellipsis;}
  .note-sub{margin-top: 2px; font-size: 12px; color: var(--n-muted); white-space: nowrap; overflow:hidden; text-overflow: ellipsis;}
  .note-date{margin-top: 4px; font-size: 11px; color: rgba(0,0,0,.45);}
  .note-badges{position:absolute; right: 8px; top: 8px; display:flex; gap:6px;}
  .badge{font-size: 10px; padding: 2px 6px; border-radius: 999px; border: 1px solid rgba(0,0,0,.10); background: rgba(255,255,255,.65); color: rgba(0,0,0,.70);}
  .badge.pub{border-color: rgba(10,132,255,.25); background: rgba(10,132,255,.10);} 

  .notes-main{flex:1; min-width:0; background: var(--n-bg2); display:flex; flex-direction:column;}
  .note-header{height: 52px; padding: 10px 14px; display:flex; align-items:center; gap:10px; border-bottom: 1px solid rgba(0,0,0,.08); background: rgba(255,255,255,.55); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);}
  .note-header .h-emoji{width: 26px; height: 26px; display:grid; place-items:center; font-size: 20px; border-radius: 9px; cursor: default;}
  .note-header .h-emoji.editable{cursor:pointer;}
  .note-header .h-titles{min-width:0; flex:1;}
  .note-header .h-title{font-weight: 800; font-size: 14px; white-space: nowrap; overflow:hidden; text-overflow: ellipsis;}
  .note-header .h-sub{font-size: 12px; color: var(--n-muted); margin-top: 1px;}
  .note-header .h-actions{display:flex; gap:8px;}
  .note-content{flex:1; min-height:0; overflow:auto; padding: 14px 18px 40px;}

  .md{max-width: 860px; margin: 0 auto; font-size: 14px; line-height: 1.65;}
  .md h1{font-size: 28px; margin: 0 0 12px;}
  .md h2{font-size: 22px; margin: 18px 0 10px;}
  .md h3{font-size: 18px; margin: 14px 0 8px;}
  .md p{margin: 10px 0;}
  .md a{color: var(--n-blue); text-decoration:none;}
  .md a:hover{text-decoration: underline;}
  .md code{background: rgba(0,0,0,.06); padding: 2px 6px; border-radius: 8px;}
  .md pre{background: rgba(0,0,0,.06); padding: 12px; border-radius: 12px; overflow:auto;}
  .md blockquote{border-left: 3px solid rgba(0,0,0,.18); margin: 12px 0; padding: 2px 12px; color: rgba(0,0,0,.70);} 
  .md hr{border:0; border-top: 1px solid rgba(0,0,0,.12); margin: 16px 0;}
  .md ul{padding-left: 20px; margin: 10px 0;}
  .md li{margin: 6px 0;}
  .md img{max-width: 100%; border-radius: 12px; border: 1px solid rgba(0,0,0,.08);} 

  .task-list-item{list-style: none; margin-left: -20px;}
  .task-list-item input[type="checkbox"]{margin-right: 10px; transform: translateY(2px);} 
  .task-list-item input[type="checkbox"]{accent-color: var(--n-blue);} 

  .note-editor{max-width: 860px; margin: 0 auto; width:100%; min-height: calc(100vh - 140px);
    border: 1px solid rgba(0,0,0,.10);
    border-radius: 14px;
    padding: 14px 14px;
    outline: none;
    background: rgba(255,255,255,.60);
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 13px;
    line-height: 1.55;
    resize: vertical;
  }

  .empty-state{height:100%; display:grid; place-items:center; color: rgba(0,0,0,.45);} 
  .kbd{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 11px; padding: 1px 6px; border: 1px solid rgba(0,0,0,.12); border-radius: 6px; background: rgba(255,255,255,.7);} 
</style>

<div class="notes-root" id="notes-root" data-app="notes">
  <div class="notes-sidebar">
    <div class="notes-sb-top">
      <div class="notes-sb-toprow">
        <div class="notes-sb-title">Notes</div>
        <div class="notes-sb-actions">
          <button class="notes-iconbtn" id="btn-new" title="New note">＋</button>
        </div>
      </div>
      <div class="notes-search" title="Search (Ctrl/⌘ + K)">
        <span style="opacity:.65">🔎</span>
        <input id="q" type="text" placeholder="Search" autocomplete="off" />
        <span class="k"><span class="kbd">Ctrl</span>+<span class="kbd">K</span></span>
      </div>
    </div>
    <div class="notes-sb-list" id="sb"></div>
  </div>

  <div class="notes-main">
    <div class="note-header" id="hdr" style="display:none;">
      <div class="h-emoji" id="hEmoji">📝</div>
      <div class="h-titles">
        <div class="h-title" id="hTitle">Select a note</div>
        <div class="h-sub" id="hSub">&nbsp;</div>
      </div>
      <div class="h-actions">
        <button class="notes-iconbtn" id="btn-pin" title="Pin/unpin">📌</button>
        <button class="notes-iconbtn" id="btn-edit" title="Edit (Esc to exit)">✎</button>
        <button class="notes-iconbtn" id="btn-del" title="Delete" style="display:none;">🗑</button>
      </div>
    </div>

    <div class="note-content" id="content">
      <div class="empty-state">
        <div>
          <div style="font-size: 22px; font-weight: 800; margin-bottom: 8px;">Select a note</div>
          <div>Or create one from the sidebar.</div>
        </div>
      </div>
    </div>
  </div>
</div>


<script>
(()=> {
  const $ = (sel, el=document) => el.querySelector(sel);
  const $$ = (sel, el=document) => Array.from(el.querySelectorAll(sel));

  // --- session id (alanagoyal SessionId.tsx) ---
  const SESSION_KEY = 'session_id';
  function uuidv4(){
    const a = crypto.getRandomValues(new Uint8Array(16));
    a[6] = (a[6] & 0x0f) | 0x40;
    a[8] = (a[8] & 0x3f) | 0x80;
    const hex = [...a].map(b => b.toString(16).padStart(2,'0')).join('');
    return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
  }
  function getSessionId(){
    // alanagoyal uses localStorage key: "session_id"
    // migrate from older JTOSX builds that used "notesSessionId" (keeps existing user notes)
    let sid = localStorage.getItem(SESSION_KEY) || '';
    if (!/^[0-9a-f-]{36}$/i.test(sid)) {
      const legacy = localStorage.getItem('notesSessionId') || '';
      if (/^[0-9a-f-]{36}$/i.test(legacy)) {
        sid = legacy;
        localStorage.setItem(SESSION_KEY, sid);
      } else {
        sid = uuidv4();
        localStorage.setItem(SESSION_KEY, sid);
      }
    }
    return sid;
  }
  const sessionId = getSessionId();

  // --- state ---
  let notes = [];               // list payload (includes content for search)
  let selectedSlug = null;
  let selectedNote = null;      // full note
  let pinned = new Set();       // slugs
  let editing = false;

  let localSearchResults = null; // Note[] | null
  let highlightedIndex = 0;

  const sbEl = $('#sb');
  const qEl = $('#q');
  const hdrEl = $('#hdr');
  const contentEl = $('#content');
  const hEmojiEl = $('#hEmoji');
  const hTitleEl = $('#hTitle');
  const hSubEl = $('#hSub');
  const btnNew = $('#btn-new');
  const btnPin = $('#btn-pin');
  const btnEdit = $('#btn-edit');
  const btnDel = $('#btn-del');

  // --- base url helper (same base resolution as the rest of JTOSX) ---
  const BASE = (window.OSX_APP?.base || window.OSX_BASE || '').replace(/\/$/, '');
  const url = (p) => {
    if (!p) return BASE + '/';
    if (p.startsWith('http://') || p.startsWith('https://')) return p;
    if (!p.startsWith('/')) p = '/' + p;
    return BASE + p;
  };

  // --- display-created-at.ts (ported 1:1) ---
  const PUBLIC_DISPLAY_CREATED_AT_KEY = "public-note-display-created-at-v2";
  const DAY_START_MINUTES = 8 * 60;  // 8:00 AM
  const DAY_END_MINUTES = 23 * 60;   // 11:00 PM

  let publicDisplayCreatedAtCache = null;

  function isValidDate(d){ return !Number.isNaN(d.getTime()); }

  function getDisplayCache(){
    if (publicDisplayCreatedAtCache) return publicDisplayCreatedAtCache;
    try{
      const raw = window.sessionStorage.getItem(PUBLIC_DISPLAY_CREATED_AT_KEY);
      if (!raw) { publicDisplayCreatedAtCache = {}; return publicDisplayCreatedAtCache; }
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object') { publicDisplayCreatedAtCache = parsed; return publicDisplayCreatedAtCache; }
    }catch{}
    publicDisplayCreatedAtCache = {};
    return publicDisplayCreatedAtCache;
  }

  function saveDisplayCache(cache){
    try{ window.sessionStorage.setItem(PUBLIC_DISPLAY_CREATED_AT_KEY, JSON.stringify(cache)); }catch{}
  }

  function toDayKey(date){
    const month = String(date.getMonth()+1).padStart(2,'0');
    const day = String(date.getDate()).padStart(2,'0');
    return `${date.getFullYear()}-${month}-${day}`;
  }

  function simpleHash(value){
    let hash = 0;
    for (let i=0;i<value.length;i++){
      hash = ((hash<<5)-hash) + value.charCodeAt(i);
      hash |= 0;
    }
    return Math.abs(hash);
  }

  function seededRandom(seed){
    const x = Math.sin(seed) * 10000;
    return x - Math.floor(x);
  }

  function deterministicInt(seedKey, min, max){
    if (max <= min) return min;
    const rand = seededRandom(simpleHash(seedKey));
    return Math.floor(rand * (max-min+1)) + min;
  }

  function withMinutes(baseDate, totalMinutes){
    const date = new Date(baseDate);
    const hours = Math.floor(totalMinutes/60);
    const minutes = totalMinutes % 60;
    date.setHours(hours, minutes, 0, 0);
    return date;
  }

  function generatePublicDisplayDate(note, now, dayKey){
    const seedBase = `${dayKey}:${note.category ?? "unknown"}:${note.id}`;

    switch(note.category){
      case "today": {
        const currentHourMinutes = now.getHours()*60;
        const minMinutes = currentHourMinutes >= DAY_START_MINUTES ? DAY_START_MINUTES : 0;
        const minutes = deterministicInt(`${seedBase}:today`, minMinutes, currentHourMinutes);
        return withMinutes(now, minutes);
      }
      case "yesterday": {
        const y = new Date(now); y.setDate(y.getDate()-1);
        const minutes = deterministicInt(`${seedBase}:yesterday`, DAY_START_MINUTES, DAY_END_MINUTES);
        return withMinutes(y, minutes);
      }
      case "7": {
        const d = new Date(now);
        const daysAgo = deterministicInt(`${seedBase}:7:days`, 2, 7);
        d.setDate(d.getDate() - daysAgo);
        const minutes = deterministicInt(`${seedBase}:7:minutes`, DAY_START_MINUTES, DAY_END_MINUTES);
        return withMinutes(d, minutes);
      }
      case "30": {
        const d = new Date(now);
        const daysAgo = deterministicInt(`${seedBase}:30:days`, 8, 30);
        d.setDate(d.getDate() - daysAgo);
        const minutes = deterministicInt(`${seedBase}:30:minutes`, DAY_START_MINUTES, DAY_END_MINUTES);
        return withMinutes(d, minutes);
      }
      case "older": {
        const d = new Date(now);
        const daysAgo = deterministicInt(`${seedBase}:older:days`, 31, 365);
        d.setDate(d.getDate() - daysAgo);
        const minutes = deterministicInt(`${seedBase}:older:minutes`, DAY_START_MINUTES, DAY_END_MINUTES);
        return withMinutes(d, minutes);
      }
      default: {
        const created = new Date(note.created_at);
        return isValidDate(created) ? created : now;
      }
    }
  }

  function withDisplayCreatedAt(note){
    if (!note.public){
      return { ...note, display_created_at: note.created_at };
    }
    const now = new Date();
    const dayKey = toDayKey(now);
    const cache = getDisplayCache();
    const cacheKey = `${dayKey}:${note.category ?? "unknown"}:${note.id}`;
    const cachedValue = cache[cacheKey];
    if (cachedValue){
      const cachedDate = new Date(cachedValue);
      if (isValidDate(cachedDate)) return { ...note, display_created_at: cachedValue };
    }
    const generated = generatePublicDisplayDate(note, now, dayKey);
    const clamped = generated > now ? now : generated;
    const display = clamped.toISOString();
    cache[cacheKey] = display;
    saveDisplayCache(cache);
    return { ...note, display_created_at: display };
  }

  function withDisplayCreatedAtForNotes(list){
    return (list||[]).map(n => withDisplayCreatedAt(n));
  }

  function getDisplayCreatedAt(note){
    return note.display_created_at ?? note.created_at;
  }

  // --- note-utils.ts (ported) ---
  function groupNotesByCategory(list, pinnedSet){
    const grouped = { pinned: [] };
    const today = new Date();
    const yesterday = new Date(today); yesterday.setDate(yesterday.getDate()-1);
    const sevenDaysAgo = new Date(today); sevenDaysAgo.setDate(sevenDaysAgo.getDate()-7);
    const thirtyDaysAgo = new Date(today); thirtyDaysAgo.setDate(thirtyDaysAgo.getDate()-30);

    (list||[]).forEach((note)=>{
      if (pinnedSet.has(note.slug)){
        grouped.pinned.push(note);
        return;
      }
      let category = note.category ?? "older";
      if (!note.public){
        const createdDate = new Date(note.created_at);
        if (createdDate.toDateString() === today.toDateString()) category = "today";
        else if (createdDate.toDateString() === yesterday.toDateString()) category = "yesterday";
        else if (createdDate > sevenDaysAgo) category = "7";
        else if (createdDate > thirtyDaysAgo) category = "30";
        else category = "older";
      }
      if (!grouped[category]) grouped[category] = [];
      grouped[category].push(note);
    });
    return grouped;
  }

  function sortGroupedNotes(grouped){
    Object.keys(grouped).forEach((cat)=>{
      grouped[cat].sort((a,b)=> String(b.created_at||'').localeCompare(String(a.created_at||'')));
    });
  }

  const labels = {
    pinned: 'Pinned',
    today: 'Today',
    yesterday: 'Yesterday',
    '7': 'Previous 7 Days',
    '30': 'Previous 30 Days',
    older: 'Older'
  };
  const order = ['pinned','today','yesterday','7','30','older'];

  // --- note-item.tsx previewContent (ported) ---
  function previewContent(content){
    return String(content||'')
      .replace(/!\[[^\]]*\]\([^\)]+\)/g, "")
      .replace(/\[([^\]]+)\]\([^\)]+\)/g, "$1")
      .replace(/\[[ x]\]/g, "")
      .replace(/[#*_~`>+\-]/g, "")
      .replace(/\n+/g, " ")
      .replace(/\s+/g, " ")
      .trim();
  }

  // --- util ---
  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;"}[c]));
  }
  function fmtSidebarDate(iso){
    const SIDEBAR_DATE_PLACEHOLDER = "00/00/0000";
    try{
      if (!iso) return SIDEBAR_DATE_PLACEHOLDER;
      const d = new Date(iso);
      if (!isValidDate(d)) return SIDEBAR_DATE_PLACEHOLDER;
      return d.toLocaleDateString("en-US");
    }catch{
      return SIDEBAR_DATE_PLACEHOLDER;
    }
  }

  // --- markdown renderer (existing minimal) ---
  function renderInline(text){
    text = escapeHtml(String(text ?? ''));
    text = text.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, (m,alt,href)=>`<img src="${href}" alt="${alt}" />`);
    text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (m,label,href)=>`<a href="${href}" target="_blank" rel="noreferrer">${label}</a>`);
    text = text.replace(/`([^`]+)`/g, (m,code)=>`<code>${code}</code>`);
    text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/\*([^*]+)\*/g, '<em>$1</em>');
    return text;
  }

  function mdToHtml(md){
    md = String(md||'');
    const fences = [];
    md = md.replace(/```([\s\S]*?)```/g, (m,code)=>{
      const key = `@@FENCE_${fences.length}@@`;
      fences.push(code);
      return key;
    });

    const lines = md.split(/\r?\n/);
    let html = '';
    let i = 0;

    const flushParagraph = (arr) => {
      if (!arr || !arr.length) return '';
      return `<p>${renderInline(arr.join('\n'))}</p>`;
    };

    while (i < lines.length){
      const line = lines[i];

      // fence placeholder
      const fm = line.trim().match(/^@@FENCE_(\d+)@@$/);
      if (fm){
        const idx = parseInt(fm[1],10);
        const code = escapeHtml(fences[idx] ?? '');
        html += `<pre><code>${code}</code></pre>`;
        i++;
        continue;
      }

      if (/^#{1,6}\s+/.test(line)){
        const lvl = (line.match(/^#+/)||['#'])[0].length;
        const txt = line.replace(/^#{1,6}\s+/, '');
        html += `<h${lvl}>${renderInline(txt)}</h${lvl}>`;
        i++; continue;
      }

      if (/^\s*[-*]\s+/.test(line)){
        let items = [];
        while (i < lines.length && /^\s*[-*]\s+/.test(lines[i])){
          const li = lines[i].replace(/^\s*[-*]\s+/, '');
          // checkboxes
          const cb = li.match(/^\[([ x])\]\s*(.*)$/i);
          if (cb){
            const checked = cb[1].toLowerCase()==='x';
            items.push(`<li class="task"><input type="checkbox" disabled ${checked?'checked':''}/> <span>${renderInline(cb[2])}</span></li>`);
          } else {
            items.push(`<li>${renderInline(li)}</li>`);
          }
          i++;
        }
        html += `<ul>${items.join('')}</ul>`;
        continue;
      }

      if (/^>\s?/.test(line)){
        let q = [];
        while (i < lines.length && /^>\s?/.test(lines[i])){
          q.push(lines[i].replace(/^>\s?/, ''));
          i++;
        }
        html += `<blockquote>${flushParagraph(q)}</blockquote>`;
        continue;
      }

      if (/^---+$/.test(line.trim())){
        html += `<hr/>`; i++; continue;
      }

      if (line.trim()===''){
        i++; continue;
      }

      let p = [];
      while (i < lines.length && lines[i].trim() !== '' &&
        !/^###\s+/.test(lines[i]) && !/^##\s+/.test(lines[i]) && !/^#\s+/.test(lines[i]) &&
        !/^\s*[-*]\s+/.test(lines[i]) && !/^>\s?/.test(lines[i]) && !/^---+$/.test(lines[i].trim()) &&
        !/^@@FENCE_\d+@@$/.test(lines[i])
      ){
        p.push(lines[i]);
        i++;
      }
      html += flushParagraph(p);
    }

    return html;
  }

  // --- API ---
  async function apiList(){
    const res = await fetch(url('/api/notes.php?session_id=') + encodeURIComponent(sessionId), { cache:'no-store' });
    const data = await res.json();
    if (!data || !data.ok) throw new Error(data?.error || 'list_failed');
    return data.items || [];
  }
  async function apiGet(slug){
    const res = await fetch(url('/api/notes.php?session_id=') + encodeURIComponent(sessionId) + '&slug=' + encodeURIComponent(slug), { cache:'no-store' });
    const data = await res.json();
    if (!data || !data.ok) throw new Error(data?.error || 'get_failed');
    return data.note;
  }
  async function apiUpsert(note){
    const payload = {
      action: 'upsert',
      session_id: sessionId,
      public: !!note.public,
      slug: note.slug,
      title: note.title,           // allow empty
      emoji: note.emoji,
      category: note.category ?? null,
      content: note.content
    };
    const res = await fetch(url('/api/notes.php'), { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
    const data = await res.json();
    if (!data || !data.ok) throw new Error(data?.error || 'save_failed');
    return data.note;
  }
  async function apiDelete(slug){
    const res = await fetch(url('/api/notes.php'), { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action:'delete', session_id: sessionId, slug }) });
    const data = await res.json();
    if (!data || !data.ok) throw new Error(data?.error || 'delete_failed');
    return true;
  }
  async function apiUploadImage(file, noteId){
    const fd = new FormData();
    fd.append('session_id', sessionId);
    fd.append('note_id', noteId);
    fd.append('file', file);
    const res = await fetch(url('/api/notes_upload.php'), { method:'POST', body: fd });
    const data = await res.json();
    if (!data || !data.ok) throw new Error(data?.error || 'upload_failed');
    return data.url;
  }

  // --- pinned (alanagoyal pinnedNotes init logic) ---
  const PIN_KEY = 'pinnedNotes';
  function loadPinnedRaw(){
    try{
      const raw = localStorage.getItem(PIN_KEY);
      if (raw) return new Set(JSON.parse(raw));
    }catch{}
    return null;
  }
  function savePinned(){
    localStorage.setItem(PIN_KEY, JSON.stringify(Array.from(pinned)));
  }

  // --- search ---
  function setSearchQuery(q){
    qEl.value = q;
    runSearch();
  }
  function clearSearch(){
    qEl.value = '';
    localSearchResults = null;
    highlightedIndex = 0;
    renderSidebar();
  }
  function runSearch(){
    const query = (qEl.value || '');
    const trimmed = query.trim().toLowerCase();
    if (trimmed === ''){
      localSearchResults = null;
      highlightedIndex = 0;
      renderSidebar();
      return;
    }
    const filtered = notes.filter(n =>
      (n.public || n.session_id === sessionId) &&
      (String(n.title||'').toLowerCase().includes(trimmed) || String(n.content||'').toLowerCase().includes(trimmed))
    );
    localSearchResults = filtered;
    highlightedIndex = 0;
    renderSidebar();
  }

  // --- selection ---
  async function selectNote(slug, openEditor=false){
    selectedSlug = slug;
    editing = false;
    renderSidebar();

    try{
      const full = await apiGet(slug);
      selectedNote = full;
    }catch{
      selectedNote = notes.find(n=>n.slug===slug) || null;
    }

    if (openEditor && canEditSelected()){
      editing = true;
    }
    renderNote();
  }

  function canEditSelected(){
    return !!selectedNote && !selectedNote.public && selectedNote.session_id === sessionId;
  }

  // --- sidebar rendering ---
  function rowHtml(n, isActive){
    const em = n.emoji || (n.public ? '📄' : '📝');
    const title = (n.title ?? '');
    const sub = previewContent(n.content || '');
    const dt = fmtSidebarDate(getDisplayCreatedAt(n));
    return `
      <div class="note-row ${isActive?'active':''}" data-slug="${escapeHtml(n.slug)}">
        <div class="note-emoji">${escapeHtml(em)}</div>
        <div class="note-meta">
          <div class="note-title">${escapeHtml(title)}</div>
          <div class="note-sub">${escapeHtml(sub)}</div>
          <div class="note-date">${escapeHtml(dt)}</div>
        </div>
      </div>
    `;
  }

  function renderSidebar(){
    const userNotes = notes.filter(n => (n.public || n.session_id === sessionId));

    // build grouped
    let grouped = null;
    if (localSearchResults){
      // in search mode show flat results (like localSearchResults list)
      grouped = { search: localSearchResults };
    } else {
      grouped = groupNotesByCategory(userNotes, pinned);
      sortGroupedNotes(grouped);
    }

    let html = '';

    if (localSearchResults){
      html += `<div class="notes-section"><div class="notes-sec-title">Search</div>`;
      (localSearchResults||[]).forEach((n, idx)=>{
        const isActive = idx === highlightedIndex;
        html += rowHtml(n, isActive);
      });
      html += `</div>`;
    } else {
      for (const cat of order){
        const arr = grouped[cat] || [];
        if (!arr.length) continue;
        html += `<div class="notes-section"><div class="notes-sec-title">${labels[cat]||cat}</div>`;
        for (const n of arr){
          html += rowHtml(n, n.slug === selectedSlug);
        }
        html += `</div>`;
      }
    }

    sbEl.innerHTML = html;

    // bind clicks
    $$('.note-row', sbEl).forEach(row=>{
      row.addEventListener('click', ()=>{
        const slug = row.getAttribute('data-slug');
        if (!slug) return;
        selectNote(slug, false);
      });
      row.addEventListener('dblclick', ()=>{
        const slug = row.getAttribute('data-slug');
        if (!slug) return;
        selectNote(slug, true);
      });
    });
  }

  // --- note rendering ---
  function renderNote(){
    if (!selectedNote){
      hdrEl.classList.add('empty');
      hdrEl.innerHTML = `<div class="note-empty">Select a note</div>`;
      contentEl.innerHTML = '';
      btnPin.disabled = true;
      btnEdit.disabled = true;
      btnDel.disabled = true;
      return;
    }

    const isPinned = pinned.has(selectedNote.slug);
    btnPin.disabled = false;
    btnPin.textContent = isPinned ? 'Unpin' : 'Pin';

    const canEdit = canEditSelected();
    btnEdit.disabled = !canEdit;
    btnDel.disabled = !canEdit;

    // header (note-header.tsx inspired: emoji + title + date)
    const displayDate = fmtSidebarDate(getDisplayCreatedAt(selectedNote));
    hdrEl.classList.remove('empty');
    hdrEl.innerHTML = `
      <div class="note-h-left">
        <div class="note-h-emoji" id="hEmoji">${escapeHtml(selectedNote.emoji || '')}</div>
        <div class="note-h-txt">
          <div class="note-h-title" id="hTitle">${escapeHtml(selectedNote.title || '')}</div>
          <div class="note-h-sub" id="hSub">${escapeHtml(displayDate)}</div>
        </div>
      </div>
    `;

    // content (note-content.tsx inspired: markdown view, textarea edit; paste image upload)
    const md = selectedNote.content || '';
    if (!editing){
      contentEl.innerHTML = `<div class="markdown-body notes-md">${mdToHtml(md)}</div>`;
      // click to edit (if allowed)
      contentEl.querySelector('.notes-md')?.addEventListener('dblclick', ()=>{
        if (!canEdit) return;
        editing = true;
        renderNote();
      });
      return;
    }

    // editor
    contentEl.innerHTML = `
      <div class="note-edit">
        <input class="note-edit-title" id="edit-title" placeholder="" value="${escapeHtml(selectedNote.title||'')}" />
        <textarea class="note-edit-area" id="edit-area" spellcheck="false"></textarea>
        <div class="note-edit-hint">Esc to stop editing · Paste images to upload</div>
      </div>
    `;
    const tTitle = $('#edit-title', contentEl);
    const tArea = $('#edit-area', contentEl);
    tArea.value = md;

    // save helper
    let saveTimer = null;
    const scheduleSave = ()=>{
      if (!canEdit) return;
      if (saveTimer) clearTimeout(saveTimer);
      saveTimer = setTimeout(async ()=>{
        try{
          const updated = await apiUpsert({
            slug: selectedNote.slug,
            public: false,
            title: tTitle.value,
            emoji: selectedNote.emoji || '👋🏼',
            category: selectedNote.category ?? null,
            content: tArea.value
          });
          selectedNote = updated;
          // also update list item content/title
          const idx = notes.findIndex(n=>n.slug===updated.slug && n.session_id===sessionId);
          if (idx >= 0){
            notes[idx] = { ...notes[idx], title: updated.title, content: updated.content, updated_at: updated.updated_at };
          }
          renderSidebar();
        }catch(err){
          console.error(err);
        }
      }, 250);
    };

    tTitle.addEventListener('input', scheduleSave);
    tArea.addEventListener('input', scheduleSave);

    // key handling like note-content.tsx
    tArea.addEventListener('keydown', (e)=>{
      if (e.key === 'Escape'){
        e.preventDefault();
        editing = false;
        renderNote();
        return;
      }
      if (e.key === 'Tab'){
        // keep existing behavior: insert 2 spaces
        e.preventDefault();
        const start = tArea.selectionStart;
        const end = tArea.selectionEnd;
        const v = tArea.value;
        tArea.value = v.slice(0,start) + "  " + v.slice(end);
        tArea.selectionStart = tArea.selectionEnd = start + 2;
        scheduleSave();
      }
    });

    // paste image upload (note-content.tsx)
    tArea.addEventListener('paste', async (e)=>{
      if (!canEdit) return;
      const items = (e.clipboardData && e.clipboardData.items) ? Array.from(e.clipboardData.items) : [];
      const imgItem = items.find(it => it.type && it.type.startsWith('image/'));
      if (!imgItem) return;
      const file = imgItem.getAsFile();
      if (!file) return;
      e.preventDefault();
      try{
        const urlImg = await apiUploadImage(file, selectedNote.id);
        // insert markdown at cursor
        const start = tArea.selectionStart;
        const end = tArea.selectionEnd;
        const before = tArea.value.slice(0,start);
        const after = tArea.value.slice(end);
        const insert = `![](${urlImg})`;
        tArea.value = before + insert + after;
        const pos = start + insert.length;
        tArea.selectionStart = tArea.selectionEnd = pos;
        scheduleSave();
      }catch(err){
        console.error(err);
      }
    });

    // focus editor
    setTimeout(()=>{ tArea.focus(); }, 0);
  }

  async function refreshList(preserveSelection=true){
    const items = await apiList();
    notes = withDisplayCreatedAtForNotes(items);

    // initialize pinned on first load if missing (alanagoyal)
    const stored = loadPinnedRaw();
    if (stored){
      pinned = stored;
    } else {
      const init = new Set(notes.filter(n => n.slug === 'about-me' || n.slug === 'quick-links' || n.session_id === sessionId).map(n => n.slug));
      pinned = init;
      savePinned();
    }

    if (!preserveSelection){
      // keep as-is
    } else {
      if (selectedSlug && !notes.some(n => n.slug === selectedSlug)){
        selectedSlug = null;
        selectedNote = null;
      }
    }

    renderSidebar();

    if (!selectedSlug){
      // auto-select first pinned note if exists
      const userNotes = notes.filter(n => (n.public || n.session_id === sessionId));
      const grouped = groupNotesByCategory(userNotes, pinned);
      sortGroupedNotes(grouped);
      const first = (grouped.pinned && grouped.pinned[0]) || userNotes[0];
      if (first){
        await selectNote(first.slug, false);
      } else {
        renderNote();
      }
    } else {
      // refresh selected note in place
      await selectNote(selectedSlug, false);
    }
  }

  // --- actions ---
  btnNew.addEventListener('click', async ()=>{
    try{
      clearSearch();
      const noteId = uuidv4();
      const slug = `new-note-${noteId}`;
      const created = await apiUpsert({
        slug,
        title: "",
        content: "",
        public: false,
        created_at: new Date().toISOString(),
        session_id: sessionId,
        category: "today",
        emoji: "👋🏼"
      });

      // pin new note (create-note.ts behavior: addNewPinnedNote)
      if (!pinned.has(created.slug)){
        pinned.add(created.slug);
        savePinned();
      }

      await refreshList(false);
      await selectNote(created.slug, true);
    }catch(err){
      console.error(err);
    }
  });

  btnPin.addEventListener('click', async ()=>{
    if (!selectedNote) return;
    // pin toggle like handlePinToggle
    const slug = selectedNote.slug;
    const isPinning = !pinned.has(slug);
    if (isPinning) pinned.add(slug); else pinned.delete(slug);
    savePinned();
    clearSearch();
    renderSidebar();
  });

  btnEdit.addEventListener('click', ()=>{
    if (!canEditSelected()) return;
    editing = !editing;
    renderNote();
  });

  btnDel.addEventListener('click', async ()=>{
    if (!canEditSelected()) return;
    if (!confirm('Delete this note?')) return;
    try{
      await apiDelete(selectedNote.slug);
      pinned.delete(selectedNote.slug);
      savePinned();
      selectedSlug = null;
      selectedNote = null;
      editing = false;
      await refreshList(false);
    }catch(err){
      console.error(err);
    }
  });

  qEl.addEventListener('input', ()=> runSearch());

  // --- keyboard shortcuts (sidebar.tsx + new-note.tsx) ---
  function isTypingTarget(target){
    if (!target) return false;
    const tag = target.tagName;
    return ["INPUT","TEXTAREA","SELECT"].includes(tag) || target.isContentEditable;
  }

  function navigateNotes(direction){
    const userNotes = notes.filter(n => (n.public || n.session_id === sessionId));
    const grouped = groupNotesByCategory(userNotes, pinned);
    sortGroupedNotes(grouped);

    // flatten in display order
    const flat = [];
    for (const cat of order){
      const arr = grouped[cat] || [];
      for (const n of arr) flat.push(n);
    }
    if (!flat.length) return;
    const idx = flat.findIndex(n=>n.slug===selectedSlug);
    const next = (idx<0) ? 0 : (idx + direction + flat.length) % flat.length;
    highlightedIndex = 0;
    localSearchResults = null;
    selectNote(flat[next].slug, false);
  }

  function goToHighlightedNote(){
    if (!localSearchResults || !localSearchResults.length) return;
    const note = localSearchResults[highlightedIndex];
    if (!note) return;
    clearSearch();
    selectNote(note.slug, false);
  }

  window.addEventListener('keydown', (event)=>{
    const target = event.target;
    // only handle inside notes app
    const within = target && target.closest && target.closest('[data-app="notes"]');
    if (!within) return;

    const typing = isTypingTarget(target);

    // New note shortcut from new-note.tsx
    if (event.key === 'n' && !event.metaKey && !event.ctrlKey && !typing){
      event.preventDefault();
      btnNew.click();
      return;
    }

    const shortcuts = {
      j: ()=> navigateNotes(1),
      ArrowDown: ()=> navigateNotes(1),
      k: ()=> navigateNotes(-1),
      ArrowUp: ()=> navigateNotes(-1),
      p: ()=> selectedNote && btnPin.click(),
      d: ()=> selectedNote && btnDel.click(),
      "/": ()=> qEl.focus(),
      Escape: ()=> (document.activeElement && document.activeElement.blur && document.activeElement.blur()),
    };

    if (typing){
      if (event.key === 'Escape'){
        event.preventDefault();
        shortcuts.Escape();
        return;
      }
      if (event.key === 'Enter' && localSearchResults && localSearchResults.length){
        event.preventDefault();
        goToHighlightedNote();
        return;
      }
      return;
    }

    const key = event.key;
    if (shortcuts[key] && !(event.metaKey || event.ctrlKey)){
      event.preventDefault();
      if (localSearchResults && ["j","ArrowDown","k","ArrowUp"].includes(key)){
        const dir = ["j","ArrowDown"].includes(key) ? 1 : -1;
        highlightedIndex = (highlightedIndex + dir + localSearchResults.length) % localSearchResults.length;
        renderSidebar();
      } else {
        shortcuts[key]();
      }
      return;
    }
    if (event.key === 'Enter' && localSearchResults && localSearchResults.length){
      event.preventDefault();
      goToHighlightedNote();
    }
  });

  // bootstrap
  refreshList(false).catch(err=>console.error(err));
})();
</script>

<?php osx_app_footer(); ?>
