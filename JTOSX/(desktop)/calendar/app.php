<?php
require_once __DIR__ . '/../../core/app_frame.php';
osx_app_header('Calendar');
?>
<style>
  /* Calendar (alanagoyal port - vanilla JS) */
  :root{
    --cal-bg: rgba(255,255,255,.86);
    --cal-surface: rgba(255,255,255,.70);
    --cal-muted: rgba(0,0,0,.04);
    --cal-muted2: rgba(0,0,0,.06);
    --cal-border: rgba(0,0,0,.10);
    --cal-text: rgba(0,0,0,.88);
    --cal-sub: rgba(0,0,0,.55);
    --cal-sub2: rgba(0,0,0,.40);
    --cal-red: #ef4444;
    --cal-blue: #0a84ff;
  }
  html.dark{
    --cal-bg: rgba(24,24,27,.92);
    --cal-surface: rgba(32,32,35,.70);
    --cal-muted: rgba(255,255,255,.06);
    --cal-muted2: rgba(255,255,255,.08);
    --cal-border: rgba(255,255,255,.12);
    --cal-text: rgba(255,255,255,.92);
    --cal-sub: rgba(255,255,255,.62);
    --cal-sub2: rgba(255,255,255,.45);
  }

  body{ background: transparent; }

  .cal-app{
    height:100%;
    display:flex;
    flex-direction:column;
    background: var(--cal-bg);
    color: var(--cal-text);
    overflow:hidden;
  }

  /* Nav bar (matches components/apps/calendar/nav.tsx structure; no window controls inside iframe) */
  .cal-nav{
    position: sticky;
    top:0;
    z-index: 5;
    display:flex;
    align-items:center;
    gap: 8px;
    padding: 8px 10px;
    background: var(--cal-muted);
    border-bottom: 1px solid var(--cal-border);
    user-select:none;
  }
  .cal-left{ display:flex; align-items:center; gap: 8px; }
  .cal-spacer{ flex:1; }

  .btn-ico{
    width: 32px; height: 32px;
    border-radius: 10px;
    border: 0;
    background: transparent;
    color: inherit;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
  }
  .btn-ico:hover{ background: var(--cal-muted2); }

  .btn{
    height: 32px;
    padding: 0 10px;
    border-radius: 10px;
    border: 1px solid var(--cal-border);
    background: rgba(255,255,255,.35);
    color: inherit;
    cursor:pointer;
    font-size: 12px;
  }
  html.dark .btn{ background: rgba(255,255,255,.06); }
  .btn:hover{ background: rgba(255,255,255,.50); }
  html.dark .btn:hover{ background: rgba(255,255,255,.10); }

  .view-switch{
    display:flex;
    align-items:center;
    gap: 0;
    padding: 2px;
    border-radius: 12px;
    border: 1px solid var(--cal-border);
    background: rgba(255,255,255,.25);
  }
  html.dark .view-switch{ background: rgba(255,255,255,.06); }
  .view-btn{
    height: 28px;
    padding: 0 10px;
    border-radius: 10px;
    border:0;
    background: transparent;
    color: var(--cal-sub);
    font-size: 12px;
    font-weight: 600;
    cursor:pointer;
  }
  .view-btn.active{
    background: var(--cal-bg);
    color: var(--cal-text);
    box-shadow: 0 1px 2px rgba(0,0,0,.08);
  }

  .cal-body{ flex:1; min-height:0; overflow:hidden; }

  /* Shared headers */
  .cal-header{
    padding: 12px 14px;
    border-bottom: 1px solid var(--cal-border);
    background: var(--cal-bg);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap: 10px;
  }
  .cal-header h1{ margin:0; font-size: 20px; font-weight: 700; letter-spacing:-.02em; }

  /* Week/day headers */
  .dow-row{
    display:flex;
    border-bottom: 1px solid var(--cal-border);
    background: rgba(0,0,0,.02);
  }
  html.dark .dow-row{ background: rgba(255,255,255,.03); }
  .dow-spacer{ width: 64px; flex:none; }
  .dow-cell{
    flex:1;
    text-align:center;
    padding: 8px 0;
    border-left: 1px solid var(--cal-border);
  }
  .dow-cell:first-child{ border-left:0; }
  .dow-name{ font-size: 11px; color: var(--cal-sub); margin-bottom: 3px; }
  .dow-num{ font-size: 16px; font-weight: 700; }
  .dow-today{
    width: 30px; height: 30px;
    margin: 0 auto;
    border-radius: 999px;
    background: var(--cal-red);
    color: #fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size: 16px;
    font-weight: 700;
  }

  /* All-day row */
  .allday-row{ display:flex; border-bottom: 1px solid var(--cal-border); background: var(--cal-bg); }
  .allday-label{
    width: 64px; flex:none;
    font-size: 11px;
    color: var(--cal-sub);
    padding: 6px 8px;
    text-align:right;
  }
  .allday-col{ flex:1; border-left: 1px solid var(--cal-border); padding: 4px 2px; min-height: 28px; overflow:hidden; }
  .allday-col:first-child{ border-left:0; }
  .allday-ev{
    font-size: 11px;
    padding: 2px 6px;
    margin: 2px 0;
    display:flex;
    align-items:center;
    gap: 6px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  .allday-dot{ width: 6px; height: 6px; border-radius: 999px; flex:none; }

  /* Time grid */
  .tg{ flex:1; min-height:0; display:flex; flex-direction:column; overflow:hidden; }
  .tg-scroll{ flex:1; min-height:0; overflow-y:auto; position:relative; }
  .tg-inner{ display:flex; position:relative; min-height: calc(var(--hourH) * 24 + 16px); }
  .tg-timecol{ width: 64px; flex:none; position:relative; }
  .tg-tlabel{
    position:absolute;
    right: 8px;
    font-size: 11px;
    color: var(--cal-sub);
    transform: translateY(-50%);
  }
  .tg-cols{ flex:1; display:flex; }
  .tg-col{ flex:1; position:relative; border-left: 1px solid var(--cal-border); }
  .tg-col:first-child{ border-left:0; }
  .tg-hline{ position:absolute; left:0; right:0; height:1px; background: rgba(0,0,0,.08); }
  html.dark .tg-hline{ background: rgba(255,255,255,.10); }

  .tg-now{ position:absolute; left:0; right:0; z-index: 20; pointer-events:none; }
  .tg-nowline{ height:2px; background: var(--cal-red); }
  .tg-nowdot{ position:absolute; left:-4px; top:-3px; width: 8px; height: 8px; border-radius: 999px; background: var(--cal-red); }

  .tg-ev{
    position:absolute;
    border-radius: 6px;
    padding: 2px 6px;
    font-size: 11px;
    overflow:hidden;
    border-left: 3px solid;
    user-select:none;
  }
  .tg-ev .t{ font-weight: 700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .tg-ev .s{ opacity:.80; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top: 2px; }

  .tg-drag{
    position:absolute;
    left: 4px; right: 4px;
    background: rgba(10,132,255,.22);
    border: 1px solid rgba(10,132,255,.55);
    border-radius: 8px;
    pointer-events:none;
  }

  /* Month view */
  .month-weekdays{ display:grid; grid-template-columns: repeat(7, 1fr); border-bottom:1px solid var(--cal-border); background: rgba(0,0,0,.02); }
  html.dark .month-weekdays{ background: rgba(255,255,255,.03); }
  .month-weekdays div{ text-align:center; padding: 8px 0; font-size: 12px; font-weight: 700; color: var(--cal-sub); }

  .month-scroll{ flex:1; min-height:0; overflow-y:auto; }
  .month-spacer{ position:relative; }
  .month-week{ position:absolute; left:0; right:0; display:grid; grid-template-columns: repeat(7, 1fr); }
  .month-cell{
    border-right: 1px solid var(--cal-border);
    border-bottom: 1px solid var(--cal-border);
    padding: 6px;
    height: 100px;
    overflow:hidden;
    cursor:pointer;
    transition: background .12s ease;
  }
  .month-cell:hover{ background: rgba(0,0,0,.03); }
  html.dark .month-cell:hover{ background: rgba(255,255,255,.04); }
  .month-cell:nth-child(7n){ border-right: 0; }
  .month-top{ display:flex; align-items:center; justify-content:flex-start; gap: 6px; }
  .month-day{
    width: 24px; height: 24px;
    display:flex; align-items:center; justify-content:center;
    font-size: 12px; font-weight: 700;
    border-radius: 999px;
  }
  .month-day.today{ background: var(--cal-red); color:#fff; }
  .month-day.muted{ color: var(--cal-sub2); }

  .month-ev{
    margin-top: 4px;
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 6px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }

  /* Year view */
  .year-scroll{ flex:1; min-height:0; overflow-y:auto; }
  .year-pad{ padding: 14px; }
  .year-block{ margin-bottom: 22px; }
  .year-label{ font-size: 16px; font-weight: 800; color: var(--cal-sub); margin-bottom: 10px; }
  .year-grid{ display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px 16px; }
  @media (max-width: 720px){ .year-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); } }

  .mini-month-name{ border:0; background: transparent; color: var(--cal-red); font-weight: 800; cursor:pointer; padding:0; margin:0 0 6px; text-align:left; }
  .mini-weekdays{ display:grid; grid-template-columns: repeat(7, 1fr); margin-bottom: 4px; }
  .mini-weekdays div{ text-align:center; font-size: 10px; color: var(--cal-sub); }
  .mini-weeks{ display:flex; flex-direction:column; gap: 2px; }
  .mini-week{ display:grid; grid-template-columns: repeat(7, 1fr); }
  .mini-day{
    border:0;
    background: transparent;
    font-size: 10px;
    width: 100%;
    aspect-ratio: 1 / 1;
    border-radius: 999px;
    cursor:pointer;
    color: inherit;
  }
  .mini-day:hover{ background: rgba(0,0,0,.05); }
  html.dark .mini-day:hover{ background: rgba(255,255,255,.08); }
  .mini-day.muted{ color: var(--cal-sub2); cursor:default; }
  .mini-day.today{ background: var(--cal-red); color:#fff; }

  /* Modal (EventForm) */
  .modal-backdrop{ position:fixed; inset:0; background: rgba(0,0,0,.30); display:none; align-items:center; justify-content:center; z-index: 50; }
  .modal-backdrop.show{ display:flex; }
  .modal{
    width: 340px;
    max-width: calc(100vw - 32px);
    border-radius: 16px;
    overflow:hidden;
    background: var(--cal-bg);
    border: 1px solid var(--cal-border);
    box-shadow: 0 20px 60px rgba(0,0,0,.35);
  }
  .modal .sec{ padding: 12px 14px; border-top: 1px solid var(--cal-border); }
  .modal .sec:first-child{ border-top: 0; }
  .modal input, .modal select{
    width:100%;
    border: 0;
    outline: none;
    background: transparent;
    color: inherit;
    font-size: 13px;
  }
  .modal .title-in{ font-size: 18px; font-weight: 700; }
  .modal .row{ display:flex; align-items:center; gap: 10px; }
  .modal .row > *{ flex:1; }
  .modal .lab{ font-size: 11px; color: var(--cal-sub); margin-bottom: 6px; }
  .modal .actions{ display:flex; gap: 10px; justify-content:flex-end; padding: 12px 14px; border-top: 1px solid var(--cal-border); }
  .danger{ border-color: rgba(239,68,68,.45) !important; color: var(--cal-red) !important; }
</style>

<div class="cal-app" id="cal-app"></div>

<div class="modal-backdrop" id="modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="modal" id="modal">
    <div class="sec">
      <div class="row" style="align-items:center; gap: 8px;">
        <input id="ev-title" class="title-in" type="text" placeholder="New Event" />
        <select id="ev-calendar" title="Calendar"></select>
      </div>
    </div>

    <div class="sec">
      <div class="lab">Location</div>
      <input id="ev-location" type="text" placeholder="Add Location" />
    </div>

    <div class="sec">
      <div class="row" style="align-items:center; justify-content:space-between;">
        <div style="font-size: 13px; font-weight: 700;">All-day</div>
        <input id="ev-allday" type="checkbox" style="width:18px; height:18px; flex:none;" />
      </div>
    </div>

    <div class="sec">
      <div class="lab">Starts</div>
      <div class="row">
        <input id="ev-start-date" type="date" />
        <select id="ev-start-time"></select>
      </div>
      <div style="height:10px"></div>
      <div class="lab">Ends</div>
      <div class="row">
        <input id="ev-end-date" type="date" />
        <select id="ev-end-time"></select>
      </div>
    </div>

    <div class="actions">
      <button class="btn danger" id="ev-delete" style="display:none;">Delete</button>
      <button class="btn" id="ev-cancel">Cancel</button>
      <button class="btn" id="ev-save">Save</button>
    </div>
  </div>
</div>

<script>
(() => {
  'use strict';

  // --- Types / constants (ground truth: components/apps/calendar/* + lib/sidebar-persistence.ts) ---
  const VALID_VIEW_TYPES = ['day','week','month','year'];

  const VIEW_STORAGE_KEY = 'calendar-view';
  const DATE_STORAGE_KEY = 'calendar-date';
  const SCROLL_STORAGE_KEY = 'calendar-scroll';

  const USER_EVENTS_KEY = 'calendar-user-events';
  const CALENDARS_STORAGE_KEY = 'calendar-calendars';

  const DEFAULT_HOUR_HEIGHT = 60; // must match getEventTimePosition()
  const GRID_PADDING_TOP = 8;

  const DEFAULT_CALENDARS = [
    { id: 'holidays', name: 'holidays', color: '#9B7ED9' },
    { id: 'exercise', name: 'exercise', color: '#E25C5C' },
    { id: 'focus', name: 'focus', color: '#E89B4C' },
    { id: 'meetings', name: 'meetings', color: '#D4B84A' },
    { id: 'meals', name: 'meals', color: '#5BBF72' },
    { id: 'events', name: 'events', color: '#5B9BD5' },
  ];

  // --- helpers ---
  const $ = (sel, el=document) => el.querySelector(sel);
  const $$ = (sel, el=document) => Array.from(el.querySelectorAll(sel));

  function clamp(n, a, b){ return Math.max(a, Math.min(b, n)); }
  function pad2(n){ return String(n).padStart(2,'0'); }

  function safeJsonParse(s, fallback){
    try { return JSON.parse(s); } catch { return fallback; }
  }

  // --- date utils (subset of date-fns behavior used by alanagoyal calendar) ---
  function dateOnly(d){ return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
  function addDays(d, n){ const x = new Date(d); x.setDate(x.getDate() + n); return x; }
  function subDays(d, n){ return addDays(d, -n); }
  function addWeeks(d, n){ return addDays(d, n*7); }
  function subWeeks(d, n){ return addWeeks(d, -n); }
  function addMonths(d, n){ const x = new Date(d); x.setMonth(x.getMonth() + n); return x; }
  function subMonths(d, n){ return addMonths(d, -n); }
  function addYears(d, n){ const x = new Date(d); x.setFullYear(x.getFullYear() + n); return x; }
  function subYears(d, n){ return addYears(d, -n); }

  function startOfWeek(d){
    const x = dateOnly(d);
    const dow = x.getDay(); // 0=Sun
    return addDays(x, -dow);
  }
  function endOfWeek(d){ return addDays(startOfWeek(d), 6); }
  function startOfMonth(d){ return new Date(d.getFullYear(), d.getMonth(), 1); }
  function endOfMonth(d){ return new Date(d.getFullYear(), d.getMonth()+1, 0); }
  function eachDayOfInterval({start, end}){
    const out = [];
    let cur = dateOnly(start);
    const last = dateOnly(end);
    while (cur.getTime() <= last.getTime()){
      out.push(new Date(cur));
      cur = addDays(cur, 1);
    }
    return out;
  }

  function isSameDay(a,b){
    return a.getFullYear()===b.getFullYear() && a.getMonth()===b.getMonth() && a.getDate()===b.getDate();
  }
  function isSameMonth(a,b){
    return a.getFullYear()===b.getFullYear() && a.getMonth()===b.getMonth();
  }
  function isToday(d){ return isSameDay(dateOnly(d), dateOnly(new Date())); }

  function formatYMD(d){
    return `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`;
  }
  function parseISODate(ymd){
    const [y,m,dd] = String(ymd).split('-').map(Number);
    return new Date(y, (m||1)-1, dd||1);
  }

  // date-fns getWeek default-ish: weeks start on Sunday, week 1 contains Jan 1.
  function getWeek(d){
    const x = dateOnly(d);
    const start = new Date(x.getFullYear(),0,1);
    const startDow = start.getDay();
    const diffDays = Math.floor((x.getTime() - dateOnly(start).getTime()) / 86400000);
    return Math.floor((diffDays + startDow) / 7) + 1;
  }

  function format(date, pattern){
    // Minimal pattern support for this calendar port.
    const d = date;
    if (pattern === 'yyyy-MM-dd') return formatYMD(d);
    if (pattern === 'yyyy-MM') return `${d.getFullYear()}-${pad2(d.getMonth()+1)}`;
    if (pattern === 'yyyy') return String(d.getFullYear());
    if (pattern === 'd') return String(d.getDate());

    const locale = undefined;
    if (pattern === 'EEE') return d.toLocaleDateString(locale, { weekday: 'short' });
    if (pattern === 'EEEE') return d.toLocaleDateString(locale, { weekday: 'long' });
    if (pattern === 'MMMM') return d.toLocaleDateString(locale, { month: 'long' });
    if (pattern === 'MMMM yyyy') return d.toLocaleDateString(locale, { month: 'long', year: 'numeric' });
    if (pattern === 'MMMM d, yyyy') {
      const mm = d.toLocaleDateString(locale, { month: 'long' });
      return `${mm} ${d.getDate()}, ${d.getFullYear()}`;
    }
    if (pattern === 'EEE d') return `${d.toLocaleDateString(locale, { weekday: 'short' })} ${d.getDate()}`;

    // Fallback
    return d.toLocaleDateString(locale);
  }

  // --- calendar utils (ported from components/apps/calendar/utils.ts) ---
  function getDayHours(){ return Array.from({length:24}, (_,i)=>i); }

  function formatDateHeader(date, view){
    if (view === 'day') return format(date, 'MMMM d, yyyy');
    if (view === 'week' || view === 'month') return format(date, 'MMMM yyyy');
    return format(date, 'yyyy');
  }

  function formatHour(hour){
    if (hour === 0) return '12 AM';
    if (hour === 12) return 'Noon';
    if (hour < 12) return `${hour} AM`;
    return `${hour-12} PM`;
  }

  function roundToNearest15(minutes){ return Math.round(minutes/15)*15; }

  function pixelToTime(pixelY, hourHeight=60){
    const totalMinutes = (pixelY / hourHeight) * 60;
    const clamped = Math.max(0, Math.min(24*60, totalMinutes));
    const rounded = roundToNearest15(clamped);
    let hour = Math.floor(rounded/60);
    let minute = rounded % 60;
    hour = Math.min(24, Math.max(0, hour));
    if (hour === 24) minute = 0;
    return { hour, minute };
  }

  function formatTimeValue(hour, minute){ return `${pad2(hour)}:${pad2(minute)}`; }

  function formatEventTime(timeStr){
    const [hourRaw, minute] = String(timeStr).split(':').map(Number);
    const hour = (hourRaw === 24) ? 0 : hourRaw;
    const h = (hour % 12) || 12;
    const ampm = hour < 12 ? 'am' : 'pm';
    if (minute === 0) return `${h}${ampm}`;
    return `${h}:${pad2(minute)}${ampm}`;
  }

  function getEventTimePosition(event){
    if (event.isAllDay || !event.startTime || !event.endTime) return { top:0, height:0 };
    const [sh, sm] = event.startTime.split(':').map(Number);
    const [eh, em] = event.endTime.split(':').map(Number);
    const startMinutes = sh*60+sm;
    const endMinutes = eh*60+em;
    const hourHeight = 60;
    const top = (startMinutes/60)*hourHeight;
    const height = ((endMinutes - startMinutes)/60)*hourHeight;
    return { top, height: Math.max(height - 2, 15) };
  }

  // Navigation helpers
  function navigateDate(date, direction, view){
    const add = direction === 'next';
    if (view === 'day') return add ? addDays(date,1) : subDays(date,1);
    if (view === 'week') return add ? addWeeks(date,1) : subWeeks(date,1);
    if (view === 'month') return add ? addMonths(date,1) : subMonths(date,1);
    return add ? addYears(date,1) : subYears(date,1);
  }

  function getMonthViewDays(date){
    const monthStart = startOfMonth(date);
    const monthEnd = endOfMonth(date);
    const start = startOfWeek(monthStart);
    const end = endOfWeek(monthEnd);
    return eachDayOfInterval({ start, end });
  }

  function getWeekDays(date){
    const start = startOfWeek(date);
    const end = endOfWeek(date);
    return eachDayOfInterval({ start, end });
  }

  function getYearMonths(year){
    return Array.from({length:12}, (_,i)=> new Date(year, i, 1));
  }

  // Sample events & holidays (ported from utils.ts)
  const DATE_NIGHT_RESTAURANTS = [
    { name: '3rd Cousin', address: '919 Cortland Ave, SF' },
    { name: 'Foreign Cinema', address: '2534 Mission St, SF' },
    { name: 'Flour + Water', address: '2401 Harrison St, SF' },
    { name: 'Frances', address: '3870 17th St, SF' },
    { name: 'Friends Only', address: '1501 California St, SF' },
    { name: 'Itria', address: '3266 24th St, SF' },
    { name: 'Kokkari', address: '200 Jackson St, SF' },
    { name: 'Lupa Trattoria', address: '4109 24th St, SF' },
    { name: 'La Ciccia', address: '291 30th St, SF' },
    { name: 'Rich Table', address: '199 Gough St, SF' },
    { name: 'Routier', address: '2801 California St, SF' },
    { name: 'Sorrel', address: '3228 Sacramento St, SF' },
    { name: 'Verjus', address: '550 Washington St, SF' },
    { name: 'Via Aurelia', address: '300 Toni Stone Xing, SF' },
    { name: 'Zuni Cafe', address: '1658 Market St, SF' },
  ];
  function getRestaurantForSaturday(date){
    const weekNumber = getWeek(date);
    return DATE_NIGHT_RESTAURANTS[weekNumber % DATE_NIGHT_RESTAURANTS.length];
  }
  const DATE_NIGHT_TIMES = [
    { start: '18:00', end: '20:30' },
    { start: '18:15', end: '20:45' },
    { start: '18:30', end: '21:00' },
  ];
  function getDateNightTime(date){
    const weekNumber = getWeek(date);
    return DATE_NIGHT_TIMES[weekNumber % DATE_NIGHT_TIMES.length];
  }

  const WEEKDAY_MEETING_PATTERNS = [
    [{ start: '13:30', end: '14:30' }, { start: '15:00', end: '16:00' }, { start: '16:30', end: '17:30' }],
    [{ start: '14:00', end: '15:00' }, { start: '15:30', end: '16:30' }],
    [{ start: '13:30', end: '14:30' }, { start: '15:00', end: '16:00' }, { start: '16:30', end: '17:30' }],
    [{ start: '14:30', end: '15:30' }, { start: '16:00', end: '17:00' }],
    [{ start: '13:30', end: '14:30' }, { start: '15:00', end: '16:00' }, { start: '16:30', end: '17:30' }],
    [{ start: '14:00', end: '15:00' }, { start: '16:00', end: '17:00' }],
    [{ start: '13:30', end: '14:30' }, { start: '15:30', end: '16:30' }],
    [{ start: '13:30', end: '14:30' }, { start: '15:00', end: '16:00' }, { start: '16:30', end: '17:30' }],
    [{ start: '14:30', end: '15:30' }, { start: '16:30', end: '17:30' }],
    [{ start: '14:00', end: '15:00' }, { start: '15:30', end: '16:30' }],
  ];
  const WEEKEND_MEETING_PATTERNS = [
    [{ start: '14:00', end: '15:00' }, { start: '15:30', end: '16:30' }],
    [{ start: '13:30', end: '14:30' }, { start: '15:00', end: '16:00' }],
    [{ start: '15:00', end: '16:00' }, { start: '16:30', end: '17:30' }],
    [{ start: '14:30', end: '15:30' }],
    [{ start: '14:00', end: '15:00' }, { start: '16:00', end: '17:00' }],
    [{ start: '13:30', end: '14:30' }, { start: '15:30', end: '16:30' }],
    [{ start: '14:30', end: '15:30' }, { start: '16:30', end: '17:30' }],
  ];

  function getPatternIndex(day, patternCount){
    const startOfYear = new Date(day.getFullYear(), 0, 1);
    const daysSinceStart = Math.floor((day.getTime() - startOfYear.getTime()) / 86400000);
    const weekNumber = Math.floor(daysSinceStart / 7);
    const dayOfWeek = day.getDay();
    return (weekNumber * 3 + dayOfWeek) % patternCount;
  }

  function generateSampleEventsForDay(day){
    const dateStr = format(day, 'yyyy-MM-dd');
    const dow = day.getDay();
    const isWeekday = dow >= 1 && dow <= 5;
    const isSaturday = dow === 6;
    const isSunday = dow === 0;

    const events = [];

    // exercise 7-8
    events.push({ id:`sample-exercise-${dateStr}`, title:'exercise', startDate:dateStr, endDate:dateStr, startTime:'07:00', endTime:'08:00', isAllDay:false, calendarId:'exercise' });
    // focus 9-13
    events.push({ id:`sample-focus-${dateStr}`, title:'focus time', startDate:dateStr, endDate:dateStr, startTime:'09:00', endTime:'13:00', isAllDay:false, calendarId:'focus' });

    if (isWeekday) {
      const patternIndex = getPatternIndex(day, WEEKDAY_MEETING_PATTERNS.length);
      const meetingPattern = WEEKDAY_MEETING_PATTERNS[patternIndex];
      meetingPattern.forEach((m, idx) => {
        events.push({ id:`sample-meeting${idx+1}-${dateStr}`, title:'busy', startDate:dateStr, endDate:dateStr, startTime:m.start, endTime:m.end, isAllDay:false, calendarId:'meetings' });
      });

      const isTuesday = dow === 2;
      const weekNumber = getWeek(day);
      const isRoundtableWeek = weekNumber % 6 === 0;

      if (isTuesday && isRoundtableWeek) {
        events.push({
          id:`sample-event-${dateStr}`,
          title:'event',
          startDate:dateStr,
          endDate:dateStr,
          startTime:'18:00',
          endTime:'21:00',
          isAllDay:false,
          calendarId:'events',
          location:'flour + water, 2401 harrison st, sf'
        });
      } else {
        events.push({ id:`sample-meals-${dateStr}`, title:'dinner', startDate:dateStr, endDate:dateStr, startTime:'18:30', endTime:'19:30', isAllDay:false, calendarId:'meals' });
      }

    } else {
      const weekNumber = getWeek(day);
      const isEventSunday = isSunday && (weekNumber % 4 === 0);

      if (isEventSunday) {
        events.push({
          id:`sample-sunday-event-${dateStr}`,
          title:'event',
          startDate:dateStr,
          endDate:dateStr,
          startTime:'14:00',
          endTime:'16:00',
          isAllDay:false,
          calendarId:'events',
          location:'665 3rd st, san francisco, ca 94107'
        });
      } else {
        const patternIndex = getPatternIndex(day, WEEKEND_MEETING_PATTERNS.length);
        const meetingPattern = WEEKEND_MEETING_PATTERNS[patternIndex];
        meetingPattern.forEach((m, idx) => {
          events.push({ id:`sample-meeting${idx+1}-${dateStr}`, title:'busy', startDate:dateStr, endDate:dateStr, startTime:m.start, endTime:m.end, isAllDay:false, calendarId:'meetings' });
        });
      }

      if (isSaturday) {
        const restaurant = getRestaurantForSaturday(day);
        const t = getDateNightTime(day);
        events.push({
          id:`sample-datenight-${dateStr}`,
          title:'date night',
          startDate:dateStr,
          endDate:dateStr,
          startTime:t.start,
          endTime:t.end,
          isAllDay:false,
          calendarId:'meals',
          location:`${restaurant.name.toLowerCase()}, ${restaurant.address.toLowerCase()}`
        });
      } else if (isSunday) {
        events.push({ id:`sample-meals-sunday-${dateStr}`, title:'dinner', startDate:dateStr, endDate:dateStr, startTime:'18:30', endTime:'20:00', isAllDay:false, calendarId:'meals' });
      }
    }

    return events;
  }

  function getHolidaysForDay(day){
    const year = day.getFullYear();
    const month = day.getMonth();
    const date = day.getDate();
    const dateStr = format(day, 'yyyy-MM-dd');
    const holidays = [];

    const getNthWeekday = (y, m, weekday, n) => {
      const firstDay = new Date(y, m, 1);
      const firstWeekday = firstDay.getDay();
      return 1 + ((weekday - firstWeekday + 7) % 7) + (n - 1) * 7;
    };
    const getLastWeekday = (y, m, weekday) => {
      const lastDay = new Date(y, m + 1, 0);
      const lastWeekday = lastDay.getDay();
      const diff = (lastWeekday - weekday + 7) % 7;
      return lastDay.getDate() - diff;
    };

    if (month === 0 && date === 1) holidays.push({ id:`holiday-newyear-${year}`, title:"new year's day", startDate:dateStr, endDate:dateStr, isAllDay:true, calendarId:'holidays' });
    if (month === 0 && date === getNthWeekday(year, 0, 1, 3)) holidays.push({ id:`holiday-mlk-${year}`, title:'martin luther king jr. day', startDate:dateStr, endDate:dateStr, isAllDay:true, calendarId:'holidays' });
    if (month === 1 && date === getNthWeekday(year, 1, 1, 3)) holidays.push({ id:`holiday-presidents-${year}`, title:"presidents' day", startDate:dateStr, endDate:dateStr, isAllDay:true, calendarId:'holidays' });
    if (month === 4 && date === getLastWeekday(year, 4, 1)) holidays.push({ id:`holiday-memorial-${year}`, title:'memorial day', startDate:dateStr, endDate:dateStr, isAllDay:true, calendarId:'holidays' });
    if (month === 6 && date === 4) holidays.push({ id:`holiday-july4-${year}`, title:'independence day', startDate:dateStr, endDate:dateStr, isAllDay:true, calendarId:'holidays' });
    if (month === 8 && date === getNthWeekday(year, 8, 1, 1)) holidays.push({ id:`holiday-labor-${year}`, title:'labor day', startDate:dateStr, endDate:dateStr, isAllDay:true, calendarId:'holidays' });
    if (month === 9 && date === getNthWeekday(year, 9, 1, 2)) holidays.push({ id:`holiday-columbus-${year}`, title:'columbus day', startDate:dateStr, endDate:dateStr, isAllDay:true, calendarId:'holidays' });
    if (month === 10 && date === 11) holidays.push({ id:`holiday-veterans-${year}`, title:'veterans day', startDate:dateStr, endDate:dateStr, isAllDay:true, calendarId:'holidays' });
    if (month === 10 && date === getNthWeekday(year, 10, 4, 4)) holidays.push({ id:`holiday-thanksgiving-${year}`, title:'thanksgiving', startDate:dateStr, endDate:dateStr, isAllDay:true, calendarId:'holidays' });
    if (month === 11 && date === 25) holidays.push({ id:`holiday-christmas-${year}`, title:'christmas day', startDate:dateStr, endDate:dateStr, isAllDay:true, calendarId:'holidays' });

    return holidays;
  }

  function getEventsForDay(userEvents, day){
    const dayStr = format(day, 'yyyy-MM-dd');

    const user = userEvents.filter((ev) => {
      return (dayStr >= ev.startDate) && (dayStr <= ev.endDate);
    });

    const sample = generateSampleEventsForDay(day);
    const holidays = getHolidaysForDay(day);

    return [...holidays, ...sample, ...user];
  }

  function getEventsForDateRange(events, start, end){
    const startStr = format(start, 'yyyy-MM-dd');
    const endStr = format(end, 'yyyy-MM-dd');
    return events.filter((ev) => ev.startDate <= endStr && ev.endDate >= startStr);
  }

  // --- storage ---
  function isValidViewType(v){ return v && VALID_VIEW_TYPES.includes(v); }

  function getDefaultScrollTop(){
    const now = new Date();
    const currentTimePixels = (now.getHours() + now.getMinutes()/60) * DEFAULT_HOUR_HEIGHT;
    return Math.max(0, currentTimePixels - 200);
  }

  function loadViewState(){
    try {
      const savedView = sessionStorage.getItem(VIEW_STORAGE_KEY);
      const savedDate = sessionStorage.getItem(DATE_STORAGE_KEY);
      const savedScroll = sessionStorage.getItem(SCROLL_STORAGE_KEY);
      return {
        view: isValidViewType(savedView) ? savedView : 'week',
        currentDate: savedDate ? new Date(savedDate) : new Date(),
        scrollTop: savedScroll ? parseInt(savedScroll,10) : getDefaultScrollTop(),
      };
    } catch {
      return { view:'week', currentDate: new Date(), scrollTop: getDefaultScrollTop() };
    }
  }

  function saveViewState(view, currentDate, scrollTop){
    try {
      sessionStorage.setItem(VIEW_STORAGE_KEY, view);
      sessionStorage.setItem(DATE_STORAGE_KEY, currentDate.toISOString());
      if (typeof scrollTop === 'number') sessionStorage.setItem(SCROLL_STORAGE_KEY, String(scrollTop));
    } catch {}
  }

  function saveScrollPosition(scrollTop){
    try { sessionStorage.setItem(SCROLL_STORAGE_KEY, String(scrollTop)); } catch {}
  }

  function loadUserEvents(){
    try {
      const stored = localStorage.getItem(USER_EVENTS_KEY);
      if (!stored) return [];
      const parsed = safeJsonParse(stored, null);
      if (!Array.isArray(parsed)) return [];
      // minimal validation from calendar-app.tsx
      return parsed.filter((e) => e && typeof e.id==='string' && typeof e.title==='string' && typeof e.startDate==='string' && typeof e.endDate==='string' && typeof e.isAllDay==='boolean' && typeof e.calendarId==='string');
    } catch { return []; }
  }

  function saveUserEvents(evts){
    try { localStorage.setItem(USER_EVENTS_KEY, JSON.stringify(evts)); } catch {}
  }

  function loadCalendars(){
    try {
      const stored = localStorage.getItem(CALENDARS_STORAGE_KEY);
      if (stored) {
        const userCalendars = safeJsonParse(stored, null);
        if (Array.isArray(userCalendars)) {
          const hasHolidays = userCalendars.some(c => c && c.id === 'holidays');
          if (!hasHolidays) return [DEFAULT_CALENDARS[0], ...userCalendars];
          return userCalendars;
        }
      }
    } catch {}
    return DEFAULT_CALENDARS;
  }

  function generateEventId(){
    return `event-${Date.now()}-${Math.random().toString(36).slice(2, 11)}`;
  }

  // --- event layout (ported from time-grid.tsx) ---
  function calculateEventLayout(events){
    if (!events.length) return [];

    const parseTime = (t) => {
      const [h,m] = String(t).split(':').map(Number);
      return (h*60)+(m||0);
    };

    const eventTimes = new Map();
    for (const ev of events) {
      eventTimes.set(ev.id, { start: parseTime(ev.startTime || '00:00'), end: parseTime(ev.endTime || '23:59') });
    }

    const sorted = [...events].sort((a,b) => {
      const at = eventTimes.get(a.id);
      const bt = eventTimes.get(b.id);
      if (at.start !== bt.start) return at.start - bt.start;
      return (bt.end - bt.start) - (at.end - at.start);
    });

    const columns = []; // { endTime, events }
    const eventColumns = new Map();

    for (const ev of sorted) {
      const { start, end } = eventTimes.get(ev.id);
      let placed = false;
      for (let col=0; col<columns.length; col++){
        if (columns[col].endTime <= start) {
          columns[col].endTime = end;
          columns[col].events.push(ev);
          eventColumns.set(ev.id, col);
          placed = true;
          break;
        }
      }
      if (!placed) {
        eventColumns.set(ev.id, columns.length);
        columns.push({ endTime:end, events:[ev] });
      }
    }

    // union-find overlap groups
    const parent = new Map();
    const find = (id) => {
      if (!parent.has(id)) parent.set(id, id);
      if (parent.get(id) !== id) parent.set(id, find(parent.get(id)));
      return parent.get(id);
    };
    const union = (a,b) => { parent.set(find(a), find(b)); };

    for (let col=0; col<columns.length-1; col++){
      for (const ev of columns[col].events){
        const { start, end } = eventTimes.get(ev.id);
        for (const other of columns[col+1].events){
          const ot = eventTimes.get(other.id);
          if (start < ot.end && end > ot.start) union(ev.id, other.id);
        }
      }
    }

    const groupMaxColumn = new Map();
    for (const ev of events){
      const group = find(ev.id);
      const col = eventColumns.get(ev.id) || 0;
      groupMaxColumn.set(group, Math.max(groupMaxColumn.get(group) || 0, col));
    }

    return events.map((ev) => ({
      event: ev,
      column: eventColumns.get(ev.id) || 0,
      totalColumns: (groupMaxColumn.get(find(ev.id)) || 0) + 1,
    }));
  }

  // --- state ---
  const initial = loadViewState();
  const state = {
    view: initial.view,
    currentDate: initial.currentDate,
    scrollTop: initial.scrollTop,
    calendars: loadCalendars(),
    userEvents: loadUserEvents(),
    selectedEventId: null,
    // modal
    modalOpen: false,
    editingId: null,
    initialDate: null,
    initialEndDate: null,
    initialStartTime: null,
    initialEndTime: null,
  };

  // --- DOM references ---
  const root = document.getElementById('cal-app');
  const modalBackdrop = document.getElementById('modal-backdrop');

  const elTitle = document.getElementById('ev-title');
  const elCalendar = document.getElementById('ev-calendar');
  const elLocation = document.getElementById('ev-location');
  const elAllDay = document.getElementById('ev-allday');
  const elStartDate = document.getElementById('ev-start-date');
  const elEndDate = document.getElementById('ev-end-date');
  const elStartTime = document.getElementById('ev-start-time');
  const elEndTime = document.getElementById('ev-end-time');
  const elDelete = document.getElementById('ev-delete');

  const TIME_OPTIONS = (() => {
    const out=[];
    for (let h=0; h<24; h++) for (let m=0; m<60; m+=15) out.push(`${pad2(h)}:${pad2(m)}`);
    return out;
  })();
  const END_TIME_OPTIONS = [...TIME_OPTIONS, '24:00'];

  // --- rendering ---
  function getCalendarColor(id){
    const c = state.calendars.find(c => c.id === id);
    return (c && c.color) ? c.color : '#007AFF';
  }

  function setTitleInShell(){
    try { window.OSX_APP?.setTitle?.('Calendar'); } catch {}
  }

  function renderNav(){
    const viewButtons = VALID_VIEW_TYPES.map(v => {
      const label = v[0].toUpperCase() + v.slice(1);
      return `<button class="view-btn ${state.view===v?'active':''}" data-view="${v}">${label}</button>`;
    }).join('');

    return `
      <div class="cal-nav" id="cal-nav">
        <div class="cal-left">
          <button class="btn-ico" id="btn-new" title="New Event">＋</button>
        </div>
        <div class="view-switch" id="view-switch">${viewButtons}</div>
        <div class="cal-spacer"></div>
        <div class="cal-right" style="display:flex; align-items:center; gap:6px;">
          <button class="btn-ico" id="btn-prev" title="Previous">‹</button>
          <button class="btn" id="btn-today">Today</button>
          <button class="btn-ico" id="btn-next" title="Next">›</button>
        </div>
      </div>
    `;
  }

  function renderWeekView(){
    const weekDays = getWeekDays(state.currentDate);

    const header = `
      <div class="cal-header">
        <h1>${escapeHtml(formatDateHeader(state.currentDate, 'week'))}</h1>
      </div>
    `;

    const dowCells = weekDays.map(d => {
      const dow = escapeHtml(format(d, 'EEE'));
      const dayNum = escapeHtml(format(d, 'd'));
      if (isToday(d)) {
        return `
          <div class="dow-cell">
            <div class="dow-name">${dow}</div>
            <div class="dow-today">${dayNum}</div>
          </div>
        `;
      }
      return `
        <div class="dow-cell">
          <div class="dow-name">${dow}</div>
          <div class="dow-num">${dayNum}</div>
        </div>
      `;
    }).join('');

    const dowRow = `
      <div class="dow-row">
        <div class="dow-spacer"></div>
        ${dowCells}
      </div>
    `;

    const allDay = renderAllDayRow(weekDays);

    const tg = renderTimeGrid(weekDays);

    return `<div class="tg" data-view="week">${header}${dowRow}${allDay}${tg}</div>`;
  }

  function renderDayView(){
    const date = dateOnly(state.currentDate);

    const header = `
      <div class="cal-header">
        <h1>${escapeHtml(formatDateHeader(date, 'day'))}</h1>
      </div>
    `;

    const dowRow = `
      <div class="dow-row">
        <div class="dow-spacer"></div>
        <div class="dow-cell" style="border-left:0">
          <div class="dow-name">${escapeHtml(format(date,'EEEE'))}</div>
          ${isToday(date)
            ? `<div class="dow-today" style="width:34px; height:34px; font-size:18px;">${escapeHtml(format(date,'d'))}</div>`
            : `<div class="dow-num" style="font-size:20px;">${escapeHtml(format(date,'d'))}</div>`
          }
        </div>
      </div>
    `;

    const allDay = renderAllDayRow([date]);
    const tg = renderTimeGrid([date]);

    return `<div class="tg" data-view="day">${header}${dowRow}${allDay}${tg}</div>`;
  }

  // Month view (ported structure from month-view.tsx; simplified virtualization but same constants)
  const WEEK_HEIGHT = 100;
  const TOTAL_WEEKS = 1040;
  const CENTER_WEEK_INDEX = TOTAL_WEEKS / 2;
  const OVERSCAN = 5;

  function getBaseDate(){
    const today = new Date();
    return startOfWeek(addWeeks(today, -CENTER_WEEK_INDEX));
  }

  const baseDate = getBaseDate();

  function getWeekForIndex(idx){
    const weekStart = addWeeks(baseDate, idx);
    const weekEnd = endOfWeek(weekStart);
    return eachDayOfInterval({ start: weekStart, end: weekEnd });
  }

  function renderMonthView(){
    // Current visible month is based on currentDate (we update it on scroll, like original).
    const header = `
      <div class="cal-header">
        <h1 id="month-title">${escapeHtml(format(state._visibleMonth || state.currentDate, 'MMMM yyyy'))}</h1>
      </div>
    `;

    const weekdays = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(d => `<div>${d}</div>`).join('');
    const wRow = `<div class="month-weekdays">${weekdays}</div>`;

    const totalHeight = TOTAL_WEEKS * WEEK_HEIGHT;

    return `
      <div class="month" style="height:100%; display:flex; flex-direction:column;">
        ${header}
        ${wRow}
        <div class="month-scroll" id="month-scroll">
          <div class="month-spacer" style="height:${totalHeight}px" id="month-spacer"></div>
        </div>
      </div>
    `;
  }

  function renderYearView(){
    const header = `
      <div class="cal-header">
        <h1 id="year-title">${escapeHtml(String(state._visibleYear || state.currentDate.getFullYear()))}</h1>
      </div>
    `;

    return `
      <div class="year" style="height:100%; display:flex; flex-direction:column;">
        ${header}
        <div class="year-scroll" id="year-scroll">
          <div class="year-pad" id="year-pad"></div>
        </div>
      </div>
    `;
  }

  function renderAllDayRow(dates){
    const allDayEventsByDate = dates.map(d => getEventsForDay(state.userEvents, d).filter(e => e.isAllDay));
    const hasAllDay = allDayEventsByDate.some(arr => arr.length > 0);
    if (!hasAllDay) return '';

    const cols = dates.map((date, idx) => {
      const dayEvents = allDayEventsByDate[idx];
      const dateStr = format(date, 'yyyy-MM-dd');
      const items = dayEvents.map((ev) => {
        const color = getCalendarColor(ev.calendarId);
        const isStart = ev.startDate === dateStr;
        const isEnd = ev.endDate === dateStr;
        const isSelected = state.selectedEventId === ev.id;
        const isUser = state.userEvents.some(e => e.id === ev.id);

        const radius = (isStart && isEnd) ? '8px' : (isStart && !isEnd) ? '8px 0 0 8px' : (!isStart && isEnd) ? '0 8px 8px 0' : '0';

        return `
          <div class="allday-ev" data-ev="${escapeAttr(ev.id)}" data-user="${isUser?'1':'0'}" style="border-radius:${radius}; background:${isSelected?color+'50':color+'20'}; color:${color}; cursor:${isUser?'pointer':'default'}">
            <span class="allday-dot" style="background:${color}"></span>
            ${isStart ? `<span style="font-weight:700;" class="truncate">${escapeHtml(ev.title)}</span>` : `<span class="truncate"></span>`}
          </div>
        `;
      }).join('');

      return `<div class="allday-col">${items}</div>`;
    }).join('');

    return `
      <div class="allday-row" id="allday-row">
        <div class="allday-label">all-day</div>
        ${cols}
      </div>
    `;
  }

  function renderTimeGrid(dates){
    const hours = getDayHours();

    const timeLabels = hours.map((h) => {
      const top = h * DEFAULT_HOUR_HEIGHT + GRID_PADDING_TOP;
      return `<div class="tg-tlabel" style="top:${top}px">${escapeHtml(formatHour(h))}</div>`;
    }).join('');

    const cols = dates.map((date, colIdx) => {
      const hourLines = hours.map((h) => {
        const top = h * DEFAULT_HOUR_HEIGHT + GRID_PADDING_TOP;
        return `<div class="tg-hline" style="top:${top}px"></div>`;
      }).join('');

      const dayEvents = getEventsForDay(state.userEvents, date).filter(e => !e.isAllDay);
      const layouts = calculateEventLayout(dayEvents);

      const now = new Date();
      const currentTimeTop = (now.getHours()*60 + now.getMinutes()) * (DEFAULT_HOUR_HEIGHT/60);
      const nowMarker = isToday(date) ? `
        <div class="tg-now" style="top:${currentTimeTop + GRID_PADDING_TOP}px">
          <div class="tg-nowdot"></div>
          <div class="tg-nowline"></div>
        </div>
      ` : '';

      const evs = layouts.map(({event, column, totalColumns}) => {
        const { top, height } = getEventTimePosition(event);
        const color = getCalendarColor(event.calendarId);
        const timeRange = (event.startTime && event.endTime) ? `${formatEventTime(event.startTime)} – ${formatEventTime(event.endTime)}` : null;

        const width = `calc((100% - 8px) / ${totalColumns})`;
        const left = `calc(4px + (100% - 8px) * ${column} / ${totalColumns})`;

        const isSelected = state.selectedEventId === event.id;
        const isUser = state.userEvents.some(e => e.id === event.id);

        return `
          <div class="tg-ev" data-ev="${escapeAttr(event.id)}" data-user="${isUser?'1':'0'}"
            style="top:${top + GRID_PADDING_TOP}px; height:${height}px; width:${width}; left:${left}; background:${isSelected?color+'50':color+'20'}; color:${color}; border-left-color:${color}; cursor:${isUser?'pointer':'default'}">
            <div class="t">${escapeHtml(event.title)}</div>
            ${(timeRange && height > 30) ? `<div class="s">${escapeHtml(timeRange)}</div>` : ''}
            ${(event.location && height > 45) ? `<div class="s">${escapeHtml(event.location)}</div>` : ''}
          </div>
        `;
      }).join('');

      return `
        <div class="tg-col" data-col="${colIdx}">
          ${hourLines}
          ${nowMarker}
          <div class="tg-events" style="position:absolute; inset:0;">${evs}</div>
        </div>
      `;
    }).join('');

    return `
      <div class="tg-scroll" id="tg-scroll" style="--hourH:${DEFAULT_HOUR_HEIGHT}px">
        <div class="tg-inner">
          <div class="tg-timecol">${timeLabels}</div>
          <div class="tg-cols" id="tg-cols">${cols}</div>
        </div>
      </div>
    `;
  }

  function render(){
    setTitleInShell();

    let viewHtml = '';
    if (state.view === 'week') viewHtml = renderWeekView();
    else if (state.view === 'day') viewHtml = renderDayView();
    else if (state.view === 'month') viewHtml = renderMonthView();
    else viewHtml = renderYearView();

    root.innerHTML = `${renderNav()}<div class="cal-body" id="cal-body">${viewHtml}</div>`;

    bindNavHandlers();

    if (state.view === 'week' || state.view === 'day') {
      setupTimeGridInteractions();
    }
    if (state.view === 'month') {
      setupMonthVirtualScroll();
    }
    if (state.view === 'year') {
      setupYearScroll();
    }

    saveViewState(state.view, state.currentDate);
  }

  // --- interactions ---
  function bindNavHandlers(){
    const vs = document.getElementById('view-switch');
    vs.addEventListener('click', (e) => {
      const b = e.target.closest('[data-view]');
      if (!b) return;
      const v = b.getAttribute('data-view');
      if (!isValidViewType(v)) return;
      state.view = v;
      if (state.view === 'day') {
        // keep currentDate
      }
      render();
    });

    document.getElementById('btn-prev').addEventListener('click', () => {
      state.currentDate = navigateDate(state.currentDate, 'prev', state.view);
      render();
    });
    document.getElementById('btn-next').addEventListener('click', () => {
      state.currentDate = navigateDate(state.currentDate, 'next', state.view);
      render();
    });
    document.getElementById('btn-today').addEventListener('click', () => {
      state.currentDate = new Date();
      render();
    });
    document.getElementById('btn-new').addEventListener('click', () => {
      openEventForm({
        initialDate: dateOnly(state.currentDate),
        initialEndDate: dateOnly(state.currentDate),
        initialStartTime: '09:00',
        initialEndTime: '10:00',
      });
    });
  }

  // Selection + keyboard shortcuts (ported from calendar-app.tsx)
  window.addEventListener('keydown', (e) => {
    if (state.modalOpen) return;
    const t = e.target;
    if (t instanceof HTMLInputElement || t instanceof HTMLTextAreaElement || t instanceof HTMLSelectElement) return;

    if ((e.key === 'Delete' || e.key === 'Backspace') && state.selectedEventId) {
      e.preventDefault();
      deleteEvent(state.selectedEventId);
      return;
    }
    if (e.key === 'Escape' && state.selectedEventId) {
      state.selectedEventId = null;
      render();
      return;
    }
    if (e.metaKey || e.ctrlKey || e.altKey) return;

    if (e.key === 'd') { state.view = 'day'; render(); }
    if (e.key === 'w') { state.view = 'week'; render(); }
    if (e.key === 'm') { state.view = 'month'; render(); }
    if (e.key === 'y') { state.view = 'year'; render(); }
  });

  function setupTimeGridInteractions(){
    const scroll = document.getElementById('tg-scroll');
    // restore scroll once
    scroll.scrollTop = (typeof state.scrollTop === 'number') ? state.scrollTop : 0;

    scroll.addEventListener('scroll', () => {
      state.scrollTop = scroll.scrollTop;
      saveScrollPosition(scroll.scrollTop);
    });

    // event click/selection
    scroll.addEventListener('click', (e) => {
      const evEl = e.target.closest('[data-ev]');
      if (!evEl) {
        if (state.selectedEventId) {
          state.selectedEventId = null;
          render();
        }
        return;
      }
      const isUser = evEl.getAttribute('data-user') === '1';
      if (!isUser) return;
      const id = evEl.getAttribute('data-ev');
      state.selectedEventId = (state.selectedEventId === id) ? null : id;
      render();
    });

    scroll.addEventListener('dblclick', (e) => {
      const evEl = e.target.closest('[data-ev]');
      if (evEl) {
        const isUser = evEl.getAttribute('data-user') === '1';
        if (isUser) {
          const id = evEl.getAttribute('data-ev');
          openEditEvent(id);
        }
        return;
      }

      // double-click empty grid => create 1-hour event (ported from time-grid.tsx)
      const colEl = e.target.closest('.tg-col');
      if (!colEl) return;
      const colIndex = parseInt(colEl.getAttribute('data-col') || '0', 10);

      const gridRect = scroll.getBoundingClientRect();
      const rawY = e.clientY - gridRect.top + scroll.scrollTop - GRID_PADDING_TOP;
      const relativeY = clamp(rawY, 0, 24 * DEFAULT_HOUR_HEIGHT);
      const time = pixelToTime(relativeY, DEFAULT_HOUR_HEIGHT);
      const startHour = Math.min(23, time.hour);
      const startMinute = (time.hour >= 24) ? 0 : time.minute;
      const endMinutes = Math.min(startHour*60 + startMinute + 60, 24*60);
      const endHour = Math.floor(endMinutes/60);
      const endMin = endMinutes % 60;

      const dates = (state.view === 'week') ? getWeekDays(state.currentDate) : [dateOnly(state.currentDate)];
      const date = dates[colIndex] || dates[0];
      openEventForm({
        initialDate: dateOnly(date),
        initialEndDate: dateOnly(date),
        initialStartTime: formatTimeValue(startHour, startMinute),
        initialEndTime: formatTimeValue(endHour, endMin),
      });
    });

    // drag-to-create (ported from time-grid.tsx)
    let dragState = null;

    scroll.addEventListener('mousedown', (e) => {
      // only left button
      if (e.button !== 0) return;
      // if clicking on an event, ignore (handled by click)
      if (e.target.closest('[data-ev]')) return;

      // if an event is selected, just deselect
      if (state.selectedEventId) {
        state.selectedEventId = null;
        render();
        return;
      }

      const colEl = e.target.closest('.tg-col');
      if (!colEl) return;
      const colIndex = parseInt(colEl.getAttribute('data-col') || '0', 10);

      const gridRect = scroll.getBoundingClientRect();
      const rawY = e.clientY - gridRect.top + scroll.scrollTop - GRID_PADDING_TOP;
      const relativeY = clamp(rawY, 0, 24 * DEFAULT_HOUR_HEIGHT);

      dragState = { colIndex, startY: relativeY, currentY: relativeY };
      drawDragPreview(scroll, dragState);

      const onMove = (ev) => {
        const raw = ev.clientY - gridRect.top + scroll.scrollTop - GRID_PADDING_TOP;
        dragState.currentY = clamp(raw, 0, 24 * DEFAULT_HOUR_HEIGHT);
        drawDragPreview(scroll, dragState);
      };

      const onUp = () => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        const ds = dragState;
        dragState = null;
        clearDragPreview(scroll);

        if (!ds) return;
        const minY = Math.min(ds.startY, ds.currentY);
        const maxY = Math.max(ds.startY, ds.currentY);

        const startTime = pixelToTime(minY, DEFAULT_HOUR_HEIGHT);
        let endTime = pixelToTime(maxY, DEFAULT_HOUR_HEIGHT);

        const startMinutes = startTime.hour*60 + startTime.minute;
        const endMinutes = endTime.hour*60 + endTime.minute;
        if (endMinutes <= startMinutes) {
          const newEndMinutes = Math.min(startMinutes + 15, 24*60);
          endTime = { hour: Math.floor(newEndMinutes/60), minute: newEndMinutes%60 };
        }

        const dates = (state.view === 'week') ? getWeekDays(state.currentDate) : [dateOnly(state.currentDate)];
        const date = dates[ds.colIndex] || dates[0];

        openEventForm({
          initialDate: dateOnly(date),
          initialEndDate: dateOnly(date),
          initialStartTime: formatTimeValue(startTime.hour, startTime.minute),
          initialEndTime: formatTimeValue(endTime.hour, endTime.minute),
        });
      };

      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    });

    // all-day row selection
    const al = document.getElementById('allday-row');
    if (al) {
      al.addEventListener('click', (e) => {
        const evEl = e.target.closest('[data-ev]');
        if (!evEl) return;
        const isUser = evEl.getAttribute('data-user') === '1';
        if (!isUser) return;
        const id = evEl.getAttribute('data-ev');
        state.selectedEventId = (state.selectedEventId === id) ? null : id;
        render();
      });
      al.addEventListener('dblclick', (e) => {
        const evEl = e.target.closest('[data-ev]');
        if (!evEl) return;
        const isUser = evEl.getAttribute('data-user') === '1';
        if (!isUser) return;
        openEditEvent(evEl.getAttribute('data-ev'));
      });
    }
  }

  function drawDragPreview(scroll, ds){
    clearDragPreview(scroll);
    const colsEl = document.getElementById('tg-cols');
    const col = colsEl && colsEl.querySelector(`.tg-col[data-col="${ds.colIndex}"]`);
    if (!col) return;
    const minY = Math.min(ds.startY, ds.currentY) + GRID_PADDING_TOP;
    const height = Math.abs(ds.currentY - ds.startY);
    const el = document.createElement('div');
    el.className = 'tg-drag';
    el.style.top = `${minY}px`;
    el.style.height = `${height}px`;
    el.dataset.drag = '1';
    col.appendChild(el);
  }

  function clearDragPreview(scroll){
    $$('#tg-cols [data-drag="1"]', scroll).forEach(n => n.remove());
  }

  // Month virtualization (mirrors month-view.tsx scroll behavior)
  function setupMonthVirtualScroll(){
    const scroll = document.getElementById('month-scroll');
    const spacer = document.getElementById('month-spacer');
    if (!scroll || !spacer) return;

    // initialize scroll to currentDate week
    const viewportHeight = scroll.clientHeight || 600;
    const weeksDiff = Math.floor((startOfWeek(state.currentDate).getTime() - baseDate.getTime()) / (7*86400000));
    const targetIndex = clamp(weeksDiff, 0, TOTAL_WEEKS-1);
    const targetScrollTop = targetIndex * WEEK_HEIGHT - viewportHeight/2 + WEEK_HEIGHT/2;
    scroll.scrollTop = Math.max(0, targetScrollTop);

    function renderVisibleWeeks(){
      const scrollTop = scroll.scrollTop;
      const startIdx = Math.max(0, Math.floor(scrollTop / WEEK_HEIGHT) - OVERSCAN);
      const endIdx = Math.min(TOTAL_WEEKS-1, Math.ceil((scrollTop + viewportHeight) / WEEK_HEIGHT) + OVERSCAN);

      // visible month calc (dominant month of center week)
      const centerIdx = Math.floor((scrollTop + viewportHeight/3) / WEEK_HEIGHT);
      const centerWeek = getWeekForIndex(centerIdx);
      if (centerWeek.length){
        const counts = new Map();
        centerWeek.forEach(day => {
          const m = day.getMonth();
          counts.set(m, (counts.get(m)||0)+1);
        });
        let dominant = centerWeek[0].getMonth();
        let max=0;
        counts.forEach((c,m)=>{ if (c>max){ max=c; dominant=m; } });
        const monthDate = centerWeek.find(d => d.getMonth()===dominant) || centerWeek[0];
        state._visibleMonth = startOfMonth(monthDate);
        const mt = document.getElementById('month-title');
        if (mt) mt.textContent = format(state._visibleMonth, 'MMMM yyyy');
      }

      // clear old
      spacer.innerHTML = '';

      for (let i=startIdx; i<=endIdx; i++){
        const days = getWeekForIndex(i);
        const weekEl = document.createElement('div');
        weekEl.className = 'month-week';
        weekEl.style.top = (i * WEEK_HEIGHT) + 'px';
        weekEl.style.height = WEEK_HEIGHT + 'px';

        for (const day of days){
          const cell = document.createElement('div');
          cell.className = 'month-cell';
          const dayIsToday = isToday(day);
          const isCurrent = (state._visibleMonth ? day.getMonth() === state._visibleMonth.getMonth() : true);

          const top = document.createElement('div');
          top.className = 'month-top';

          const dn = document.createElement('div');
          dn.className = 'month-day' + (dayIsToday ? ' today' : '') + (!isCurrent ? ' muted' : '');
          dn.textContent = String(day.getDate());

          top.appendChild(dn);
          cell.appendChild(top);

          const dayEvents = getEventsForDay(state.userEvents, day);
          // show up to 3 events
          const show = dayEvents.slice(0, 3);
          for (const ev of show){
            const color = getCalendarColor(ev.calendarId);
            const evEl = document.createElement('div');
            evEl.className = 'month-ev';
            evEl.style.background = color + '20';
            evEl.style.color = color;
            if (!ev.isAllDay && ev.startTime && ev.endTime) {
              evEl.textContent = `${formatEventTime(ev.startTime)} ${ev.title}`;
            } else {
              evEl.textContent = ev.title;
            }
            cell.appendChild(evEl);
          }

          cell.addEventListener('click', () => {
            state.currentDate = day;
            state.view = 'day';
            render();
          });
          cell.addEventListener('dblclick', (e) => {
            e.preventDefault();
            openEventForm({ initialDate: dateOnly(day), initialEndDate: dateOnly(day), initialStartTime: '09:00', initialEndTime: '10:00' });
          });

          weekEl.appendChild(cell);
        }

        spacer.appendChild(weekEl);
      }
    }

    renderVisibleWeeks();
    scroll.addEventListener('scroll', () => {
      renderVisibleWeeks();
    });
  }

  // Year view (ported from year-view.tsx; fixed year range)
  function setupYearScroll(){
    const scroll = document.getElementById('year-scroll');
    const pad = document.getElementById('year-pad');
    if (!scroll || !pad) return;

    const YEARS_BEFORE = 10;
    const YEARS_AFTER = 10;

    const currentYear = state.currentDate.getFullYear();
    const years = [];
    for (let i=-YEARS_BEFORE; i<=YEARS_AFTER; i++) years.push(currentYear + i);

    const yearRefs = new Map();

    pad.innerHTML = '';
    years.forEach((year) => {
      const block = document.createElement('div');
      block.className = 'year-block';
      block.dataset.year = String(year);

      const label = document.createElement('div');
      label.className = 'year-label';
      label.textContent = String(year);
      block.appendChild(label);

      const grid = document.createElement('div');
      grid.className = 'year-grid';

      getYearMonths(year).forEach((monthDate) => {
        const mm = document.createElement('div');

        const btn = document.createElement('button');
        btn.className = 'mini-month-name';
        btn.textContent = format(monthDate, 'MMMM');
        btn.addEventListener('click', () => {
          state.currentDate = monthDate;
          state.view = 'month';
          render();
        });

        mm.appendChild(btn);

        const wds = document.createElement('div');
        wds.className = 'mini-weekdays';
        ['S','M','T','W','T','F','S'].forEach(ch => {
          const d = document.createElement('div');
          d.textContent = ch;
          wds.appendChild(d);
        });
        mm.appendChild(wds);

        const days = getMonthViewDays(monthDate);
        const weeks = [];
        for (let i=0; i<days.length; i+=7) weeks.push(days.slice(i, i+7));

        const weeksEl = document.createElement('div');
        weeksEl.className = 'mini-weeks';

        weeks.forEach((week) => {
          const row = document.createElement('div');
          row.className = 'mini-week';
          week.forEach((day) => {
            const isCur = isSameMonth(day, monthDate);
            const b = document.createElement('button');
            b.className = 'mini-day' + (!isCur ? ' muted' : '') + (isToday(day) ? ' today' : '');
            b.textContent = String(day.getDate());
            if (!isCur) {
              b.disabled = true;
            } else {
              b.addEventListener('click', () => {
                state.currentDate = day;
                state.view = 'day';
                render();
              });
            }
            row.appendChild(b);
          });
          weeksEl.appendChild(row);
        });

        mm.appendChild(weeksEl);
        grid.appendChild(mm);
      });

      block.appendChild(grid);
      pad.appendChild(block);
      yearRefs.set(year, block);
    });

    // initial scroll to current year
    const yearEl = yearRefs.get(currentYear);
    if (yearEl) {
      scroll.scrollTop = Math.max(0, yearEl.offsetTop - scroll.clientHeight/4);
      state._visibleYear = currentYear;
      const yt = document.getElementById('year-title');
      if (yt) yt.textContent = String(currentYear);
    }

    scroll.addEventListener('scroll', () => {
      const viewportTop = scroll.scrollTop + 100;
      let closestYear = currentYear;
      let closestDist = Infinity;
      yearRefs.forEach((el, year) => {
        const dist = Math.abs(el.offsetTop - viewportTop);
        if (dist < closestDist) { closestDist = dist; closestYear = year; }
      });
      if (closestYear !== state._visibleYear) {
        state._visibleYear = closestYear;
        const yt = document.getElementById('year-title');
        if (yt) yt.textContent = String(closestYear);
      }
    });
  }

  // --- modal (EventForm) ---
  function populateCalendarSelect(selectedId){
    const all = [...state.calendars.filter(c => c.id !== 'holidays'), ...state.calendars.filter(c => c.id === 'holidays')];
    elCalendar.innerHTML = all.map(c => `<option value="${escapeAttr(c.id)}">${escapeHtml(c.name)}</option>`).join('');
    elCalendar.value = selectedId;
  }

  function populateTimeSelects(){
    elStartTime.innerHTML = TIME_OPTIONS.map(t => `<option value="${t}">${t}</option>`).join('');
    elEndTime.innerHTML = END_TIME_OPTIONS.map(t => `<option value="${t}">${t}</option>`).join('');
  }
  populateTimeSelects();

  function openEventForm({ initialDate, initialEndDate, initialStartTime, initialEndTime }){
    state.editingId = null;
    state.modalOpen = true;

    const d0 = initialDate || new Date();
    const d1 = initialEndDate || d0;

    const defaultCalendarId = (
      state.calendars.find(c => c.id === 'meetings')?.id ||
      state.calendars.find(c => c.id !== 'holidays')?.id ||
      state.calendars[0]?.id ||
      'meetings'
    );

    elTitle.value = '';
    elLocation.value = '';
    elAllDay.checked = false;
    elStartDate.value = format(d0, 'yyyy-MM-dd');
    elEndDate.value = format(d1, 'yyyy-MM-dd');
    elStartTime.value = initialStartTime || '09:00';
    elEndTime.value = initialEndTime || '10:00';

    populateCalendarSelect(defaultCalendarId);

    elDelete.style.display = 'none';

    showModal(true);
    setTimeout(() => elTitle.focus(), 20);
  }

  function openEditEvent(eventId){
    const ev = state.userEvents.find(e => e.id === eventId);
    if (!ev) return;

    state.editingId = ev.id;
    state.modalOpen = true;

    elTitle.value = ev.title || '';
    elLocation.value = ev.location || '';
    elAllDay.checked = !!ev.isAllDay;
    elStartDate.value = ev.startDate;
    elEndDate.value = ev.endDate;
    elStartTime.value = ev.startTime || '09:00';
    elEndTime.value = ev.endTime || '10:00';

    populateCalendarSelect(ev.calendarId);

    elDelete.style.display = 'inline-flex';

    showModal(true);
    setTimeout(() => elTitle.focus(), 20);
  }

  function closeModal(){
    state.modalOpen = false;
    state.editingId = null;
    showModal(false);
  }

  function showModal(show){
    modalBackdrop.classList.toggle('show', !!show);
    modalBackdrop.setAttribute('aria-hidden', show ? 'false' : 'true');
  }

  function saveEventFromModal(){
    const title = (elTitle.value || '').trim() || 'New Event';
    const location = (elLocation.value || '').trim() || undefined;
    const isAllDay = !!elAllDay.checked;
    let startDate = elStartDate.value;
    let endDate = elEndDate.value;
    const startTime = elStartTime.value;
    const endTime = elEndTime.value;
    const calendarId = elCalendar.value;

    // keep endDate >= startDate
    if (endDate < startDate) endDate = startDate;

    const eventEndDate = (!isAllDay && endTime === '24:00') ? startDate : endDate;

    const ev = {
      id: state.editingId || generateEventId(),
      title,
      startDate,
      endDate: eventEndDate,
      startTime: isAllDay ? undefined : startTime,
      endTime: isAllDay ? undefined : endTime,
      isAllDay,
      calendarId,
      location,
    };

    const idx = state.userEvents.findIndex(e => e.id === ev.id);
    if (idx >= 0) state.userEvents[idx] = ev;
    else state.userEvents.push(ev);

    saveUserEvents(state.userEvents);
    closeModal();
    render();
  }

  function deleteEvent(eventId){
    const exists = state.userEvents.some(e => e.id === eventId);
    if (!exists) return;
    state.userEvents = state.userEvents.filter(e => e.id !== eventId);
    saveUserEvents(state.userEvents);
    state.selectedEventId = null;
    render();
  }

  // modal handlers
  $('#ev-cancel').addEventListener('click', closeModal);
  $('#ev-save').addEventListener('click', saveEventFromModal);
  elDelete.addEventListener('click', () => {
    if (state.editingId) deleteEvent(state.editingId);
    closeModal();
  });

  // click outside
  modalBackdrop.addEventListener('click', (e) => {
    if (e.target === modalBackdrop) closeModal();
  });

  // all-day toggle disables times
  elAllDay.addEventListener('change', () => {
    const dis = !!elAllDay.checked;
    elStartTime.disabled = dis;
    elEndTime.disabled = dis;
  });

  // keep endDate >= startDate
  elStartDate.addEventListener('change', () => {
    if (elEndDate.value < elStartDate.value) elEndDate.value = elStartDate.value;
  });

  // --- restore from sessionStorage (already done), then render ---
  // If mobile embedding ever happens, original forces week view; JTOSX shell is desktop.

  // restore scroll from sessionStorage
  try {
    const savedScroll = sessionStorage.getItem(SCROLL_STORAGE_KEY);
    if (savedScroll) state.scrollTop = parseInt(savedScroll, 10);
  } catch {}

  // initial render
  render();

  // --- utilities ---
  function escapeHtml(s){
    return String(s).replace(/[&<>"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  }
  function escapeAttr(s){
    return String(s).replace(/[&<>"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  }

})();
</script>

<?php osx_app_footer(); ?>
