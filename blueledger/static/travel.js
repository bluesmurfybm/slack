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
  // nginx 가 업로드 크기 한도(client_max_body_size)로 먼저 끊으면 JSON 이 아닌 413 페이지가 온다
  if(r.status===413&&!j.error)throw new Error('파일이 서버 업로드 한도보다 큽니다. 서버 관리자에게 업로드 크기 설정을 확인해 달라고 알려 주세요.');
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
  }else if(TRIP_PARAM){const t=S.trips.find(x=>x.id===TRIP_PARAM);if(t)fillForm(t)} // 구성원이 알림으로 들어오면 그 청구서를 연다
  clearInterval(S.poll);S.poll=setInterval(()=>{if(!document.hidden)loadState().catch(()=>{})},20000);
}
/* ---------- 구성원 / 관리자 화면 전환 (관리자 계정만) ---------- */
const isMine=t=>t.createdBy===S.me||t.traveler===S.me;
function viewTrips(){return S.role==='staff'?S.allTrips.filter(isMine):S.allTrips}
function setViewRole(){
  S.role=S.view==='admin'?S.realRole:'staff';
  document.body.dataset.role=S.role;
  F('modeSeg').querySelectorAll('[data-mode]').forEach(b=>b.setAttribute('aria-selected',b.dataset.mode===S.view));
  applyRole();
}
F('modeSeg').addEventListener('click',e=>{
  const b=e.target.closest('[data-mode]');if(!b||S.realRole==='staff'||b.dataset.mode===S.view)return;
  S.view=b.dataset.mode;setViewRole();S.trips=viewTrips();
  S.fStatus='all';clearForm();render();renderInfo();
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
F('btnSlipClose').addEventListener('click',()=>S.single?closeSingle():closeDrawer());
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
  // 작성기준 전체는 늘 [출장비 기준] 창 안에 있다(관리자 화면은 '출장비 기준' 카드의 [전체 기준 보기]로 연다)
  F('rulePanel').open=true;if(!staff)closeDrawer();
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
  const ap=S.role==='approver'&&!!S.editingId,cur=t?(t.approval||'승인대기'):'',lockSame=S.role==='approver';
  if(S.role==='approver')F('btnSave').hidden=true;
  if(S.role==='admin')F('btnSave').hidden=!S.editingId;
  F('btnApprove').hidden=F('btnReject').hidden=!ap;
  F('btnApprove').textContent=lockSame&&cur==='승인'?'승인됨':'승인';F('btnApprove').disabled=lockSame&&cur==='승인';
  F('btnReject').textContent=lockSame&&cur==='반려'?'반려됨':'반려';F('btnReject').disabled=lockSame&&cur==='반려';
  const msg=F('lockMsg');
  if(S.role==='approver'){msg.hidden=false;msg.textContent=S.editingId?`지금 ${cur}입니다. 내용과 영수증을 확인하고 아래 승인 또는 반려를 누르면 바로 저장됩니다.`:'목록이나 처리할 청구에서 승인할 건을 눌러 주세요.'}
  else if(locked){msg.hidden=false;msg.textContent=t.approval==='반려'?`반려된 청구입니다.${t.rejectReason?` 반려 사유: ${t.rejectReason.replace(/[.\s]+$/,'')}.`:''} 내용을 고쳐 새 청구서로 다시 올려 주세요.`:'승인된 청구는 수정할 수 없습니다. 고칠 내용이 있으면 관리자에게 알려 주세요.'}
  else if(staff){msg.hidden=false;msg.textContent='승인과 정산은 관리자가 처리합니다. 승인 전까지는 내용을 고칠 수 있습니다.'}
  else if(S.role==='admin'&&S.editingId){msg.hidden=false;msg.textContent=`지금 ${cur}입니다. 승인·반려는 대표(승인 관리자)가 합니다. 고친 내용과 정산 칸은 아래 [변경 저장]으로 저장됩니다.`}
  else msg.hidden=true;
  document.querySelectorAll('.att').forEach(a=>a.style.visibility=locked?'hidden':'');
  document.querySelectorAll('.th .x').forEach(b=>b.hidden=!!locked);
  renderSteps();renderDraftNote();
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
  S.companions.delete(me);
  // 고른 사람은 이름표(동그란 이니셜 + 이름 + x)로 붙는다
  F('compChips').innerHTML=[...S.companions].map(n=>`<span class="tag" data-n="${esc(n)}"><i>${esc(n.slice(0,1))}</i>${esc(n)}<button type="button" class="x" aria-label="${esc(n)} 빼기">${IC.x}</button></span>`).join('');
  renderSugg();
  updateCompCount();
}
// 동행자 검색 — 칸에 쓰면 근무인원 중 맞는 이름이 아래에 뜬다(본인·이미 고른 사람 제외)
function renderSugg(){
  const box=F('compSugg'),inp=F('compSearch'),q=inp.value.trim(),me=F('traveler').value;
  if(document.activeElement!==inp){box.hidden=true;return}
  const all=staffList(),list=all.filter(n=>n!==me&&!S.companions.has(n)&&(!q||n.includes(q)));
  box.innerHTML=list.length?list.map(n=>`<button type="button" role="option" data-n="${esc(n)}"><i>${esc(n.slice(0,1))}</i>${esc(n)}</button>`).join('')
    :`<div class="hint">${all.length?'맞는 이름이 없습니다. 외부 동행자는 아래 칸에 적어 주세요.':'설정에서 근무인원을 추가해 주세요.'}</div>`;
  box.hidden=false;
}
function addComp(n){if(!n)return;S.companions.add(n);F('compSearch').value='';renderChips();saveDraftSoon()}
function companionsArr(){
  const extra=F('compExtra').value.split(',').map(s=>s.trim()).filter(Boolean);
  return [...S.companions,...extra];
}
function updateCompCount(){const n=companionsArr().length;F('compCount').textContent=n?`· ${n}명`:'';updateMealHint()}
F('compChips').addEventListener('click',e=>{const b=e.target.closest('.x');if(!b)return;S.companions.delete(b.closest('.tag').dataset.n);renderChips();saveDraftSoon()});
F('compSearch').addEventListener('input',renderSugg);
F('compSearch').addEventListener('focus',renderSugg);
F('compSearch').addEventListener('blur',()=>setTimeout(renderSugg,150));
F('compSearch').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();const b=F('compSugg').querySelector('[data-n]');if(b)addComp(b.dataset.n)}if(e.key==='Escape'){F('compSearch').value='';F('compSearch').blur()}});
// mousedown 으로 받아야 칸의 blur 보다 먼저 잡힌다
F('compSugg').addEventListener('mousedown',e=>{const b=e.target.closest('[data-n]');if(!b)return;e.preventDefault();addComp(b.dataset.n)});
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
function updateTotal(){F('total').textContent=F('moneyTotal').textContent=F('settleTotal').value=won(sum())+'원';updateMealHint();updateRcState()}

function fuelPrice(mode){const f=S.settings.fuel||{};return mode.includes('휘발유')?f.gas:mode.includes('경유')?f.diesel:mode.includes('LPG')?f.lpg:null}
function updateFuelInfo(){
  const m=F('mode').value,p=fuelPrice(m);
  F('fuelBox').style.display=p?'flex':'none';
  if(p){const km=parseNum(F('km').value),eff=S.settings.eff||10,raw=km/eff*p*2,v=km?Math.ceil(raw/1000)*1000:0;
    F('fuelInfo').innerHTML=`편도 <b>${km?esc(String(km)):'○'}</b>km ÷ 연비 ${esc(String(eff))} × 오늘 유가 ${won(p)}원 × 왕복 2`
      +(km?` = ${won(raw)}원 → <b>${won(v)}원</b>`:' = <span class="hint">편도 거리를 넣어 주세요</span>')}
  F('xs-transport').textContent=modeValue()||'교통 수단을 골라 주세요';
  updateRcState();
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
F('extraChips').addEventListener('click',e=>{const b=e.target.closest('.chip');if(!b)return;const m=b.dataset.m;S.extraModes.has(m)?S.extraModes.delete(m):S.extraModes.add(m);b.setAttribute('aria-pressed',S.extraModes.has(m));F('xs-transport').textContent=modeValue()});
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
  const dl=v=>v.replace(/-/g,'. ')+' ('+'일월화수목금토'[new Date(v+'T00:00').getDay()]+')';
  const last=end&&days>1?ymd(end.getFullYear(),end.getMonth()+1,end.getDate()):'';
  F('calPick').textContent=sel?dl(sel)+(last?' – '+dl(last).slice(6):''):'날짜를 골라 주세요';
  F('periodBadge').textContent=F('period').value||'당일';
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
  F('formMode').textContent='New';F('btnDel').hidden=true;F('btnSave').textContent='제출';closeCalPop();F('slipTitle').textContent='새 출장비 청구';
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
  F('formMode').textContent='Edit';F('btnDel').hidden=false;F('btnSave').textContent='변경 저장';closeCalPop();F('slipTitle').textContent='출장비 청구';
  renderChips();updateFuelInfo();updateTotal();highlightRow();
  if(t.companionCount&&!comps.length)F('compCount').textContent=`· ${t.companionCount}명 (기존 기록)`;
  refreshLock();
  if(S.role==='staff'){switchTab('form');openDrawer()}
}
F('btnNew').addEventListener('click',()=>{clearForm();switchTab('form');F('traveler').focus()});

/* [승인]·[반려] — 누르면 그 자리에서 저장한다. 승인자·승인일은 서버·오늘 날짜로 채워진다.
   승인 관리자는 승인 상태만 보낸다. 정산 관리자는 청구서에서 고친 내용(정산 칸 포함)까지 같이 저장한다 */
async function decide(v,reason){
  if(!S.editingId||!isAdmin())return;
  if(S.role==='admin'){const cur=(S.trips.find(x=>x.id===S.editingId)||{}).approval||'승인대기';
    return saveForm({approval:v,approvedAt:today()},cur===v?'저장했습니다':v==='승인'?'승인하고 저장했습니다':'반려하고 저장했습니다')}
  const b=v==='승인'?F('btnApprove'):F('btnReject');b.disabled=true;
  try{
    await store.save(S.editingId,v==='반려'?{approval:v,approvedAt:today(),rejectReason:reason}:{approval:v,approvedAt:today()});
    toast(v==='승인'?'승인했습니다':'반려했습니다');
    if(S.single)afterSingleSave();else clearForm();
    render();
  }catch(e){toast(e.message||'처리하지 못했습니다');refreshLock()}
}
F('btnApprove').addEventListener('click',()=>decide('승인'));
F('btnReject').addEventListener('click',async()=>{const t=S.trips.find(x=>x.id===S.editingId);if(!t)return;const r=await askReject(t);if(r!=null)decide('반려',r)});
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
  try{await store.save(id,data);if(id==='new_'){writeDraft(null);if(S.redoOf){markRedone(S.redoOf);S.redoOf=''}}toast(doneMsg||(S.editingId?'변경 내용을 저장했습니다':(S.role==='staff'?'청구서를 제출했습니다. 승인·정산 결과는 관리자가 안내합니다':'청구서를 저장했습니다')));S.year=data.date.slice(0,4);
    // 팝업은 저장한 그 청구서를 계속 보여 주고, 연 창의 목록을 새로 받게 알린다
    if(S.single)afterSingleSave();else{clearForm();closeDrawer()}render()}
  catch(e){toast(e.message||'저장하지 못했습니다')}
  finally{F('btnSave').disabled=false;refreshLock()}
}
F('btnDel').addEventListener('click',async()=>{
  if(!S.editingId||!confirm('이 청구서를 삭제할까요? 되돌릴 수 없습니다.'))return;
  try{await store.remove(S.editingId);toast('청구서를 삭제했습니다');if(S.single){showSingleGone('청구서를 삭제했습니다.');tellOpener()}else{clearForm();closeDrawer()}}catch(e){toast(e.message||'삭제하지 못했습니다')}
});

/* ---------- 현황/목록 ---------- */
function yearTrips(){return S.trips.filter(t=>S.year==='all'||String(t.date).startsWith(S.year))}
function editedAfter(t){return t.staffUpdatedAt&&t.reviewedAt&&t.staffUpdatedAt>t.reviewedAt&&(t.approval||'승인대기')!=='승인대기'}
function badgeA(a){return a==='승인'?'<span class="badge b-ok">승인</span>':a==='반려'?'<span class="badge b-no">반려</span>':'<span class="badge b-wait">대기</span>'}
function badgeS(s){return s==='처리완료'?'<span class="badge b-ok">완료</span>':'<span class="badge b-wait">미처리</span>'}

/* 관리자 화면 '출장경비 관리' (2026-11-11 시안) — 요약 카드 → 처리할 청구·출장비 기준 → 월별·출장자별 → 청구 목록.
   여기는 화면만 그린다. 승인·반려·정산은 청구서 창과 같은 저장(store.save)을 쓴다. */
const tot=a=>a.reduce((x,t)=>x+(t.total||0),0);
const initial=n=>esc(String(n||'?').slice(0,1));
function render(){
  // 연도
  const years=[...new Set(S.trips.map(t=>String(t.date).slice(0,4)).filter(y=>/^\d{4}$/.test(y)))];
  if(!years.includes(String(new Date().getFullYear())))years.push(String(new Date().getFullYear()));
  years.sort().reverse();
  F('yearSel').innerHTML=years.map(y=>`<option value="${y}">${y}년</option>`).join('')+'<option value="all">전체 기간</option>';
  F('yearSel').value=S.year;

  const rows=yearTrips();
  const total=tot(rows);
  const waitA=rows.filter(QUEUES.wait.has);
  const unpaid=rows.filter(QUEUES.settle.has);
  const label=S.year==='all'?'전체':S.year+'년';
  F('kpis').innerHTML=`
    <div class="scard"><span class="sk">${label} 출장</span><span class="sv num">${rows.length}<small>건</small></span><span class="ss">출장자 ${new Set(rows.map(t=>t.traveler)).size}명</span></div>
    <div class="scard"><span class="sk">청구 합계</span><span class="sv num">${won(total)}<small>원</small></span><span class="ss num">건당 평균 ${won(rows.length?total/rows.length:0)}원</span></div>
    <button type="button" class="scard${waitA.length?' alert':''}" data-f="승인대기"><span class="sk">승인 대기</span><span class="sv num">${waitA.length}<small>건</small></span><span class="ss num">${won(tot(waitA))}원</span></button>
    <button type="button" class="scard" data-f="정산대기"><span class="sk">정산 대기</span><span class="sv num">${unpaid.length}<small>건</small></span><span class="ss num">${won(tot(unpaid))}원 지급 예정</span></button>`;

  renderMonthChart(rows);
  renderPeople(rows);
  F('statsYear').textContent=label;
  renderTable(rows);
  renderBoard();
  renderMyList();
  renderMemberDash();
}
// 승인 대기·정산 대기 카드 → 아래 청구 목록을 그 상태로 걸러서 보여 준다
function showList(f){S.fStatus=f;render();F('listCard').scrollIntoView({behavior:'smooth',block:'start'})}
F('kpis').addEventListener('click',e=>{const k=e.target.closest('[data-f]');if(k)showList(k.dataset.f)});

/* 월별 출장여비 — 1~12월 막대. 이번 달은 포인트 색, 청구가 있는 달은 연한 색, 없는 달은 바닥에 회색 짧은 선 */
function renderMonthChart(rows){
  const W=640,H=230,pt=26,pb=30,px=6,now=today();
  let labels,buckets,cur=-1;
  if(S.year==='all'){
    labels=[...new Set(S.trips.map(t=>String(t.date).slice(0,4)))].filter(y=>/^\d{4}$/.test(y)).sort();
    buckets=labels.map(y=>S.trips.filter(t=>String(t.date).startsWith(y)));cur=labels.indexOf(now.slice(0,4));
    labels=labels.map(y=>y+'년');
  }else{
    labels=Array.from({length:12},(_,i)=>(i+1)+'월');buckets=labels.map((_,i)=>rows.filter(t=>Number(String(t.date).slice(5,7))===i+1));
    if(S.year===now.slice(0,4))cur=Number(now.slice(5,7))-1;
  }
  const sums=buckets.map(tot),max=Math.max(1,...sums);
  F('monthMeta').textContent=(S.year==='all'?'연도별':S.year+'년')+' · '+won(sums.reduce((a,b)=>a+b,0))+'원';
  const ih=H-pt-pb,slot=(W-px*2)/Math.max(1,labels.length),bw=Math.min(30,slot*.56),base=pt+ih;
  const fmt=v=>v>=10000?`${+(v/10000).toFixed(v>=1e6?0:1)}만`:won(v);
  let g=`<line x1="${px}" x2="${W-px}" y1="${base+.5}" y2="${base+.5}" stroke="var(--line)" stroke-width="1.5"/>`;
  labels.forEach((l,i)=>{
    const cx=px+slot*(i+.5),v=sums[i],on=i===cur;
    if(v){const h=Math.max(6,v/max*ih),y=base-h;
      g+=`<rect x="${cx-bw/2}" y="${y}" width="${bw}" height="${h}" rx="6" fill="${on?'var(--blue)':'var(--bar-soft)'}"><title>${esc(l)} ${won(v)}원 · ${buckets[i].length}건</title></rect>`
        +`<text x="${cx}" y="${y-7}" text-anchor="middle" font-size="11" font-weight="700" fill="${on?'var(--blue)':'var(--sub)'}">${fmt(v)}</text>`;
    }else g+=`<line x1="${cx-bw/2+2}" x2="${cx+bw/2-2}" y1="${base-1.5}" y2="${base-1.5}" stroke="var(--gray-mid)" stroke-width="2" stroke-linecap="round"/>`;
    g+=`<text x="${cx}" y="${H-9}" text-anchor="middle" font-size="11.5" font-weight="${on?700:500}" fill="${on?'var(--ink)':'var(--sub)'}">${esc(l)}</text>`;
  });
  F('monthChart').innerHTML=`<svg viewBox="0 0 ${W} ${H}" width="100%" role="img" aria-label="월별 출장여비 막대그래프" style="display:block">${g}</svg>`;
}

/* 출장자별 합계 — 이름 · 금액 · 건수, 막대는 가장 큰 금액에 견준 비율 */
function renderPeople(rows){
  const m={};rows.forEach(t=>{const p=m[t.traveler]||(m[t.traveler]={c:0,t:0});p.c++;p.t+=t.total||0});
  const arr=Object.entries(m).map(([n,p])=>({n,...p})).sort((a,b)=>b.t-a.t);
  F('personMeta').textContent=arr.length?`${arr.length}명`:'';
  if(!arr.length){F('byPerson').innerHTML='<div class="empty">이 기간에 청구된 출장이 없습니다.</div>';return}
  const max=arr[0].t||1;
  F('byPerson').innerHTML=arr.map(p=>`<div class="prow"><span class="av">${initial(p.n)}</span>
    <div class="pmain"><div class="ptop"><b>${esc(p.n)}</b><span class="num"><b>${won(p.t)}원</b> · ${p.c}건</span></div>
    <div class="pbar"><i style="width:${Math.max(2,p.t/max*100).toFixed(1)}%"></i></div></div></div>`).join('');
}

/* 산정기준 확인 — 식대(1인 기준 × 인원 × 일수 × 2)와 자가용 주유비(편도 거리로 계산한 금액)를 넘었는지.
   청구서의 식대 '기준' 안내·주유비 계산과 같은 식이다. 숙박·기타는 영수증 실비라 보지 않는다 */
function ruleCheck(t){
  const over=[],comp=String(t.companions||'').split(',').filter(x=>x.trim()).length||t.companionCount||0;
  const unit=S.settings.mealUnit||15000,days=periodDays(t.period);
  if((t.meal||0)>unit*(1+comp)*days*2)over.push('식대');
  const p=isCar(t.mode)?fuelPrice(t.mode):null;
  if(p&&t.km>0&&(t.transport||0)>fuelCost(t.km,p))over.push('주유비');
  return over;
}
// 관리자 화면으로 들어올 때 뜨는 팝업의 목록 한 줄
function qrowHtml({t,k}){return `<div class="qrow" data-id="${esc(t.id)}" tabindex="0"><span class="badge ${k==='승인대기'?'b-wait':'b-none'}">${k}</span><span class="who">${esc(t.traveler)}</span><span class="what">${esc(t.date)} ${esc(t.place)} · ${esc(t.purpose)}</span><span class="num">${won(t.total)}원</span></div>`}
/* 관리자 화면으로 들어올 때 대기 건이 있으면 건수를 팝업으로 알린다(연도와 상관없이 전체).
   건수를 누르면 가장 오래된 청구서 창이, 건을 누르면 그 청구서 창이 열린다. 띄웠으면 true */
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

/* 청구 목록 — 상태 탭(전체·승인 대기·정산 대기·정산 완료)과 출장자·출장지 검색. 최근 출장부터 */
const LIST_TABS=[['all','전체'],['승인대기','승인 대기'],['정산대기','정산 대기'],['처리완료','정산 완료'],['반려','반려']];
function statusMatch(t,f){
  if(f==='승인대기')return QUEUES.wait.has(t);
  if(f==='정산대기')return QUEUES.settle.has(t);
  if(f==='처리완료')return t.status==='처리완료';
  if(f==='반려')return t.approval==='반려';
  return true;
}
function filtered(rows){
  const q=S.q.trim().toLowerCase();
  return rows.filter(t=>statusMatch(t,S.fStatus)&&(!q||[t.traveler,t.place,t.purpose,t.companions,t.note].join(' ').toLowerCase().includes(q)))
    .sort((a,b)=>String(b.date).localeCompare(String(a.date))||String(b.id).localeCompare(String(a.id)));
}
function renderTable(rows){
  const base=S.year==='all'?S.trips:rows;
  if(!LIST_TABS.some(([k])=>k===S.fStatus))S.fStatus='all';
  F('fTabs').innerHTML=LIST_TABS.map(([k,l])=>`<button type="button" role="tab" data-f="${k}" aria-selected="${S.fStatus===k}">${l}<span class="num">${base.filter(t=>statusMatch(t,k)).length}</span></button>`).join('');
  const list=filtered(base);
  F('listMeta').textContent=`${list.length}건`;
  if(!list.length){F('tbody').innerHTML=`<tr class="none"><td colspan="5" class="empty">${S.trips.length?'조건에 맞는 청구가 없습니다.':'아직 청구서가 없습니다.'}</td></tr>`;F('tfoot').innerHTML='';return}
  F('tbody').innerHTML=list.map(t=>{const st=stageOf(t),n=rcCount(t);
    return `<tr data-id="${esc(t.id)}" tabindex="0">
    <td class="num tdate">${esc(t.date)}<small>${esc(t.period||'당일')}</small></td>
    <td><span class="who"><span class="av xs">${initial(t.traveler)}</span>${esc(t.traveler)}</span></td>
    <td class="tplace"><b>${esc(t.place)}</b>${t.purpose?`<span title="${esc(t.purpose)}">${esc(t.purpose)}</span>`:''}</td>
    <td class="r num"><b>${won(t.total)}원</b>${n?`<small>영수증 ${n}</small>`:''}</td>
    <td><span class="sbadge st-${st.key}">${esc(st.label)}</span>${editedAfter(t)?' <span class="sbadge st-no">승인 후 수정</span>':''}</td></tr>`}).join('');
  F('tfoot').innerHTML=`<tr><td colspan="3">합계 ${list.length}건</td><td class="r num">${won(tot(list))}원</td><td></td></tr>`;
  highlightRow();
}
function highlightRow(){document.querySelectorAll('#tbody tr,#myList .myrow').forEach(r=>r.classList.toggle('sel',r.dataset.id===S.editingId))}
F('tbody').addEventListener('click',e=>{const r=e.target.closest('tr[data-id]');if(!r)return;const t=S.trips.find(x=>x.id===r.dataset.id);if(t)(S.role==='staff'?fillForm:openTrip)(t)});
F('tbody').addEventListener('keydown',e=>{if(e.key==='Enter')e.target.click()});
F('fTabs').addEventListener('click',e=>{const b=e.target.closest('[data-f]');if(!b)return;S.fStatus=b.dataset.f;render()});
F('q').addEventListener('input',e=>{S.q=e.target.value;render()});
F('yearSel').addEventListener('change',e=>{S.year=e.target.value;render()});

/* ---------- 유가·작성기준 ---------- */
const FUEL_ROWS=[[20,'청원, 증평'],[30,'연기, 진천'],[40,''],[50,'괴산, 음성, 보은, 대전, 공주'],[60,'옥천, 천안'],[70,'충주, 아산, 계룡'],[80,'금산, 평택, 안성'],[90,'영동, 예산, 오산, 청양, 부여, 이천'],[100,'제천, 여주, 논산'],[110,'홍성, 용인, 경기도 광주'],[120,'수원, 보령'],[130,'성남, 화성, 단양'],[140,'서울, 당진'],[150,'서산'],[160,'서천, 태안'],[170,''],[180,'부천'],[190,'파주'],[200,'']];
const fuelCost=(km,price)=>Math.ceil(km/(S.settings.eff||10)*price*2/1000)*1000;
function renderInfo(){
  const p=S.settings.prices||{},f=S.settings.fuel||{};
  const auto=S.settings.autoFuel!==false;   // 켜져 있으면 받은 유가가 곧 산정 기준이다(서버가 맞춘다)
  // 관리자 화면 '출장비 기준' 카드 아래 — 유가 출처·기준 주간. 실패·키 없음·수동 적용은 여기서 알린다(예전 '오늘의 유가' 카드 아래 줄)
  const week=String(p.label||S.settings.priceBase||'').replace(/\s*주간 평균$/,'');
  const err=p.error?`<span class="perr">최근 갱신 실패: ${esc(p.error)}</span>`:'';
  const noKey=isSettler()&&!S.settings.hasOpinetKey?'<span class="perr">설정에 오피넷 API 키를 넣으면 유가가 자동으로 바뀝니다.</span>':'';
  F('ruleSrc').innerHTML=`<span>유가 출처: 오피넷 유가정보 API${week?` · ${esc(week)} 기준 주간`:''}</span>${err}${noKey}`
    +(isSettler()&&!auto?'<button type="button" class="btn xs" id="btnPriceApply">이 가격을 산정 기준으로 적용</button>':'');
  // 헤더 — 마지막 갱신 시각(HH:MM)
  const fa=String(p.fetchedAt||p.checkedAt||'').replace('T',' ');
  F('fuelTime').textContent=fa.length>=16?`${fa.slice(11,16)} 갱신`:'';
  F('fuelUpd').title=fa?`${fa.slice(0,16)} 갱신${p.source?' · '+p.source:''}`:'';
  F('fuelUpd').hidden=false;
  F('btnRuleEdit').textContent=isSettler()?'기준 수정':'전체 기준';F('btnRuleAll').hidden=!isSettler();
  F('ruleMeal').textContent=won(S.settings.mealUnit||15000);
  document.querySelectorAll('.rbMeal').forEach(x=>x.textContent=won(S.settings.mealUnit||15000));
  // 헤더의 작은 알약 — 오늘 유가 · 휘발유 · 경유 · LPG (같은 유가 API 값)
  const pv=k=>p[k]!=null?won(p[k]):(f[k]!=null?won(f[k]):'-');
  F('fuelPill').hidden=false;
  F('fuelPill').innerHTML=`<b>오늘 유가</b><span>휘발유 ${pv('gas')}원</span><span>경유 ${pv('diesel')}원</span><span>LPG ${pv('lpg')}원</span>`;
  F('fuelPill').title=p.label||'';
  F('ruleBase').textContent=`(${S.settings.priceBase||'산정 기준'} 유가, 연비 ${S.settings.eff||10}km/L)`;
  F('fuelTable').innerHTML=FUEL_ROWS.map(([km,pl])=>`<tr><td>${km}km 이내</td><td class="r num">${won(fuelCost(km,f.gas||0))}</td><td class="r num">${won(fuelCost(km,f.diesel||0))}</td><td class="r num">${won(fuelCost(km,f.lpg||0))}</td><td>${esc(pl)}</td></tr>`).join('');
}
// 헤더의 동그란 새로고침 — 예전 '지금 갱신'(정산 관리자). 받은 뒤 화면 전체를 새로 그린다
F('btnFuelRefresh').addEventListener('click',async e=>{const b=e.currentTarget;if(b.classList.contains('spin'))return;b.classList.add('spin');b.disabled=true;
  try{await api('prices/refresh',{});await loadState();toast('유가를 새로 가져왔습니다')}catch(err){toast(err.message)}finally{b.classList.remove('spin');b.disabled=false}});
F('ruleSrc').addEventListener('click',async e=>{
  if(e.target.id!=='btnPriceApply')return;const p=S.settings.prices||{};
  if(!confirm(`주유비 산정 기준을 ${p.label||'현재'} 유가로 바꿀까요?
휘발유 ${p.gas} / 경유 ${p.diesel} / LPG ${p.lpg}

작성기준상 기준유가는 매주 최신 유가로 산정합니다.`))return;
  try{await api('prices/apply',{});await loadState();toast('주유비 산정 기준을 바꿨습니다')}catch(err){toast(err.message)}
});
// [기준 수정] — 정산 관리자는 설정(식대·유가·연비), 승인 관리자는 전체 기준을 본다. [전체 기준 보기]는 작성기준 전체
F('btnRuleEdit').addEventListener('click',()=>{if(isSettler())F('btnSettings').click();else F('btnRules').click()});
F('btnRuleAll').addEventListener('click',()=>F('btnRules').click());

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
  updateRcState();saveDraftSoon();
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
// 관리자 '처리할 청구'의 [영수증 보기] — 청구서를 열지 않고 그 건의 영수증을 같은 창에 띄운다
function openTripReceipts(t,i=0){
  const rc=t.receipts||{};
  rcList=['transport','toll','meal','etc'].flatMap(x=>(rc[x]||[]).map(r=>({k:x,r})));rcPos=Math.min(Math.max(0,i),Math.max(0,rcList.length-1));
  if(!rcList.length){toast('첨부된 영수증이 없습니다');return}
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


/* ================================================================
   2026-11-11 시안 — 구성원 '출장경비 대시보드'와 오른쪽 드로어 청구서.
   여기는 화면만 그린다. 저장·계산·API 는 위의 함수(saveForm·updateTotal·api …)를 그대로 쓴다.
   ================================================================ */
const IC=(()=>{const s=d=>`<svg class="ic" viewBox="0 0 24 24" aria-hidden="true">${d}</svg>`;return{
  x:s('<path d="M6 6l12 12M18 6L6 18"/>'),
  alert:s('<circle cx="12" cy="12" r="8.5"/><path d="M12 7.8v5.2M12 16.2h.01"/>'),
  check:s('<path d="M5 12.5l4.5 4.5L19 7.5"/>'),
  doc:s('<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4M10 12h5M10 16h5"/>'),
  clock:s('<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>'),
  redo:s('<path d="M4.5 12a7.5 7.5 0 1 0 2.2-5.3"/><path d="M4.5 4.5v3.8h3.8"/>'),
  clip:s('<path d="M15.5 7.5l-6.4 6.4a1.8 1.8 0 0 0 2.5 2.5l6.6-6.6a3.4 3.4 0 0 0-4.8-4.8l-6.7 6.7a5 5 0 0 0 7.1 7.1l6-6"/>')}})();

/* ---------- 드로어 ---------- */
const drawerOn=()=>document.body.classList.contains('drawer-open');
function openDrawer(){if(S.role!=='staff'||S.single)return;document.body.classList.add('drawer-open');F('form').scrollTop=0}
function closeDrawer(){if(!drawerOn())return;document.body.classList.remove('drawer-open');closeCalPop();F('compSugg').hidden=true}
F('scrim').addEventListener('click',()=>{closeDrawer();closeKD()});
document.addEventListener('keydown',e=>{
  if(e.key!=='Escape'||document.querySelector('dialog[open]'))return;
  if(!F('calPop').hidden){closeCalPop();F('dateField').focus();return}
  if(kdOn()){closeKD();return}
  if(drawerOn())closeDrawer();
});
// [+ 새 출장비 청구] — 작성 중이던 청구가 있으면 이어서 쓴다
function newClaim(){clearForm();S.redoOf='';const d=readDraft();if(draftHasContent(d))restoreDraft(d);openDrawer();renderDraftNote()}
F('btnNewClaim').addEventListener('click',newClaim);

/* ---------- 출장 기간 달력 팝업 ---------- */
function closeCalPop(){F('calPop').hidden=true;F('dateField').setAttribute('aria-expanded','false')}
F('dateField').addEventListener('click',()=>{const open=F('calPop').hidden;F('calPop').hidden=!open;F('dateField').setAttribute('aria-expanded',String(open));if(open)renderCal()});
F('calDone').addEventListener('click',()=>{closeCalPop();F('dateField').focus()});
document.addEventListener('mousedown',e=>{if(!F('calPop').hidden&&!e.target.closest('.datewrap'))closeCalPop()});

/* ---------- 영수증이 필요한 항목 ----------
   금액이 있는데 영수증이 없으면 카드 테두리를 주황색으로. 자가용 주유비(교통비)는 오늘 유가로 계산하므로 영수증이 필요 없다. */
const RC_KEYS=['transport','toll','meal','etc'];
const isCar=mode=>String(mode||'').startsWith('자가용');
function rcMissingOf(o){return RC_KEYS.filter(k=>parseNum(o[k])>0&&!((o.receipts||{})[k]||[]).length&&!(k==='transport'&&isCar(o.mode)))}
function updateRcState(){
  RC_KEYS.forEach(k=>{
    const card=F('xc-'+k);if(!card)return;
    const n=(S.receipts[k]||[]).length,amt=parseNum(F(k).value),free=k==='transport'&&isCar(F('mode').value);
    card.classList.toggle('need',amt>0&&!n&&!free);
    card.classList.toggle('free',free);
    F('rcn-'+k).textContent=n?`영수증 ${n}장`:'';
  });
  // 기준 초과 — 관리자 화면의 ruleCheck 와 같은 식(식대: 1인 기준×인원×일수×2, 자가용: 주유비 계산값)
  const people=1+companionsArr().length,unit=S.settings.mealUnit||15000,mode=F('mode').value,fp=isCar(mode)?fuelPrice(mode):null,km=parseNum(F('km').value);
  F('xc-meal').classList.toggle('over',parseNum(F('meal').value)>unit*people*periodDays(F('period').value)*2);
  F('xc-transport').classList.toggle('over',!!(fp&&km>0&&parseNum(F('transport').value)>fuelCost(km,fp)));
  renderSumBar();
}

/* ---------- 진행 단계 ① 청구 — ② 대표 승인 — ③ 정산 ---------- */
function stageOf(t){
  if(!t)return{key:'draft',label:'작성 중',stage:'작성 중',bars:['cur','','']};
  const a=t.approval||'승인대기';
  if(a==='반려')return{key:'no',label:'반려',stage:'반려됨',bars:['on','no','']};
  if(a==='승인대기')return{key:'wait',label:'승인 대기',stage:'대표 승인 대기',bars:['on','cur','']};
  if(t.status!=='처리완료')return{key:'settle',label:'정산 대기',stage:'정산 대기',bars:['on','on','cur']};
  return{key:'done',label:'정산 완료',stage:'정산 완료',bars:['on','on','on']};
}
function renderSteps(){
  const st=stageOf(S.trips.find(x=>x.id===S.editingId)).bars;
  F('steps').querySelectorAll('li').forEach((li,i)=>{li.className=st[i]==='on'?'done':st[i]==='cur'?'cur':st[i]==='no'?'no':''});
}

/* ---------- 작성 중(임시 보관) ----------
   새 청구를 쓰는 동안 입력한 내용을 이 브라우저에 자동으로 보관한다(서버에는 제출할 때만 간다).
   드로어를 닫았다 다시 열거나 새로고침해도 이어서 쓸 수 있다. 제출하면 지운다. */
const DRAFT_KEY=()=>'bl_travel_draft_v1:'+(S.me||'');
function readDraft(){try{return JSON.parse(localStorage.getItem(DRAFT_KEY())||'null')}catch(e){return null}}
function writeDraft(d){try{d?localStorage.setItem(DRAFT_KEY(),JSON.stringify(d)):localStorage.removeItem(DRAFT_KEY())}catch(e){}}
function draftHasContent(d){return !!d&&!!(d.place||d.purpose||parseNum(d.transport)||parseNum(d.toll)||parseNum(d.meal)||parseNum(d.etc)||rcCount(d))}
function draftData(){
  return{traveler:F('traveler').value,companions:companionsArr().join(', '),date:F('date').value,period:F('period').value,
    place:F('place').value.trim(),purpose:F('purpose').value.trim(),mode:modeValue(),km:parseNum(F('km').value)||0,
    transport:parseNum(F('transport').value),toll:parseNum(F('toll').value),meal:parseNum(F('meal').value),etc:parseNum(F('etc').value),
    etcNote:F('etcNote').value.trim(),receipts:S.receipts,redoOf:S.redoOf||'',savedAt:new Date().toISOString()};
}
function saveDraftSoon(){
  if(S.role!=='staff'||S.editingId||!drawerOn())return;
  clearTimeout(saveDraftSoon._t);
  saveDraftSoon._t=setTimeout(()=>{if(S.editingId||!drawerOn())return;const d=draftData();writeDraft(draftHasContent(d)?d:null);renderDraftNote();renderMemberDash()},400);
}
function restoreDraft(d){
  const staff=staffList(),comps=String(d.companions||'').split(',').map(x=>x.trim()).filter(Boolean);
  if(d.traveler){F('traveler').value=d.traveler;if(F('traveler').value!==d.traveler){renderStaff();F('traveler').value=d.traveler}}
  S.companions=new Set(comps.filter(n=>staff.includes(n)));F('compExtra').value=comps.filter(n=>!staff.includes(n)).join(', ');
  F('period').value=d.period||'당일';setDate(d.date||today());F('place').value=d.place||'';F('purpose').value=d.purpose||'';
  setModeValue(d.mode||'자가용(휘발유)');F('km').value=d.km||'';
  RC_KEYS.forEach(k=>F(k).value=parseNum(d[k])?won(parseNum(d[k])):'');F('etcNote').value=d.etcNote||'';
  S.receipts={...emptyRc(),...structuredClone(d.receipts||{})};S.redoOf=d.redoOf||S.redoOf||'';
  renderThumbs();renderChips();updateFuelInfo();updateTotal();
}
['input','change'].forEach(ev=>F('form').addEventListener(ev,saveDraftSoon));
function renderDraftNote(){
  const n=F('draftNote'),t=S.trips.find(x=>x.id===S.editingId),fresh=S.role==='staff'&&!S.editingId&&!S.single;
  F('btnDraft').hidden=!fresh;if(fresh)F('btnSave').textContent='대표 승인 요청';
  if(t){n.textContent=stageOf(t).stage;n.className='draftnote st-'+stageOf(t).key;return}
  const d=readDraft();
  n.className='draftnote st-draft';
  n.textContent=draftHasContent(d)?`작성 중 · 자동 저장됨 ${d.savedAt?new Date(d.savedAt).toTimeString().slice(0,5):''}`:'작성 중 · 입력하면 자동 저장됩니다';
}

/* ---------- 출장 기준 ---------- */
F('ruleShow').addEventListener('click',()=>{const open=F('ruleBox').hidden;F('ruleBox').hidden=!open;F('ruleShow').setAttribute('aria-expanded',String(open));F('ruleShow').textContent=open?'기준 닫기':'기준 보기'});
F('btnRules').addEventListener('click',()=>{F('rulePanel').open=true;F('ruleDlg').showModal()});
F('ruleDlgClose').addEventListener('click',()=>F('ruleDlg').close());
F('ruleDlg').addEventListener('click',e=>{if(e.target===F('ruleDlg'))F('ruleDlg').close()});

/* ================================================================
   2026-11-13 시안 — 구성원 '출장경비 대시보드'.
   파란 배너 → 할 일 + 이번 달 경비 구성 → 출장비 기준 알약 → 진행 보드(작성 중 → 대표 승인 대기 → 정산 대기 → 정산 완료).
   여기는 화면만 그린다. 저장·계산·영수증 올리기는 위의 함수(saveForm·updateTotal·api …)를 그대로 쓴다.
   ================================================================ */
const KO_MON=m=>`${+m}월`;
function daysAgo(n){const d=new Date();d.setDate(d.getDate()-n);return ymd(d.getFullYear(),d.getMonth()+1,d.getDate())}
// 반려된 청구 — 최근 60일 안에 반려됐고 아직 [다시 작성하기]로 다시 내지 않은 것만 할 일·작성 중 칸에 보인다.
// 다시 낸 건은 이 브라우저에 기억해 둔다(서버에는 '다시 냈다'는 칸이 없다)
const REDONE_KEY=()=>'bl_travel_redone_v1:'+(S.me||'');
function redone(){try{return JSON.parse(localStorage.getItem(REDONE_KEY())||'[]')}catch(e){return []}}
function markRedone(id){try{const a=redone();if(!a.includes(id)){a.push(id);localStorage.setItem(REDONE_KEY(),JSON.stringify(a.slice(-50)))}}catch(e){}}
function recentRejected(){const cut=daysAgo(60),done=redone();
  return S.trips.filter(t=>t.approval==='반려'&&!done.includes(t.id)&&String(t.approvedAt||t.reviewedAt||t.date).slice(0,10)>=cut)}
const draftItem=d=>({draft:true,...d,date:d.date||today(),total:RC_KEYS.reduce((x,k)=>x+parseNum(d[k]),0)});
const compN=t=>t.companionCount||String(t.companions||'').split(',').filter(x=>x.trim()).length;
const tripMeta=t=>[t.period||'당일',t.mode||'',compN(t)?`동행 ${compN(t)}명`:'단독'].filter(Boolean).map(esc).join(' · ');
// 영수증 누락은 아직 고칠 수 있는 청구(작성 중·승인 대기)만 알린다
const missOf=t=>(t.draft||(t.approval||'승인대기')==='승인대기')?rcMissingOf(t):[];

function renderMemberDash(){
  if(S.role!=='staff')return;
  const ym=today().slice(0,7),mine=S.trips;
  const d=readDraft(),dHas=draftHasContent(d),rej=recentRejected();
  const wait=mine.filter(t=>(t.approval||'승인대기')==='승인대기');
  const month=mine.filter(t=>String(t.date).startsWith(ym)&&t.approval!=='반려');
  F('mbTitle').textContent=`${S.me?S.me+' 님의 ':''}${+ym.slice(5,7)}월 출장경비`;
  F('mbAmt').innerHTML=`${won(tot(month))}<small>원</small>`;
  F('mbSub').textContent=`청구 ${month.length}건 · 대표 승인 대기 ${won(tot(wait))}원 · 작성 중 ${(dHas?1:0)+rej.length}건`;
  renderTodos(d,dHas,wait,rej);
  renderDonut(month);
  renderMBoard(d,dHas,rej);
}

/* 할 일 — 처리해야 할 것만 모은다: 영수증 누락(빨강) · 반려(빨강) · 작성 중(회색) · 대표 승인 대기(주황, 할 일 없음) */
function renderTodos(d,dHas,wait,rej){
  const items=[],missLine=m=>`${m.map(k=>RC_LABEL[k]).join('·')} ${m.length}장 누락`;
  const dMiss=dHas?rcMissingOf(d):[];
  if(dMiss.length)items.push({c:'no',ic:IC.alert,t:`${d.place?esc(d.place)+' 출장':'작성 중인 청구'} 영수증 첨부`,s:`${missLine(dMiss)} · 아직 제출 전`,b:'첨부하기',act:'attach-draft'});
  wait.forEach(t=>{const m=rcMissingOf(t);if(m.length)items.push({c:'no',ic:IC.alert,t:`${esc(t.place)} 출장 영수증 첨부`,s:`${missLine(m)} · ${esc(shortDate(t.date))} 출장 · 승인 전이라 고칠 수 있어요`,b:'첨부하기',act:'attach',id:t.id})});
  rej.forEach(t=>items.push({c:'no',ic:IC.redo,t:`${esc(t.place)} 출장이 반려됐어요`,s:t.rejectReason?`사유 · ${esc(t.rejectReason)}`:'반려 사유가 적혀 있지 않아요',b:'다시 작성하기',act:'redo',id:t.id}));
  if(dHas&&!dMiss.length)items.push({c:'draft',ic:IC.doc,t:'작성 중인 청구가 있어요',s:`${esc(d.place||'출장지 미입력')} · ${won(draftItem(d).total)}원 · 이 브라우저에 자동 저장됨`,b:'이어서 작성하기',act:'draft'});
  if(wait.length)items.push({c:'wait',ic:IC.clock,t:`대표 승인 대기 ${wait.length}건`,s:`합계 ${won(tot(wait))}원 · 따로 할 일 없음`});
  const todo=items.filter(x=>x.b).length;
  F('todoMeta').textContent=todo?`${todo}건`:'';
  F('todoList').innerHTML=todo||items.length?items.map(x=>`<div class="todo t-${x.c}">
      <span class="tic">${x.ic}</span><div class="tm"><b>${x.t}</b><span title="${x.s.replace(/"/g,'&quot;')}">${x.s}</span></div>
      ${x.b?`<button type="button" class="btn sm" data-act="${x.act}"${x.id?` data-id="${esc(x.id)}"`:''}>${x.b}</button>`:''}</div>`).join('')
    :`<div class="todo-empty">${IC.check}<b>처리할 일이 없어요</b><span>출장을 다녀오면 [새 출장비 청구]로 시작하세요.</span></div>`;
}
F('todoList').addEventListener('click',e=>{const b=e.target.closest('[data-act]');if(!b)return;memberAct(b.dataset.act,b.dataset.id)});
function memberAct(act,id){
  const t=id&&S.trips.find(x=>x.id===id);
  if(act==='draft'){newClaim();return}
  if(act==='attach-draft'){newClaim();focusMissing();return}
  if(act==='attach'&&t){fillForm(t);focusMissing();return}
  if(act==='redo'&&t){rewriteFrom(t);return}
  if(act==='open'&&t)fillForm(t);
}
// 청구 작성 창에서 영수증이 빠진 첫 항목으로 가서 잠깐 눈에 띄게
function focusMissing(){setTimeout(()=>{const c=document.querySelector('.xcard.need')||F('xc-transport');if(!c)return;
  c.scrollIntoView({block:'center'});c.classList.remove('flash');void c.offsetWidth;c.classList.add('flash');
  const a=c.querySelector('.att input');if(a)a.focus({preventScroll:true})},260)}
/* [다시 작성하기] — 반려된 청구 내용을 새 청구로 불러온다. 영수증은 그 청구에 묶여 있어 다시 첨부해야 한다 */
function rewriteFrom(t){
  if(draftHasContent(readDraft())&&!confirm('작성 중인 청구가 있습니다. 반려된 청구 내용으로 바꿔 쓸까요?\n작성 중이던 내용은 사라집니다.'))return;
  clearForm();S.redoOf=t.id;
  restoreDraft({traveler:t.traveler,companions:t.companions,date:t.date,period:t.period,place:t.place,purpose:t.purpose,mode:t.mode,km:t.km,
    transport:t.transport,toll:t.toll,meal:t.meal,etc:t.etc,etcNote:t.etcNote,receipts:emptyRc()});
  openDrawer();writeDraft(draftData());renderDraftNote();renderMemberDash();
  toast('반려된 청구 내용을 불러왔습니다. 고칠 곳을 고치고 영수증을 다시 첨부해 주세요');
}

/* 이번 달 경비 구성 — 도넛. 교통(교통비+통행료) · 숙박·기타(기타 항목) · 식대 */
function renderDonut(month){
  const parts=[['tr','교통',t=>(t.transport||0)+(t.toll||0)],['lodge','숙박·기타',t=>t.etc||0],['meal','식대',t=>t.meal||0]]
    .map(([k,l,f])=>({k,l,v:month.reduce((x,t)=>x+f(t),0)}));
  const all=parts.reduce((x,p)=>x+p.v,0),R=42,C=2*Math.PI*R;
  let off=0,arcs='';
  parts.forEach(p=>{if(!p.v)return;const len=p.v/all*C;
    arcs+=`<circle class="m-${p.k}" cx="60" cy="60" r="${R}" stroke-dasharray="${len.toFixed(2)} ${(C-len).toFixed(2)}" stroke-dashoffset="${(-off).toFixed(2)}"><title>${p.l} ${won(p.v)}원</title></circle>`;off+=len});
  F('mixDonut').innerHTML=`<svg viewBox="0 0 120 120" role="img" aria-label="이번 달 경비 구성"><circle class="dtrack" cx="60" cy="60" r="${R}"/><g transform="rotate(-90 60 60)">${arcs}</g>
    <text x="60" y="55" text-anchor="middle" class="dk">이번 달</text><text x="60" y="73" text-anchor="middle" class="dv num">${all>=1e4?`${+(all/1e4).toFixed(all>=1e6?0:1)}만`:won(all)}원</text></svg>`;
  F('mixList').innerHTML=all?parts.map(p=>`<div class="mrow"><span><i class="m-${p.k}"></i>${p.l}</span><span class="num">${Math.round(p.v/all*100)}%</span><b class="num">${won(p.v)}원</b></div>`).join('')
    :'<div class="empty">이번 달 청구가 아직 없어요.</div>';
}

/* 진행 보드 — 작성 중(이 브라우저에 보관 중인 청구 + 반려된 청구) → 대표 승인 대기 → 정산 대기 → 정산 완료(최근 3개월) */
const MCOLS=[['draft','작성 중','아직 제출 전 · 이 브라우저에 보관'],['wait','대표 승인 대기','제출 완료 · 대표님 확인 중'],['settle','정산 대기','승인 완료 · 지급 준비 중'],['done','정산 완료','지급 완료 · 최근 3개월']];
function renderMBoard(d,dHas,rej){
  const cut=daysAgo(92),c={draft:[...(dHas?[draftItem(d)]:[]),...rej],wait:[],settle:[],done:[]};
  S.trips.forEach(t=>{const k=stageOf(t).key;
    if(k==='wait'||k==='settle')c[k].push(t);
    else if(k==='done'&&String(t.processedAt||t.date).slice(0,10)>=cut)c.done.push(t)});
  const desc=(a,b)=>String(b.date).localeCompare(String(a.date));
  c.wait.sort(desc);c.settle.sort(desc);c.done.sort((a,b)=>String(b.processedAt||b.date).localeCompare(String(a.processedAt||a.date)));
  F('mBoard').innerHTML=MCOLS.map(([k,l,s])=>`<section class="mcol" aria-label="${l}">
    <div class="mhead"><i class="kdot k-${k}"></i><b>${l}</b><span class="kcnt num">${c[k].length}</span></div>
    <div class="mdesc">${s}</div>
    <div class="mcards">${c[k].length?c[k].map(t=>mcardHtml(t,k)).join(''):'<div class="kempty">비어 있음</div>'}</div></section>`).join('');
}
function mcardHtml(t,col){
  const miss=missOf(t),rejd=!t.draft&&t.approval==='반려',dt=String(t.date||'');
  const btn=t.draft?`<button type="button" class="btn sm primary" data-act="draft">이어서 작성하기</button>`
    :rejd?`<button type="button" class="btn sm" data-act="redo" data-id="${esc(t.id)}">다시 작성하기</button>`
    :miss.length?`<button type="button" class="btn sm" data-act="attach" data-id="${esc(t.id)}">영수증 첨부하기</button>`:'';
  return `<article class="mcard${miss.length?' miss':''}${t.draft?' draft':''}${rejd?' rej':''}" ${t.draft?'data-act="draft"':`data-act="open" data-id="${esc(t.id)}"`} tabindex="0">
    <div class="mc-top"><div class="dbox num"><span>${dt?KO_MON(dt.slice(5,7)):''}</span><b>${esc(dt.slice(8,10)||'—')}</b></div>
      <div class="mc-main"><div class="mc-pl"><b>${esc(t.place||'출장지 미입력')}</b>${rejd?'<span class="sbadge st-no">반려</span>':t.draft?'<span class="sbadge st-draft">작성 중</span>':''}</div><div class="mc-meta">${tripMeta(t)}</div></div>
      <b class="mc-amt num">${won(t.total)}원</b></div>
    ${t.purpose?`<div class="mc-pu" title="${esc(t.purpose)}">${esc(t.purpose)}</div>`:''}
    ${miss.length?`<div class="mmiss">${IC.alert}영수증 ${miss.length}장이 빠져 있어요</div>`:''}
    ${rejd?`<div class="kc-reason" title="${esc(t.rejectReason||'')}">사유 · ${esc(t.rejectReason||'적힌 사유가 없어요')}</div>`:''}
    ${btn?`<div class="mc-act">${btn}</div>`:''}
  </article>`;
}
F('mBoard').addEventListener('click',e=>{const b=e.target.closest('[data-act]');if(!b)return;memberAct(b.dataset.act,b.dataset.id)});
F('mBoard').addEventListener('keydown',e=>{if(e.key==='Enter'&&e.target.classList.contains('mcard'))e.target.click()});
F('btnAllHist').addEventListener('click',()=>{renderMyList();F('myDlg').showModal()});
// [영수증 먼저 올리기] — 새 청구(또는 작성 중 청구)를 열고 경비 항목으로 간다. 올린 영수증은 작성 중 청구에 자동 저장된다
F('btnRcFirst').addEventListener('click',()=>{newClaim();setTimeout(()=>{F('fsMoney').scrollIntoView({block:'start'});
  document.querySelectorAll('.xcard .att').forEach(a=>{a.classList.remove('flash');void a.offsetWidth;a.classList.add('flash')})},260);
  toast('영수증을 올릴 항목에서 [사진 첨부]를 눌러 주세요. 자동으로 저장됩니다')});

/* 청구 작성 창 아래 — 합계 옆 항목 비율 막대, [임시 저장] */
function renderSumBar(){
  const parts=[['transport','교통비'],['toll','통행료'],['meal','식대'],['etc','기타']].map(([k,l])=>({k,l,v:parseNum(F(k).value)})),all=parts.reduce((x,p)=>x+p.v,0);
  F('sumBar').innerHTML=all?`<div class="sfb">${parts.filter(p=>p.v).map(p=>`<i class="m-${p.k}" style="width:${(p.v/all*100).toFixed(2)}%"></i>`).join('')}</div>
    <div class="sfl">${parts.filter(p=>p.v).map(p=>`<span><i class="m-${p.k}"></i>${p.l} <b class="num">${Math.round(p.v/all*100)}%</b></span>`).join('')}</div>`
    :'<div class="sfb"></div><div class="sfl"><span>금액을 넣으면 항목 비율이 보여요</span></div>';
}
F('btnDraft').addEventListener('click',()=>{
  const d=draftData();
  if(!draftHasContent(d)){toast('저장할 내용이 없습니다');return}
  writeDraft(d);closeDrawer();renderMemberDash();toast('임시 저장했습니다. 진행 현황의 작성 중 칸에서 이어서 쓸 수 있어요');
});

/* ================================================================
   2026-11-13 칸반 시안 — 관리자 '청구 보드'.
   승인 대기 → 정산 대기 → 정산 완료, 그리고 반려. 카드를 다음 열로 끌거나 카드·상세 창의 단추를 누르면
   청구서 창과 같은 값을 저장한다(store.save). 화면만 새로 그리고, 저장 규칙은 서버(lib/trips.php)가 그대로 정한다.
   권한: [승인]·[반려]·[승인 대기로 되돌리기]는 승인 관리자(대표)만, [정산 완료]는 정산 관리자만 보인다.
   ================================================================ */
const canApprove=()=>S.role==='approver';
S.aview=(()=>{try{return localStorage.getItem('bl_admin_view')||'board'}catch(e){return 'board'}})();
if(!['board','cal','stats'].includes(S.aview))S.aview='board';
function setAview(v){
  S.aview=v;document.body.dataset.aview=v;
  F('viewSeg').querySelectorAll('[data-v]').forEach(b=>b.setAttribute('aria-selected',b.dataset.v===v));
  try{localStorage.setItem('bl_admin_view',v)}catch(e){}
  if(v!=='board')closeKD();
  window.scrollTo({top:0});
}
F('viewSeg').addEventListener('click',e=>{const b=e.target.closest('[data-v]');if(b&&b.dataset.v!==S.aview)setAview(b.dataset.v)});

// 사람마다 다른 색 — 이름으로 8가지 중 하나를 고른다(같은 사람은 늘 같은 색)
function pc(name){let h=0;for(const c of String(name||''))h=(h*31+c.codePointAt(0))>>>0;return 'pc'+(h%8)}
function avHtml(name,cls=''){return `<span class="av ${pc(name)}${cls?' '+cls:''}">${initial(name)}</span>`}
const KCOLS=[['wait','승인 대기'],['settle','정산 대기'],['done','정산 완료'],['no','반려']];
const KMOVES={'wait>settle':'approve','wait>no':'reject','settle>done':'settle','no>wait':'revert'};
function moveAct(from,to){const a=KMOVES[from+'>'+to];if(!a)return null;return (a==='settle'?isSettler():canApprove())?a:null}
function shortDate(d){d=String(d||'');return d.slice(0,4)===today().slice(0,4)?d.slice(5).replace('-','.'):d.replace(/-/g,'.')}
// 정산 완료·반려 열은 이번 달 건만 — 정산 완료는 처리일, 반려는 반려한 날 기준(없으면 출장일)
const doneMonth=t=>String(t.processedAt||t.date).slice(0,7);
const rejMonth=t=>String(t.approvedAt||t.reviewedAt||t.date).slice(0,7);
function boardCols(){
  const ym=today().slice(0,7),c={wait:[],settle:[],done:[],no:[]},old={done:0,no:0};
  S.trips.forEach(t=>{const k=stageOf(t).key;
    if(k==='done'){if(doneMonth(t)===ym)c.done.push(t);else old.done++}
    else if(k==='no'){if(rejMonth(t)===ym)c.no.push(t);else old.no++}
    else if(c[k])c[k].push(t)});
  const asc=(a,b)=>String(a.date).localeCompare(String(b.date))||String(a.id).localeCompare(String(b.id));
  c.wait.sort(asc);c.settle.sort(asc);
  c.done.sort((a,b)=>String(b.processedAt||b.date).localeCompare(String(a.processedAt||a.date)));
  c.no.sort((a,b)=>rejMonth(b).localeCompare(rejMonth(a))||String(b.date).localeCompare(String(a.date)));
  return {c,old};
}
function cardActs(t,col){
  if(col==='wait'&&canApprove())return `<button type="button" class="btn sm rej" data-act="reject">반려</button><button type="button" class="btn sm primary" data-act="approve">승인 →</button>`;
  if(col==='settle'&&isSettler())return `<button type="button" class="btn sm dark" data-act="settle">정산 완료 →</button>`;
  if(col==='no'&&canApprove())return `<button type="button" class="btn sm" data-act="revert">승인 대기로 되돌리기</button>`;
  return '';
}
function kcardHtml(t,col){
  const over=ruleCheck(t),miss=rcMissingOf(t),n=rcCount(t),acts=cardActs(t,col);
  const tags=[n?`<span class="ktag">${IC.clip}영수증 ${n}장</span>`:'',
    over.length?`<span class="ktag over" title="${esc(over.join('·'))} 기준 초과">${IC.alert}기준 초과</span>`:'',
    miss.length?`<span class="ktag miss" title="${esc(miss.map(k=>RC_LABEL[k]).join('·'))} 영수증 없음">${IC.alert}영수증 누락</span>`:'',
    editedAfter(t)?'<span class="ktag miss">승인 후 수정</span>':''].join('');
  return `<article class="kcard${over.length?' over':''}${t.id===S.kd?' sel':''}" data-id="${esc(t.id)}" draggable="${acts?'true':'false'}" tabindex="0">
    <div class="kc-top">${avHtml(t.traveler)}<div class="kc-who"><b>${esc(t.traveler)}</b><span class="num">${esc(shortDate(t.date))} · ${esc(t.period||'당일')}</span></div><b class="kc-amt num">${won(t.total)}원</b></div>
    <div class="kc-place" title="${esc(t.place)}${t.purpose?' · '+esc(t.purpose):''}"><b>${esc(t.place)}</b>${t.purpose?` · ${esc(t.purpose)}`:''}</div>
    ${tags?`<div class="kc-tags">${tags}</div>`:''}
    ${col==='no'&&t.rejectReason?`<div class="kc-reason" title="${esc(t.rejectReason)}">사유 · ${esc(t.rejectReason)}</div>`:''}
    ${acts?`<div class="kc-acts">${acts}</div>`:''}
  </article>`;
}
let kDrag=null;
function renderBoard(){
  if(S.role==='staff')return;
  if(kDrag){S.boardDirty=true;return}   // 끄는 중에 새 목록이 오면 놓은 뒤에 그린다
  const {c,old}=boardCols();
  F('sumApprove').textContent=won(tot(c.wait))+'원';
  F('sumPay').textContent=won(tot(c.settle))+'원';
  const cur=F('kTabs').querySelector('[aria-selected="true"]'),sel=cur?cur.dataset.c:'wait';
  F('kTabs').innerHTML=KCOLS.map(([k,l])=>`<button type="button" role="tab" data-c="${k}" aria-selected="${k===sel}"><i class="kdot k-${k}"></i>${l}<span class="num">${c[k].length}</span></button>`).join('');
  F('kBoard').innerHTML=KCOLS.map(([k,l])=>{
    const list=c[k];
    const more=k==='done'?`<button type="button" class="linkbtn kmore" data-more="처리완료">이전 정산 내역 보기${old.done?` · ${old.done}건`:''} →</button>`
      :k==='no'&&old.no?`<button type="button" class="linkbtn kmore" data-more="반려">이전 반려 ${old.no}건 보기 →</button>`:'';
    const sub=k==='done'||k==='no'?'<span class="ksub">이번 달</span>':'';
    return `<section class="kcol" data-col="${k}" aria-label="${l}">
      <div class="khead"><i class="kdot k-${k}"></i><b>${l}</b><span class="kcnt num">${list.length}</span>${sub}<span class="kamt num">${won(tot(list))}원</span></div>
      <div class="kcards">${list.length?list.map(t=>kcardHtml(t,k)).join(''):'<div class="kempty">비어 있음</div>'}</div>${more}
    </section>`}).join('');
  renderKD();
}
// 휴대폰 — 열 탭을 누르면 그 열로 넘어가고, 밀어서 넘기면 탭이 따라 바뀐다
F('kTabs').addEventListener('click',e=>{const b=e.target.closest('[data-c]');if(!b)return;
  F('kTabs').querySelectorAll('[data-c]').forEach(x=>x.setAttribute('aria-selected',x===b));
  const col=F('kBoard').querySelector(`[data-col="${b.dataset.c}"]`);if(col)F('kBoard').scrollTo({left:col.offsetLeft-F('kBoard').firstElementChild.offsetLeft})});
F('kBoard').addEventListener('scroll',()=>{clearTimeout(F('kBoard')._t);F('kBoard')._t=setTimeout(()=>{
  const b=F('kBoard'),cols=[...b.querySelectorAll('.kcol')];if(!cols.length||b.scrollWidth<=b.clientWidth+4)return;
  const x=b.scrollLeft+cols[0].offsetLeft,i=cols.reduce((best,c,j)=>Math.abs(c.offsetLeft-x)<Math.abs(cols[best].offsetLeft-x)?j:best,0);
  F('kTabs').querySelectorAll('[data-c]').forEach(t=>t.setAttribute('aria-selected',t.dataset.c===cols[i].dataset.col))},80)});

/* 처리 — 카드 단추·끌어 옮기기·상세 창 단추가 모두 여기로 온다 */
async function boardAct(t,act){
  let data,msg;
  if(act==='reject'){const reason=await askReject(t);if(reason==null)return;
    data={approval:'반려',approvedAt:today(),rejectReason:reason};msg=`${t.traveler} 청구를 반려했습니다`}
  else if(act==='approve'){data={approval:'승인',approvedAt:today()};msg=`${t.traveler} 청구를 승인했습니다 · 정산 대기로 옮겼습니다`}
  else if(act==='settle'){
    if(!confirm(`${t.traveler} · ${won(t.total)}원을 정산 완료로 처리할까요?\n지급방법: ${t.payMethod||'계좌이체'} · 처리일 ${today()}`))return;
    data={status:'처리완료',processedAt:today(),payMethod:t.payMethod||'계좌이체'};msg=`${t.traveler} 청구를 정산 완료로 처리했습니다`}
  else if(act==='revert'){
    if(!confirm(`${t.traveler} · ${t.date} ${t.place} 청구를 승인 대기로 되돌릴까요?\n반려 사유는 지워집니다.`))return;
    data={approval:'승인대기',approvedAt:''};msg=`${t.traveler} 청구를 승인 대기로 되돌렸습니다`}
  else return;
  document.querySelectorAll(`.kcard[data-id="${CSS.escape(t.id)}"],#kDrawer`).forEach(el=>el.classList.add('busy'));
  try{await store.save(t.id,data);toast(msg)}
  catch(e){toast(e.message||'처리하지 못했습니다');render()}
  finally{document.querySelectorAll('.kcard.busy,#kDrawer.busy').forEach(el=>el.classList.remove('busy'))}
}
F('kBoard').addEventListener('click',e=>{
  const m=e.target.closest('[data-more]');
  if(m){setAview('stats');S.fStatus=m.dataset.more;render();F('listCard').scrollIntoView({behavior:'smooth',block:'start'});return}
  const card=e.target.closest('.kcard');if(!card||card.classList.contains('busy'))return;
  const t=S.trips.find(x=>x.id===card.dataset.id);if(!t)return;
  const b=e.target.closest('[data-act]');
  if(b)boardAct(t,b.dataset.act);else openKD(t.id);
});
F('kBoard').addEventListener('keydown',e=>{if(e.key==='Enter'&&e.target.classList.contains('kcard'))openKD(e.target.dataset.id)});

/* 끌어서 옮기기(PC) — 옮길 수 있는 열만 밝게, 나머지는 흐리게. 놓으면 단추와 같은 처리 */
F('kBoard').addEventListener('dragstart',e=>{
  const card=e.target.closest('.kcard[draggable="true"]');if(!card){e.preventDefault();return}
  const from=card.closest('.kcol').dataset.col;kDrag={id:card.dataset.id,from};
  e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',card.dataset.id);
  requestAnimationFrame(()=>card.classList.add('dragging'));
  F('kBoard').classList.add('dragmode');
  F('kBoard').querySelectorAll('.kcol').forEach(c=>{const ok=!!moveAct(from,c.dataset.col);c.classList.toggle('can-drop',ok);c.classList.toggle('no-drop',!ok&&c.dataset.col!==from)});
});
F('kBoard').addEventListener('dragover',e=>{
  const col=e.target.closest('.kcol');if(!kDrag||!col||!col.classList.contains('can-drop'))return;
  e.preventDefault();e.dataTransfer.dropEffect='move';
  F('kBoard').querySelectorAll('.kcol.over').forEach(c=>{if(c!==col)c.classList.remove('over')});col.classList.add('over');
});
F('kBoard').addEventListener('dragleave',e=>{const col=e.target.closest('.kcol');if(col&&!col.contains(e.relatedTarget))col.classList.remove('over')});
F('kBoard').addEventListener('drop',e=>{
  const col=e.target.closest('.kcol');if(!kDrag||!col)return;e.preventDefault();
  const act=moveAct(kDrag.from,col.dataset.col),t=S.trips.find(x=>x.id===kDrag.id);
  endDrag();
  if(act&&t)boardAct(t,act);
});
function endDrag(){
  kDrag=null;F('kBoard').classList.remove('dragmode');
  F('kBoard').querySelectorAll('.kcol').forEach(c=>c.classList.remove('can-drop','no-drop','over'));
  F('kBoard').querySelectorAll('.kcard.dragging').forEach(c=>c.classList.remove('dragging'));
  if(S.boardDirty){S.boardDirty=false;renderBoard()}
}
F('kBoard').addEventListener('dragend',()=>{if(kDrag)endDrag()});

/* 반려 사유 — 꼭 적어야 한다. 적은 사유를 돌려주고, 취소하면 null */
function askReject(t){
  return new Promise(res=>{
    const dlg=F('rejDlg'),ta=F('rejReason');
    F('rejWho').innerHTML=`${avHtml(t.traveler,'xs')}<b>${esc(t.traveler)}</b><span class="num">${esc(t.date)} · ${esc(t.place)} · ${won(t.total)}원</span>`;
    ta.value='';F('rejOk').disabled=true;
    let done=false;const fin=v=>{if(done)return;done=true;if(dlg.open)dlg.close();res(v)};
    F('rejOk').onclick=()=>{const v=ta.value.trim();if(!v){ta.focus();return}fin(v)};
    F('rejCancel').onclick=()=>fin(null);
    dlg.onclose=()=>fin(null);
    dlg.showModal();ta.focus();
  });
}
F('rejReason').addEventListener('input',()=>{F('rejOk').disabled=!F('rejReason').value.trim()});

/* 카드 상세 — 오른쪽에서 열리는 창. 출장 정보·항목별 표(기준 초과는 주황)·영수증·처리 기록, 아래에 카드와 같은 단추 */
S.kd=null;
const kdOn=()=>document.body.classList.contains('kd-open');
function openKD(id){
  S.kd=id;S.kdSig='';renderKD();if(!S.kd)return;
  document.body.classList.add('kd-open');F('kDrawer').setAttribute('aria-hidden','false');F('kdBody').scrollTop=0;
  document.querySelectorAll('.kcard').forEach(c=>c.classList.toggle('sel',c.dataset.id===id));
  setTimeout(()=>F('kdClose').focus(),60);
}
function closeKD(){
  if(!kdOn()&&!S.kd)return;
  const id=S.kd;S.kd=null;document.body.classList.remove('kd-open');F('kDrawer').setAttribute('aria-hidden','true');
  document.querySelectorAll('.kcard.sel').forEach(c=>c.classList.remove('sel'));
  const c=id&&F('kBoard').querySelector(`.kcard[data-id="${CSS.escape(id)}"]`);if(c)c.focus({preventScroll:true});
}
F('kdClose').addEventListener('click',closeKD);
F('kdOpen').addEventListener('click',()=>{const t=S.trips.find(x=>x.id===S.kd);if(t)openTrip(t)});
function renderKD(){
  if(!S.kd)return;
  const t=S.trips.find(x=>x.id===S.kd);
  if(!t){closeKD();return}
  const sig=JSON.stringify(t)+S.role;if(sig===S.kdSig)return;S.kdSig=sig;
  const st=stageOf(t),col=st.key==='draft'?'':st.key,over=ruleCheck(t),miss=rcMissingOf(t);
  F('kdAv').className='av '+pc(t.traveler);F('kdAv').textContent=String(t.traveler||'?').slice(0,1);
  F('kdName').textContent=t.traveler;
  F('kdSub').innerHTML=`<span class="sbadge st-${st.key}">${esc(st.label)}</span><span class="num">${won(t.total)}원</span>`;
  const comps=String(t.companions||'').split(',').map(x=>x.trim()).filter(Boolean);
  const ppl=1+(comps.length||t.companionCount||0),days=periodDays(t.period),unit=S.settings.mealUnit||15000;
  const car=isCar(t.mode),fp=car?fuelPrice(t.mode):null,fuel=car&&fp&&t.km>0?fuelCost(t.km,fp):0;
  const rows=[
    ['transport','교통비',[t.mode||'-',car&&t.km?`편도 ${t.km}km`:''].filter(Boolean).join(' · '),car?(fuel?`주유비 ${won(fuel)}원`:'주유비 계산'):'영수증 실비',over.includes('주유비')],
    ['toll','통행료','고속도로 통행료·주차비','영수증 실비',false],
    ['meal','식대',`${ppl}명 · ${days}일`,`${won(unit*ppl*days*2)}원 이내`,over.includes('식대')],
    ['etc','기타',t.etcNote||'-','영수증 실비',false]];
  const info=[['출장자',esc(t.traveler)],['기간',`<span class="num">${esc(t.date)}</span> · ${esc(t.period||'당일')}`],['출장지',esc(t.place)],
    ['목적',esc(t.purpose||'-')],['동행자',comps.length?esc(comps.join(', ')):t.companionCount?`${t.companionCount}명 (기존 기록)`:'없음']];
  const rc=t.receipts||{},rcs=RC_KEYS.flatMap(k=>(rc[k]||[]).map(r=>({k,r})));
  const proc=[['승인자',t.approver],['승인일',t.approvedAt],['처리자',t.handler],['처리일',t.processedAt],['지급방법',t.payMethod],['비고',t.note]].filter(([,v])=>v);
  F('kdBody').innerHTML=`
    ${t.approval==='반려'?`<div class="kd-reason"><b>반려 사유</b><p>${t.rejectReason?esc(t.rejectReason):'<span class="hint">적힌 사유가 없습니다(예전 기록).</span>'}</p></div>`:''}
    ${over.length||miss.length?`<div class="kd-warn">${over.length?`<span class="ktag over">${IC.alert}기준 초과 · ${esc(over.join('·'))}</span>`:''}${miss.length?`<span class="ktag miss">${IC.alert}영수증 누락 · ${esc(miss.map(k=>RC_LABEL[k]).join('·'))}</span>`:''}</div>`:''}
    <dl class="kd-info">${info.map(([k,v])=>`<div><dt>${k}</dt><dd>${v}</dd></div>`).join('')}</dl>
    <h3 class="kd-h">경비 항목</h3>
    <div class="tablewrap"><table class="kd-tbl"><thead><tr><th>항목</th><th>내용</th><th>기준</th><th class="r">금액</th></tr></thead>
      <tbody>${rows.map(([k,l,what,rule,o])=>`<tr class="${o?'over':''}${t[k]?'':' zero'}"><td><b>${l}</b></td><td>${esc(what)}</td><td class="num">${esc(rule)}${o?' <em>초과</em>':''}</td><td class="r num"><b>${won(t[k])}원</b></td></tr>`).join('')}</tbody>
      <tfoot><tr><td colspan="3">합계</td><td class="r num">${won(t.total)}원</td></tr></tfoot></table></div>
    <h3 class="kd-h">영수증 <span class="num">${rcs.length}장</span></h3>
    ${rcs.length?`<div class="kd-rc">${rcs.map(({k,r},i)=>{const href=apiUrl('receipt','id='+encodeURIComponent(r.id));
      return `<button type="button" class="kd-th" data-i="${i}" title="${esc(RC_LABEL[k])}${r.name?' · '+esc(r.name):''}">${r.type==='application/pdf'?'<span class="pdf">PDF</span>':`<img src="${href}" alt="${esc(RC_LABEL[k])} 영수증">`}<span class="lab">${esc(RC_LABEL[k])}</span></button>`}).join('')}</div>`
      :'<div class="hint kd-none">첨부된 영수증이 없습니다.</div>'}
    ${proc.length?`<h3 class="kd-h">처리 기록</h3><dl class="kd-info">${proc.map(([k,v])=>`<div><dt>${k}</dt><dd>${esc(v)}</dd></div>`).join('')}</dl>`:''}`;
  const acts=cardActs(t,col);
  F('kdActs').innerHTML=acts||`<span class="hint">${st.key==='wait'?'대표(승인 관리자)가 승인합니다':st.key==='settle'?'정산 담당이 처리합니다':''}</span>`;
}
F('kdBody').addEventListener('click',e=>{const b=e.target.closest('.kd-th');if(!b)return;const t=S.trips.find(x=>x.id===S.kd);if(t)openTripReceipts(t,+b.dataset.i)});
F('kdActs').addEventListener('click',e=>{const b=e.target.closest('[data-act]');if(!b||F('kDrawer').classList.contains('busy'))return;const t=S.trips.find(x=>x.id===S.kd);if(t)boardAct(t,b.dataset.act)});
setAview(S.aview);

/* ---------- 시작 ---------- */
clearForm();
render();
initStore();
})();
