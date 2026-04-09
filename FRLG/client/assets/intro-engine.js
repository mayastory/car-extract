class FRLGEngine {
  constructor(canvas){
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d');
    this.state = 'title';
    this.menuIndex = 0;
    this.menu = ['NEW GAME', 'CONTINUE', 'RESET CLIENT'];
    this.text = '깨진 오크/필드 패치를 모두 걷어낸 초기화 빌드입니다.';
    this.text2 = '다음 패치부터 oak_speech.c 기준으로 다시 포팅합니다.';
    this.pointerVisible = true;
    this.blinkAt = 0;
    this.onStateChange = null;
    this.onSaveLabelChange = null;
    this.hasSave = false;
    this.loop = this.loop.bind(this);
    requestAnimationFrame(this.loop);
  }
  setState(next){
    this.state = next;
    if (this.onStateChange) this.onStateChange(next);
  }
  handleInput(key){
    if (key === 'r' || key === 'R'){ this.reset(); return; }
    if (this.state === 'title') return this.handleTitle(key);
    if (this.state === 'main-menu') return this.handleMenu(key);
    if (this.state === 'oak-reset') return this.handleResetInfo(key);
  }
  handleTitle(key){
    if (key === 'Enter' || key === ' '){ this.setState('main-menu'); }
  }
  handleMenu(key){
    if (key === 'ArrowUp') this.menuIndex = (this.menuIndex + this.menu.length - 1) % this.menu.length;
    else if (key === 'ArrowDown') this.menuIndex = (this.menuIndex + 1) % this.menu.length;
    else if (key === 'Escape') this.setState('title');
    else if (key === 'Enter' || key === ' '){
      if (this.menuIndex === 0) this.setState('oak-reset');
      else if (this.menuIndex === 1) this.text = 'continue는 잠시 비활성화했습니다. 먼저 오크 구간을 소스 기준으로 다시 만듭니다.';
      else this.reset();
    }
  }
  handleResetInfo(key){
    if (key === 'Escape') this.setState('main-menu');
    else if (key === 'Enter' || key === ' ') {
      this.text = '다음 작업 순서: oak-intro → gender-select → post-gender';
      this.text2 = '임시 배치 없이 source-driven으로 한 화면씩 다시 붙입니다.';
    }
  }
  reset(){
    this.menuIndex = 0;
    this.text = '깨진 오크/필드 패치를 모두 걷어낸 초기화 빌드입니다.';
    this.text2 = '다음 패치부터 oak_speech.c 기준으로 다시 포팅합니다.';
    this.setState('title');
  }
  loop(ts){
    if (ts - this.blinkAt > 500){ this.pointerVisible = !this.pointerVisible; this.blinkAt = ts; }
    this.render();
    requestAnimationFrame(this.loop);
  }
  render(){
    const ctx = this.ctx;
    ctx.clearRect(0,0,this.canvas.width,this.canvas.height);
    this.drawBackdrop();
    if (this.state === 'title') this.drawTitle();
    else if (this.state === 'main-menu') this.drawMainMenu();
    else if (this.state === 'oak-reset') this.drawResetNotice();
  }
  drawBackdrop(){
    const ctx=this.ctx;
    const w=this.canvas.width,h=this.canvas.height;
    ctx.fillStyle='#08121d'; ctx.fillRect(0,0,w,h);
    const g=ctx.createLinearGradient(0,0,0,h);
    g.addColorStop(0,'#0b1f32'); g.addColorStop(1,'#08111a');
    ctx.fillStyle=g; ctx.fillRect(24,24,w-48,h-48);
    ctx.strokeStyle='#18384f'; ctx.lineWidth=2; ctx.strokeRect(24,24,w-48,h-48);
  }
  drawTitle(){
    const ctx=this.ctx;
    ctx.fillStyle='#f6fbff'; ctx.font='bold 42px Arial'; ctx.textAlign='center';
    ctx.fillText('POKéMON FIRE RED / LEAF GREEN', this.canvas.width/2, 150);
    ctx.fillStyle='#9fd9ff'; ctx.font='20px Arial';
    ctx.fillText('client reset build', this.canvas.width/2, 185);
    this.drawBox(120,260,480,110);
    ctx.textAlign='left'; ctx.fillStyle='#2b3a48'; ctx.font='26px Arial';
    ctx.fillText('Enter / A 로 시작', 150, 315);
    ctx.fillStyle='#51697d'; ctx.font='18px Arial';
    ctx.fillText('오크 구간은 임시 구현을 모두 제거하고 다시 시작합니다.', 150, 348);
  }
  drawMainMenu(){
    const ctx=this.ctx;
    ctx.fillStyle='#f6fbff'; ctx.font='bold 28px Arial'; ctx.textAlign='center';
    ctx.fillText('MAIN MENU', this.canvas.width/2, 100);
    this.drawBox(210,140,300,180);
    ctx.textAlign='left'; ctx.font='26px Arial';
    for(let i=0;i<this.menu.length;i++){
      const y=190 + i*46;
      ctx.fillStyle='#2b3a48';
      ctx.fillText(this.menu[i], 270, y);
      if (i===this.menuIndex && this.pointerVisible){
        ctx.fillStyle='#d43b3b';
        ctx.beginPath(); ctx.moveTo(238, y-16); ctx.lineTo(258, y-8); ctx.lineTo(238, y); ctx.closePath(); ctx.fill();
      }
    }
  }
  drawResetNotice(){
    const ctx=this.ctx;
    ctx.fillStyle='#f6fbff'; ctx.font='bold 30px Arial'; ctx.textAlign='center';
    ctx.fillText('OAK SEQUENCE RESET', this.canvas.width/2, 96);
    this.drawBox(90,140,540,210);
    ctx.textAlign='left'; ctx.fillStyle='#2b3a48'; ctx.font='24px Arial';
    wrapText(ctx, this.text, 120, 195, 480, 34);
    ctx.font='22px Arial'; wrapText(ctx, this.text2, 120, 255, 480, 32);
    ctx.fillStyle='#546c7f'; ctx.font='18px Arial';
    ctx.fillText('ESC / MENU : 메인 메뉴', 120, 318);
    ctx.fillText('ENTER / A : 작업 순서 표시', 120, 344);
  }
  drawBox(x,y,w,h){
    const ctx=this.ctx;
    ctx.fillStyle='#f3f5ef'; ctx.fillRect(x,y,w,h);
    ctx.strokeStyle='#48657c'; ctx.lineWidth=4; ctx.strokeRect(x,y,w,h);
  }
}
function wrapText(ctx, text, x, y, maxWidth, lineHeight){
  const words = text.split(' ');
  let line='';
  for (let i=0;i<words.length;i++){
    const test=line ? line+' '+words[i] : words[i];
    if (ctx.measureText(test).width > maxWidth && line){
      ctx.fillText(line, x, y); line = words[i]; y += lineHeight;
    } else line = test;
  }
  if (line) ctx.fillText(line, x, y);
}
window.FRLGEngine = FRLGEngine;
