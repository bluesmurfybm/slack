/* BlueLedger 출장여비 화면. 파이썬 버전(travel/static/index.html)의 스크립트를 그대로 옮겼고,
   바뀐 곳은 서버 주소(api.php?p=…)·CSRF 토큰·포털 로그인·영수증 올리기(multipart)·권한 설정뿐이다.
   계산(주유비·식대 기준·합계)은 화면과 서버가 같은 규칙을 쓴다 — 합계는 서버가 다시 계산한다. */
(function(){
const $=s=>document.querySelector(s);
const F=id=>document.getElementById(id);
const won=n=>(Math.round(n||0)).toLocaleString('ko-KR');
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const today=()=>{const d=new Date();d.setMinutes(d.getMinutes()-d.getTimezoneOffset());return d.toISOString().slice(0,10)};
const parseNum=v=>{const n=Number(String(v??'').replace(/[^\d.-]/g,''));return isFinite(n)?n:0};

const DEFAULT_SETTINGS={staff:[],approverName:'김호영',handlerName:'김지안',fuel:{gas:1866.16,diesel:1849.17,lpg:1103.31},eff:10,priceBase:'2026년 8월 1주',mealUnit:15000};
const S={trips:[],settings:structuredClone(DEFAULT_SETTINGS),year:String(new Date().getFullYear()),editingId:null,q:'',fStatus:'all',companions:new Set(),approval:'승인대기',status:'미처리',receipts:emptyRc()};
let store=null,assets=null;
// realRole: 서버가 준 권한(admin 정산·approver 승인·staff). role: 지금 화면의 권한 —
// 관리자도 '구성원' 화면에서는 staff 와 똑같이 보고 쓴다. 서버 권한은 그대로다.
S.role='local';S.realRole='staff';S.view='member';S.allTrips=[];S.uid=null;
const REVIEW_KEYS=['approval','approver','approvedAt','status','handler','processedAt','payMethod','note'];
const isAdmin=()=>S.role!=='staff';
const isSettler=()=>S.role==='admin';
function emptyRc(){return {transport:[],toll:[],meal:[],etc:[]}}
function rcCount(t){const r=t.receipts||{};return ['transport','toll','meal','etc'].reduce((a,k)=>a+((r[k]||[]).length),0)}

function toast(msg){const t=$('#toast');t.textContent=msg;t.classList.add('on');clearTimeout(toast._t);toast._t=setTimeout(()=>t.classList.remove('on'),2200)}

/* ---------- 저장소 ---------- */
// 서버는 api.php 하나다. 'state' → api.php?p=state. 쓰기 요청에는 CSRF 토큰을 싣는다.
const CSRF=document.querySelector('meta[name="csrf-token"]').content;
const apiUrl=(p,q)=>'api.php?p='+encodeURIComponent(p)+(q?'&'+q:'');
async function api(path,body){
  const opt={credentials:'same-origin'};
  if(body!==undefined){
    opt.method='POST';opt.headers={'X-CSRF-Token':CSRF};
    if(body instanceof FormData)opt.body=body;
    else{opt.headers['Content-Type']='application/json';opt.body=JSON.stringify(body)}
  }
  const r=await fetch(apiUrl(path),opt);
  let j={};try{j=await r.json()}catch(e){}
  // 포털에서 로그아웃했거나 세션이 끝났다 — 로그인 화면으로 보낸다
  if(r.status===401){location.href='../index.php?need_login=blueledger';throw new Error(j.error||'로그인이 필요합니다.')}
  if(!r.ok)throw new Error(j.error||'요청을 처리하지 못했습니다.');
  return j;
}
async function loadState(){
  const j=await api('state');S.lastLoad=Date.now();
  if(j.me&&j.me.role!==S.realRole){startAs(j.me);return}
  S.allTrips=j.trips||[];S.trips=viewTrips();S.settings={...structuredClone(DEFAULT_SETTINGS),...(j.settings||{})};
  renderStaff();if(!S.editingId&&!F('traveler').value)presetMe();updateFuelInfo();updateMealHint();render();renderInfo();if(S.editingId)refreshLock();
}
async function initStore(){
  assets=true;
  store={
    save:async(id,data)=>{await api('trips',{id:id.startsWith('new_')?'':id,data});await loadState()},
    remove:async id=>{await api('trips/delete',{id});await loadState()},
    saveSettings:async d=>{await api('settings',d);await loadState()}
  };
  // 로그인은 포털이 한다. 여기 왔다면 이미 로그인한 상태고, 첫 state 응답에 내 이름·권한이 온다.
  let first=null;
  try{first=await api('state')}catch(e){toast('서버에 연결할 수 없습니다. 잠시 뒤 새로고침해 주세요.');return}
  await startAs(first.me);
}
async function startAs(u){
  S.me=u.name;S.realRole=u.role;
  // 관리자도 처음엔 구성원 화면으로 연다. 관리자 화면은 전환 탭으로 간다
  if(u.role==='staff')S.view='member';
  // 팝업(?trip=)으로 열렸으면 관리자 화면 권한으로 그 청구서 한 장만 보인다
  if(TRIP_PARAM&&u.role!=='staff'){S.single=TRIP_PARAM;S.view='admin';document.body.dataset.single='1'}
  F('modeSeg').hidden=u.role==='staff'||!!S.single;
  setViewRole();
  updateAttUI();
  await loadState().catch(e=>toast(e.message));
  if(S.single){
    const t=S.trips.find(x=>x.id===S.single);
    if(t)showSingle(t);else showSingleGone('청구서를 찾을 수 없습니다. 이미 삭제되었을 수 있습니다.');
  }
  clearInterval(S.poll);S.poll=setInterval(()=>{if(!document.hidden)loadState().catch(()=>{})},20000);
}
/* ---------- 구성원 / 관리자 화면 전환 (관리자 계정만) ---------- */
const isMine=t=>t.createdBy===S.me||t.traveler===S.me;
function viewTrips(){return S.role==='staff'?S.allTrips.filter(isMine):S.allTrips}
function setViewRole(){
  S.role=S.view==='admin'?S.realRole:'staff';
  document.body.dataset.role=S.role;
  F('modeSeg').querySelectorAll('[data-mode]').forEach(b=>b.setAttribute('aria-selected',b.dataset.mode===S.view));
  F('rulePanel').open=S.role==='staff';
  applyRole();
}
F('modeSeg').addEventListener('click',e=>{
  const b=e.target.closest('[data-mode]');if(!b||S.realRole==='staff'||b.dataset.mode===S.view)return;
  S.view=b.dataset.mode;setViewRole();S.trips=viewTrips();
  S.fStatus='all';F('fStatus').value='all';clearForm();render();renderInfo();
  if(S.role!=='staff'){switchTab('dash');loadState().catch(()=>{}).finally(showPending)}
});
/* ---------- 관리자 화면: 청구서는 팝업 창으로 ----------
   목록·처리할 청구에서 건을 누르면 travel.php?trip=<id> 를 청구서 크기의 팝업 창으로, 지금 창 가운데에 연다.
   창 이름을 건마다 정해 두어 같은 건을 다시 누르면 새로 늘지 않고 그 창이 앞으로 온다.
   팝업은 그 청구서 한 장만 보이고(S.single), 저장·삭제하면 이 창(opener)에 알려 목록을 바로 새로 받게 한다. */
const TRIP_PARAM=new URLSearchParams(location.search).get('trip')||'';
/* 대기 건 차례로 처리하기(&q=wait|settle) — 팝업의 승인 대기·정산 대기 칸을 누르면 가장 오래된 건부터 연다.
   그 건을 처리하면(승인·반려, 정산 처리완료) 같은 창에서 다음 건으로 넘어간다 */
const QUEUES={wait:{label:'승인 대기',has:t=>(t.approval||'승인대기')==='승인대기'},settle:{label:'정산 대기',has:t=>t.approval==='승인'&&t.status!=='처리완료'}};
S.queue=QUEUES[new URLSearchParams(location.search).get('q')]?new URLSearchParams(location.search).get('q'):'';
function queueTrips(q){return S.trips.filter(QUEUES[q].has).sort((a,b)=>String(a.date).localeCompare(String(b.date))||String(a.id).localeCompare(String(b.id)))}
function showSingle(t){
  S.single=t.id;fillForm(t);document.title=`${t.traveler} ${t.date} · BlueExpense`;
  history.replaceState(null,'','travel.php?trip='+encodeURIComponent(t.id)+(S.queue?'&q='+S.queue:''));
  const tag=F('queueTag'),list=S.queue?queueTrips(S.queue):[],i=list.findIndex(x=>x.id===t.id);
  tag.hidden=!S.queue;tag.textContent=S.queue?`${QUEUES[S.queue].label} ${i<0?'처리됨':`${i+1} / ${list.length}`}`:'';
}
// 팝업에서 저장한 뒤 — 차례로 처리하는 중이고 이 건이 처리됐으면 다음 건으로, 아니면 그 건을 다시 보여 준다
function afterSingleSave(){
  tellOpener();
  const t=S.trips.find(x=>x.id===S.single);
  if(S.queue&&t&&!QUEUES[S.queue].has(t)){
    const next=queueTrips(S.queue)[0];
    if(next){showSingle(next);setTimeout(()=>toast(`다음 ${QUEUES[S.queue].label} 건입니다 · 남은 ${queueTrips(S.queue).length}건`),1600);return}
    showSingleGone(`${QUEUES[S.queue].label} 건을 모두 처리했습니다.`);return;
  }
  if(t)showSingle(t);
}
function openTrip(t,q=''){
  // 화면 크기를 못 알려 주는 브라우저(0)도 있어 최소 크기를 둔다
  const w=Math.max(420,Math.min(560,(screen.availWidth||1280)-40)),h=Math.max(600,Math.min(960,(screen.availHeight||900)-60));
  const left=Math.max(0,Math.round(window.screenX+(window.outerWidth-w)/2)),top=Math.max(0,Math.round(window.screenY+(window.outerHeight-h)/2));
  const win=window.open('travel.php?trip='+encodeURIComponent(t.id)+(q?'&q='+q:''),q?'bl_queue_'+q:'bl_trip_'+t.id,`popup=yes,width=${w},height=${h},left=${left},top=${top}`);
  if(win)win.focus();else toast('팝업 창을 열 수 없습니다. 브라우저의 팝업 차단을 풀어 주세요');
}
function tellOpener(){try{if(window.opener&&!window.opener.closed)window.opener.postMessage({bl:'reload'},location.origin)}catch(e){}}
window.addEventListener('message',e=>{if(e.origin===location.origin&&e.data&&e.data.bl==='reload'&&!S.single)loadState().catch(()=>{})});
function showSingleGone(msg){
  $('.slip').innerHTML=`<div class="gone"><b>${esc(msg)}</b><span>이 창은 닫아도 됩니다.</span><button type="button" class="btn" id="btnGoneClose">창 닫기</button></div>`;
  F('btnGoneClose').addEventListener('click',closeSingle);
}
function closeSingle(){window.close();setTimeout(()=>{location.href='travel.php'},150)}   // 스크립트가 연 창이 아니면 닫히지 않는다 — 그땐 목록으로
F('btnSlipClose').addEventListener('click',closeSingle);
// 다른 창에서 승인·정산하고 돌아오면 목록을 바로 새로 받는다(20초 주기를 기다리지 않게)
S.lastLoad=0;
window.addEventListener('focus',()=>{if(!S.single&&Date.now()-S.lastLoad>2000)loadState().catch(()=>{})});
// 상단바 사용자 메뉴
F('hdrChip').addEventListener('click',e=>{e.stopPropagation();F('hdrUserMenu').classList.toggle('open')});
document.addEventListener('click',e=>{if(!F('hdrUserMenu').contains(e.target))F('hdrUserMenu').classList.remove('open')});
function pick(o,keys){const r={};keys.forEach(k=>{if(o[k]!==undefined)r[k]=o[k]});return r}
function omit(o,keys){const r={...o};keys.forEach(k=>delete r[k]);return r}
function presetMe(){if(isAdmin())return;const me=S.me||'';
  if(me&&staffList().includes(me)){F('traveler').value=me;renderChips()}}
function applyRole(){
  const staff=!isAdmin();
  F('fsApproval').disabled=S.role!=='approver';F('fsSettle').disabled=!isSettler();
  // 승인 관리자는 승인하러 들어온다 — 관리자 화면에서는 승인 칸을 청구서 맨 위로. 그 밖에는 원래 자리(명세 아래)
  if(S.role==='approver')F('form').prepend(F('fsApproval'));else F('fsMoney').after(F('fsApproval'));
  F('queueTitle').firstChild.textContent=staff?'진행 중인 내 청구':'처리할 청구';
  refreshLock();
}
function refreshLock(){
  const staff=!isAdmin(),t=S.trips.find(x=>x.id===S.editingId);
  const locked=(staff&&t&&(t.approval||'승인대기')!=='승인대기')||S.role==='approver';
  F('fsInfo').disabled=!!locked;F('fsMoney').disabled=!!locked;
  F('btnSave').hidden=!!locked;
  F('btnDel').hidden=!S.editingId||!isSettler();
  // '새로 쓰기'는 열어 둔 청구가 있을 때만. 관리자(승인) 화면은 승인만 하므로 두지 않는다
  F('btnNew').hidden=!S.editingId||S.role!=='staff';
  // 관리자(승인·정산): 위쪽 승인 칸과 '변경 저장' 대신 아래 [반려][승인]만 둔다.
  // 승인 관리자는 지금 상태와 같은 단추를 '승인됨/반려됨'으로 막는다. 정산 관리자는 [승인]으로 정산 내용도 저장하므로
  // 이미 승인된 건에서도 [승인]을 누를 수 있다(2026-10-26 지안 님 요청: 삭제·승인·반려만)
  const ap=isAdmin()&&!!S.editingId,cur=t?(t.approval||'승인대기'):'',lockSame=S.role==='approver';
  if(isAdmin())F('btnSave').hidden=true;
  F('btnApprove').hidden=F('btnReject').hidden=!ap;
  F('btnApprove').textContent=lockSame&&cur==='승인'?'승인됨':'승인';F('btnApprove').disabled=lockSame&&cur==='승인';
  F('btnReject').textContent=lockSame&&cur==='반려'?'반려됨':'반려';F('btnReject').disabled=lockSame&&cur==='반려';
  const msg=F('lockMsg');
  if(S.role==='approver'){msg.hidden=false;msg.textContent=S.editingId?`지금 ${cur}입니다. 내용과 영수증을 확인하고 아래 승인 또는 반려를 누르면 바로 저장됩니다.`:'목록이나 처리할 청구에서 승인할 건을 눌러 주세요.'}
  else if(locked){msg.hidden=false;msg.textContent=t.approval==='반려'?'반려된 청구입니다. 내용을 고쳐 새 청구서로 다시 올려 주세요.':'승인된 청구는 수정할 수 없습니다. 고칠 내용이 있으면 관리자에게 알려 주세요.'}
  else if(staff){msg.hidden=false;msg.textContent='승인과 정산은 관리자가 처리합니다. 승인 전까지는 내용을 고칠 수 있습니다.'}
  else if(ap){msg.hidden=false;msg.textContent=`지금 ${cur}입니다. 고친 내용과 정산 칸은 아래 승인(또는 반려)을 누를 때 함께 저장됩니다.`}
  else msg.hidden=true;
  document.querySelectorAll('.att').forEach(a=>a.style.visibility=locked?'hidden':'');
  document.querySelectorAll('.th .x').forEach(b=>b.hidden=!!locked);
}

/* ---------- 폼 ---------- */
function staffList(){
  const set=new Set(S.settings.staff||[]);
  return [...set];
}
function renderStaff(){
  const sel=F('traveler'),cur=sel.value;
  const list=staffList();
  if(cur&&!list.includes(cur))list.push(cur);
  sel.innerHTML='<option value="">선택</option>'+list.map(n=>`<option>${esc(n)}</option>`).join('');
  sel.value=cur||'';
  renderChips();
}
function renderChips(){
  const me=F('traveler').value;
  const names=staffList().filter(n=>n!==me);
  [...S.companions].forEach(n=>{if(!names.includes(n)&&n!==me)names.push(n)});
  S.companions.delete(me);
  F('compChips').innerHTML=names.length?names.map(n=>`<button type="button" class="chip" aria-pressed="${S.companions.has(n)}" data-n="${esc(n)}">${esc(n)}</button>`).join(''):'<span class="hint">설정에서 근무인원을 추가해 주세요.</span>';
  updateCompCount();
}
function companionsArr(){
  const extra=F('compExtra').value.split(',').map(s=>s.trim()).filter(Boolean);
  return [...S.companions,...extra];
}
function updateCompCount(){const n=companionsArr().length;F('compCount').textContent=n?`· ${n}명`:'';updateMealHint()}
F('compChips').addEventListener('click',e=>{const b=e.target.closest('.chip');if(!b)return;const n=b.dataset.n;S.companions.has(n)?S.companions.delete(n):S.companions.add(n);b.setAttribute('aria-pressed',S.companions.has(n));updateCompCount()});
F('compExtra').addEventListener('input',updateCompCount);
F('traveler').addEventListener('change',renderChips);

// 승인자는 고정 — 승인 관리자(김호영) 이름이 늘 보인다. 승인 관리자가 직접 보면 본인 이름, 그 밖에는 설정의 승인자 이름.
// 실제 저장 값은 서버가 정한다(승인·반려한 승인 관리자 본인 이름, 승인대기면 빈칸).
function handlerName(){return S.settings.handlerName||'김지안'}
function approverName(){return S.role==='approver'?S.me:(S.settings.approverName||'김호영')}
function setSeg(id,val){document.querySelectorAll(`#${id} button`).forEach(b=>b.setAttribute('aria-pressed',b.dataset.v===val));renderStatusText()}
// 고칠 수 없는 사람에게 보이는 상태 글자. 반려된 청구는 정산도 '반려'다(지급하지 않는다).
function statusBadges(approval,status){
  const a=approval||'승인대기';
  const s=a==='반려'?'반려':status==='처리완료'?'처리완료':'미처리';
  const cls=v=>v==='승인'||v==='처리완료'?'b-ok':v==='반려'?'b-no':'b-wait';
  return [`<span class="badge ${cls(a)}">${esc(a)}</span>`,`<span class="badge ${cls(s)}">${esc(s)}</span>`];
}
function renderStatusText(){
  const [a,s]=statusBadges(S.approval,S.status);
  // 승인 상태 옆에 승인자(김호영 고정)를 함께 보인다 — 정산 관리자·구성원은 승인 칸을 글자로만 본다
  F('stApproval').innerHTML=a+` <span class="hint">승인자 ${esc(F('approver').value||approverName())}</span>`;
  F('stSettle').innerHTML=s+` <span class="hint">처리자 ${esc(F('handler').value||handlerName())}</span>`;
}
// 구성원 화면의 '내 청구' — 연도와 상관없이 최근 출장부터. 누르면 청구서가 열리고 승인·정산 상태가 보인다.
function renderMyList(){
  if(S.role!=='staff')return;
  // 최근 1년 안에 청구한 건만(청구일 = 올린 날, 옛 엑셀 기록은 출장일). 진행 중이어도 1년이 지나면 숨긴다.
  const d=new Date(),cut=ymd(d.getFullYear()-1,d.getMonth()+1,d.getDate());
  const list=S.trips.filter(t=>String(t.createdAt||t.date).slice(0,10)>=cut)
    .sort((a,b)=>String(b.date).localeCompare(String(a.date)));
  const hidden=S.trips.length-list.length;
  F('myMeta').textContent=(list.length?`최근 1년 ${list.length}건`:'')+(hidden?` · 그 전 ${hidden}건은 관리자에게 문의`:'');
  F('myCount').textContent=list.length||'';
  F('myList').innerHTML=list.length?list.map(t=>{const [a,s]=statusBadges(t.approval,t.status);
    return `<div class="myrow${t.id===S.editingId?' sel':''}" data-id="${esc(t.id)}" tabindex="0">
      <span class="when num">${esc(t.date)}</span>
      <span class="what">${esc(t.place)}${t.purpose?`<small>${esc(t.purpose)}</small>`:''}</span>
      <span class="amt num">${won(t.total)}원</span>
      <span class="st"><span class="hint">승인</span>${a}<span class="hint">정산</span>${s}</span></div>`}).join('')
    :`<div class="empty">${hidden?'최근 1년 안에 올린 청구가 없습니다.':'아직 올린 청구가 없습니다.'} 청구서를 써서 제출해 보세요.</div>`;
}
F('btnMy').addEventListener('click',()=>{renderMyList();F('myDlg').showModal()});
F('myDlgClose').addEventListener('click',()=>F('myDlg').close());
F('myList').addEventListener('click',e=>{const r=e.target.closest('.myrow');if(!r)return;const t=S.trips.find(x=>x.id===r.dataset.id);if(t){F('myDlg').close();fillForm(t)}});
F('myList').addEventListener('keydown',e=>{if(e.key==='Enter')e.target.click()});
F('approvalSeg').addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;S.approval=b.dataset.v;setSeg('approvalSeg',S.approval);
  // 승인자는 고정 — 승인·반려를 누른 승인 관리자 본인 이름(서버도 같은 값으로 저장한다)
  F('approver').value=approverName();
  if(S.approval!=='승인대기'&&!F('approvedAt').value)F('approvedAt').value=today()});
F('statusSeg').addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;S.status=b.dataset.v;setSeg('statusSeg',S.status);
  // 처리자는 고정(김지안) — 서버도 같은 값으로 저장한다
  F('handler').value=handlerName();
  if(S.status==='처리완료'){if(!F('processedAt').value)F('processedAt').value=today();if(!F('payMethod').value)F('payMethod').value='계좌이체'}});

['transport','toll','meal','etc'].forEach(id=>{
  F(id).addEventListener('input',updateTotal);
  F(id).addEventListener('blur',()=>{const v=parseNum(F(id).value);F(id).value=v?won(v):''});
  F(id).addEventListener('focus',()=>{const v=parseNum(F(id).value);F(id).value=v||''});
});
function sum(){return ['transport','toll','meal','etc'].reduce((a,id)=>a+parseNum(F(id).value),0)}
function updateTotal(){F('total').textContent=F('moneyTotal').textContent=F('settleTotal').value=won(sum())+'원';updateMealHint()}

function fuelPrice(mode){const f=S.settings.fuel||{};return mode.includes('휘발유')?f.gas:mode.includes('경유')?f.diesel:mode.includes('LPG')?f.lpg:null}
function updateFuelInfo(){
  const m=F('mode').value,p=fuelPrice(m);
  F('fuelBox').style.display=p?'flex':'none';
  if(p){const km=parseNum(F('km').value);const v=km?Math.ceil(km/(S.settings.eff||10)*p*2/1000)*1000:0;
    F('fuelInfo').textContent=km?`왕복 기준 ${won(v)}원 (유가 ${won(p)}원, ${S.settings.priceBase})`:`유가 ${p}원/L · 연비 ${S.settings.eff}km/L`}
}
F('mode').addEventListener('change',()=>{F('modeLegacy').hidden=true;renderExtra();updateFuelInfo()});F('km').addEventListener('input',updateFuelInfo);

/* 교통 수단 — 자가용 셋은 하나만, 그 밖의 수단은 갈아탄 것을 더 고를 수 있다(KTX+지하철+택시).
   옛 엑셀 기록의 '자가용'(유종 모름)·'-시외버스 -지하철' 같은 값은 목록에 넣지 않는다.
   알아볼 수 있으면 나눠서 고르고, 못 알아보면 원래 값을 안내로만 보여 주고 고르지 않으면 그대로 저장한다. */
const CAR_MODES=['자가용(휘발유)','자가용(경유)','자가용(LPG)'];
const OTHER_MODES=[...F('mode').options].map(o=>o.value).filter(v=>v&&!CAR_MODES.includes(v));
// 법인차량은 갈아타는 수단이 아니라 '함께 이용'에는 넣지 않는다(맨 위 교통 수단으로는 고를 수 있다)
const EXTRA_MODES=OTHER_MODES.filter(m=>m!=='법인차량');
S.extraModes=new Set();S.legacyMode='';
function renderExtra(){
  const main=F('mode').value,show=!!main&&!CAR_MODES.includes(main);
  F('extraBox').hidden=!show;
  S.extraModes.delete(main);
  F('extraChips').innerHTML=show?EXTRA_MODES.filter(m=>m!==main).map(m=>`<button type="button" class="chip" aria-pressed="${S.extraModes.has(m)}" data-m="${esc(m)}">${esc(m)}</button>`).join(''):'';
}
F('extraChips').addEventListener('click',e=>{const b=e.target.closest('.chip');if(!b)return;const m=b.dataset.m;S.extraModes.has(m)?S.extraModes.delete(m):S.extraModes.add(m);b.setAttribute('aria-pressed',S.extraModes.has(m))});
function modeValue(){
  const main=F('mode').value;
  if(!main)return S.legacyMode;
  if(CAR_MODES.includes(main))return main;
  return [main,...EXTRA_MODES.filter(m=>m!==main&&S.extraModes.has(m))].join('+');
}
function setModeValue(v){
  v=String(v||'').trim();S.extraModes=new Set();S.legacyMode='';F('modeLegacy').hidden=true;
  if(!v||CAR_MODES.includes(v)){F('mode').value=v||'자가용(휘발유)';renderExtra();return}
  const known=v.split(/[+\-,\/\s]+/).map(s=>s.trim()).filter(s=>OTHER_MODES.includes(s));
  if(known.length){F('mode').value=known[0];known.slice(1).forEach(m=>S.extraModes.add(m))}
  else{F('mode').value='';S.legacyMode=v;F('modeLegacy').hidden=false;F('modeLegacy').textContent=`기존 기록: ${v} — 바꾸려면 위에서 고르세요`}
  renderExtra();
}
F('btnFuel').addEventListener('click',()=>{const p=fuelPrice(F('mode').value),km=parseNum(F('km').value);if(!km){F('km').focus();return}
  F('transport').value=won(Math.ceil(km/(S.settings.eff||10)*p*2/1000)*1000);updateTotal()});
function updateMealHint(){
  const people=1+companionsArr().length,days=periodDays(F('period').value),unit=S.settings.mealUnit||15000;
  const limit=unit*people,meal=parseNum(F('meal').value);
  const h=F('mealHint');
  h.textContent=`기준: 1인 ${won(unit)}원 × ${people}명 = ${won(limit)}원`+(days>1?' (1회 기준)':'');
  h.className='hint'+(meal>limit*days*2?' warn':'');
}
function periodDays(p){const m=String(p).match(/(\d)박/);return m?Number(m[1])+1:1}
F('period').addEventListener('change',()=>{updateMealHint();renderCal()});

/* ---------- 출장일 달력 ----------
   고른 날은 숨은 칸 #date 에 둔다. S.calMonth 는 지금 보이는 달(YYYY-MM). */
const ymd=(y,m,d)=>`${y}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
function setDate(v){F('date').value=v||'';S.calMonth=(v||today()).slice(0,7);renderCal()}
function renderCal(){
  const sel=F('date').value,[y,m]=(S.calMonth||today().slice(0,7)).split('-').map(Number);
  const first=new Date(y,m-1,1),start=new Date(y,m-1,1-first.getDay());
  // 기간(1박2일이면 이틀)만큼 고른 날부터 옅게 잇는다
  const days=sel?periodDays(F('period').value):0,end=sel?new Date(new Date(sel+'T00:00').getTime()+(days-1)*864e5):null;
  const t=today();let cells='';
  for(let i=0;i<42;i++){
    const d=new Date(start.getTime()+i*864e5),v=ymd(d.getFullYear(),d.getMonth()+1,d.getDate()),w=d.getDay();
    const inRange=sel&&v>sel&&end&&d<=end;
    cells+=`<button type="button" class="d${d.getMonth()!==m-1?' out':''}${w===0?' sun':w===6?' sat':''}${v===t?' now':''}${v===sel?' sel':''}${inRange?' range':''}" data-v="${v}" aria-label="${v}"${v===sel?' aria-pressed="true"':''}>${d.getDate()}</button>`;
  }
  F('cal').innerHTML=`<div class="cal-head"><button type="button" data-nav="-1" aria-label="이전 달">‹</button><b>${y}년 ${m}월</b><span><button type="button" class="today" data-nav="0">오늘</button><button type="button" data-nav="1" aria-label="다음 달">›</button></span></div>
    <div class="cal-grid">${['일','월','화','수','목','금','토'].map((x,i)=>`<span class="wd${i===0?' sun':i===6?' sat':''}">${x}</span>`).join('')}${cells}</div>`;
  F('calPick').textContent=sel?sel.replace(/-/g,'. ')+' ('+'일월화수목금토'[new Date(sel+'T00:00').getDay()]+')':'날짜를 눌러 주세요';
}
F('cal').addEventListener('click',e=>{
  const nav=e.target.closest('[data-nav]');
  if(nav){const n=+nav.dataset.nav;if(!n){S.calMonth=today().slice(0,7)}else{const [y,m]=S.calMonth.split('-').map(Number);const d=new Date(y,m-1+n,1);S.calMonth=ymd(d.getFullYear(),d.getMonth()+1,1).slice(0,7)}renderCal();return}
  const b=e.target.closest('.d');if(!b)return;
  setDate(b.dataset.v);
});
function clearForm(){
  S.editingId=null;S.companions=new Set();S.approval='승인대기';S.status='미처리';S.receipts=emptyRc();renderThumbs();
  F('form').reset();F('traveler').value='';setDate(today());F('period').value='당일';setModeValue('자가용(휘발유)');
  ['transport','toll','meal','etc'].forEach(id=>F(id).value='');
  F('approver').value=approverName();F('handler').value=handlerName();renderStatusText();
  setSeg('approvalSeg',S.approval);setSeg('statusSeg',S.status);
  F('formMode').textContent='New';F('btnDel').hidden=true;F('btnSave').textContent='청구서 저장';
  presetMe();renderChips();updateFuelInfo();updateTotal();highlightRow();refreshLock();
}
function fillForm(t){
  S.editingId=t.id;
  S.receipts={...emptyRc(),...structuredClone(t.receipts||{})};renderThumbs();
  F('traveler').value=t.traveler||'';if(F('traveler').value!==t.traveler){renderStaff();F('traveler').value=t.traveler}
  const comps=String(t.companions||'').split(',').map(s=>s.trim()).filter(Boolean);
  const staff=staffList();
  S.companions=new Set(comps.filter(n=>staff.includes(n)));
  F('compExtra').value=comps.filter(n=>!staff.includes(n)).join(', ');
  F('period').value=t.period||'당일';setDate(t.date||'');F('place').value=t.place||'';F('purpose').value=t.purpose||'';
  setModeValue(t.mode);
  F('km').value=t.km||'';
  ['transport','toll','meal','etc'].forEach(id=>F(id).value=t[id]?won(t[id]):'');
  F('etcNote').value=t.etcNote||'';
  S.approval=t.approval||'승인대기';S.status=t.status||'미처리';setSeg('approvalSeg',S.approval);setSeg('statusSeg',S.status);
  F('approver').value=t.approver||approverName();F('approvedAt').value=t.approvedAt||'';renderStatusText();
  F('handler').value=t.handler||handlerName();F('processedAt').value=t.processedAt||'';F('payMethod').value=t.payMethod||'';F('note').value=t.note||'';renderStatusText();
  F('formMode').textContent='Edit';F('btnDel').hidden=false;F('btnSave').textContent='변경 저장';
  renderChips();updateFuelInfo();updateTotal();highlightRow();
  if(t.companionCount&&!comps.length)F('compCount').textContent=`· ${t.companionCount}명 (기존 기록)`;
  refreshLock();
  if(S.role==='staff')switchTab('form');
}
F('btnNew').addEventListener('click',()=>{clearForm();switchTab('form');F('traveler').focus()});

/* [승인]·[반려] — 누르면 그 자리에서 저장한다. 승인자·승인일은 서버·오늘 날짜로 채워진다.
   승인 관리자는 승인 상태만 보낸다. 정산 관리자는 청구서에서 고친 내용(정산 칸 포함)까지 같이 저장한다 */
async function decide(v){
  if(!S.editingId||!isAdmin())return;
  if(S.role==='admin'){const cur=(S.trips.find(x=>x.id===S.editingId)||{}).approval||'승인대기';
    return saveForm({approval:v,approvedAt:today()},cur===v?'저장했습니다':v==='승인'?'승인하고 저장했습니다':'반려하고 저장했습니다')}
  const b=v==='승인'?F('btnApprove'):F('btnReject');b.disabled=true;
  try{
    await store.save(S.editingId,{approval:v,approvedAt:today()});
    toast(v==='승인'?'승인했습니다':'반려했습니다');
    if(S.single)afterSingleSave();else clearForm();
    render();
  }catch(e){toast(e.message||'처리하지 못했습니다');refreshLock()}
}
F('btnApprove').addEventListener('click',()=>decide('승인'));
F('btnReject').addEventListener('click',()=>{if(confirm('이 청구를 반려할까요?'))decide('반려')});
F('btnSave').addEventListener('click',()=>saveForm());
async function saveForm(over={},doneMsg=''){
  const need=[['traveler','출장자'],['date','출장일'],['place','출장지']].find(([id])=>!F(id).value.trim());
  if(need){toast(`${need[1]}을(를) 입력해 주세요`);F(need[0]).focus();return}
  const comps=companionsArr();
  const prev=S.trips.find(t=>t.id===S.editingId)||{};
  if(!isAdmin()&&S.editingId&&(prev.approval||'승인대기')!=='승인대기'){toast('승인된 청구는 수정할 수 없습니다');return}
  const data={
    traveler:F('traveler').value,companions:comps.join(', '),companionCount:comps.length||(!S.editingId?0:(prev.companionCount||0)),
    date:F('date').value,period:F('period').value,place:F('place').value.trim(),purpose:F('purpose').value.trim(),
    mode:modeValue(),km:parseNum(F('km').value)||0,
    transport:parseNum(F('transport').value),toll:parseNum(F('toll').value),meal:parseNum(F('meal').value),etc:parseNum(F('etc').value),etcNote:F('etcNote').value.trim(),
    approval:S.approval,approver:F('approver').value.trim(),approvedAt:F('approvedAt').value,
    status:S.status,handler:F('handler').value.trim(),processedAt:F('processedAt').value,payMethod:F('payMethod').value,note:F('note').value.trim(),
    receipts:S.receipts,
    createdAt:prev.createdAt||new Date().toISOString(),updatedAt:new Date().toISOString()
  };
  if(comps.length===0&&prev.companionCount&&!prev.companions)data.companionCount=prev.companionCount;
  data.total=data.transport+data.toll+data.meal+data.etc;
  
  Object.assign(data,over);
  const id=S.editingId||'new_';
  F('btnSave').disabled=F('btnApprove').disabled=F('btnReject').disabled=true;
  try{await store.save(id,data);toast(doneMsg||(S.editingId?'변경 내용을 저장했습니다':(S.role==='staff'?'청구서를 제출했습니다. 승인·정산 결과는 관리자가 안내합니다':'청구서를 저장했습니다')));S.year=data.date.slice(0,4);
    // 팝업은 저장한 그 청구서를 계속 보여 주고, 연 창의 목록을 새로 받게 알린다
    if(S.single)afterSingleSave();else clearForm();render()}
  catch(e){toast(e.message||'저장하지 못했습니다')}
  finally{F('btnSave').disabled=false;refreshLock()}
}
F('btnDel').addEventListener('click',async()=>{
  if(!S.editingId||!confirm('이 청구서를 삭제할까요? 되돌릴 수 없습니다.'))return;
  try{await store.remove(S.editingId);toast('청구서를 삭제했습니다');if(S.single){showSingleGone('청구서를 삭제했습니다.');tellOpener()}else clearForm()}catch(e){toast(e.message||'삭제하지 못했습니다')}
});

/* ---------- 현황/목록 ---------- */
function yearTrips(){return S.trips.filter(t=>S.year==='all'||String(t.date).startsWith(S.year))}
function editedAfter(t){return t.staffUpdatedAt&&t.reviewedAt&&t.staffUpdatedAt>t.reviewedAt&&(t.approval||'승인대기')!=='승인대기'}
function badgeA(a){return a==='승인'?'<span class="badge b-ok">승인</span>':a==='반려'?'<span class="badge b-no">반려</span>':'<span class="badge b-wait">대기</span>'}
function badgeS(s){return s==='처리완료'?'<span class="badge b-ok">완료</span>':'<span class="badge b-wait">미처리</span>'}

function render(){
  // 연도
  const years=[...new Set(S.trips.map(t=>String(t.date).slice(0,4)).filter(y=>/^\d{4}$/.test(y)))];
  if(!years.includes(String(new Date().getFullYear())))years.push(String(new Date().getFullYear()));
  years.sort().reverse();
  F('yearSel').innerHTML=years.map(y=>`<option value="${y}">${y}년</option>`).join('')+'<option value="all">전체 기간</option>';
  F('yearSel').value=S.year;

  const rows=yearTrips();
  const total=rows.reduce((a,t)=>a+(t.total||0),0);
  const waitA=rows.filter(t=>(t.approval||'승인대기')==='승인대기');
  const unpaid=rows.filter(t=>t.approval==='승인'&&t.status!=='처리완료');
  const label=(isAdmin()?'':'내 ')+(S.year==='all'?'전체':S.year+'년');
  F('kpis').innerHTML=`
    <div class="kpi"><div class="k">${label} 출장</div><div class="v num">${rows.length}건</div><div class="s">출장자 ${new Set(rows.map(t=>t.traveler)).size}명</div></div>
    <div class="kpi"><div class="k">${label} 청구 합계</div><div class="v num">${won(total)}원</div><div class="s">건당 평균 ${won(rows.length?total/rows.length:0)}원</div></div>
    <div class="kpi click ${waitA.length?'alert':''}" data-f="승인대기"><div class="k">${label} 승인 대기</div><div class="v num">${waitA.length}건</div><div class="s">${won(waitA.reduce((a,t)=>a+(t.total||0),0))}원</div></div>
    <div class="kpi click ${unpaid.length?'alert':''}" data-f="미처리"><div class="k">${label} 정산 대기</div><div class="v num">${unpaid.length}건</div><div class="s">${won(unpaid.reduce((a,t)=>a+(t.total||0),0))}원 지급 예정</div></div>`;

  renderMonthChart(rows);
  renderPeople(rows);
  renderQueue(waitA,unpaid);
  renderTable(rows);
  renderMyList();
}
// 청구 목록은 처음엔 접혀 있다. 제목 줄을 누를 때마다 펼치고 접는다
function setListFold(folded){F('listPanel').classList.toggle('folded',folded);F('listFold').setAttribute('aria-expanded',String(!folded))}
F('listFold').addEventListener('click',()=>setListFold(!F('listPanel').classList.contains('folded')));
setListFold(true);
function showList(f){setListFold(false);S.fStatus=f;F('fStatus').value=S.fStatus;render();switchTab('list');F('listPanel').scrollIntoView({behavior:'smooth',block:'start'})}
// 관리자 화면의 승인 대기·정산 대기 칸은 '처리할 청구' 팝업을 다시 띄운다(대기 건이 없으면 목록으로)
F('kpis').addEventListener('click',e=>{const k=e.target.closest('.kpi.click');if(k&&!showPending())showList(k.dataset.f)});

function renderMonthChart(rows){
  const W=620,H=220,pl=46,pr=8,pt=12,pb=28;
  let buckets,labels;
  if(S.year==='all'){
    const ys=[...new Set(S.trips.map(t=>String(t.date).slice(0,4)))].filter(y=>/^\d{4}$/.test(y)).sort();
    labels=ys;buckets=ys.map(y=>S.trips.filter(t=>String(t.date).startsWith(y)));F('monthMeta').textContent='연도별';
  }else{
    labels=Array.from({length:12},(_,i)=>(i+1)+'월');buckets=labels.map((_,i)=>rows.filter(t=>Number(String(t.date).slice(5,7))===i+1));F('monthMeta').textContent=S.year+'년';
  }
  const keys=['transport','toll','meal','etc'],cols=['var(--c1)','var(--c2)','var(--c3)','var(--c4)'];
  const sums=buckets.map(b=>keys.map(k=>b.reduce((a,t)=>a+(t[k]||0),0)));
  const max=Math.max(10000,...sums.map(s=>s.reduce((a,b)=>a+b,0)));
  const step=niceStep(max/4),top=Math.ceil(max/step)*step;
  const iw=W-pl-pr,ih=H-pt-pb,bw=Math.min(36,iw/labels.length*0.6);
  let g='';
  for(let v=0;v<=top;v+=step){const y=pt+ih-(v/top)*ih;g+=`<line x1="${pl}" x2="${W-pr}" y1="${y}" y2="${y}" stroke="var(--line)"/><text x="${pl-6}" y="${y+4}" text-anchor="end" font-size="10" fill="var(--sub)">${v>=10000?(v/10000)+'만':won(v)}</text>`}
  labels.forEach((l,i)=>{
    const cx=pl+iw*(i+.5)/labels.length;let y=pt+ih;
    const tot=sums[i].reduce((a,b)=>a+b,0);
    sums[i].forEach((v,k)=>{if(!v)return;const h=v/top*ih;y-=h;g+=`<rect x="${cx-bw/2}" y="${y}" width="${bw}" height="${h}" fill="${cols[k]}" rx="1"><title>${l} ${['교통비','통행료','식대','기타'][k]} ${won(v)}원</title></rect>`});
    if(tot)g+=`<text x="${cx}" y="${y-4}" text-anchor="middle" font-size="10" fill="var(--ink)" font-weight="600">${tot>=10000?(Math.round(tot/1000)/10)+'만':won(tot)}</text>`;
    g+=`<text x="${cx}" y="${H-8}" text-anchor="middle" font-size="11" fill="var(--sub)">${esc(l)}</text>`;
  });
  F('monthChart').innerHTML=`<svg viewBox="0 0 ${W} ${H}" width="100%" role="img" aria-label="기간별 출장여비 막대그래프" style="display:block">${g}</svg>`;
}
function niceStep(x){const p=Math.pow(10,Math.floor(Math.log10(x)));const n=x/p;return (n<=1?1:n<=2?2:n<=5?5:10)*p}

function renderPeople(rows){
  const m={};rows.forEach(t=>{const p=m[t.traveler]||(m[t.traveler]={n:0,k:[0,0,0,0]});p.n++;['transport','toll','meal','etc'].forEach((k,i)=>p.k[i]+=t[k]||0)});
  const arr=Object.entries(m).map(([n,p])=>({n,c:p.n,k:p.k,t:p.k.reduce((a,b)=>a+b,0)})).sort((a,b)=>b.t-a.t);
  if(!arr.length){F('byPerson').innerHTML='<div class="empty" style="padding:0">이 기간에 청구된 출장이 없습니다.</div>';return}
  const max=arr[0].t||1,cols=['var(--c1)','var(--c2)','var(--c3)','var(--c4)'];
  F('byPerson').innerHTML=arr.map(p=>`<div class="bh"><span>${esc(p.n)}</span><div class="track" style="width:${Math.max(4,p.t/max*100)}%">${p.k.map((v,i)=>v?`<span style="width:${v/p.t*100}%;background:${cols[i]}"></span>`:'').join('')}</div><span class="amt num">${won(p.t)}원 · ${p.c}건</span></div>`).join('');
}
function renderQueue(waitA,unpaid){
  const items=[...waitA.map(t=>({t,k:'승인대기'})),...unpaid.map(t=>({t,k:'정산대기'}))].sort((a,b)=>String(a.t.date).localeCompare(String(b.t.date)));
  if(!items.length){F('queue').innerHTML='<div class="empty">처리할 청구가 없습니다.</div>';return}
  F('queue').innerHTML=items.slice(0,6).map(qrowHtml).join('')+(items.length>6?`<div class="empty" style="padding:6px 8px">외 ${items.length-6}건</div>`:'');
}
function qrowHtml({t,k}){return `<div class="qrow" data-id="${esc(t.id)}" tabindex="0"><span class="badge ${k==='승인대기'?'b-wait':'b-none'}">${k}</span><span class="who">${esc(t.traveler)}</span><span class="what">${esc(t.date)} ${esc(t.place)} · ${esc(t.purpose)}</span><span class="num">${won(t.total)}원</span></div>`}

/* 관리자 화면으로 들어올 때, 그리고 현황의 승인 대기·정산 대기 칸을 누를 때 대기 건이 있으면 건수를 팝업으로
   알린다(연도와 상관없이 전체). 건수를 누르면 가장 오래된 청구서 창이, 건을 누르면 그 청구서 창이 열린다.
   띄웠으면 true */
function showPending(){
  if(S.role==='staff'||S.single||F('pendDlg').open)return false;
  const waitA=S.trips.filter(t=>(t.approval||'승인대기')==='승인대기');
  const unpaid=S.trips.filter(t=>t.approval==='승인'&&t.status!=='처리완료');
  if(!waitA.length&&!unpaid.length)return false;
  const sum=a=>won(a.reduce((s,t)=>s+(t.total||0),0));
  F('pendKpis').innerHTML=`
    <div class="kpi click ${waitA.length?'alert':''}" data-q="wait" tabindex="0" title="가장 오래된 건부터 엽니다"><div class="k">승인 대기 <span class="go">열기 ›</span></div><div class="v num">${waitA.length}건</div><div class="s">${sum(waitA)}원</div></div>
    <div class="kpi click ${unpaid.length?'alert':''}" data-q="settle" tabindex="0" title="가장 오래된 건부터 엽니다"><div class="k">정산 대기 <span class="go">열기 ›</span></div><div class="v num">${unpaid.length}건</div><div class="s">${sum(unpaid)}원 지급 예정</div></div>`;
  const items=[...waitA.map(t=>({t,k:'승인대기'})),...unpaid.map(t=>({t,k:'정산대기'}))].sort((a,b)=>String(a.t.date).localeCompare(String(b.t.date)));
  F('pendList').innerHTML=items.slice(0,8).map(qrowHtml).join('')+(items.length>8?`<div class="empty" style="padding:6px 8px">외 ${items.length-8}건</div>`:'');
  F('pendDlg').showModal();return true;
}
F('pendDlgClose').addEventListener('click',()=>F('pendDlg').close());
// 칸을 누르면 그 대기 건 중 가장 오래된 청구서를 바로 연다(처리하면 같은 창에서 다음 건으로)
F('pendKpis').addEventListener('click',e=>{const k=e.target.closest('.kpi.click');if(!k)return;const q=k.dataset.q,first=queueTrips(q)[0];if(!first)return;F('pendDlg').close();openTrip(first,q)});
F('pendList').addEventListener('click',e=>{const r=e.target.closest('.qrow');if(!r)return;const t=S.trips.find(x=>x.id===r.dataset.id);if(t){F('pendDlg').close();openTrip(t,QUEUES.wait.has(t)?'wait':'settle')}});
[F('pendKpis'),F('pendList')].forEach(el=>el.addEventListener('keydown',e=>{if(e.key==='Enter')e.target.click()}));
F('queue').addEventListener('click',e=>{const r=e.target.closest('.qrow');if(r){const t=S.trips.find(x=>x.id===r.dataset.id);if(t)(S.role==='staff'?fillForm:openTrip)(t)}});
F('queue').addEventListener('keydown',e=>{if(e.key==='Enter')e.target.click()});

function filtered(rows){
  const q=S.q.trim().toLowerCase();
  return rows.filter(t=>{
    if(S.fStatus==='승인대기'&&(t.approval||'승인대기')!=='승인대기')return false;
    if(S.fStatus==='반려'&&t.approval!=='반려')return false;
    if(S.fStatus==='미처리'&&!(t.status!=='처리완료'&&t.approval!=='반려'))return false;
    if(S.fStatus==='처리완료'&&t.status!=='처리완료')return false;
    if(q&&![t.traveler,t.place,t.purpose,t.companions,t.note].join(' ').toLowerCase().includes(q))return false;
    return true;
  }).sort((a,b)=>String(a.date).localeCompare(String(b.date)));
}
function renderTable(rows){
  const list=filtered(S.year==='all'?S.trips:rows);
  F('listMeta').textContent=`${list.length}건`;
  if(!list.length){F('tbody').innerHTML=`<tr><td colspan="21" class="empty">${S.trips.length?'조건에 맞는 청구가 없습니다.':'아직 청구서가 없습니다. 새 청구서로 첫 출장을 등록해 보세요.'}</td></tr>`;F('tfoot').innerHTML='';return}
  F('tbody').innerHTML=list.map((t,i)=>`<tr data-id="${esc(t.id)}" tabindex="0">
    <td class="num">${i+1}</td><td>${esc(t.traveler)}</td><td>${esc(t.companions||(t.companionCount?t.companionCount+'명':'-'))}</td><td class="num">${esc(t.date)}</td><td>${esc(t.period)}</td><td>${esc(t.place)}</td><td class="purpose" title="${esc(t.purpose)}">${esc(t.purpose)}</td>
    <td>${esc(t.mode)}</td><td class="r num">${won(t.transport)}</td><td class="r num">${won(t.toll)}</td><td class="r num">${won(t.meal)}</td><td class="r num" title="${esc(t.etcNote)}">${won(t.etc)}</td><td class="r num"><b>${won(t.total)}</b></td><td>${rcCount(t)?`<span class="clip">📎 ${rcCount(t)}</span>`:''}</td>
    <td>${badgeA(t.approval)}${isAdmin()&&editedAfter(t)?' <span class="badge b-edit">승인 후 수정</span>':''}</td><td>${esc(t.approver)}</td>
    <td>${badgeS(t.status)}</td><td>${esc(t.handler)}</td><td class="num">${esc(t.processedAt)}</td><td>${esc(t.payMethod)}</td><td class="purpose" title="${esc(t.note)}">${esc(t.note)}</td></tr>`).join('');
  const s=k=>list.reduce((a,t)=>a+(t[k]||0),0);
  F('tfoot').innerHTML=`<tr><td colspan="8">합계</td><td class="r num">${won(s('transport'))}</td><td class="r num">${won(s('toll'))}</td><td class="r num">${won(s('meal'))}</td><td class="r num">${won(s('etc'))}</td><td class="r num">${won(s('total'))}</td><td class="num">${list.reduce((a,t)=>a+rcCount(t),0)||''}</td><td colspan="7"></td></tr>`;
  highlightRow();
}
function highlightRow(){document.querySelectorAll('#tbody tr,#myList .myrow').forEach(r=>r.classList.toggle('sel',r.dataset.id===S.editingId))}
F('tbody').addEventListener('click',e=>{const r=e.target.closest('tr[data-id]');if(!r)return;const t=S.trips.find(x=>x.id===r.dataset.id);if(t)(S.role==='staff'?fillForm:openTrip)(t)});
F('tbody').addEventListener('keydown',e=>{if(e.key==='Enter')e.target.click()});
F('q').addEventListener('input',e=>{S.q=e.target.value;render()});
F('fStatus').addEventListener('change',e=>{S.fStatus=e.target.value;render()});
F('yearSel').addEventListener('change',e=>{S.year=e.target.value;render()});

/* ---------- 유가·작성기준 ---------- */
const FUEL_ROWS=[[20,'청원, 증평'],[30,'연기, 진천'],[40,''],[50,'괴산, 음성, 보은, 대전, 공주'],[60,'옥천, 천안'],[70,'충주, 아산, 계룡'],[80,'금산, 평택, 안성'],[90,'영동, 예산, 오산, 청양, 부여, 이천'],[100,'제천, 여주, 논산'],[110,'홍성, 용인, 경기도 광주'],[120,'수원, 보령'],[130,'성남, 화성, 단양'],[140,'서울, 당진'],[150,'서산'],[160,'서천, 태안'],[170,''],[180,'부천'],[190,'파주'],[200,'']];
const fuelCost=(km,price)=>Math.ceil(km/(S.settings.eff||10)*price*2/1000)*1000;
function renderInfo(){
  const p=S.settings.prices||{},f=S.settings.fuel||{};
  const items=[['gas','국내주유소판매','휘발유'],['diesel','국내주유소판매','자동차용경유'],['lpg','국내LPG판매','자동차용부탄']];
  F('priceLabel').textContent=p.label||'';
  F('priceCards').innerHTML=items.map(([k,a,b])=>{
    const d=(p.diff||{})[k];const cls=d>0?'up':d<0?'down':'flat';
    const dt=d===undefined||d===null?'':`<span class="d ${cls}">${d>0?'▲':d<0?'▼':'–'} ${Math.abs(d).toFixed(2)}</span>`;
    return `<div class="pc"><div class="n">${a} (${b})</div><div class="v num">${p[k]!=null?Number(p[k]).toLocaleString('ko-KR',{minimumFractionDigits:2,maximumFractionDigits:2}):'-'}<small>원/L</small></div>${dt}
      <div class="b">산정 기준 ${f[k]!=null?Number(f[k]).toLocaleString('ko-KR',{maximumFractionDigits:2}):'-'}원</div></div>`}).join('');
  const when=p.fetchedAt?`${p.fetchedAt.replace('T',' ').slice(0,16)} 자동 갱신`:'';
  const auto=S.settings.autoFuel!==false;   // 켜져 있으면 받은 유가가 곧 산정 기준이다(서버가 맞춘다)
  const err=p.error?`<span class="perr">최근 갱신 실패: ${esc(p.error)}</span>`:'';
  const noKey=isSettler()&&!S.settings.hasOpinetKey?'<span class="perr">설정에 오피넷 API 키를 넣으면 자동으로 바뀝니다.</span>':'';
  F('priceFoot').innerHTML=`<span>출처: ${esc(p.source||'')}${when?' · '+when:''}${auto?' · 주유비 산정 기준에 바로 적용':''}</span>${err}${noKey}
    <a href="https://www.petronet.co.kr/v4/main.jsp" target="_blank" rel="noopener">페트로넷에서 확인</a>
    ${isSettler()?'<span class="hspace"></span><button class="btn" id="btnPriceRefresh" style="padding:4px 10px">지금 갱신</button>'+(auto?'':'<button class="btn" id="btnPriceApply" style="padding:4px 10px">이 가격을 산정 기준으로 적용</button>')+'':''}`;
  F('ruleMeal').textContent=won(S.settings.mealUnit||15000);
  F('ruleBase').textContent=`(${S.settings.priceBase||'산정 기준'} 유가, 연비 ${S.settings.eff||10}km/L)`;
  F('fuelTable').innerHTML=FUEL_ROWS.map(([km,pl])=>`<tr><td>${km}km 이내</td><td class="r num">${won(fuelCost(km,f.gas||0))}</td><td class="r num">${won(fuelCost(km,f.diesel||0))}</td><td class="r num">${won(fuelCost(km,f.lpg||0))}</td><td>${esc(pl)}</td></tr>`).join('');
}
F('pricePanel').addEventListener('click',async e=>{
  if(e.target.id==='btnPriceRefresh'){e.target.disabled=true;try{await api('prices/refresh',{});await loadState();toast('유가를 새로 가져왔습니다')}catch(err){toast(err.message)}finally{if(F('btnPriceRefresh'))F('btnPriceRefresh').disabled=false}}
  if(e.target.id==='btnPriceApply'){const p=S.settings.prices||{};
    if(!confirm(`주유비 산정 기준을 ${p.label||'현재'} 유가로 바꿀까요?\n휘발유 ${p.gas} / 경유 ${p.diesel} / LPG ${p.lpg}\n\n작성기준상 기준유가는 매주 최신 유가로 산정합니다.`))return;
    try{await api('prices/apply',{});await loadState();toast('주유비 산정 기준을 바꿨습니다')}catch(err){toast(err.message)}}
});

/* ---------- 탭 (모바일) ---------- */
function switchTab(tab){
  if(!matchMedia('(max-width:1100px)').matches&&tab!=='form')return;
  F('main').dataset.tab=tab;document.querySelectorAll('.tabs button').forEach(b=>b.setAttribute('aria-selected',b.dataset.tab===tab));
  if(matchMedia('(max-width:1100px)').matches)window.scrollTo({top:0});
}
document.querySelector('.tabs').addEventListener('click',e=>{const b=e.target.closest('button');if(b)switchTab(b.dataset.tab)});

/* ---------- 영수증 ---------- */
function updateAttUI(){F('attHint').textContent='영수증은 사진(JPG·PNG) 또는 PDF로 올릴 수 있습니다. 사진은 자동으로 줄여서 저장합니다.'}
function renderThumbs(){
  ['transport','toll','meal','etc'].forEach(k=>{
    const box=F('th-'+k);if(!box)return;
    box.innerHTML=(S.receipts[k]||[]).map((r,i)=>{
      const href=apiUrl('receipt','id='+encodeURIComponent(r.id));
      // loading="lazy" 를 쓰지 않는다 — 스크롤 칸(청구서) 안에서는 브라우저가 '보이는 중'으로 치지 않아
      // 그림이 끝내 안 뜨는 일이 있었다(특히 관리자 팝업). 청구서 한 장의 영수증은 몇 장뿐이라 바로 받는다.
      const inner=r.type==='application/pdf'?'PDF':`<img src="${href}" alt="${esc(r.name||'영수증')}" onerror="this.replaceWith(document.createTextNode('IMG'))">`;
      return `<div class="th" title="${esc(r.name||'')}"><a href="${href}" target="_blank" rel="noopener" data-rc="${k}:${i}">${inner}</a><button type="button" class="x" data-k="${k}" data-i="${i}" aria-label="영수증 삭제">×</button></div>`;
    }).join('');
  });
}
/* ---------- 영수증 크게 보기 창 ----------
   항목 옆 미리보기의 링크(data-rc="항목:순번")를 누르면 새 탭 대신 이 창에 띄운다.
   ‹ › 와 키보드 ←→ 로 이 청구서의 영수증을 넘겨 본다. Ctrl·가운데 단추로 누르면 예전처럼 새 탭으로 열린다. */
const RC_LABEL={transport:'교통비',toll:'통행료',meal:'식대',etc:'기타'};
let rcList=[],rcPos=0;
function showReceipt(){
  const {k,r}=rcList[rcPos],href=apiUrl('receipt','id='+encodeURIComponent(r.id));
  F('rcDlgTitle').textContent=RC_LABEL[k]+(r.name?' · '+r.name:'');
  F('rcDlgPos').textContent=rcList.length>1?`${rcPos+1} / ${rcList.length}`:'';
  F('rcDlgOpen').href=href;
  F('rcDlgView').innerHTML=r.type==='application/pdf'?`<iframe src="${href}" title="${esc(RC_LABEL[k])} 영수증"></iframe>`
    :`<img src="${href}" alt="${esc(RC_LABEL[k])} 영수증 ${esc(r.name||'')}">`;
  F('rcPrev').hidden=F('rcNext').hidden=rcList.length<2;
}
function openReceipt(k,i){
  rcList=['transport','toll','meal','etc'].flatMap(x=>(S.receipts[x]||[]).map(r=>({k:x,r})));
  rcPos=Math.max(0,rcList.findIndex(it=>it.k===k&&it.r===(S.receipts[k]||[])[i]));
  if(!rcList.length)return;
  showReceipt();if(!F('rcDlg').open)F('rcDlg').showModal();
}
function stepReceipt(d){if(rcList.length<2)return;rcPos=(rcPos+d+rcList.length)%rcList.length;showReceipt()}
document.addEventListener('click',e=>{
  const a=e.target.closest('a[data-rc]');if(!a||e.ctrlKey||e.metaKey||e.shiftKey||e.button!==0)return;
  e.preventDefault();const [k,i]=a.dataset.rc.split(':');openReceipt(k,+i);
});
F('rcPrev').addEventListener('click',()=>stepReceipt(-1));
F('rcNext').addEventListener('click',()=>stepReceipt(1));
F('rcDlgClose').addEventListener('click',()=>F('rcDlg').close());
F('rcDlg').addEventListener('click',e=>{if(e.target===F('rcDlg'))F('rcDlg').close()});   // 바깥(어두운 곳)을 누르면 닫힌다
F('rcDlg').addEventListener('keydown',e=>{if(e.key==='ArrowLeft')stepReceipt(-1);if(e.key==='ArrowRight')stepReceipt(1)});
// 닫히면 PDF·그림을 비운다. close 알림은 늦게 오므로, 그 사이 다시 열렸으면 비우지 않는다
F('rcDlg').addEventListener('close',()=>{if(!F('rcDlg').open)F('rcDlgView').innerHTML=''});
async function prepFile(f){
  if(f.type==='application/pdf')return {blob:f,type:'application/pdf'};
  if(!/^image\//.test(f.type))return {blob:f,type:f.type||'application/octet-stream'};
  try{
    const bmp=await createImageBitmap(f);
    const sc=Math.min(1,1800/Math.max(bmp.width,bmp.height));
    const c=document.createElement('canvas');c.width=Math.round(bmp.width*sc);c.height=Math.round(bmp.height*sc);
    c.getContext('2d').drawImage(bmp,0,0,c.width,c.height);
    const b=await new Promise(r=>c.toBlob(r,'image/jpeg',0.82));
    return b?{blob:b,type:'image/jpeg'}:{blob:f,type:f.type};
  }catch(e){return {blob:f,type:f.type}}
}
document.querySelectorAll('.att input').forEach(inp=>inp.addEventListener('change',async()=>{
  const lab=inp.closest('.att'),k=lab.dataset.k,files=[...inp.files];inp.value='';
  if(!files.length)return;
  lab.classList.add('busy');const orig=lab.firstChild.textContent;lab.firstChild.textContent='올리는 중…';
  let ok=0;
  for(const f of files){
    try{
      const {blob,type}=await prepFile(f);
      // 줄인 사진은 이름이 사라지므로 원래 이름을 붙여 보낸다. 확장자는 서버가 내용을 보고 다시 정한다.
      const fd=new FormData();fd.append('file',blob,f.name);
      const r=await api('receipts',fd);
      S.receipts[k].push({id:r.id,type:r.type,name:f.name});ok++;
    }catch(e){
      toast(`${f.name}: ${e.message||'올리지 못했습니다'}`);
    }
  }
  lab.classList.remove('busy');lab.firstChild.textContent=orig;renderThumbs();
  if(ok)toast(`영수증 ${ok}장을 첨부했습니다. 청구서를 저장해야 반영됩니다`);
}));
F('form').addEventListener('click',e=>{
  const b=e.target.closest('.th .x');if(!b)return;
  if(!confirm('이 영수증을 청구서에서 뺄까요?'))return;
  S.receipts[b.dataset.k].splice(+b.dataset.i,1);renderThumbs();
});

/* ---------- CSV ---------- */
F('btnCsv').addEventListener('click',()=>{const a=document.createElement('a');a.href=apiUrl('export','year='+encodeURIComponent(S.year));document.body.appendChild(a);a.click();a.remove()});

/* ---------- 설정 ---------- */
let users=[];
const ROLE_LABEL={admin:'관리자 · 정산',approver:'관리자 · 승인',staff:'구성원'};
// 구성원 추가·퇴사·비밀번호는 포털 계정에서 한다. 여기서는 출장여비 권한만 고른다.
async function loadUsers(){users=(await api('roles')).users;renderUsers()}
function renderUsers(){
  F('userList').innerHTML=users.map(u=>`<div class="urow"><span>${esc(u.name)}</span>
    <select data-e="${esc(u.email)}" aria-label="${esc(u.name)} 권한">${Object.entries(ROLE_LABEL).map(([v,l])=>`<option value="${v}"${u.role===v?' selected':''}>${l}</option>`).join('')}</select></div>`).join('');
}
F('userList').addEventListener('change',async e=>{
  const s=e.target.closest('select[data-e]');if(!s)return;
  try{users=(await api('roles',{email:s.dataset.e,role:s.value})).users;renderUsers();toast('권한을 바꿨습니다')}
  catch(err){toast(err.message);renderUsers()}
});
F('btnSettings').addEventListener('click',async()=>{
  const s=S.settings;
  F('sGas').value=s.fuel.gas;F('sDiesel').value=s.fuel.diesel;F('sLpg').value=s.fuel.lpg;F('sEff').value=s.eff;F('sBase').value=s.priceBase;F('sMeal').value=s.mealUnit;
  F('sAutoFuel').checked=s.autoFuel!==false;syncAutoFuelInputs();
  F('sKey').value='';F('keyState').textContent=s.hasOpinetKey?'API 키가 등록되어 있습니다. 바꿀 때만 새 키를 입력하세요. 누군가 화면을 열 때 마지막 확인에서 3시간이 지났으면 유가를 새로 받아 옵니다.':'오피넷(opinet.co.kr) 회원가입 후 \'무료 API 이용 신청\'에서 키를 받아 붙여넣으세요.';
  F('userList').innerHTML='<span class="hint">불러오는 중…</span>';F('dlg').showModal();
  loadUsers().catch(e=>toast(e.message));
});
// 자동 적용이 켜져 있으면 유가·기준일 칸은 서버가 채우므로 손으로 고치지 못하게 흐리게 둔다
function syncAutoFuelInputs(){['sGas','sDiesel','sLpg','sBase'].forEach(id=>F(id).disabled=F('sAutoFuel').checked)}
F('sAutoFuel').addEventListener('change',syncAutoFuelInputs);
F('dlgCancel').addEventListener('click',()=>F('dlg').close());
F('dlgSave').addEventListener('click',async()=>{
  const d={opinetKey:F('sKey').value.trim(),
    fuel:{gas:parseNum(F('sGas').value),diesel:parseNum(F('sDiesel').value),lpg:parseNum(F('sLpg').value)},eff:parseNum(F('sEff').value)||10,
    priceBase:F('sBase').value.trim(),mealUnit:parseNum(F('sMeal').value)||15000,autoFuel:F('sAutoFuel').checked};
  try{await store.saveSettings(d);if(d.opinetKey){api('prices/refresh',{}).then(loadState).catch(e=>toast(e.message))}updateFuelInfo();updateMealHint();F('dlg').close();toast('설정을 저장했습니다')}catch(e){toast(e.message||'설정을 저장하지 못했습니다')}
});

/* ---------- 시작 ---------- */
clearForm();
render();
initStore();
})();
