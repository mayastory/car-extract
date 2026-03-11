/* dp_auth_watchdog.js (v1)
   - 단일세션(동시접속 차단) 상태를 주기적으로 체크해서, 다른 곳에서 로그인되면 즉시 로그아웃/로그인화면으로 이동
   - 서버 엔드포인트: /<APP>/auth_ping.php
*/
(function(){
  'use strict';

  function detectBase(){
    if (window.DP_BASE_URL) return window.DP_BASE_URL.replace(/\/+$/,'');
    var p = location.pathname || '';
    // "/JTMES/..." -> "/JTMES"
    var m = p.match(/^(\/[^\/]+)(?:\/|$)/);
    if (m) return m[1];
    return '';
  }

  var BASE = detectBase();

  function pingUrl(){
    if (window.DP_AUTH_PING_URL) {
      var u = window.DP_AUTH_PING_URL;
      if (/^https?:\/\//i.test(u)) return u;
      if (u.indexOf('/') === 0) return u;
      return (BASE ? BASE : '') + '/' + u;
    }
    return (BASE ? BASE : '') + '/auth_ping.php';
  }

  function loginUrlFallback(){
    return (BASE ? BASE : '') + '/index?kicked=1';
  }

  function showKickModal(){
    if (document.getElementById('dpKickModal')) return;
    var wrap = document.createElement('div');
    wrap.id = 'dpKickModal';
    wrap.innerHTML = '' +
      '<div class="dpKickBackdrop"></div>' +
      '<div class="dpKickCard">' +
        '<div class="dpKickTitle">세션이 종료되었습니다</div>' +
        '<div class="dpKickMsg">다른 곳에서 로그인되어 자동 로그아웃되었습니다.<br>확인을 누르면 로그인 화면으로 이동합니다.</div>' +
        '<div class="dpKickBtns">' +
          '<button type="button" class="dpKickOk">확인</button>' +
        '</div>' +
      '</div>';

    var css = document.createElement('style');
    css.textContent = '' +
      '#dpKickModal{position:fixed;inset:0;z-index:999999;display:flex;align-items:center;justify-content:center;}' +
      '#dpKickModal .dpKickBackdrop{position:absolute;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(2px);}' +
      '#dpKickModal .dpKickCard{position:relative;min-width:280px;max-width:420px;padding:18px 18px;border-radius:16px;' +
        'background:#202124;color:#e8eaed;box-shadow:0 10px 30px rgba(0,0,0,.45);text-align:center;}' +
      '#dpKickModal .dpKickTitle{font-size:16px;font-weight:700;margin-bottom:8px;}' +
      '#dpKickModal .dpKickMsg{font-size:13px;line-height:1.5;opacity:.9;}' +
      '#dpKickModal .dpKickBtns{margin-top:14px;display:flex;justify-content:center;}' +
      '#dpKickModal .dpKickOk{min-width:120px;height:34px;border:0;border-radius:10px;' +
        'background:#f4d03f;color:#111;cursor:pointer;font-weight:700;}' +
      '#dpKickModal .dpKickOk:active{transform:translateY(1px);}';
    document.head.appendChild(css);
    document.body.appendChild(wrap);
  }

  var locked = false;
  function handleKicked(login){
    if (locked) return;
    locked = true;
    var to = login || loginUrlFallback();
    try {
      showKickModal();
      var btn = document.querySelector('#dpKickModal .dpKickOk');
      if (btn) {
        btn.addEventListener('click', function(){
          location.href = to;
        });
        btn.focus();
      } else {
        location.href = to;
      }
    } catch(e){
      location.href = to;
    }
  }

  function tick(){
    fetch(pingUrl(), { credentials: 'same-origin', cache: 'no-store' })
      .then(function(r){ return r.json().catch(function(){ return null; }); })
      .then(function(j){
        if (!j) return;
        if (j.ok) return;
        if (j.reason === 'kicked') handleKicked(j.login);
        else if (j.reason === 'nologin') handleKicked(j.login || loginUrlFallback());
      })
      .catch(function(){ /* ignore */ });
  }

  // 첫 실행 + 주기 체크
  setTimeout(tick, 1200);
  setInterval(tick, 3500);
})();
