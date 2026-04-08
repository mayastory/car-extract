import { getActorPriorityState, getFrontCoverAt as getOwFrontCoverAt, getFrontOccluderAt as getOwFrontOccluderAt, getGrassCoverAt as getOwGrassCoverAt, isTallGrassBehavior as isOwTallGrassBehavior } from "./overworld_priority.js";
export class Overworld{
  constructor({canvas,status,playToken=null,apiBase="/api", fixedZoom=2.0, lockZoom=true}){
    this.canvas=canvas; this.ctx=canvas.getContext("2d");
    this.status=status||(()=>{});
    this.playToken = playToken;
    this.apiBase=apiBase;
    this.playerId=null;
    this.playerName='';

    this.map=null;
    this.tilesetImg=new Image();
    this.tilesetImgs=[];
    this.tilesetUpperImgs = [];
    this._fxTallGrassImg = null;
    this._fxTallGrassReady = false;
    this._fxTallGrassPromise = null;
    this._grassFx = new Map();
    this._grassFxDuration = 260;
    this._grassFxStepMs = 65;
    this._grassFxFrames = 4;
    this._tileAnimFrame=0;
    this._tileAnimT=0;
    this._tileAnimFps=7.5;
    this._off=document.createElement('canvas');
    this._offCtx=this._off.getContext('2d');
    this._offCtx.imageSmoothingEnabled=false;
    this.playerImg=new Image();

    // Player sprite: FRLG object sprites are commonly a single row of 9 frames
    // (16x32 each): down(3) + up(3) + side(3). Right is a mirrored side.
    this.playerSprite={ kind:"frlg9", frameW:16, frameH:32, framesPerDir:3 };
    this.playerImg.src=this._publicUrl("pret/sprites/player/red_normal.png");
    this._playerFallbackSrc=this._publicUrl("assets/sprites/player_placeholder.png");

    // Zoom (pixel-art)
    // - 기본은 300% 고정(사용자 요청). (GBA 느낌 유지)
    // - 기존 debug/실험용 600% 기본값은 제거.
    this.lockZoom = !!lockZoom;
    const _fz = (typeof fixedZoom === 'number' && isFinite(fixedZoom) && fixedZoom > 0) ? fixedZoom : 3.0;
    this.fixedZoom = _fz;

    // When locked, min/max are the same and setZoom ignores user changes.
    if(this.lockZoom){
      this.zoomMin = _fz;
      this.zoomMax = _fz;
      this.zoomStep = 0.5; // UI label helper only; no effect while locked
      this.defaultZoom = _fz;
      this.zoom = _fz;
    }else{
      this.zoomMin = 1.0;
      this.zoomMax = 10.0;
      this.zoomStep = 0.5;
      this.defaultZoom = _fz;
      this.zoom = this.defaultZoom;
    }


    // Movement timing (GBA-like fixed-step @60fps)
    // - FRLG: 16px per tile, fixed 60fps update.
    // - Quantize movement to whole pixels per fixed tick (prevents "too smooth" gliding on 120/144Hz).
    this.fixedFps = 60;
    this.moveFramesPerTile = 16;                 // 16 frames -> 0.2666s per tile
    this.moveSeconds = this.moveFramesPerTile / this.fixedFps; // (legacy) seconds per tile
    this.stepCooldown = 0.0;                     // seconds AFTER each step (set e.g. 0.04 if you want a tiny pause)
    this._fixedStep = 1 / this.fixedFps;
    this._accum = 0;
    this._moveFrames = 0;
    this._moveFramesTotal = 0;
    this._movePx = 0;
    this._moveDistPx = 0;
    this._moveDirLocked = null;
    this._queuedDir = null;

    this.keys=new Set();
    this._started=false;
    this._inputBound=false;
    this.tileSize=16;
    this.camera={x:0,y:0};
    // 1.0 = lock follow (no wobble). Set to e.g. 0.25 if you later want smoothing.
    this.cameraFollowAlpha=1.0;
    this.player={x:0,y:0,dir:0,px:0,py:0,moving:false};
    this.moveCooldown=0;
    this.frame=0;
    this.animFrame=0;
    this.animTimer=0;
    this.netTimer=0;

    // Monsters (spawned from script/monster)
    this.mobs=[];
    this.mobNetTimer=0;
    this.mobPollInterval=0.8;
    // Mob icon cache (species_id -> Image)
    this._mobIconImg=new Map();
    this._mobIconErr=new Set();
    this._mobIconFrameH=32; // icons are usually 32x64 (2 frames stacked)

    // Mob visual state (client-side): interpolate like player (fixed-step, pixel-quantized)
    this._mobVis = new Map();
    this._mobMoveFramesPerTile = this.moveFramesPerTile || 16;
    this._mobIconDrawSize = 32;
    // Gen3 icon default facing: 대부분 LEFT. RIGHT로 움직일 때만 좌우반전.
    this._mobIconFacesLeft = true;

    // Items (from script/map/item)
    this.items=[];
    // 사용자 요청: 아이템 표시는 아직 필요 없음(파란박스 방지). 디버그에서만 임시로 켤 수 있게.
    this.itemsEnabled=false;
    this.itemNetTimer=0;
    this.itemPollInterval=1.0;
    this._lastActionAt=0;
    this._serverStateLoaded=false;
    this._serverInitPromise=null;
    this._serverResyncing=false;
    this._syncLockUntil=0;

    // NPCs (script/npc)
    this.npcs=[];
    this._npcImg=new Map();
    this._npcErr=new Set();

    // UI skin (FR/LG)
    this.uiGameVer = 0;
    this._uiFrameImg = new Map();


    // Fishing (visible encounter FX)
    this._fishFx=null;
    this._fishLockUntil=0;

    this._loading=false;
    this._zoomUser=false;
    this._syncInFlight=false;
    this._syncQueued=false;
    this._syncLatest=null;
    this._syncPromise=null;
    this._lastSyncKey="";

    // Warp + map connection preview
    this._warpCooldown=0;
    this._warpPending=false;
    this._neighborCache=new Map();        // mapId -> { map, tilesetImgs, tilesetImg, tilesetCols }
    this._neighborPromises=new Map();     // mapId -> Promise

    // Seamless connections + ledge jump (FRLG feel)
    this._edgePending=null;               // set while stepping across a connected border
    this._moveSecondsNow=null;            // per-move override (e.g., jump)
    this.jumpSeconds=0.38;                // jump is a bit slower than a normal step
    this._jumping=false;

    // HTML debug window (forced OFF)
    // 사용자 요청: 맵 이동 로그(Seamless ...)가 뜨는 DEBUG 창은 우선 완전히 끈다.
    // (나중에 필요하면 아래 OW_DEBUG_WINDOW_ALLOW 를 true로 바꾸고
    //  URL에 ?owdbg=1&dev=1 을 붙여서만 켤 수 있음)
    const OW_DEBUG_WINDOW_ALLOW = false;
    let dbgOn=false;
    if(OW_DEBUG_WINDOW_ALLOW){
      try{
        const qs=new URLSearchParams(window.location.search);
        const ow=(qs.get('owdbg')||'').toString().toLowerCase();
        const dev=(qs.get('dev')||'').toString().toLowerCase();
        const owOn=(ow==='1'||ow==='true'||ow==='yes');
        const devOn=(dev==='1'||dev==='true'||dev==='yes');
        dbgOn=(owOn && devOn);
      }catch(_e){ dbgOn=false; }
    }
    this._dbg={ enabled:dbgOn, max:300, lines:[], root:null, body:null, visible:dbgOn };
    this._initDebugWindow();

    // 디버그(좌표/맵ID 텍스트) 표시 여부
    this.debug=false;
  }

  _initDebugWindow(){
    // If disabled, aggressively remove any previous DOM nodes so it never lingers.
    if(!this._dbg?.enabled){
      try{
        const root=document.getElementById('owDbgWin');
        if(root) root.remove();
        const t=document.getElementById('owDbgToggle');
        if(t) t.remove();
      }catch(_e){}
      return;
    }
    try{
      let root=document.getElementById('owDbgWin');
      if(!root){
        root=document.createElement('div');
        root.id='owDbgWin';
        root.style.position='fixed';
        root.style.left='16px';
        root.style.bottom='16px';
        root.style.width='360px';
        root.style.maxWidth='calc(100vw - 32px)';
        root.style.height='220px';
        root.style.maxHeight='calc(100vh - 32px)';
        root.style.background='rgba(10,14,20,0.78)';
        root.style.backdropFilter='blur(8px)';
        root.style.border='1px solid rgba(255,255,255,0.12)';
        root.style.borderRadius='12px';
        root.style.color='#e8eef6';
        root.style.font='12px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
        root.style.zIndex='9999';
        root.style.overflow='hidden';
        root.style.display='flex';
        root.style.flexDirection='column';

        const head=document.createElement('div');
        head.style.display='flex';
        head.style.alignItems='center';
        head.style.gap='8px';
        head.style.padding='8px 10px';
        head.style.borderBottom='1px solid rgba(255,255,255,0.10)';
        head.style.userSelect='none';
        head.innerHTML = '<b style="letter-spacing:.2px">DEBUG</b>';

        const spacer=document.createElement('div');
        spacer.style.flex='1';
        head.appendChild(spacer);

        const btnClear=document.createElement('button');
        btnClear.textContent='Clear';
        btnClear.style.border='1px solid rgba(255,255,255,0.18)';
        btnClear.style.background='rgba(255,255,255,0.06)';
        btnClear.style.color='inherit';
        btnClear.style.borderRadius='10px';
        btnClear.style.padding='4px 10px';
        btnClear.style.cursor='pointer';
        btnClear.onclick=()=>{ this._dbg.lines=[]; this._dbgRender(); };
        head.appendChild(btnClear);

        const btnHide=document.createElement('button');
        btnHide.textContent='×';
        btnHide.title='Hide';
        btnHide.style.border='1px solid rgba(255,255,255,0.18)';
        btnHide.style.background='rgba(255,255,255,0.06)';
        btnHide.style.color='inherit';
        btnHide.style.borderRadius='10px';
        btnHide.style.padding='4px 10px';
        btnHide.style.cursor='pointer';
        btnHide.onclick=()=>{ root.style.display='none'; this._dbg.visible=false; };
        head.appendChild(btnHide);

        const body=document.createElement('div');
        body.style.flex='1';
        body.style.padding='8px 10px';
        body.style.overflow='auto';
        body.style.whiteSpace='pre-wrap';
        body.style.wordBreak='break-word';

        root.appendChild(head);
        root.appendChild(body);
        document.body.appendChild(root);
        this._dbg.root=root; this._dbg.body=body;

        // Small floating toggle button
        const t=document.getElementById('owDbgToggle') || document.createElement('button');
        if(!t.id){
          t.id='owDbgToggle';
          t.textContent='DBG';
          t.style.position='fixed';
          t.style.left='16px';
          t.style.bottom='246px';
          t.style.zIndex='9999';
          t.style.border='1px solid rgba(255,255,255,0.18)';
          t.style.background='rgba(10,14,20,0.78)';
          t.style.backdropFilter='blur(8px)';
          t.style.color='#e8eef6';
          t.style.borderRadius='999px';
          t.style.padding='6px 10px';
          t.style.cursor='pointer';
          t.onclick=()=>{
            this._dbg.visible=!this._dbg.visible;
            root.style.display=this._dbg.visible ? 'flex' : 'none';
          };
          document.body.appendChild(t);
        }
      }else{
        this._dbg.root=root;
        this._dbg.body=root.querySelector('div:last-child') || null;
      }
    }catch(_e){
      // ignore (e.g., in headless contexts)
    }
  }

  _dbgRender(){
    if(!this._dbg.body) return;
    this._dbg.body.textContent=this._dbg.lines.join('\n');
    // keep pinned to bottom
    try{ this._dbg.body.scrollTop=this._dbg.body.scrollHeight; }catch(_e){}
  }

  _log(msg){
    if(!this._dbg.enabled) return;
    const d=new Date();
    const hh=String(d.getHours()).padStart(2,'0');
    const mm=String(d.getMinutes()).padStart(2,'0');
    const ss=String(d.getSeconds()).padStart(2,'0');
    const line=`[${hh}:${mm}:${ss}] ${msg}`;
    this._dbg.lines.push(line);
    if(this._dbg.lines.length>this._dbg.max) this._dbg.lines.splice(0, this._dbg.lines.length-this._dbg.max);
    this._dbgRender();
  }

  _publicBase(){
    // location.href typically ends with /public/ or /public/index.html
    return new URL('.', window.location.href);
  }
  _publicUrl(p){
    if(!p) return '';
    const base=this._publicBase();
    const rel=String(p).replace(/^\//,''); // treat leading '/' as relative-to-public
    return new URL(rel, base).toString();
  }


  async _actionFish(){
    if(!this.map||!this.player||this._loading) return;
    const now = performance.now();
    if(now < this._fishLockUntil) return;

    const ft = this._frontTile();
    const b = this._behaviorAt(ft.x, ft.y);
    if(!this._isWaterBehavior(b)){
      this.status('여기서는 낚시할 수 없다.');
      return;
    }

    // lock input + show cast immediately
    this._fishLockUntil = now + 1700;
    this._fishFx = {
      startMs: now,
      durationMs: 900,
      riseStartMs: 450,
      riseDurMs: 600,
      tx: ft.x, ty: ft.y,
      bite: false,
      species_key: '',
      species_id: 0,
      level: 1,
      msgShown: false
    };

    try{
      const r = await fetch(`${this.apiBase}/rt/fish.php`, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        credentials: 'include',
        body: JSON.stringify({ dir: this.player.dir })
      });
      const j = await r.json().catch(()=>null);
      if(!j || !j.ok){
        this.status((j && j.err) ? j.err : '낚시 실패');
        this._fishFx = null;
        this._fishLockUntil = 0;
        return;
      }
      if(!j.bite){
        this.status('아무것도 걸리지 않았다.');
        if(this._fishFx) this._fishFx.durationMs = 700;
        return;
      }

      this.status('무언가가 걸렸다!');
      if(this._fishFx){
        this._fishFx.bite = true;
        this._fishFx.species_key = j.species_key || '';
        this._fishFx.species_id = +j.species_id || 0;
        this._fishFx.level = +j.level || 1;
        this._fishFx.durationMs = 1700;
      }
    } catch(e){
      console.warn('fish failed', e);
      this.status('낚시 실패');
      this._fishFx = null;
      this._fishLockUntil = 0;
    }
  }

  _mobIconUrl(speciesKey, speciesId){
  // Extracted by: tools/extract_pokemon_assets_to_public.py
  // Stored as: public/assets/pokemon/<species_key>/icon.png
  const key = (speciesKey||'').toString().trim();
  if(key){
    return this._publicUrl(`assets/pokemon/${key}/icon.png`);
  }
  // fallback (old style): assets/pokemon/icons/<species_id>.png
  const id = (speciesId|0);
  if(id<=0) return '';
  return this._publicUrl(`assets/pokemon/icons/${id}.png`);
}

  _getMobIconImg(speciesKey, speciesId){
  const id=(speciesId|0);
  const key=(speciesKey||'').toString().trim();
  const cacheKey= key ? `k:${key}` : (id>0 ? `id:${id}` : '');
  if(!cacheKey) return null;
  if(this._mobIconErr.has(cacheKey)) return null;
  if(this._mobIconImg.has(cacheKey)) return this._mobIconImg.get(cacheKey);

  const url=this._mobIconUrl(key, id);
  if(!url) return null;

  const img=new Image();
  img.onload=()=>{ /* ok */ };
  img.onerror=()=>{ this._mobIconErr.add(cacheKey); this._mobIconImg.delete(cacheKey); };
  img.src=url;

  this._mobIconImg.set(cacheKey,img);
  return img;
}

  // --- Mob client-side visual state (match player feel) ---
  _mobKey(m){
    const mid = ((m && m.mob_id) ? (m.mob_id|0) : 0);
    if(mid>0) return `id:${mid}`;
    const sk = (m && m.spawn_key) ? String(m.spawn_key) : '';
    const sn = (m && m.spawn_name) ? String(m.spawn_name) : '';
    const sid = (m && m.species_id) ? (m.species_id|0) : 0;
    const x = (m && m.x!=null) ? (m.x|0) : 0;
    const y = (m && m.y!=null) ? (m.y|0) : 0;
    return `k:${sk}|${sn}|${sid}|${x},${y}`;
  }

  _mobSeed(m){
    const mid = ((m && m.mob_id) ? (m.mob_id|0) : 0);
    if(mid>0) return mid;
    const x=(m && m.x!=null)?(m.x|0):0;
    const y=(m && m.y!=null)?(m.y|0):0;
    // cheap hash
    return ((x*73856093) ^ (y*19349663))|0;
  }

  _mobIngest(list){
    if(!this._mobVis) this._mobVis = new Map();
    const seen = new Set();

    for(const m of list){
      const key = this._mobKey(m);
      seen.add(key);

      let s = this._mobVis.get(key);
      if(!s){
        const ix=(m.x|0), iy=(m.y|0);
        const seed=this._mobSeed(m);
        s = {
          // rendered pos in tile units (float)
          x: ix, y: iy,
          // tween segment
          fx: ix, fy: iy,
          tx: ix, ty: iy,
          step: 0,
          total: (this._mobMoveFramesPerTile || this.moveFramesPerTile || 16),
          moving: false,
          // dir codes match player: 0=down,1=up,2=left,3=right
          dir: 2,
          flipX: false,
          bob: 0,
          seed,
          // last server tile
          sx: ix, sy: iy,
          data: m,
        };
        this._mobVis.set(key, s);
      }

      const nx=(m.x|0), ny=(m.y|0);
      const moved = (nx !== (s.sx|0) || ny !== (s.sy|0));
      if(moved){
        const dx = nx - (s.sx|0);
        const dy = ny - (s.sy|0);

        // Decide facing by delta (do NOT trust server dir; it may be static)
        if(dx>0){
          s.dir = 3; // right
        }else if(dx<0){
          s.dir = 2; // left
        }else if(dy>0){
          s.dir = 0; // down
        }else if(dy<0){
          s.dir = 1; // up
        }

        // flip only for horizontal move; keep last flip for vertical
        if(dx!==0){
          const facesLeft = (this._mobIconFacesLeft !== false);
          // if base faces LEFT, flip when moving RIGHT
          s.flipX = facesLeft ? (dx>0) : (dx<0);
        }

        // start new segment from current rendered pos (supports mid-step updates)
        s.fx = s.x; s.fy = s.y;
        s.tx = nx; s.ty = ny;
        s.step = 0;
        s.total = (this._mobMoveFramesPerTile || this.moveFramesPerTile || 16);
        s.moving = true;

        s.sx = nx; s.sy = ny;
      }else{
        // no movement: keep last dir/flip (prevents "왼쪽 보고 오른쪽 이동" 같은 어색함)
        s.sx = nx; s.sy = ny;
      }

      s.data = m;
    }

    // drop vanished
    for(const k of Array.from(this._mobVis.keys())){
      if(!seen.has(k)) this._mobVis.delete(k);
    }

    this.mobs = list;
  }

  _mobTick(){
    if(!this._mobVis || this._mobVis.size===0) return;
    const ts = this.tileSize || 16;
    for(const s of this._mobVis.values()){
      if(!s.moving) { s.bob = 0; continue; }
      const total = Math.max(1, (s.total|0) || (this.moveFramesPerTile||16));
      s.step = (s.step|0) + 1;
      const t = s.step / total;
      if(t >= 1){
        s.x = s.tx; s.y = s.ty;
        s.moving = false;
        s.bob = 0;
        continue;
      }
      // linear + pixel-quantized (match player)
      const px = roundi((s.fx*ts) + (s.tx - s.fx)*ts*t);
      const py = roundi((s.fy*ts) + (s.ty - s.fy)*ts*t);
      s.x = px / ts;
      s.y = py / ts;

      // 1px bob at mid-step (GBA 느낌)
      s.bob = -roundi(Math.sin(Math.PI*t) * 1);
    }

    function roundi(v){ return Math.round(v); }
  }

  _mobPos(m){
    const key = this._mobKey(m);
    const s = this._mobVis ? this._mobVis.get(key) : null;
    if(!s){
      // fallback: no vis state
      const seed = this._mobSeed(m);
      return { x:(m.x|0), y:(m.y|0), moving:false, flipX:false, dir:2, bob:0, seed };
    }
    return { x:s.x, y:s.y, moving:!!s.moving, flipX:!!s.flipX, dir:(s.dir|0), bob:(s.bob|0), seed:(s.seed|0) };
  }



  _applyGenderSprite(){
    const g=String(this.playerGender||'M').toUpperCase();
    const want=(g==='F') ? 'green_normal' : 'red_normal';
    const url=this._publicUrl(`pret/sprites/player/${want}.png`);
    // Only swap if changed; keep existing Image instance for smoothness.
    if(this.playerImg && this.playerImg.src===url) return;
    const img=new Image();
    img.src=url;
    // swap when loaded (prevents flashing)
    img.onload=()=>{ this.playerImg=img; };
    img.onerror=()=>{ /* keep current */ };
  }

  async load(mapUrl, opts={}){
    const r=await fetch(mapUrl,{cache:"no-store"});
    if(!r.ok) throw new Error(`map load fail: ${mapUrl} (${r.status})`);
    this.map=await r.json();
    this.tileSize=this.map.tileSize||16;
    this.tilesetCols=this.map.tilesetCols||0;

    // tileset (static or animated frames)
    this.tilesetCols=16; // generator uses 16 columns (16x16 metatiles)
    this.tilesetImgs=[];
    this._tileAnimFrame=0;
    this._tileAnimT=0;
    this._tileAnimFps=(typeof this.map.tileAnimFps==="number" && this.map.tileAnimFps>0) ? this.map.tileAnimFps : 7.5;

    if(Array.isArray(this.map.tilesetFrames) && this.map.tilesetFrames.length>0){
      this.tilesetImgs=this.map.tilesetFrames.map(p=>{
        const img=new Image();
        img.src=this._publicUrl(p);
        return img;
      });
      await Promise.all(this.tilesetImgs.map(img=>this._waitImage(img)));
      this.tilesetImg=this.tilesetImgs[0];
    

      // Upper tileset (metatile layer1) if provided
      this.tilesetUpperImgs = [];
      this.tilesetUpperImg = null;
      if (this.map.tilesetUpperFrames && this.map.tilesetUpperFrames.length) {
        this.tilesetUpperImgs = this.map.tilesetUpperFrames.map((u)=>{
          const img = new Image();
          img.src = this._publicUrl(u);
          return img;
        });
        await Promise.all(this.tilesetUpperImgs.map(img=>this._waitImage(img)));
        this.tilesetUpperImg = this.tilesetUpperImgs[0] || null;
      } else if (this.map.tilesetUpper) {
        const img = new Image();
        img.src = this._publicUrl(this.map.tilesetUpper);
        await this._waitImage(img);
        this.tilesetUpperImgs = [img];
        this.tilesetUpperImg = img;
      }
}else{
      // tileset path: stored in map as /assets/... under public
      this.tilesetImg.src=this._publicUrl(this.map.tileset || "assets/tiles/tileset_placeholder.png");
      await this._waitImage(this.tilesetImg);
    }

// Player sprite: fallback to placeholder if red_normal is missing.
    try{
      await this._waitImage(this.playerImg);
    }catch(e){
      this.playerSprite={ kind:"placeholder", frameW:16, frameH:24, framesPerDir:3 };
      this.playerImg.src=this._publicUrl("assets/sprites/player_placeholder.png");
      await this._waitImage(this.playerImg);
    }

    // Spawn
    let sp=this.map.spawn||{x:10,y:10,dir:0};
    if(opts && opts.transition){
      const t=opts.transition;
      const W=this.map.width, H=this.map.height;
      const fromX=t.fromX|0, fromY=t.fromY|0;
      const off=t.offset|0;
      const dir=String(t.direction||"");
      let x=sp.x|0, y=sp.y|0;
      if(dir==="up")   { x=fromX-off; y=H-1; }
      if(dir==="down") { x=fromX-off; y=0;   }
      if(dir==="left") { x=W-1;       y=fromY-off; }
      if(dir==="right"){ x=0;         y=fromY-off; }
      x=Math.max(0,Math.min(W-1,x));
      y=Math.max(0,Math.min(H-1,y));
      sp={x,y,dir:t.faceDir ?? sp.dir ?? 0};
    }
    this.player.x=sp.x; this.player.y=sp.y; this.player.dir=sp.dir;
    this.player.px=sp.x; this.player.py=sp.y;
    this._resetMapTransientState();

    this._resize();
    this._snapCameraToPlayer();
    if(!this._zoomUser) this.resetZoom();
    if(!this._inputBound){
      window.addEventListener("resize",()=>this._resize());
      window.addEventListener("keydown",(e)=>{
      // Prevent browser scroll on arrows.
      if(["ArrowUp","ArrowDown","ArrowLeft","ArrowRight"].includes(e.key)) e.preventDefault();

      // Debug toggle (F3)
      if(e.key==="F3"){
        this.debug=!this.debug;
        this.status(this.debug? "DEBUG ON" : "DEBUG OFF");
        return;
      }

      // Dialog input (if an NPC/script opened a dialog)
      if(this._dialog && this._dialog.open){
        const k=e.key;
        if(k==="a"||k==="A"){
          e.preventDefault();
          this._dialogNext();
          return;
        }
        if(k==="s"||k==="S"){
          e.preventDefault();
          this._dialogClose();
          return;
        }
        // Block other inputs while dialog is open.
        return;
      }

      // GBA keymap:
      //   A key   -> A button
      //   S key   -> B button
      //   Z key   -> START
      //   X key   -> SELECT
      if(e.key==="a"||e.key==="A"){ e.preventDefault(); this._actionInteract(); return; }
      if(e.key==="s"||e.key==="S"){ e.preventDefault(); this._actionCancel(); return; }
      if(e.key==="z"||e.key==="Z"){ e.preventDefault(); this._actionStart(); return; }
      if(e.key==="x"||e.key==="X"){ e.preventDefault(); this._actionSelect(); return; }

      // Movement: arrows only (server-authoritative movement).
      this.keys.add(e.key);
      if(e.key==="ArrowUp") this.player.dir=1;
      if(e.key==="ArrowDown") this.player.dir=0;
      if(e.key==="ArrowLeft") this.player.dir=2;
      if(e.key==="ArrowRight") this.player.dir=3;
      }, {passive:false});
      window.addEventListener("keyup",(e)=>this.keys.delete(e.key));
      this._inputBound=true;
    }

    // Do not force-write spawn/transition coordinates here.
    // The saved server state is applied by _initFromServer(), and later movement/warp
    // commits are synced explicitly. Forcing an upsert here can overwrite the saved
    // location and desync seamless connections.
    this.status("오버월드 로드 OK");

    // Prefetch connected maps (for seamless connection preview)
    this._prefetchNeighbors();
    this._fetchNpcs().catch(()=>{});
    this._log(`로드: ${this.map?.map_id || 'UNKNOWN'} (${this.map?.width||0}x${this.map?.height||0})`);
  }

  async _loadStaticPretMap(mapId, opts={}){
    const url = `./pret/maps/${encodeURIComponent(mapId)}.json`;
    const r = await fetch(url, {cache:"no-store"});
    if(!r.ok) throw new Error(`static pret map failed (${r.status})`);
    await this.load(url, opts);
    return true;
  }

  _pretRequiredGenVer(){
    return "r20_priority_split";
  }

  _pretMapNeedsRefresh(mapObj){
    const have = String(mapObj?.meta?.gen_ver || "");
    return have !== this._pretRequiredGenVer();
  }

  async _generatePretMap(mapId, opts={}){
    const url=`${this.apiBase}/pret/map.php?map=${encodeURIComponent(mapId)}`;
    const r=await fetch(url,{cache:"no-store"});
    const j=await r.json().catch(()=>null);
    if(!r.ok || !j || !j.ok) throw new Error(j?.detail||j?.err||"pret/map.php failed");
    await this.load(j.mapUrl, opts);
    return j;
  }

  async loadPret(mapId, opts={}){
    // 1) Prefer the static public cache first, but only when it was generated by
    // the current metatile renderer. Older caches from r16 clip the upper 4 tiles
    // (roof / building overlay) out of bounds and make towns look half-missing.
    try{
      await this._loadStaticPretMap(mapId, opts);
      if(!this._pretMapNeedsRefresh(this.map)) return;
    }catch(e){}

    // 2) Final fallback: regenerate with the current renderer (requires Packege)
    // Skip map_cached.php entirely here. It is only a wrapper around the same static
    // cache, and when that wrapper fails it pollutes movement/connection flow with
    // noisy 500s while the local static cache is already good enough.
    await this._generatePretMap(mapId, opts);
  }

  
  setZoom(z, {user=true}={}){
    // Locked zoom: always keep fixedZoom (prevents accidental changes)
    if(this.lockZoom){
      this.zoom = this.fixedZoom || this.defaultZoom || 3.0;
      return;
    }
    const min = (typeof this.zoomMin === 'number' && isFinite(this.zoomMin)) ? this.zoomMin : 1.0;
    const max = (typeof this.zoomMax === 'number' && isFinite(this.zoomMax)) ? this.zoomMax : 10.0;
    const step = (typeof this.zoomStep === 'number' && isFinite(this.zoomStep) && this.zoomStep > 0) ? this.zoomStep : 0.25;
    const clamp = (v)=>Math.max(min, Math.min(max, v));
    const q = (v)=>Math.round(v/step)*step;

    this.zoom = q(clamp(z));
    if(user) this._zoomUser = true;
    this._updateUiScale();
  }


    resetZoom(){
    this.setZoom(this.defaultZoom, {user:false});
  }

  start(){
    if(this._started) return;
    this._started=true;

    // Load server-side player info/position (token-based)
    this._serverInitPromise = this._initFromServer().catch(()=>{});

    let last = performance.now();
    const tick = (now)=>{
      // Real-time delta (fix: avoid "fast-forward" on high refresh-rate monitors)
      let dt = (now - last) / 1000;
      last = now;
      if(!isFinite(dt) || dt<=0) dt = 1/60;
      // clamp to avoid giant jumps when tab was inactive
      dt = Math.min(0.05, dt);

      // Fixed-step update (60fps) to match GBA feel across any monitor refresh rate.
      this._accum = (this._accum || 0) + dt;
      const step = this._fixedStep || (1/60);
      // guard: avoid spiral of death if tab was paused
      let guard = 0;
      while(this._accum >= step && guard < 8){
        this._update(step);
        this._accum -= step;
        guard++;
      }
      this._draw();
      requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  }

  _applyServerState(st){
    if(!st) return;
    if(st.map_id && (!this.map || this.map.map_id !== st.map_id)){
      return false;
    }
    if(typeof st.x === 'number') this.player.x = st.x|0;
    if(typeof st.y === 'number') this.player.y = st.y|0;
    if(typeof st.dir === 'number') this.player.dir = st.dir|0;
    this.player.px = this.player.x;
    this.player.py = this.player.y;
    this.player.moving = false;
    this._moveFrames=0; this._moveFramesTotal=0; this._movePx=0; this._moveDistPx=0; this._moveT=0;
    this._jumping=false;
    this._moveSecondsNow=null;
    this._moveDirLocked=null;
    const payload = this._syncPayload();
    this._lastSyncKey = `${payload.map_id}:${payload.x}:${payload.y}:${payload.dir}`;
    this._syncLatest = payload;
    return true;
  }

  async _resyncFromServerState(){
    if(!this.playToken || this._serverResyncing) return;
    this._serverResyncing = true;
    const hdr = { "Authorization": `Bearer ${this.playToken}` };
    try{
      const s = await fetch(`${this.apiBase}/rt/get.php`, {cache:"no-store", headers: hdr});
      if(!s.ok) return;
      const j = await s.json().catch(()=>null);
      const st = (j && j.ok && j.state) ? j.state : null;
      if(!st) return;
      if(st.map_id && (!this.map || this.map.map_id !== st.map_id)){
        try{ await this.loadPret(st.map_id); }catch(e){}
      }
      this._applyServerState(st);
    }catch(e){}
    finally{
      this._serverResyncing = false;
    }
  }

  async _initFromServer(){
    if(!this.playToken) { this._serverStateLoaded=true; return; }
    const hdr = { "Authorization": `Bearer ${this.playToken}` };

    try{
      const w = await fetch(`${this.apiBase}/auth/whoami.php`, {cache:"no-store", headers: hdr});
      if(w.ok){
        const j = await w.json();
        if(j && j.ok && j.player){
          this.playerId = j.player.player_id ?? this.playerId;
          this.playerName = j.player.display_name ?? this.playerName;
          this.playerGender = (j.player.gender ?? this.playerGender);
          this._applyGenderSprite();
        }
      }
    }catch(e){}

    try{
      const s = await fetch(`${this.apiBase}/rt/get.php`, {cache:"no-store", headers: hdr});
      if(s.ok){
        const j = await s.json();
        if(j && j.ok && j.state){
          const st = j.state;
          // If map differs, try to load it (no reload if already loaded)
          if(st.map_id && (!this.map || this.map.map_id !== st.map_id)){
            try{ await this.loadPret(st.map_id); }catch(e){}
          }
          this._applyServerState(st);
        }
      }
    }catch(e){}
    finally{
      this._serverStateLoaded = true;
    }
  }


  _resize(){
    const dpr = window.devicePixelRatio || 1;
    const rect = this.canvas.getBoundingClientRect();

    // Cache these so camera math doesn't "wobble" due to tiny per-frame layout decimals.
    this.dpr = dpr;
    this._cssW = rect.width;
    this._cssH = rect.height;

    this.canvas.width = Math.floor(rect.width * dpr);
    this.canvas.height = Math.floor(rect.height * dpr);
    this.ctx.setTransform(dpr,0,0,dpr,0,0);

    // Pixel-art: avoid blurred "palette" 느낌.
    this.ctx.imageSmoothingEnabled=false;

    // keep DOM UI elements (map name popup) pixel-perfect with the same zoom
    this._updateUiScale();
  }


  _ensureMapNamePopup(){
    if (this._mapNamePopup) return;
    const pane = this.canvas.closest("#overworldPane") || this.canvas.parentElement || document.body;

    const el = document.createElement("div");
    el.id = "mapNamePopup";
    el.className = "gba-mapname-popup";
    el.style.setProperty("--uiScale", String(this.zoom || 1));

    const span = document.createElement("span");
    span.className = "gba-mapname-text";
    el.appendChild(span);

    pane.appendChild(el);

    this._mapNamePopup = el;
    this._mapNameTextEl = span;
  }

  _updateUiScale(){
    if (!this._mapNamePopup) return;
    const z = (this.zoom || 1);
    this._mapNamePopup.style.setProperty("--uiScale", String(z));
  }

  _mapNameKo(mapId){
    // 최소 매핑 (필요하면 계속 확장)
    const KO = {
      "PalletTown": "태초마을",
      "Route1": "1번도로",
      "ViridianCity": "상록시티",
      "Route2": "2번도로",
      "PewterCity": "회색시티",
      "Route3": "3번도로",
      "CeruleanCity": "블루시티",
      "VermilionCity": "갈색시티",
      "LavenderTown": "보라타운",
      "CeladonCity": "무지개시티",
      "FuchsiaCity": "연분홍시티",
      "CinnabarIsland": "홍련섬",
      "IndigoPlateau": "포켓몬리그",
    };
    return KO[mapId] || mapId;
  }

  showMapNamePopup(mapIdOrName){
    this._ensureMapNamePopup();

    const name = this._mapNameKo(mapIdOrName);
    this._mapNameTextEl.textContent = name;

    // restart animation
    this._mapNamePopup.classList.remove("is-show");
    // force reflow
    void this._mapNamePopup.offsetWidth;
    this._mapNamePopup.classList.add("is-show");
  }


  _waitImage(img){
    return new Promise((res,rej)=>{
      if(img.complete && img.naturalWidth>0) return res();
      img.onload=()=>res();
      img.onerror=()=>rej(new Error(`img fail: ${img.src||"(empty src)"}`));
    });
  }

  _resetMapTransientState({clearNeighbors=false}={}){
    this._edgePending = null;
    this._warpPending = false;
    this._queuedDir = null;
    this._moveSecondsNow = null;
    this._jumping = false;
    this.moveCooldown = 0;
    this.stepCooldown = 0.0;
    this.player.moving = false;
    this._moveT = 0;
    this._moveFrames = 0;
    this._moveFramesTotal = 0;
    this._movePx = 0;
    this._moveDistPx = 0;
    this._moveDirLocked = null;
    if(this._grassFx && this._grassFx.clear) this._grassFx.clear();
    if(clearNeighbors){
      if(this._neighborCache && this._neighborCache.clear) this._neighborCache.clear();
      if(this._neighborPromises && this._neighborPromises.clear) this._neighborPromises.clear();
    }
  }

  _snapCameraToPlayer(){
    if(!this.player || !this.canvas) return;
    const ts = this.tileSize || 16;
    const cssW = (typeof this._cssW === "number") ? this._cssW : this.canvas.getBoundingClientRect().width;
    const cssH = (typeof this._cssH === "number") ? this._cssH : this.canvas.getBoundingClientRect().height;
    const zoom = this.zoom || 1;
    const viewW = cssW / zoom;
    const viewH = cssH / zoom;
    const px = (this.player.x|0) * ts;
    const py = (this.player.y|0) * ts;
    this.camera.x = Math.round(px - viewW / 2);
    this.camera.y = Math.round(py - viewH / 2);
  }

  _groundAt(x,y){
    const W=this.map.width, H=this.map.height;
    if(x>=0&&y>=0&&x<W&&y<H) return this.map.layers[0].data[y*W+x] ?? 0;

    // Fill outside view with repeating border metatiles if provided.
    const b=this.map.border;
    if(b && Array.isArray(b.data) && b.data.length>0){
      const bw=b.w||2, bh=b.h||2;
      const ix=((x%bw)+bw)%bw;
      const iy=((y%bh)+bh)%bh;
      return b.data[iy*bw+ix] ?? 0;
    }
    return 0;
  }

  _resolveMapContextAt(x,y){
    if(!this.map) return null;
    const W=this.map.width|0, H=this.map.height|0;
    if(x>=0 && y>=0 && x<W && y<H){
      return { map:this.map, x:x|0, y:y|0, assets:{ tilesetCols:this.tilesetCols||16, tilesetImgs:this.tilesetImgs||[], tilesetImg:this.tilesetImg, tilesetUpperImgs:this.tilesetUpperImgs||[], tilesetUpperImg:this.tilesetUpperImg||null } };
    }

    let dir=null;
    if(y<0) dir="up";
    else if(y>=H) dir="down";
    else if(x<0) dir="left";
    else if(x>=W) dir="right";
    if(!dir) return null;

    const c=this._getConnection(dir);
    if(!(c && c.map_id && this._neighborCache.has(c.map_id))) return null;

    const nb=this._neighborCache.get(c.map_id);
    const nW=nb?.map?.width|0, nH=nb?.map?.height|0;
    let nx=x|0, ny=y|0;
    const off=(c.offset||0)|0;

    if(dir==="up")        { ny=(nH + y); nx=(x-off); }
    else if(dir==="down") { ny=(y - H);  nx=(x-off); }
    else if(dir==="left") { nx=(nW + x); ny=(y-off); }
    else if(dir==="right"){ nx=(x - W);  ny=(y-off); }

    if(nx<0 || ny<0 || nx>=nW || ny>=nH) return null;
    return { map:nb.map, x:nx|0, y:ny|0, assets:{ tilesetCols:nb.tilesetCols||16, tilesetImgs:nb.tilesetImgs||[], tilesetImg:nb.tilesetImg, tilesetUpperImgs:nb.tilesetUpperImgs||[], tilesetUpperImg:nb.tilesetUpperImg||null } };
  }

  _mapArrayValue(map, key, x, y, fallback=0){
    const W=map?.width|0, H=map?.height|0;
    const arr=map?.[key];
    if(!Array.isArray(arr) || arr.length !== W*H) return fallback;
    if(x<0 || y<0 || x>=W || y>=H) return fallback;
    return arr[y*W+x] ?? fallback;
  }

  _behaviorAt(x,y){
    const ctx=this._resolveMapContextAt(x,y);
    if(!ctx) return 0;
    return this._mapArrayValue(ctx.map, 'behavior', ctx.x, ctx.y, 0);
  }

  _isTallGrassBehavior(b) {
    return isOwTallGrassBehavior(b);
  }

  _frontCoverAt(x, y) {
    const ctx=this._resolveMapContextAt(x,y);
    if(!ctx) return 0;
    return getOwFrontCoverAt(ctx.map, ctx.x, ctx.y);
  }

  _frontOccluderAt(x, y) {
    const ctx=this._resolveMapContextAt(x,y);
    if(!ctx) return 0;
    return getOwFrontOccluderAt(ctx.map, ctx.x, ctx.y);
  }

  _grassCoverAt(x, y) {
    const ctx=this._resolveMapContextAt(x,y);
    if(!ctx) return 0;
    return getOwGrassCoverAt(ctx.map, ctx.x, ctx.y, (tx, ty) => this._mapArrayValue(ctx.map, 'behavior', tx, ty, 0));
  }

  _playerPriorityState() {
    return getActorPriorityState({
      map: this.map,
      renderX: this._playerRenderX(),
      renderY: this._playerRenderY(),
      dir: this.player?.dir ?? 0,
      moving: !!this.player?.moving,
      behaviorAt: (tx, ty) => this._behaviorAt(tx, ty),
      grassCoverAt: (tx, ty) => this._grassCoverAt(tx, ty),
      frontOccluderAt: (tx, ty) => this._frontOccluderAt(tx, ty),
    });
  }

  _ensureFxTallGrass() {
    if (this._fxTallGrassPromise) return this._fxTallGrassPromise;
    this._fxTallGrassPromise = new Promise((resolve) => {
      const img = new Image();
      img.onload = () => { this._fxTallGrassImg = img; this._fxTallGrassReady = true; resolve(true); };
      img.onerror = () => { this._fxTallGrassReady = false; resolve(false); };
      img.src = this._publicUrl('pret/fx/tall_grass.png');
    });
    return this._fxTallGrassPromise;
  }

  _drawTallGrassFx(oc, x0, y0, x1, y1) {
    if (!this._fxTallGrassReady || !this._fxTallGrassImg || !this._grassFx) return;
    const ts = this.tileSize || 16;
    const now = performance.now();
    for (const [k, t0] of this._grassFx.entries()) {
      const age = now - t0;
      if (age < 0 || age > this._grassFxDuration) continue;
      const parts = k.split(',');
      const tx = parseInt(parts[0], 10);
      const ty = parseInt(parts[1], 10);
      if (tx < x0 || tx > x1 || ty < y0 || ty > y1) continue;
      const frame = Math.min(this._grassFxFrames - 1, Math.floor(age / this._grassFxStepMs));
      const sx = 0;
      const sy = frame * ts;
      const dx = tx * ts;
      const dy = ty * ts;
      oc.drawImage(this._fxTallGrassImg, sx, sy, ts, ts, dx, dy, ts, ts);
    }
  }


  _isWaterBehavior(b){
    // match api/lib/pret_public.php
    return [0x10,0x11,0x12,0x13,0x14,0x15,0x16,0x17,0x19,0x1A,0x1B,0x50,0x51,0x52,0x53].includes(b);
  }

  _frontTile(){
    const p=this.player;
    let dx=0,dy=0;
    if(p.dir===0) dy=1; // down
    else if(p.dir===1) dy=-1; // up
    else if(p.dir===2) dx=-1; // left
    else if(p.dir===3) dx=1; // right
    return {x:p.x+dx, y:p.y+dy};
  }

  _isBlockedTile(t,x,y){
    const W=this.map.width, H=this.map.height;
    if(x==null||y==null) return (t===4||t===7||t===5);
    if(this.map.collision && this.map.collision.length===W*H){
      return (this.map.collision[y*W+x]||0)!==0;
    }
    return (t===4||t===7||t===5);
  }

  _tryMove(dx,dy,dir){
    if(this.player.moving || this._loading) return;
    // lock movement during fishing
    if(this._fishFx && performance.now() < this._fishLockUntil) return;
    const nx=this.player.x+dx, ny=this.player.y+dy;
    this.player.dir=dir;

    // Seamless connected map borders (FRLG-style scrolling instead of "warp")
    if(nx<0||ny<0||nx>=this.map.width||ny>=this.map.height){
      this._trySeamlessEdgeMove(nx,ny,dx,dy);
      return;
    }

    // Block movement into visible NPC tile (sprite_key exists)
    const _npcDest = this._npcAtTile(nx,ny);
    if(_npcDest && String(_npcDest.sprite_key||"").trim()) return;

    // Ledge jump (south-only): the ledge tile is the next tile; landing is one more tile down.
    // Behavior id is taken from pret tileset metatile attributes.
    if(dx===0 && dy===1){
      const beh=this._behaviorAt(nx,ny);
      // FRLG-style ledge behaviors are typically in the MB_JUMP_* range (0x3B..0x3E)
      // and when moving south we treat any of them as a "jump down" trigger.
      if(beh>=0x3B && beh<=0x3E){
        const landX=nx;
        const landY=ny+1;
        if(landY>=0 && landY<this.map.height){
          const t2=this._groundAt(landX,landY);
          const _npcLand = this._npcAtTile(landX,landY);
          if(!this._isBlockedTile(t2,landX,landY) && !(_npcLand && String(_npcLand.sprite_key||"").trim())){
            this._startJump(landX,landY,dir);
            return;
          }
        }
      }
    }

    const t=this._groundAt(nx,ny);
    const w=this._findWarpAt(nx,ny);
    if(this._isBlockedTile(t,nx,ny)){
      // Door tiles are often marked as collision in decomp data.
      // If this tile is a warp, allow stepping onto it so _afterStep() can trigger the warp.
      if(!(w && w.dest_map_id)) return;
    }

    this._edgePending=null;
    this._moveSecondsNow=null;
    this._jumping=false;
    this.player.moving=true;
    this.player.px=this.player.x; this.player.py=this.player.y;
    this.player.x=nx; this.player.y=ny;
    this._moveT=0;

    // GBA-like fixed-step movement init
    const fps = this.fixedFps || 60;
    const seconds = (this._moveSecondsNow || this.moveSeconds || (16 / fps));
    this._moveFrames = 0;
    this._moveFramesTotal = Math.max(1, Math.round(seconds * fps));
    const mdx = (this.player.x - this.player.px);
    const mdy = (this.player.y - this.player.py);
    this._moveDistPx = (Math.abs(mdx)+Math.abs(mdy)) * (this.tileSize||16);
    this._movePx = 0;
    this._moveDirLocked = dir;

    // DB sync is committed after the step finishes.
  }

  _startJump(toX,toY,dir){
    // Special 2-tile-ish movement (ledge jump). We jump directly to the landing tile
    // but animate the sprite with an arc + shadow.
    this._edgePending=null;
    this._jumping=true;
    this._moveSecondsNow=this.jumpSeconds||this.moveSeconds;

    this.player.dir=dir;
    this.player.moving=true;
    this.player.px=this.player.x; this.player.py=this.player.y;
    this.player.x=toX; this.player.y=toY;
    this._moveT=0;

    // GBA-like fixed-step movement init (jump)
    const fps = this.fixedFps || 60;
    const seconds = (this._moveSecondsNow || this.jumpSeconds || this.moveSeconds || (16 / fps));
    this._moveFrames = 0;
    this._moveFramesTotal = Math.max(1, Math.round(seconds * fps));
    const mdx = (this.player.x - this.player.px);
    const mdy = (this.player.y - this.player.py);
    this._moveDistPx = (Math.abs(mdx)+Math.abs(mdy)) * (this.tileSize||16);
    this._movePx = 0;
    this._moveDirLocked = dir;

    // DB sync is committed after the jump lands.
  }

  _trySeamlessEdgeMove(nx,ny,dx,dy){
    // Decide which connection we'd cross
    const dir = (dx===0&&dy===-1) ? "up" : (dx===0&&dy===1) ? "down" : (dx===-1&&dy===0) ? "left" : (dx===1&&dy===0) ? "right" : "";
    if(!dir) return;
    const c=this._getConnection(dir);
    if(!c || !c.map_id) return; // no connection => block
    const off=(c.offset||0)|0;

    // Ensure neighbor is available (we need it for collision checks + preview drawing)
    const have = this._neighborCache.has(c.map_id);
    if(!have){
      this._loading=true;
      this.status(`이웃 맵 로딩: ${c.map_id}`);
      this._ensureNeighbor(c.map_id).then(()=>{
        this._loading=false;
        // Re-try the move once the neighbor is ready.
        this._tryMove(dx,dy,this.player.dir);
      }).catch((e)=>{
        this._loading=false;
        const msg=`이웃 맵 로드 실패: ${c.map_id} (${e?.message||'error'})`;
        this.status(msg);
        this._log(msg);
      });
      return;
    }

    const nb=this._neighborCache.get(c.map_id);
    const W=this.map.width, H=this.map.height;

    // Map the out-of-bounds step target to a concrete neighbor tile for collision check.
    let tx=0, ty=0;
    if(dir==="right"){
      tx=0;
      ty=(this.player.y - off);
    }else if(dir==="left"){
      tx=(nb.map.width-1);
      ty=(this.player.y - off);
    }else if(dir==="down"){
      tx=(this.player.x - off);
      ty=0;
    }else if(dir==="up"){
      tx=(this.player.x - off);
      ty=(nb.map.height-1);
    }
    if(tx<0||ty<0||tx>=nb.map.width||ty>=nb.map.height) return; // invalid => block
    const nt= (nb.map.layers && nb.map.layers[0] && nb.map.layers[0].data) ? (nb.map.layers[0].data[ty*nb.map.width+tx]||0) : 0;
    const blocked = (nb.map.collision && nb.map.collision.length===nb.map.width*nb.map.height) ? ((nb.map.collision[ty*nb.map.width+tx]||0)!==0) : (nt===4||nt===7||nt===5);
    if(blocked) return;

    // Start a normal step that goes 1 tile beyond the current map. During the step,
    // drawing will use neighbor preview tiles for the outside strip.
    this._edgePending={ dir, map_id:c.map_id, offset:off, fromW:W, fromH:H };
    this._moveSecondsNow=null;
    this._jumping=false;
    this.player.moving=true;
    this.player.px=this.player.x; this.player.py=this.player.y;
    this.player.x=nx; this.player.y=ny;
    this._moveT=0;

    // GBA-like fixed-step movement init
    const fps = this.fixedFps || 60;
    const seconds = (this._moveSecondsNow || this.moveSeconds || (16 / fps));
    this._moveFrames = 0;
    this._moveFramesTotal = Math.max(1, Math.round(seconds * fps));
    const mdx = (this.player.x - this.player.px);
    const mdy = (this.player.y - this.player.py);
    this._moveDistPx = (Math.abs(mdx)+Math.abs(mdy)) * (this.tileSize||16);
    this._movePx = 0;
    // Keep numeric facing (caller already set it)
    this._moveDirLocked = this.player.dir;
    // NOTE: do NOT upsert now (coords are temporarily out-of-bounds). We'll upsert after commit.
  }

  _commitSeamlessIfNeeded(){
    if(!this.map || !this.player) return false;
    const W=this.map.width, H=this.map.height;
    const x=this.player.x, y=this.player.y;
    if(x>=0 && y>=0 && x<W && y<H) return false;

    // Which side did we exit?
    let dir="";
    if(x<0) dir="left";
    else if(x>=W) dir="right";
    else if(y<0) dir="up";
    else if(y>=H) dir="down";
    if(!dir) return false;

    const c=this._getConnection(dir);
    if(!c || !c.map_id) return false;
    const nb=this._neighborCache.get(c.map_id);
    if(!nb) return false;
    const off=(c.offset||0)|0;

    const ts=this.tileSize;
    let newX=0, newY=0;
    let camDx=0, camDy=0;

    if(dir==="right"){
      newX=0;
      newY=(this.player.y - off);
      camDx = -W*ts;
      camDy = off*ts;
    }else if(dir==="left"){
      newX=(nb.map.width-1);
      newY=(this.player.y - off);
      camDx = (nb.map.width*ts);
      camDy = off*ts;
    }else if(dir==="down"){
      newY=0;
      newX=(this.player.x - off);
      camDy = -H*ts;
      camDx = off*ts;
    }else if(dir==="up"){
      newY=(nb.map.height-1);
      newX=(this.player.x - off);
      camDy = (nb.map.height*ts);
      camDx = off*ts;
    }

    // clamp
    newX=Math.max(0,Math.min(nb.map.width-1,newX|0));
    newY=Math.max(0,Math.min(nb.map.height-1,newY|0));

    // Apply new map + tileset instantly (no flash)
    this.map = nb.map;
    this.tilesetCols = nb.tilesetCols||16;
    this.tilesetImgs = nb.tilesetImgs||[];
    this.tilesetImg  = nb.tilesetImg;
    this.tilesetUpperImgs = nb.tilesetUpperImgs||[];
    this.tilesetUpperImg = nb.tilesetUpperImg||null;

    // Adjust camera so the screen doesn't "jump" when local coordinates reset.
    this.camera.x += camDx;
    this.camera.y += camDy;

    // Snap player into the new map.
    this.player.x=newX; this.player.y=newY;
    this.player.px=newX; this.player.py=newY;

    // Done
    this._edgePending=null;
    this._moveSecondsNow=null;
    this._jumping=false;
    this.status(`맵 연결: ${c.map_id}`);
    this._log(`Seamless -> ${c.map_id} (${dir}, off=${off})`);

    // Prefetch next neighbors and persist position.
    this._prefetchNeighbors();
    this._fetchNpcs().catch(()=>{});
    // Freeze input until DB sync + first mob fetch completes (pre-seed avoids pop-in)
    this._loading=true;
    this._upsert().then(()=>this._fetchMobs()).catch(()=>{}).finally(()=>{
      this._loading=false;
    });
    return true;
  }

  _queueEdgeTransition(dx,dy){
    const dir = (dx===0&&dy===-1) ? "up" : (dx===0&&dy===1) ? "down" : (dx===-1&&dy===0) ? "left" : (dx===1&&dy===0) ? "right" : "";
    if(!dir) return;
    const conns = Array.isArray(this.map?.connections) ? this.map.connections : [];
    const c = conns.find(v=>v && v.direction===dir);
    if(!c || !c.map_id) return; // no connection => block

    this._loading=true;
    this.status(`맵 이동: ${this.map.map_id} -> ${c.map_id}`);
    const fromX=this.player.x, fromY=this.player.y;
    const faceDir=this.player.dir;
    (async()=>{
      try{
        await this.loadPret(c.map_id, { transition:{ fromX, fromY, direction:dir, offset:(c.offset||0), faceDir } });
        await this._upsert();
        await this._fetchMobs();
      await this._fetchItems();
      await this._fetchNpcs();
      }catch(e){
        console.error(e);
        const msg = `맵 이동 실패: ${e?.message||e}`;
        this.status(msg);
        this._log(msg);
      }finally{
        this._loading=false;
      }
    })();
  }

  _getConnection(dir){
    const arr=this.map?.connections||[];
    for(const c of arr){
      if(c && c.direction===dir && c.map_id) return c;
    }
    return null;
  }

  _prefetchNeighbors(){
    if(!this.map || !Array.isArray(this.map.connections)) return;
    for(const c of this.map.connections){
      if(!c || !c.map_id) continue;
      this._ensureNeighbor(c.map_id).then(()=>{}).catch((e)=>{
        this._log(`이웃 로드 실패: ${c.map_id} (${e?.message||'error'})`);
      });
    }
  }

  async _ensureNeighbor(mapId){
    if(this._neighborCache.has(mapId)) return this._neighborCache.get(mapId);
    if(this._neighborPromises.has(mapId)) return this._neighborPromises.get(mapId);

    const p=(async()=>{
      try{
        let mj = null;
        let meta = null;

        // Prefer the static public cache for seamless neighbor previews, but reject
        // stale r16 caches because their metatile overlays are clipped and connections
        // look broken at the map edges.
        try{
          const rr = await fetch(`./pret/maps/${encodeURIComponent(mapId)}.json`, {cache:"no-store"});
          if(rr.ok){
            mj = await rr.json();
            if(this._pretMapNeedsRefresh(mj)) mj = null;
          }
        }catch(e){}

        if(!mj){
          const rg = await fetch(`${this.apiBase}/pret/map.php?map=${encodeURIComponent(mapId)}`, {cache:"no-store"});
          meta = await rg.json().catch(()=>null);
          if(!rg.ok || !meta || !meta.ok){
            throw new Error(meta?.detail || meta?.err || `pret/map.php failed (${rg.status})`);
          }
          const rr = await fetch(meta.mapUrl, {cache:"no-store"});
          mj = await rr.json();
        }

        const a={
          map: mj,
          tilesetCols: (mj.tilesetCols||16),
          tilesetImgs: [],
          tilesetImg: new Image(),
          tilesetUpperImgs: [],
          tilesetUpperImg: null,
        };

        if(Array.isArray(mj.tilesetFrames) && mj.tilesetFrames.length){
          a.tilesetImgs = mj.tilesetFrames.map(p=>{
            const img=new Image();
            img.src=this._publicUrl(p);
            return img;
          });
          await Promise.all(a.tilesetImgs.map(img=>this._waitImage(img)));
          a.tilesetImg = a.tilesetImgs[0];
        }else{
          a.tilesetImg.src=this._publicUrl(mj.tileset || "assets/tiles/tileset_placeholder.png");
          await this._waitImage(a.tilesetImg);
        }

        if(Array.isArray(mj.tilesetUpperFrames) && mj.tilesetUpperFrames.length){
          a.tilesetUpperImgs = mj.tilesetUpperFrames.map(p=>{
            const img=new Image();
            img.src=this._publicUrl(p);
            return img;
          });
          await Promise.all(a.tilesetUpperImgs.map(img=>this._waitImage(img)));
          a.tilesetUpperImg = a.tilesetUpperImgs[0] || null;
        }else if(mj.tilesetUpper){
          const img = new Image();
          img.src = this._publicUrl(mj.tilesetUpper);
          await this._waitImage(img);
          a.tilesetUpperImgs = [img];
          a.tilesetUpperImg = img;
        }

        this._neighborCache.set(mapId, a);
        return a;
      }finally{
        this._neighborPromises.delete(mapId);
      }
    })();

    this._neighborPromises.set(mapId,p);
    return p;
  }



  _npcSpriteUrl(spriteKey){
    const k = (spriteKey ?? "").toString().trim();
    if(!k) return "";
    return this._publicUrl(`pret/sprites/npc/${k}.png`);
  }

  _getNpcImg(spriteKey){
    const k = (spriteKey ?? "").toString().trim();
    if(!k) return null;
    if(this._npcErr.has(k)) return null;
    if(this._npcImg.has(k)) return this._npcImg.get(k);

    const url = this._npcSpriteUrl(k);
    if(!url) return null;

    const img = new Image();
    img.onerror = ()=>{ this._npcErr.add(k); this._npcImg.delete(k); };
    img.src = url;

    this._npcImg.set(k, img);
    return img;
  }

  async _fetchNpcs(){
    if(!this.map || !this.map.map_id) return;
    const mapId = this.map.map_id;
    try{
      const r = await fetch(`${this.apiBase}/game/npcs.php?map=${encodeURIComponent(mapId)}`, {
        cache: "no-store",
        headers: {
          ...(this.playToken ? {"Authorization": `Bearer ${this.playToken}`} : {}),
        },
      });
      const j = await r.json().catch(()=>null);
      if(!r.ok || !j || !j.ok){
        this.npcs = [];
        return;
      }
      this.npcs = Array.isArray(j.npcs) ? j.npcs : [];
      this.uiGameVer = (j.game_ver|0) || this.uiGameVer || 0;
      for(const n of this.npcs){
        const k = (n && n.sprite_key) ? String(n.sprite_key).trim() : "";
        if(k) this._getNpcImg(k);
      }
    }catch(e){
      this.npcs = [];
    }
  }

  _npcAtTile(x,y){
    x|=0; y|=0;
    for(const n of (this.npcs||[])){
      if(!n) continue;
      if(((n.x|0)===x) && ((n.y|0)===y)) return n;
    }
    return null;
  }

  async _runNpcEvent(npc){
    if(!npc) return;
    try{
      const tp = String(npc.type||"").toLowerCase();
      if(tp==="shop"){
        const nm = String(npc.name||"SHOP");
        this._openDialog([`[SHOP] ${nm}\n(아직 UI 미구현)`]);
        return;
      }

      const r = await fetch(`${this.apiBase}/game/npc_event.php`, {
        method: "POST",
        cache: "no-store",
        headers: {
          "Content-Type": "application/json",
          ...(this.playToken ? {"Authorization": `Bearer ${this.playToken}`} : {}),
        },
        body: JSON.stringify({
          npc_id: String(npc.id||""),
          map_id: String(this.map?.map_id||""),
          apply_warp: true,
        }),
      });

      const j = await r.json().catch(()=>null);
      if(!r.ok || !j || !j.ok){
        const err = (j && (j.error||j.err)) ? (j.error||j.err) : String(r.status);
        this._openDialog([`NPC 실행 실패: ${err}`]);
        return;
      }

      const pages = (j.run && j.run.dialog && Array.isArray(j.run.dialog.pages)) ? j.run.dialog.pages : [];
      if(pages.length) this._openDialog(pages);
      else this._openDialog(["(대화 없음)"]);

      const acts = (j.run && Array.isArray(j.run.actions)) ? j.run.actions : [];
      const didWarp = acts.some(a=>a && a.type==="warp");
      if(didWarp){
        await this._initFromServer();
      }
    }catch(e){
      this._openDialog(["NPC 실행 예외"]);
    }
  }


  _frontTile(){
    const x = this.player.x|0, y = this.player.y|0, d = this.player.dir|0;
    if(d===0) return {x, y:y+1};
    if(d===1) return {x, y:y-1};
    if(d===2) return {x:x-1, y};
    return {x:x+1, y};
  }

  _openDialog(pages){
    const ps = Array.isArray(pages) ? pages.map(p=>String(p ?? "")) : [];
    if(this.keys && this.keys.clear) this.keys.clear(); // prevent walking while dialog opens
    this._dialog = { open: true, pages: ps, idx: 0, char: 0, _t: 0 };
  }
  _dialogClose(){
    if(this._dialog) this._dialog.open = false;
  }
  _dialogFullText(){
    if(!this._dialog) return "";
    return String((this._dialog.pages||[])[this._dialog.idx] ?? "");
  }
  _dialogIsDone(){
    if(!this._dialog) return true;
    const full = this._dialogFullText();
    return (this._dialog.char|0) >= full.length;
  }
  _dialogNext(){
    if(!this._dialog || !this._dialog.open) return;
    const full = this._dialogFullText();
    if((this._dialog.char|0) < full.length){
      this._dialog.char = full.length;
      return;
    }
    this._dialog.idx = (this._dialog.idx|0) + 1;
    if(this._dialog.idx >= (this._dialog.pages?.length || 0)){
      this._dialog.open = false;
      return;
    }
    this._dialog.char = 0;
    this._dialog._t = 0;
  }

  _getUiFrameImg(){
    const gv = this.uiGameVer|0;
    const key = (gv===2) ? "lg" : "fr";
    this._uiFrameImg = this._uiFrameImg || new Map();
    const hit = this._uiFrameImg.get(key);
    if(hit) return hit;
    const img = new Image();
    img.src = this._publicUrl(`assets/gba_ui/text_window_${key}.png`);
    this._uiFrameImg.set(key, img);
    return img;
  }

  _drawTextWindow9(oc, img, x, y, w, h){
    const t = 8;
    // fallback if frame not ready
    if(!img || !img.complete || !img.naturalWidth){
      oc.save();
      oc.fillStyle = "rgba(255,255,255,0.95)";
      oc.strokeStyle = "rgba(0,0,0,0.85)";
      oc.lineWidth = 2;
      oc.fillRect(x, y, w, h);
      oc.strokeRect(x+1, y+1, w-2, h-2);
      oc.restore();
      return;
    }
    const sx = (ix,iy)=>ix*t;
    const sy = (ix,iy)=>iy*t;

    const x0 = x|0, y0 = y|0;
    const x1 = (x0 + w - t)|0;
    const y1 = (y0 + h - t)|0;

    // corners
    oc.drawImage(img, 0,0,t,t, x0,y0,t,t);
    oc.drawImage(img, 2*t,0,t,t, x1,y0,t,t);
    oc.drawImage(img, 0,2*t,t,t, x0,y1,t,t);
    oc.drawImage(img, 2*t,2*t,t,t, x1,y1,t,t);

    // edges + fill
    for(let xx=x0+t; xx<=x1-t; xx+=t){
      oc.drawImage(img, t,0,t,t, xx,y0,t,t);       // top
      oc.drawImage(img, t,2*t,t,t, xx,y1,t,t);     // bottom
    }
    for(let yy=y0+t; yy<=y1-t; yy+=t){
      oc.drawImage(img, 0,t,t,t, x0,yy,t,t);       // left
      oc.drawImage(img, 2*t,t,t,t, x1,yy,t,t);     // right
    }
    for(let yy=y0+t; yy<=y1-t; yy+=t){
      for(let xx=x0+t; xx<=x1-t; xx+=t){
        oc.drawImage(img, t,t,t,t, xx,yy,t,t);     // center
      }
    }
  }

  _wrapDialogText(text, maxChars){
    const out = [];
    const rawLines = String(text||"").split("\n");
    for(const raw of rawLines){
      let line = raw;
      while(line.length > maxChars){
        // try break on space
        let cut = line.lastIndexOf(" ", maxChars);
        if(cut < Math.max(1, maxChars-6)) cut = maxChars;
        out.push(line.slice(0, cut).trimEnd());
        line = line.slice(cut).trimStart();
      }
      out.push(line);
    }
    return out;
  }

  _drawDialogUI(oc, offW, offH){
    if(!this._dialog || !this._dialog.open) return;

    // typewriter reveal (~30 cps @ 60fps)
  const full = this._dialogFullText();
    if(full){
      // Packege/src/new_menu_helpers.c : GetTextSpeedSetting() -> frame delays (SLOW=8, MID=4, FAST=1)
      const delayByOpt = [8,4,1];
      let opt = 1; // default MID
      try{
        const v = localStorage.getItem("textSpeed");
        if(v != null) opt = parseInt(v, 10);
      }catch(e){}
      if(!Number.isFinite(opt) || opt < 0 || opt > 2) opt = 1;

      // Convert dt to "frames" (GBA logic is frame-based).
      const dt = (this._fixedStep || (1/60));
      const frames = dt * 60;
      this._dialog._t = (this._dialog._t || 0) + frames;

      const delay = delayByOpt[opt] || 4;
      const add = Math.floor(this._dialog._t / delay);
      if(add > 0 && (this._dialog.char|0) < full.length){
        this._dialog.char = Math.min(full.length, (this._dialog.char|0) + add);
        this._dialog._t -= add * delay;
      }
    }

    const gv = this.uiGameVer|0;
    const frame = this._getUiFrameImg() || null;

    const pad = 8;
    const boxH = 56; // 7 tiles
    const boxX = pad;
    const boxY = (offH - boxH - pad)|0;
    const boxW = (offW - pad*2)|0;

    oc.save();
    oc.imageSmoothingEnabled = false;

    this._drawTextWindow9(oc, frame, boxX, boxY, boxW, boxH);

    // text
    const innerX = boxX + 12;
    const innerY = boxY + 14;
    const innerW = boxW - 24;
    const maxChars = Math.max(8, Math.floor(innerW / 8));
    const shown = full ? full.slice(0, (this._dialog.char|0)) : "";
    const lines = this._wrapDialogText(shown, maxChars).slice(0, 3);

    oc.fillStyle = "#111";
    oc.font = "12px Galmuri11, monospace";
    oc.textBaseline = "top";
    const lh = 14;
    for(let i=0;i<lines.length;i++){
      oc.fillText(lines[i], innerX, innerY + i*lh);
    }

    // continue indicator
    const done = this._dialogIsDone();
    const hasNext = ((this._dialog.pages?.length||0) - 1) > (this._dialog.idx|0);
    if(done){
      const triX = boxX + boxW - 16;
      const triY = boxY + boxH - 14;
      oc.fillStyle = "#111";
      oc.beginPath();
      oc.moveTo(triX, triY);
      oc.lineTo(triX+8, triY);
      oc.lineTo(triX+4, triY+6);
      oc.closePath();
      oc.fill();
      if(!hasNext){
        // small blink on last page
        if((this.frame|0) % 30 < 15){
          oc.fillRect(triX-10, triY+1, 6, 6);
        }
      }
    }

    oc.restore();
  }
  _currentTilesetImg(){
    if(this.tilesetImgs && this.tilesetImgs.length){
      return this.tilesetImgs[this._tileAnimFrame % this.tilesetImgs.length];
    }
    return this.tilesetImg;
  }

  _tileInfoAt(mx,my){
    const ctx=this._resolveMapContextAt(mx,my);
    if(ctx){
      const idx=ctx.y*ctx.map.width+ctx.x;
      const t=(ctx.map.layers?.[0]?.data?.[idx] ?? 0);
      const assets=ctx.assets||{};
      const img=(assets.tilesetImgs && assets.tilesetImgs.length)
        ? assets.tilesetImgs[this._tileAnimFrame % assets.tilesetImgs.length]
        : (assets.tilesetImg || this._currentTilesetImg());
      return { tile:t, img, cols: assets.tilesetCols || this.tilesetCols || 16 };
    }

    const t=this._groundAt(mx,my);
    return { tile:t, img:this._currentTilesetImg(), cols:this.tilesetCols||16 };
  }

  _tileUpperInfoAt(mx,my){
    const ctx=this._resolveMapContextAt(mx,my);
    if(!ctx) return null;
    const idx=ctx.y*ctx.map.width+ctx.x;
    const t=(ctx.map.layers?.[0]?.data?.[idx] ?? 0);
    const assets=ctx.assets||{};
    const img=(assets.tilesetUpperImgs && assets.tilesetUpperImgs.length)
      ? assets.tilesetUpperImgs[this._tileAnimFrame % assets.tilesetUpperImgs.length]
      : assets.tilesetUpperImg;
    return img ? { tile:t, img, cols: assets.tilesetCols || this.tilesetCols || 16 } : null;
  }

  _findWarpAt(x,y){
    const arr=this.map?.warp_events||[];
    for(const w of arr){
      const wx=(w.x|0), wy=(w.y|0);
      const ww = (w.w==null?1:(w.w|0));
      const wh = (w.h==null?1:(w.h|0));
      if(x>=wx && y>=wy && x<wx+ww && y<wy+wh) return w;
    }
    return null;
  }

  _afterStep(){
    if(this._loading) return;
    if(this._warpCooldown>0) return;
    const w=this._findWarpAt(this.player.x|0, this.player.y|0);
    if(w && w.dest_map_id){
      if(this._warpPending) return;
      this._warpCooldown=0.35;
      this._warpPending = true;
      this._queuedDir = null;
      ["ArrowUp","ArrowDown","ArrowLeft","ArrowRight"].forEach(k=>this.keys.delete(k));
      this._syncLockUntil = Math.max(this._syncLockUntil||0, performance.now() + 650);
      (async()=>{
        try{
          // Sync the source warp tile first. Without this, the server still thinks the
          // player is one step before the doorway, so the next cross-map upsert looks
          // like an illegal teleport and MAP_WARP_RATE_LIMIT snaps the player back out.
          if(this._serverStateLoaded){
            await this._upsert();
          }
          await this._warpTo(w);
        }catch(e){
          console.error(e);
        }finally{
          this._warpPending = false;
        }
      })();
      return;
    }
  
    // Tall grass rustle FX (only on step)
    const ft = this._playerFootTile();
    const b = this._behaviorAt(ft.x, ft.y);
    if (this._isTallGrassBehavior(b)) {
      this._ensureFxTallGrass();
      this._grassFx.set(ft.x + ',' + ft.y, performance.now());
    }

    // Persist only after the local step has fully committed.
    if(this._serverStateLoaded){
      this._upsert();
    }

  }

  async _warpTo(w){
    if(this._loading) return;
    this._loading=true;
    const from=this.map?.map_id||"?";
    const to=w.dest_map_id;
    const entryDir = Number.isFinite(this.player?.dir) ? (this.player.dir|0) : 1;
    this._log(`워프: ${from} -> ${to} (warp ${w.warp_id} -> ${w.dest_warp_id})`);
    try{
      await this.loadPret(to);

// after load, place player at destination
// 1) script-warp: uses dest_x/dest_y (rAthena-like)
if (Number.isFinite(w.dest_x) && Number.isFinite(w.dest_y)) {
  this.player.x = (w.dest_x|0);
  this.player.y = (w.dest_y|0);
  this.player.px = this.player.x;
  this.player.py = this.player.y;
  this.player.dir = Number.isFinite(w.dest_dir) ? (w.dest_dir|0) : entryDir;
} else {
  // 2) legacy: destination warp tile id
  const destId=(w.dest_warp_id|0);
  let dest=null;
  for(const ww of (this.map?.warp_events||[])){
    if((ww.warp_id|0)===destId){ dest=ww; break; }
  }
  if(dest){
    this.player.x = (dest.x|0);
    this.player.y = (dest.y|0);
    this.player.px = this.player.x;
    this.player.py = this.player.y;
  }
  this.player.dir = entryDir;
}

      this._resetMapTransientState({ clearNeighbors: true });
      this.player.px = this.player.x;
      this.player.py = this.player.y;
      this._snapCameraToPlayer();
      this._prefetchNeighbors();
      this._warpCooldown=0.35;
      this._queuedDir = null;
      ["ArrowUp","ArrowDown","ArrowLeft","ArrowRight"].forEach(k=>this.keys.delete(k));
      await this._upsert();
      await this._fetchMobs();
      await this._fetchItems();
      await this._fetchNpcs();
    }catch(e){
      const msg=`워프 실패: ${e?.message||String(e)}`;
      console.error(e);
      this.status(msg);
      this._log(msg);
    }finally{
      this._loading=false;
    }
  }

  _update(dt){
    this.frame++;

    this._warpCooldown = Math.max(0, (this._warpCooldown||0) - dt);

    this.moveCooldown=Math.max(0,this.moveCooldown-dt);

    // Fishing FX progress
    const nowMs = performance.now();
    if(this._fishFx){
      const dms = nowMs - (this._fishFx.startMs||0);
      if(this._fishFx.bite && !this._fishFx.msgShown){
        const threshold = (this._fishFx.riseStartMs||0) + (this._fishFx.riseDurMs||0) + 120;
        if(dms >= threshold){
          const name = this._fishFx.species_key || '포켓몬';
          const lv = this._fishFx.level || 1;
          this.status(`야생의 ${name} (Lv.${lv})!`);
          this._fishFx.msgShown = true;
        }
      }
      const dur = this._fishFx.durationMs || 0;
      if(dur>0 && dms >= dur){
        this._fishFx = null;
      }
    }

    // Input (GBA-style): facing is locked during a step (no mid-step turning).
    const up = this.keys.has("ArrowUp");
    const down = this.keys.has("ArrowDown");
    const left = this.keys.has("ArrowLeft");
    const right = this.keys.has("ArrowRight");

    const wantDir = up ? 1 : down ? 0 : left ? 2 : right ? 3 : null;

    if(!this.player.moving){
      if(wantDir!==null) this.player.dir = wantDir;

      const syncLocked = (this._syncLockUntil||0) > performance.now();
      // Tile movement (only when not moving)
      if(!syncLocked && this.moveCooldown<=0){
        if(up) this._tryMove(0,-1,1);
        else if(down) this._tryMove(0,1,0);
        else if(left) this._tryMove(-1,0,2);
        else if(right) this._tryMove(1,0,3);
      }
    }else{
      // Buffer the latest direction request so continuous walking feels natural after the step ends.
      if(wantDir!==null) this._queuedDir = wantDir;
      if(this._moveDirLocked!==null && this._moveDirLocked!==undefined) this.player.dir = this._moveDirLocked;
    }

    if(this.player.moving){
      // Fixed-step movement (quantized to whole pixels)
      const fps = this.fixedFps || 60;

      // Total duration in frames (seconds-based config preserved)
      const seconds = (this._moveSecondsNow || this.moveSeconds || (16 / fps));
      const total = (this._moveFramesTotal && this._moveFramesTotal>0)
        ? this._moveFramesTotal
        : Math.max(1, Math.round(seconds * fps));
      this._moveFramesTotal = total;

      this._moveFrames = (this._moveFrames || 0) + 1;

      const distPx = (this._moveDistPx && this._moveDistPx>0) ? this._moveDistPx : (this.tileSize||16);
      const prog = Math.min(1, (this._moveFrames / total));
      this._movePx = Math.min(distPx, Math.floor(distPx * prog));
      this._moveT = distPx>0 ? (this._movePx / distPx) : 1;

      if(this._moveFrames >= total){
        this.player.moving=false;

        // Apply cooldown AFTER the step (optional)
        this.moveCooldown=this.stepCooldown||0;

        // Commit seamless map border transition (if we stepped 1 tile beyond the edge)
        const committed = this._commitSeamlessIfNeeded();
        // If something went wrong (neighbor missing), fall back to the old edge transition.
        if(!committed && this._edgePending){
          const e=this._edgePending;
          this._edgePending=null;
          const dx2 = (e.dir==="left")?-1:(e.dir==="right")?1:0;
          const dy2 = (e.dir==="up")?-1:(e.dir==="down")?1:0;
          this._queueEdgeTransition(dx2,dy2);
          // reset motion state
          this._moveFrames=0; this._moveFramesTotal=0; this._movePx=0; this._moveDistPx=0; this._moveT=0;
          this._jumping=false; this._moveSecondsNow=null; this._moveDirLocked=null;
          return;
        }

        // reset motion state
        this._moveFrames=0; this._moveFramesTotal=0; this._movePx=0; this._moveDistPx=0; this._moveT=0;

        // Finish any special movement
        this._jumping=false;
        this._moveSecondsNow=null;
        this._moveDirLocked=null;
        this._afterStep();
      }
    }

// Walking animation
    // 기존 방식(0.12s 타이머)은 "한 칸 이동"이 너무 짧아서 프레임이 안 바뀌는 문제가 있었음.
    // 이동 보간 값(_moveT: 0..1)을 기준으로 0-1-2-1 패턴을 강제.
    if(this.player.moving){
      const t = Math.max(0, Math.min(1, this._moveT||0));
      const phase = Math.min(3, Math.floor(t * 4));
      this.animFrame = [0,1,2,1][phase];
    }else{
      this.animFrame = 0;
    }


    // camera follow (pixel-perfect, no seasick "wobble")
    const px = this._playerRenderX() * this.tileSize;
    const py = this._playerRenderY() * this.tileSize;

    // Use cached CSS size from _resize() to avoid per-frame subpixel fluctuations.
    const cssW = (typeof this._cssW === "number") ? this._cssW : this.canvas.getBoundingClientRect().width;
    const cssH = (typeof this._cssH === "number") ? this._cssH : this.canvas.getBoundingClientRect().height;

    const zoom = this.zoom || 1;
    const viewW = cssW / zoom;
    const viewH = cssH / zoom;

    const targetX = Math.round(px - viewW / 2);
    const targetY = Math.round(py - viewH / 2);

    // Lock follow (GBA-style stable camera). If you want smoothing later, set alpha to < 1.
    const alpha = (typeof this.cameraFollowAlpha === "number") ? this.cameraFollowAlpha : 1.0;
    this.camera.x += (targetX - this.camera.x) * alpha;
    this.camera.y += (targetY - this.camera.y) * alpha;

    // Snap to integer world pixels to prevent subpixel jitter.
    this.camera.x = Math.round(this.camera.x);
    this.camera.y = Math.round(this.camera.y);

    // tileset animation (swap pre-rendered tileset sheets)
    if(this.tilesetImgs && this.tilesetImgs.length>1){
      const step=1/this._tileAnimFps;
      this._tileAnimT+=dt;
      while(this._tileAnimT>=step){
        this._tileAnimT-=step;
        this._tileAnimFrame=(this._tileAnimFrame+1)%this.tilesetImgs.length;
        this.tilesetImg=this.tilesetImgs[this._tileAnimFrame];
      
        if (this.tilesetUpperImgs && this.tilesetUpperImgs.length) {
          this.tilesetUpperImg = this.tilesetUpperImgs[this._tileAnimFrame % this.tilesetUpperImgs.length];
        }
}
    }

    // health ping
    this.netTimer+=dt;
    if(this.netTimer>=1.0){ this.netTimer=0; this._ping(); }

    // mobs poll (for spawn placement testing)
    this.mobNetTimer+=dt;
    if(this.playToken && this.map && this.mobNetTimer>=this.mobPollInterval){
      this.mobNetTimer=0;
      this._fetchMobs();
    }

    // items poll (item balls / hidden items)
    this.itemNetTimer+=dt;
    if(this.itemsEnabled && this.playToken && this.map && this.itemNetTimer>=this.itemPollInterval){
      this.itemNetTimer=0;
      this._fetchItems();
    }
    // mob visual tick (smooth movement + bob)
    this._mobTick();

  
    // cleanup tall grass FX
    if (this._grassFx && this._grassFx.size) {
      const nowFx = performance.now();
      for (const [k, t0] of this._grassFx.entries()) {
        if ((nowFx - t0) > this._grassFxDuration) this._grassFx.delete(k);
      }
    }

  }

  _playerRenderX(){
    if(!this.player.moving) return this.player.x;
    const dx = (this.player.x - this.player.px);
    const dy = (this.player.y - this.player.py);
    const ts = this.tileSize || 16;
    const distPx = (this._moveDistPx && this._moveDistPx>0) ? this._moveDistPx : (Math.abs(dx)+Math.abs(dy))*ts;
    const movePx = Math.min(distPx||0, (this._movePx||0));
    const off = ts>0 ? (movePx / ts) : 0;
    return this.player.px + Math.sign(dx) * off;
  }
  _playerRenderY(){
    if(!this.player.moving) return this.player.y;
    const dx = (this.player.x - this.player.px);
    const dy = (this.player.y - this.player.py);
    const ts = this.tileSize || 16;
    const distPx = (this._moveDistPx && this._moveDistPx>0) ? this._moveDistPx : (Math.abs(dx)+Math.abs(dy))*ts;
    const movePx = Math.min(distPx||0, (this._movePx||0));
    const off = ts>0 ? (movePx / ts) : 0;
    return this.player.py + Math.sign(dy) * off;
  }

  _playerFootTile(){
    const pr = this._playerPriorityState();
    return { x: pr.footX|0, y: pr.footY|0 };
  }

  _draw(){
    const rect=this.canvas.getBoundingClientRect();
    if(!rect.width || !rect.height) return;
    if(!this.map || !this.tilesetImg || !this.playerImg) return;

    const ts=this.tileSize;
    const zoom=this.zoom || 1;

    // Offscreen render at 1x (world pixels), then scale once to the screen.
    const viewW=rect.width/zoom;
    const viewH=rect.height/zoom;

    const offW=Math.max(1, Math.ceil(viewW));
    const offH=Math.max(1, Math.ceil(viewH));
    if(this._off.width!==offW || this._off.height!==offH){
      this._off.width=offW;
      this._off.height=offH;
    }
    const oc=this._offCtx;
    oc.setTransform(1,0,0,1,0,0);
    oc.clearRect(0,0,offW,offH);
    oc.imageSmoothingEnabled=false;

    // Pixel-perfect camera snap (integer world px)
    const camX=Math.floor(this.camera.x);
    const camY=Math.floor(this.camera.y);

    oc.save();
    oc.translate(-camX,-camY);

    const x0=Math.floor(camX/ts)-2;
    const y0=Math.floor(camY/ts)-2;
    const x1=Math.floor((camX+viewW)/ts)+2;
    const y1=Math.floor((camY+viewH)/ts)+2;

    for(let y=y0;y<=y1;y++){
      for(let x=x0;x<=x1;x++){
        const info=this._tileInfoAt(x,y);
        const t=info.tile|0;
        const cols=Math.max(1, (info.cols||16)|0);
        const img=info.img || this._currentTilesetImg();
        const sx=(t%cols)*ts;
        const sy=Math.floor(t/cols)*ts;
        oc.drawImage(img, sx, sy, ts, ts, x*ts, y*ts, ts, ts);
      }
    }

    // Map items (temporary debug render)
    // 사용자 요청: 기본 숨김(파란박스 방지). F3 디버그에서만 임시 표시.
    if(this.itemsEnabled && Array.isArray(this.items) && this.items.length){
      for(const it of this.items){
        const ix = (it.x|0); const iy = (it.y|0);
        if(ix<x0-2 || ix>x1+2 || iy<y0-2 || iy>y1+2) continue;
        const cx = ix*ts + ts/2;
        const cy = iy*ts + ts*0.62;
        oc.save();
        oc.fillStyle = it.visible ? "rgba(80,210,255,0.85)" : "rgba(180,120,255,0.75)";
        oc.beginPath();
        oc.rect(cx - ts*0.22, cy - ts*0.22, ts*0.44, ts*0.44);
        oc.fill();
        oc.strokeStyle = "rgba(0,0,0,0.55)";
        oc.lineWidth = 2;
        oc.stroke();
        if(this.debug){
          const lbl = String(it.item||"");
          oc.font = "bold 8px system-ui";
          oc.textAlign = "center";
          oc.textBaseline = "bottom";
          oc.lineWidth = 3;
          oc.strokeStyle = "rgba(0,0,0,0.85)";
          oc.fillStyle = "rgba(255,255,255,0.95)";
          oc.strokeText(lbl, cx, iy*ts - 2);
          oc.fillText(lbl, cx, iy*ts - 2);
        }
        oc.restore();
      }
    }

    const drawTallGrassCoverAt = (tx, ty) => {
      if(tx == null || ty == null) return;
      if(!this._grassCoverAt(tx, ty)) return;
      const info = this._tileInfoAt(tx, ty);
      if(!info || !info.img) return;
      const t = info.tile|0;
      const cols = Math.max(1, (info.cols||16)|0);
      const sx = (t % cols) * ts;
      const sy = Math.floor(t / cols) * ts;
      const dx = tx * ts;
      const dy = ty * ts;
      oc.drawImage(info.img, sx, sy + Math.floor(ts/2), ts, Math.ceil(ts/2), dx, dy + Math.floor(ts/2), ts, Math.ceil(ts/2));
    };

    const drawSouthOccluderAt = (tx, ty) => {
      if(tx == null || ty == null) return;
      const base = this._tileInfoAt(tx, ty);
      if(!base || !base.img) return;
      const tileId = base.tile|0;
      const upper = this._tileUpperInfoAt(tx, ty);
      const frontMeta = this._frontCoverAt(tx, ty);
      const shouldCover = (frontMeta !== null) ? !!frontMeta : false;
      if(!shouldCover) return;

      const dx = tx * ts;

      // r19: exporter now splits lower/upper metatile sheets.
      // Project the lower half of the UPPER layer onto the tile immediately north.
      // This matches FRLG-style roof/tree/front bands much better than copying from
      // the already-composited base tile.
      if (upper && upper.img) {
        const upperTileId = upper.tile|0;
        const upperCols = Math.max(1, (upper.cols||16)|0);
        const sx = (upperTileId % upperCols) * ts;
        const sy = Math.floor(upperTileId / upperCols) * ts;
        const band = Math.floor(ts / 2);
        const srcY = sy + band;
        const dstY = (ty - 1) * ts + band;
        oc.drawImage(upper.img, sx, srcY, ts, band, dx, dstY, ts, band);
        return;
      }

      // Legacy fallback for older caches that only had the composited base sheet.
      const cols = Math.max(1, (base.cols||16)|0);
      const sx = (tileId % cols) * ts;
      const sy = Math.floor(tileId / cols) * ts;
      const band = Math.max(4, Math.floor(ts * 0.25));
      const srcY = sy + ts - band;
      const dstY = (ty - 1) * ts + ts - band;
      oc.drawImage(base.img, sx, srcY, ts, band, dx, dstY, ts, band);
    };

    const drawNpc = (n)=>{
      const k = (n && n.sprite_key) ? String(n.sprite_key).trim() : "";
      const img = this._getNpcImg(k);
      if(!img || !img.complete || !img.naturalWidth || !img.naturalHeight) return;

      const nx = n.x|0, ny = n.y|0, d = n.dir|0;
      const bx = nx*ts;
      const by = ny*ts;
      const iw = img.naturalWidth|0;
      const ih = img.naturalHeight|0;

      let frameW = 16, frameH = 32;
      let mode = "person";
      if(ih===16){
        frameW = 16; frameH = 16; mode = "small";
      }else if(ih===32 && iw<=64 && (iw%32===0)){
        frameW = 32; frameH = 32; mode = "big";
      }else if(ih===32){
        frameW = 16; frameH = 32; mode = "person";
      }else if(ih===64 && iw%32===0){
        frameW = 32; frameH = 32; mode = "big";
      }

      const cols = Math.max(1, Math.floor(iw / frameW));
      let sx = 0, sy = 0, flip = false;
      if(mode==="person"){
        const walkSeq = [0,1,2,1];
        const isMovingNpc = !!(n && (n.moving || n.walking || n.animating));
        const anim = isMovingNpc ? walkSeq[(this.animFrame|0) % walkSeq.length] : 1;
        const nd = (d===0||d===1||d===2||d===3) ? d : 0;
        const base = (nd===0) ? 0 : (nd===1) ? 3 : 6;
        const col  = Math.min(cols-1, base + anim);
        sx = col * frameW; sy = 0;
        flip = (nd===3);
      }else{
        const col = (cols>=2 && ((this.animFrame|0) % 2 === 1)) ? 1 : 0;
        sx = Math.min(cols-1, col) * frameW; sy = 0;
        flip = false;
      }

      oc.save();
      if(flip){
        oc.scale(-1, 1);
        oc.drawImage(img, sx, sy, frameW, frameH, -bx-frameW, by-(frameH-ts), frameW, frameH);
      }else{
        oc.drawImage(img, sx, sy, frameW, frameH, bx, by-(frameH-ts), frameW, frameH);
      }
      oc.restore();
    };

    const drawPlayer = ()=>{
      const px=Math.round(this._playerRenderX()*ts);
      const pyBase=Math.round(this._playerRenderY()*ts);
      const tJump = Math.max(0, Math.min(1, this._moveT||0));
      const jumpRaise = (this._jumping && this.player.moving) ? (Math.sin(Math.PI*tJump) * 8) : 0;
      const py = Math.round(pyBase - jumpRaise);

      if(this._jumping && this.player.moving){
        const cx = px + ts/2;
        const cy = pyBase + ts - 3;
        oc.save();
        oc.fillStyle = "rgba(0,0,0,0.35)";
        oc.beginPath();
        oc.ellipse(cx, cy, ts*0.32, ts*0.14, 0, 0, Math.PI*2);
        oc.fill();
        oc.restore();
      }

      if(this.playerSprite.kind==="frlg9"){
        const sw=this.playerSprite.frameW, sh=this.playerSprite.frameH;
        const f=this.animFrame%3;
        const d=this.player.dir;
        const framesByDir = (d===0) ? [0,3,4]
                          : (d===1) ? [1,5,6]
                          :           [2,7,8];
        const idx = framesByDir[f] ?? framesByDir[0];
        const flip = (d===3);
        const sx=idx*sw;
        const sy=0;
        oc.save();
        if(flip){
          oc.scale(-1, 1);
          oc.drawImage(this.playerImg, sx, sy, sw, sh, -px-sw, py-(sh-ts), sw, sh);
        }else{
          oc.drawImage(this.playerImg, sx, sy, sw, sh, px, py-(sh-ts), sw, sh);
        }
        oc.restore();
      }else{
        const sprW=16, sprH=24;
        const fx=this.animFrame;
        const dir=this.player.dir;
        oc.drawImage(this.playerImg, fx*sprW, dir*sprH, sprW, sprH, px, py-(sprH-ts), sprW, sprH);
      }
    };

    const drawMob = (m)=>{
      const p = this._mobPos(m);
      const mx = +p.x;
      const my = +p.y;
      const imx = Math.floor(mx);
      const imy = Math.floor(my);
      if(imx<x0-2 || imx>x1+2 || imy<y0-2 || imy>y1+2) return;

      const img = this._getMobIconImg(m.species_key||'', m.species_id);
      const drawW = this._mobIconDrawSize || 32;
      const drawH = this._mobIconDrawSize || 32;
      const dx = Math.round(mx*ts + Math.floor((ts - drawW)/2));
      const dy = Math.round(my*ts + Math.floor(ts - drawH) + (p.bob||0));
      const seed = (p.seed|0);
      const div = p.moving ? 4 : 12;
      const iconFrame = (Math.floor((this.frame + (seed & 63)) / div) & 1);

      if(img && img.complete && img.naturalWidth>0){
        const sw = 32;
        const sh = 32;
        const sx = 0;
        const sy = iconFrame * this._mobIconFrameH;
        if(p.flipX){
          oc.save();
          oc.translate(dx + drawW, dy);
          oc.scale(-1, 1);
          oc.drawImage(img, sx, sy, sw, sh, 0, 0, drawW, drawH);
          oc.restore();
        }else{
          oc.drawImage(img, sx, sy, sw, sh, dx, dy, drawW, drawH);
        }
      }else{
        const cx = mx*ts + ts/2;
        const cy = my*ts + ts*0.62;
        oc.save();
        oc.fillStyle = "rgba(255,64,64,0.80)";
        oc.beginPath();
        oc.arc(cx, cy, 3.5, 0, Math.PI*2);
        oc.fill();
        oc.restore();
      }

      if(this.debug){
        const cx = mx*ts + ts/2;
        const lbl = "#" + (m.mob_id||0) + " S" + (m.species_id||0) + " L" + (m.level||0);
        oc.save();
        oc.font = "bold 8px system-ui";
        oc.textAlign = "center";
        oc.textBaseline = "bottom";
        oc.lineWidth = 3;
        oc.strokeStyle = "rgba(0,0,0,0.85)";
        oc.fillStyle = "rgba(255,255,255,0.95)";
        oc.strokeText(lbl, cx, my*ts - 2);
        oc.fillText(lbl, cx, my*ts - 2);
        oc.restore();
      }
    };

    const rowBuckets = new Map();
    const queueRowDraw = (row, sortX, drawFn, cover=null, southCover=null) => {
      const ry = row|0;
      let bucket = rowBuckets.get(ry);
      if(!bucket){
        bucket = [];
        rowBuckets.set(ry, bucket);
      }
      bucket.push({ x: +sortX || 0, draw: drawFn, cover, southCover });
    };

    if(Array.isArray(this.mobs) && this.mobs.length){
      for(const m of this.mobs){
        const p = this._mobPos(m);
        const mx = +p.x;
        const my = +p.y;
        const pr = getActorPriorityState({
          map: this.map,
          renderX: mx,
          renderY: my,
          dir: p.dir|0,
          moving: !!p.moving,
          behaviorAt: (tx, ty) => this._behaviorAt(tx, ty),
        });
        queueRowDraw(pr.sortRow, mx, ()=>drawMob(m), pr.grassCover, pr.southOccluder);
      }
    }

    const npcs = Array.isArray(this.npcs) ? this.npcs : [];
    for(const n of npcs){
      if(!n) continue;
      const nx=n.x|0, ny=n.y|0;
      const pr = getActorPriorityState({
        map: this.map,
        renderX: nx,
        renderY: ny,
        dir: (n.dir|0),
        moving: !!(n.moving || n.walking || n.animating),
        behaviorAt: (tx, ty) => this._behaviorAt(tx, ty),
      });
      queueRowDraw(pr.sortRow, nx, ()=>drawNpc(n), pr.grassCover, pr.southOccluder);
    }

    const pPriority = this._playerPriorityState();
    queueRowDraw(pPriority.sortRow, this._playerRenderX(), ()=>drawPlayer(), pPriority.grassCover, pPriority.southOccluder);

    for(let ty=y0; ty<=y1; ty++){
      const bucket = rowBuckets.get(ty);
      if(bucket && bucket.length){
        bucket.sort((a,b)=>(a.x-b.x));
        for(const op of bucket){
          try{ op.draw(); }catch(_e){}
        }
      }

      for(let tx=x0; tx<=x1; tx++){
        const info = this._tileUpperInfoAt(tx, ty);
        if(!info || !info.img) continue;
        const t = info.tile|0;
        const cols = info.cols||16;
        const sx = (t % cols) * ts;
        const sy = Math.floor(t / cols) * ts;
        oc.drawImage(info.img, sx, sy, ts, ts, tx*ts, ty*ts, ts, ts);
      }

      if(bucket && bucket.length){
        for(const op of bucket){
          if(op.southCover) drawSouthOccluderAt(op.southCover.x, op.southCover.y);
          if(op.cover) drawTallGrassCoverAt(op.cover.x, op.cover.y);
        }
      }
    }

    // Tall grass FX (rustle)
    this._drawTallGrassFx(oc, x0, y0, x1, y1);

    // Name label (above the player)
    const _nm = String(this.playerName||"").trim();
    if(_nm){
      const _labelPx = Math.round(this._playerRenderX()*ts);
      const _labelPyBase = Math.round(this._playerRenderY()*ts);
      const _labelJumpT = Math.max(0, Math.min(1, this._moveT||0));
      const _labelJumpRaise = (this._jumping && this.player.moving) ? (Math.sin(Math.PI*_labelJumpT) * 8) : 0;
      const _labelPy = Math.round(_labelPyBase - _labelJumpRaise);
      const tx = _labelPx + ts/2;
      const ty = _labelPy - 2;
      const shown = (_nm.length>12) ? (_nm.slice(0,12)+"…") : _nm;
      oc.save();
      oc.font = "bold 8px system-ui";
      oc.textAlign = "center";
      oc.textBaseline = "bottom";
      oc.lineWidth = 3;
      oc.strokeStyle = "rgba(0,0,0,0.85)";
      oc.fillStyle = "rgba(255,255,255,0.95)";
      oc.strokeText(shown, tx, ty);
      oc.fillText(shown, tx, ty);
      oc.restore();
    }

    // Fishing FX (cast + rise)
    if(this._fishFx){
      const fx=this._fishFx;
      const tms=performance.now() - (fx.startMs||0);
      const ts=this.tileSize;
      const pxw=(this.player.x|0)*ts + ts/2;
      const pyw=(this.player.y|0)*ts + ts/2;
      const txw=(fx.tx|0)*ts + ts/2;
      const tyw=(fx.ty|0)*ts + ts/2;

      oc.save();
      oc.strokeStyle="rgba(255,255,255,0.85)";
      oc.lineWidth=2;
      oc.beginPath();
      oc.moveTo(pxw, pyw);
      oc.lineTo(txw, tyw);
      oc.stroke();

      // bobber (simple dot)
      const bob = Math.sin(tms/120) * 1.5;
      oc.fillStyle="rgba(255,80,80,0.9)";
      oc.beginPath();
      oc.arc(txw, tyw + bob, 2.4, 0, Math.PI*2);
      oc.fill();

      // rising pokemon icon
      if(fx.bite && tms >= (fx.riseStartMs||450)){
        const riseT = Math.max(0, Math.min(1, (tms-(fx.riseStartMs||450)) / (fx.riseDurMs||600)));
        const yoff = (1-riseT)*10 + riseT*18;
        const img=this._getMobIconImg(fx.species_key||"", fx.species_id||0);
        if(img && img.complete && img.naturalWidth>0){
          const frameW=32, frameH=32;
          const drawSz=this._mobIconDrawSize||32;
          oc.drawImage(img, 0, 0, frameW, frameH, Math.round(txw-drawSz/2), Math.round((tyw+bob)-drawSz/2 - yoff), drawSz, drawSz);
        }
      }
      oc.restore();
    }



    oc.restore();

    this._drawDialogUI(oc, offW, offH);

    // Blit scaled to the screen (single scale -> no seams between tiles)
    const ctx=this.ctx;
    const dpr = this.dpr || (window.devicePixelRatio || 1);
    ctx.setTransform(dpr,0,0,dpr,0,0);
    ctx.clearRect(0,0,rect.width,rect.height);
    ctx.imageSmoothingEnabled=false;
    ctx.drawImage(this._off, 0,0,offW,offH, 0,0,rect.width,rect.height);

    if(this.debug){
      ctx.font="12px system-ui";
      ctx.fillStyle="rgba(255,255,255,.85)";
      const pid = (this.playerId===null||this.playerId===undefined) ? '?' : this.playerId;
      ctx.fillText(`player#${pid} (${this.player.x},${this.player.y}) map=${this.map.map_id} zoom ${this.zoom.toFixed(2)} anim=${this._tileAnimFrame}`, 10, 18);
    }
  }

  _syncPayload(){
    return {
      map_id:(this.map && this.map.map_id) ? this.map.map_id : "overworld_demo",
      x:(this.player && Number.isFinite(this.player.x)) ? (this.player.x|0) : 0,
      y:(this.player && Number.isFinite(this.player.y)) ? (this.player.y|0) : 0,
      dir:(this.player && Number.isFinite(this.player.dir)) ? (this.player.dir|0) : 0,
      tick:(this.frame|0),
    };
  }

  async _upsert(){
    const payload = this._syncPayload();
    const key = `${payload.map_id}:${payload.x}:${payload.y}:${payload.dir}`;
    this._syncLatest = payload;

    if(this._syncInFlight){
      this._syncQueued = true;
      return this._syncPromise || Promise.resolve();
    }

    if(key === this._lastSyncKey){
      return this._syncPromise || Promise.resolve();
    }

    this._syncInFlight = true;
    this._syncPromise = (async()=>{
      try{
        do{
          this._syncQueued = false;
          const send = this._syncLatest || this._syncPayload();
          const sendKey = `${send.map_id}:${send.x}:${send.y}:${send.dir}`;

          if(sendKey === this._lastSyncKey) continue;

          let r = null;
          try{
            r = await fetch(`${this.apiBase}/rt/upsert.php`,{
              method:"POST",
              headers:{
                "Content-Type":"application/json",
                ...(this.playToken ? {"Authorization": `Bearer ${this.playToken}`} : {})
              },
              body:JSON.stringify(send)
            });
          }catch(e){
            this.status("서버 연결 실패");
            continue;
          }

          if(r.ok){
            this._lastSyncKey = sendKey;
            this.status("DB 동기화 OK");
            continue;
          }

          const j = await r.json().catch(()=>null);
          const err = (j && (j.error || j.err)) ? (j.error || j.err) : `${r.status}`;
          this.status(`DB 동기화 실패: ${err}`);
          if(r.status===400 || r.status===429){
            this._syncQueued = false;
            this._queuedDir = null;
            ["ArrowUp","ArrowDown","ArrowLeft","ArrowRight"].forEach(k=>this.keys.delete(k));
            this._syncLockUntil = performance.now() + 650;
            this._loading = true;
            try{
              await this._resyncFromServerState();
            }finally{
              this._loading = false;
            }
          }
        }while(this._syncQueued);
      }finally{
        this._syncInFlight = false;
        this._syncPromise = null;
      }
    })();
    return this._syncPromise;
  }

  async _fetchMobs(){
    try{
      const r=await fetch(`${this.apiBase}/rt/map_mobs.php`,{
        cache:"no-store",
        headers:{
          ...(this.playToken ? {"Authorization": `Bearer ${this.playToken}`} : {})
        }
      });
      if(!r.ok) return;
      const j=await r.json();
      if(j && j.ok && Array.isArray(j.mobs)){
        this._mobIngest(j.mobs);
      }
    }catch(e){}
  }

async _fetchItems(){
  try{
    const debug = this.debug ? 1 : 0;
    const r=await fetch(`${this.apiBase}/rt/map_items.php?debug=${debug}`,{
      cache:"no-store",
      headers:{
        ...(this.playToken ? {"Authorization": `Bearer ${this.playToken}`} : {})
      }
    });
    if(!r.ok) return;
    const j=await r.json();
    if(j && j.ok && Array.isArray(j.items)){
      this.items=j.items;
    }
  }catch(e){}
}

async _actionInteract(){
  if(!this.playToken || !this.map) return;
  const now = Date.now();
  if(now - (this._lastActionAt||0) < 250) return;
  this._lastActionAt = now;

  try{
    const r = await fetch(`${this.apiBase}/rt/pick_item.php`,{
      method:"POST",
      headers:{
        "Content-Type":"application/json",
        ...(this.playToken ? {"Authorization": `Bearer ${this.playToken}`} : {})
      },
      body: JSON.stringify({ mode:"front" })
    });
    const j = await r.json().catch(()=>null);
    if(r.ok && j && j.ok){
      const p = j.picked || {};
      const msg = `획득: ${p.item||"ITEM"} x${p.qty||1}`;
      this.status(msg);
      this._log(msg);
      this._fetchItems();
      return;
    }

    // Not an item ball in front; try hidden item (front)
    const r2 = await fetch(`${this.apiBase}/rt/pick_item.php`,{
      method:"POST",
      headers:{
        "Content-Type":"application/json",
        ...(this.playToken ? {"Authorization": `Bearer ${this.playToken}`} : {})
      },
      body: JSON.stringify({ mode:"front", kind:"hidden_item" })
    });
    const j2 = await r2.json().catch(()=>null);
    if(r2.ok && j2 && j2.ok){
      const p2 = j2.picked || {};
      const msg2 = `숨은 아이템: ${p2.item||"ITEM"} x${p2.qty||1}`;
      this.status(msg2);
      this._log(msg2);
      this._fetchItems();
      return;
    }

    // NPC in front (rAthena-style script/npc)
    const ft = this._frontTile();
    const npc = this._npcAtTile(ft.x, ft.y);
    if(npc){
      await this._runNpcEvent(npc);
      return;
    }

    if(j && j.error === 'ALREADY_PICKED'){
      this.status('이미 획득함');
      return;
    }

    // else ignore (could be NPC/etc in the future)
  }catch(e){}
}

async _ping(){

    try{
      const r=await fetch(`${this.apiBase}/util/health.php`,{cache:"no-store"});
      if(r.ok) this.status("서버 OK");
    }catch(e){}
  }
}
