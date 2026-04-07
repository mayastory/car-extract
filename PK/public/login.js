const API = "../api";

const elUser = document.getElementById('username');
const elPass = document.getElementById('password');
const elMsg = document.getElementById('msg');
const elSlots = document.getElementById('slots');

const panelLogin = document.getElementById('panelLogin');
const panelSlots = document.getElementById('panelSlots');

const btnLogin = document.getElementById('btnLogin');
const btnRefresh = document.getElementById('btnRefresh');
const btnChangeAccount = document.getElementById('btnChangeAccount');

const modalRegister = document.getElementById('modalRegister');
const btnOpenRegister = document.getElementById('btnOpenRegister');
const btnRegCancel = document.getElementById('btnRegCancel');
const btnRegSubmit = document.getElementById('btnRegSubmit');
const regUser = document.getElementById('regUser');
const regPass = document.getElementById('regPass');
const regPass2 = document.getElementById('regPass2');

const modalCharCreate = document.getElementById('modalCharCreate');
const charSlotLabel = document.getElementById('charSlotLabel');
const charName = document.getElementById('charName');
const btnGenderM = document.getElementById('btnGenderM');
const btnGenderF = document.getElementById('btnGenderF');
const charSprite = document.getElementById('charSprite');
const charCreateErr = document.getElementById('charCreateErr');
const btnCharCancel = document.getElementById('btnCharCancel');
const btnCharCreate = document.getElementById('btnCharCreate');

let _createSlot = null;
let _createGender = 'M';
const NAME_RE = /^[A-Za-z0-9 _\-가-힣]{2,16}$/u;


function msg(t){ if(elMsg) elMsg.textContent = t; }

function getAccToken(){ return sessionStorage.getItem('account_token') || ''; }
function setAccToken(t){ if(t) sessionStorage.setItem('account_token', t); else sessionStorage.removeItem('account_token'); }
function setPlayToken(t){ if(t) sessionStorage.setItem('play_token', t); else sessionStorage.removeItem('play_token'); }

function show(el){ el?.classList.remove('hide'); }
function hide(el){ el?.classList.add('hide'); }

function setStep(step){
  if(step==='slots'){
    hide(panelLogin);
    show(panelSlots);
  } else {
    show(panelLogin);
    hide(panelSlots);
  }
}

async function apiJson(url, opts={}){
  const res = await fetch(url, {
    cache: 'no-store',
    headers: {
      'Content-Type': 'application/json',
      ...(opts.headers||{})
    },
    ...opts,
  });
  const txt = await res.text();
  let j = null;
  try{ j = JSON.parse(txt); }catch(e){
    throw new Error(`JSON parse fail: ${url} (${res.status})\n${txt}`);
  }
  if (!res.ok) {
    const err = j && (j.error||j.err) ? (j.error||j.err) : `HTTP_${res.status}`;
    // DB 연결 실패 같은 경우 detail도 같이 보여주기
    const detail = j && j.detail ? `\n${j.detail}` : '';
    throw new Error(err + detail);
  }
  return j;
}

function renderSlots(players){
  const bySlot = new Map();
  (players||[]).forEach(p=> bySlot.set(Number(p.slot), p));

  elSlots.innerHTML = '';
  for(let s=0; s<4; s++){
    const p = bySlot.get(s);
    const div = document.createElement('div');
    div.className = 'slot';

    if(!p){
      div.innerHTML = `
        <div class="name" style="opacity:.6">빈 슬롯</div>
        <div class="meta">슬롯 ${s+1}</div>
        <div class="actions">
          <button data-act="create" data-slot="${s}">생성</button>
        </div>
      `;
    } else {
      const g = (p.gender || 'M').toUpperCase();
      const gLabel = (g === 'F') ? '여' : '남';
      const map = p.map_id || '-';
      div.innerHTML = `
        <div class="name">${escapeHtml(p.display_name || '(NoName)')}</div>
        <div class="meta">슬롯 ${s+1} · ${gLabel} · ${escapeHtml(map)}</div>
        <div class="actions">
          <button data-act="enter" data-slot="${s}">입장</button>
        </div>
      `;
    }

    elSlots.appendChild(div);
  }

  elSlots.querySelectorAll('button[data-act]').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const act = btn.getAttribute('data-act');
      const slot = Number(btn.getAttribute('data-slot'));
      if(act==='enter') return enterSlot(slot);
      if(act==='create') return createSlot(slot);
    });
  });
}

async function login(){
  const username = (elUser.value||'').trim();
  const password = (elPass?.value||'').trim();
  if(!username) { msg('계정 ID를 입력하세요.'); return; }
  if(!password) { msg('비밀번호를 입력하세요.'); return; }

  msg('로그인 중...');
  const j = await apiJson(`${API}/auth/login.php`, {
    method:'POST',
    body: JSON.stringify({username, password}),
  });

  setAccToken(j.account_token);
  setPlayToken('');
  setStep('slots');
  msg(`로그인 완료: ${username} (슬롯 선택)`);
  renderSlots(j.players||[]);
}

async function refreshPlayers(){
  const acc = getAccToken();
  if(!acc){ msg('먼저 로그인하세요.'); setStep('login'); return; }
  msg('슬롯 목록 불러오는 중...');
  const j = await apiJson(`${API}/auth/players.php`, {
    method:'GET',
    headers: { 'Authorization': `Bearer ${acc}` }
  });
  setStep('slots');
  renderSlots(j.players||[]);
  const u = (j.account && j.account.username) ? j.account.username : '';
  msg(`로그인됨: ${u} (슬롯 선택)`);
}

function createSlot(slot){
  return openCharCreate(slot);
}

async function enterSlot(slot){
  const acc = getAccToken();
  if(!acc){ msg('먼저 로그인하세요.'); setStep('login'); return; }

  msg('접속 토큰 발급 중...');
  const j = await apiJson(`${API}/auth/player_select.php`, {
    method:'POST',
    headers: { 'Authorization': `Bearer ${acc}` },
    body: JSON.stringify({slot}),
  });

  setPlayToken(j.play_token);
  msg(`접속 중... 슬롯 ${slot+1}`);
  window.location.href = './index.html';
}

function logoutAll(){
  setAccToken('');
  setPlayToken('');
  if(elSlots) elSlots.innerHTML = '';
  setStep('login');
  msg('로그아웃됨');
}

function openRegister(){
  regUser.value = (elUser.value||'').trim();
  regPass.value = '';
  regPass2.value = '';
  show(modalRegister);
  modalRegister.setAttribute('aria-hidden','false');
}
function closeRegister(){
  hide(modalRegister);
  modalRegister.setAttribute('aria-hidden','true');
}

function _setCharErr(t){
  if(!charCreateErr) return;
  if(t){
    charCreateErr.textContent = t;
    charCreateErr.style.display = 'block';
  }else{
    charCreateErr.textContent = '';
    charCreateErr.style.display = 'none';
  }
}

function _applyGenderUI(){
  const isM = (_createGender === 'M');
  if(btnGenderM){
    btnGenderM.style.background = isM ? '#3b82f6' : '#334155';
    btnGenderM.style.color = 'white';
  }
  if(btnGenderF){
    btnGenderF.style.background = (!isM) ? '#3b82f6' : '#334155';
    btnGenderF.style.color = 'white';
  }
  if(charSprite){
    charSprite.src = isM ? './pret/sprites/player/red_normal.png' : './pret/sprites/player/green_normal.png';
  }
}

function openCharCreate(slot){
  const acc = getAccToken();
  if(!acc){ msg('먼저 로그인하세요.'); setStep('login'); return; }
  _createSlot = Number(slot);
  _createGender = 'M';
  if(charSlotLabel) charSlotLabel.textContent = `슬롯 ${_createSlot+1}`;
  if(charName) charName.value = '';
  _setCharErr('');
  _applyGenderUI();
  show(modalCharCreate);
  modalCharCreate?.setAttribute('aria-hidden','false');
  setTimeout(()=> charName?.focus(), 0);
}

function closeCharCreate(){
  hide(modalCharCreate);
  modalCharCreate?.setAttribute('aria-hidden','true');
  _createSlot = null;
  _setCharErr('');
}

async function submitCharCreate(){
  const acc = getAccToken();
  if(!acc){ msg('먼저 로그인하세요.'); setStep('login'); closeCharCreate(); return; }
  const slot = Number(_createSlot);
  const name = (charName?.value||'').trim();

  if(!(slot>=0 && slot<=3)) { _setCharErr('슬롯 오류'); return; }
  if(!NAME_RE.test(name)){
    _setCharErr('이름 형식 오류: 2~16자, 영문/숫자/공백/_-/(한글)');
    return;
  }

  _setCharErr('');
  btnCharCreate && (btnCharCreate.disabled = true);
  msg('캐릭터 생성 중...');
  try{
    await apiJson(`${API}/auth/player_create.php`, {
      method:'POST',
      headers: { 'Authorization': `Bearer ${acc}` },
      body: JSON.stringify({slot, display_name: name, gender: _createGender}),
    });
    closeCharCreate();
    msg('생성 완료');
    await refreshPlayers();
  }catch(e){
    // humanize common errors
    const em = String(e.message||'');
    if(em.includes('NAME_TAKEN')) _setCharErr('이미 사용 중인 이름입니다.');
    else if(em.includes('SLOT_OCCUPIED')) _setCharErr('이미 사용 중인 슬롯입니다.');
    else if(em.includes('BAD_NAME')) _setCharErr('이름을 입력하세요.');
    else if(em.includes('BAD_NAME_CHARS')) _setCharErr('이름 형식 오류(허용 문자 확인)');
    else _setCharErr(`생성 실패: ${em}`);
    msg('생성 실패');
  }finally{
    btnCharCreate && (btnCharCreate.disabled = false);
  }
}

async function submitRegister(){
  const u = (regUser.value||'').trim();
  const p1 = (regPass.value||'');
  const p2 = (regPass2.value||'');
  if(!u){ msg('회원가입: 계정 ID를 입력하세요.'); return; }
  if(p1.length < 4){ msg('회원가입: 비밀번호는 4자 이상'); return; }
  if(p1 !== p2){ msg('회원가입: 비밀번호 확인이 다릅니다.'); return; }

  msg('회원가입 중...');
  await apiJson(`${API}/auth/register.php`, {
    method:'POST',
    body: JSON.stringify({username:u, password:p1}),
  });
  closeRegister();
  msg('회원가입 완료. 이제 로그인하세요.');
  elUser.value = u;
  elPass.value = '';
  elPass.focus();
}

function escapeHtml(s){
  return String(s).replace(/[&<>"']/g, (m)=>({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[m]));
}

btnLogin?.addEventListener('click', ()=> login().catch(e=> msg(`로그인 실패: ${e.message}`)));
elPass?.addEventListener('keydown', (e)=>{ if(e.key==='Enter') btnLogin?.click(); });

btnRefresh?.addEventListener('click', ()=> refreshPlayers().catch(e=> msg(`새로고침 실패: ${e.message}`)));
btnChangeAccount?.addEventListener('click', logoutAll);

btnOpenRegister?.addEventListener('click', openRegister);
btnRegCancel?.addEventListener('click', closeRegister);
btnRegSubmit?.addEventListener('click', ()=> submitRegister().catch(e=> msg(`회원가입 실패: ${e.message}`)));
modalRegister?.addEventListener('click', (e)=>{ if(e.target === modalRegister) closeRegister(); });


btnGenderM?.addEventListener('click', ()=>{ _createGender='M'; _applyGenderUI(); });
btnGenderF?.addEventListener('click', ()=>{ _createGender='F'; _applyGenderUI(); });
btnCharCancel?.addEventListener('click', closeCharCreate);
btnCharCreate?.addEventListener('click', ()=> submitCharCreate().catch(e=> _setCharErr(String(e.message||e))));
charName?.addEventListener('keydown', (e)=>{ if(e.key==='Enter') btnCharCreate?.click(); });
modalCharCreate?.addEventListener('click', (e)=>{ if(e.target === modalCharCreate) closeCharCreate(); });

// Auto refresh if already logged in
(async ()=>{
  const acc = getAccToken();
  if(acc){
    try{ await refreshPlayers(); }
    catch(e){ logoutAll(); msg(`세션 만료/오류: ${e.message}`); }
  } else {
    setStep('login');
  }
})();
