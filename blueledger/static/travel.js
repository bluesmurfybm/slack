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

const DEFAULT_SETTINGS={staff:[],approverName:'대표',handlerName:'김지안',fuel:{gas:1866.16,diesel:1849.17,lpg:1103.31},eff:10,priceBase:'2026년 8월 1주',mealUnit:10000};
const S={trips:[],settings:structuredClone(DEFAULT_SETTINGS),year:String(new Date().getFullYear()),editingId:null,q:'',fStatus:'all',companions:new Set(),approval:'승인대기',status:'미처리',receipts:emptyRc()};
let store=null,assets=null;
S.role='local';S.uid=null;
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
  const j=await api('state');
  if(j.me&&j.me.role!==S.role){startAs(j.me);return}
  S.trips=j.trips||[];S.settings={...structuredClone(DEFAULT_SETTINGS),...(j.settings||{})};
  renderStaff();if(!S.editingId&&!F('traveler').value)presetMe();updateFuelInfo();updateMealHint();render();renderInfo();if(S.editingId)refreshLock();
  $('#conn').textContent=(S.role==='staff'?'내 청구':'전체 청구')+' · '+new Date().toLocaleTimeString('ko-KR',{hour:'2-digit',minute:'2-digit'})+' 기준';
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
  try{first=await api('state')}catch(e){$('#conn').textContent='서버에 연결할 수 없습니다. 잠시 뒤 새로고침해 주세요.';return}
  await startAs(first.me);
}
async function startAs(u){
  S.me=u.name;S.role=u.role;document.body.dataset.role=u.role;
  F('roleTag').textContent=u.role==='admin'?'정산 담당':u.role==='approver'?'대표 승인':'';
  F('rulePanel').open=u.role==='staff';
  updateAttUI();applyRole();
  await loadState().catch(e=>toast(e.message));
  clearInterval(S.poll);S.poll=setInterval(()=>{if(!document.hidden)loadState().catch(()=>{})},20000);
}
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
  // 대표는 승인하러 들어온다 — 승인 칸을 청구서 맨 위로 올려 스크롤 없이 보이게 한다
  if(S.role==='approver')F('form').prepend(F('fsApproval'));
  F('queueTitle').firstChild.textContent=staff?'진행 중인 내 청구':'처리할 청구';
  refreshLock();
}
function refreshLock(){
  const staff=!isAdmin(),t=S.trips.find(x=>x.id===S.editingId);
  const locked=(staff&&t&&(t.approval||'승인대기')!=='승인대기')||S.role==='approver';
  F('fsInfo').disabled=!!locked;F('fsMoney').disabled=!!locked;
  F('btnSave').hidden=!!locked;
  F('btnDel').hidden=!S.editingId||!isSettler();
  if(S.role==='approver'){F('btnSave').hidden=!S.editingId;F('btnSave').textContent='승인 저장';F('btnNew').hidden=true}
  const msg=F('lockMsg');
  if(S.role==='approver'){msg.hidden=false;msg.textContent=S.editingId?'승인 또는 반려를 고른 뒤 승인 저장을 눌러 주세요.':'목록이나 처리할 청구에서 승인할 건을 눌러 주세요.'}
  else if(locked){msg.hidden=false;msg.textContent=t.approval==='반려'?'반려된 청구입니다. 내용을 고쳐 새 청구서로 다시 올려 주세요.':'승인된 청구는 수정할 수 없습니다. 고칠 내용이 있으면 정산 담당자에게 알려 주세요.'}
  else if(staff){msg.hidden=false;msg.textContent='승인과 정산은 대표자와 정산 담당자가 처리합니다. 승인 전까지는 내용을 고칠 수 있습니다.'}
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
  F('stApproval').innerHTML=a;F('stSettle').innerHTML=s;
}
// 직원의 '내 청구' — 연도와 상관없이 최근 출장부터. 누르면 청구서가 열리고 승인·정산 상태가 보인다.
function renderMyList(){
  if(S.role!=='staff')return;
  const list=[...S.trips].sort((a,b)=>String(b.date).localeCompare(String(a.date)));
  F('myMeta').textContent=list.length?`${list.length}건`:'';
  F('myCount').textContent=list.length||'';
  F('myList').innerHTML=list.length?list.map(t=>{const [a,s]=statusBadges(t.approval,t.status);
    return `<div class="myrow${t.id===S.editingId?' sel':''}" data-id="${esc(t.id)}" tabindex="0">
      <span class="when num">${esc(t.date)}</span>
      <span class="what">${esc(t.place)}${t.purpose?`<small>${esc(t.purpose)}</small>`:''}</span>
      <span class="amt num">${won(t.total)}원</span>
      <span class="st"><span class="hint">승인</span>${a}<span class="hint">정산</span>${s}</span></div>`}).join('')
    :'<div class="empty">아직 올린 청구가 없습니다. 청구서를 써서 제출해 보세요.</div>';
}
F('btnMy').addEventListener('click',()=>{renderMyList();F('myDlg').showModal()});
F('myDlgClose').addEventListener('click',()=>F('myDlg').close());
F('myList').addEventListener('click',e=>{const r=e.target.closest('.myrow');if(!r)return;const t=S.trips.find(x=>x.id===r.dataset.id);if(t){F('myDlg').close();fillForm(t)}});
F('myList').addEventListener('keydown',e=>{if(e.key==='Enter')e.target.click()});
F('approvalSeg').addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;S.approval=b.dataset.v;setSeg('approvalSeg',S.approval);
  if(S.approval!=='승인대기'){if(!F('approver').value)F('approver').value=S.settings.approverName||'대표';if(!F('approvedAt').value)F('approvedAt').value=today()}});
F('statusSeg').addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;S.status=b.dataset.v;setSeg('statusSeg',S.status);
  if(S.status==='처리완료'){if(!F('handler').value)F('handler').value=S.settings.handlerName||'김지안';if(!F('processedAt').value)F('processedAt').value=today();if(!F('payMethod').value)F('payMethod').value='계좌이체'}});

['transport','toll','meal','etc'].forEach(id=>{
  F(id).addEventListener('input',updateTotal);
  F(id).addEventListener('blur',()=>{const v=parseNum(F(id).value);F(id).value=v?won(v):''});
  F(id).addEventListener('focus',()=>{const v=parseNum(F(id).value);F(id).value=v||''});
});
function sum(){return ['transport','toll','meal','etc'].reduce((a,id)=>a+parseNum(F(id).value),0)}
function updateTotal(){F('total').textContent=won(sum())+'원';updateMealHint()}

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
  const people=1+companionsArr().length,days=periodDays(F('period').value),unit=S.settings.mealUnit||10000;
  const limit=unit*people,meal=parseNum(F('meal').value);
  const h=F('mealHint');
  h.textContent=`기준: 1인 ${won(unit)}원 × ${people}명 = ${won(limit)}원`+(days>1?' (1회 기준)':'');
  h.className='hint'+(meal>limit*days*2?' warn':'');
}
function periodDays(p){const m=String(p).match(/(\d)박/);return m?Number(m[1])+1:1}
F('period').addEventListener('change',updateMealHint);

function clearForm(){
  S.editingId=null;S.companions=new Set();S.approval='승인대기';S.status='미처리';S.receipts=emptyRc();renderThumbs();
  F('form').reset();F('traveler').value='';F('date').value=today();F('period').value='당일';setModeValue('자가용(휘발유)');
  ['transport','toll','meal','etc'].forEach(id=>F(id).value='');
  F('approver').value='';F('handler').value='';
  setSeg('approvalSeg',S.approval);setSeg('statusSeg',S.status);
  F('formMode').textContent='새 청구';F('btnDel').hidden=true;F('btnSave').textContent='청구서 저장';
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
  F('date').value=t.date||'';F('period').value=t.period||'당일';F('place').value=t.place||'';F('purpose').value=t.purpose||'';
  setModeValue(t.mode);
  F('km').value=t.km||'';
  ['transport','toll','meal','etc'].forEach(id=>F(id).value=t[id]?won(t[id]):'');
  F('etcNote').value=t.etcNote||'';
  S.approval=t.approval||'승인대기';S.status=t.status||'미처리';setSeg('approvalSeg',S.approval);setSeg('statusSeg',S.status);
  F('approver').value=t.approver||'';F('approvedAt').value=t.approvedAt||'';
  F('handler').value=t.handler||'';F('processedAt').value=t.processedAt||'';F('payMethod').value=t.payMethod||'';F('note').value=t.note||'';
  F('formMode').textContent='수정 중';F('btnDel').hidden=false;F('btnSave').textContent='변경 저장';
  renderChips();updateFuelInfo();updateTotal();highlightRow();
  if(t.companionCount&&!comps.length)F('compCount').textContent=`· ${t.companionCount}명 (기존 기록)`;
  refreshLock();
  switchTab('form');
}
F('btnNew').addEventListener('click',()=>{clearForm();switchTab('form');F('traveler').focus()});

F('btnSave').addEventListener('click',async()=>{
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
  
  const id=S.editingId||'new_';
  F('btnSave').disabled=true;
  try{await store.save(id,data);toast(S.editingId?'변경 내용을 저장했습니다':(S.role==='staff'?'청구서를 제출했습니다. 승인·정산 결과는 정산 담당자가 안내합니다':'청구서를 저장했습니다'));S.year=data.date.slice(0,4);clearForm();render()}
  catch(e){toast(e.message||'저장하지 못했습니다')}
  finally{F('btnSave').disabled=false}
});
F('btnDel').addEventListener('click',async()=>{
  if(!S.editingId||!confirm('이 청구서를 삭제할까요? 되돌릴 수 없습니다.'))return;
  try{await store.remove(S.editingId);toast('청구서를 삭제했습니다');clearForm()}catch(e){toast(e.message||'삭제하지 못했습니다')}
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
    <div class="kpi click ${waitA.length?'alert':''}" data-f="승인대기"><div class="k">${label} 대표 승인 대기</div><div class="v num">${waitA.length}건</div><div class="s">${won(waitA.reduce((a,t)=>a+(t.total||0),0))}원</div></div>
    <div class="kpi click ${unpaid.length?'alert':''}" data-f="미처리"><div class="k">${label} 정산 대기</div><div class="v num">${unpaid.length}건</div><div class="s">${won(unpaid.reduce((a,t)=>a+(t.total||0),0))}원 지급 예정</div></div>`;

  renderMonthChart(rows);
  renderPeople(rows);
  renderQueue(waitA,unpaid);
  renderTable(rows);
  renderMyList();
}
F('kpis').addEventListener('click',e=>{const k=e.target.closest('.kpi.click');if(!k)return;S.fStatus=k.dataset.f;F('fStatus').value=S.fStatus;render();switchTab('list');F('listPanel').scrollIntoView({behavior:'smooth',block:'start'})});

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
  F('queue').innerHTML=items.slice(0,6).map(({t,k})=>`<div class="qrow" data-id="${esc(t.id)}" tabindex="0"><span class="badge ${k==='승인대기'?'b-wait':'b-none'}">${k}</span><span class="who">${esc(t.traveler)}</span><span class="what">${esc(t.date)} ${esc(t.place)} · ${esc(t.purpose)}</span><span class="num">${won(t.total)}원</span></div>`).join('')+(items.length>6?`<div class="empty" style="padding:6px 8px">외 ${items.length-6}건</div>`:'');
}
F('queue').addEventListener('click',e=>{const r=e.target.closest('.qrow');if(r){const t=S.trips.find(x=>x.id===r.dataset.id);if(t)fillForm(t)}});
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
F('tbody').addEventListener('click',e=>{const r=e.target.closest('tr[data-id]');if(!r)return;const t=S.trips.find(x=>x.id===r.dataset.id);if(t)fillForm(t)});
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
  const err=p.error?`<span class="perr">최근 갱신 실패: ${esc(p.error)}</span>`:'';
  const noKey=isSettler()&&!S.settings.hasOpinetKey?'<span class="perr">설정에 오피넷 API 키를 넣으면 자동으로 바뀝니다.</span>':'';
  F('priceFoot').innerHTML=`<span>출처: ${esc(p.source||'')}${when?' · '+when:''}</span>${err}${noKey}
    <a href="https://www.petronet.co.kr/v4/main.jsp" target="_blank" rel="noopener">페트로넷에서 확인</a>
    ${isSettler()?'<span class="hspace"></span><button class="btn" id="btnPriceRefresh" style="padding:4px 10px">지금 갱신</button><button class="btn" id="btnPriceApply" style="padding:4px 10px">이 가격을 산정 기준으로 적용</button>':''}`;
  F('ruleMeal').textContent=won(S.settings.mealUnit||10000);
  F('ruleBase').textContent=`(${S.settings.priceBase||'산정 기준'} 유가, 연비 ${S.settings.eff||10}km/L)`;
  F('fuelTable').innerHTML=FUEL_ROWS.map(([km,pl])=>`<tr><td>${km}km 이내</td><td class="r num">${won(fuelCost(km,f.gas||0))}</td><td class="r num">${won(fuelCost(km,f.diesel||0))}</td><td class="r num">${won(fuelCost(km,f.lpg||0))}</td><td>${esc(pl)}</td></tr>`).join('');
}
F('pricePanel').addEventListener('click',async e=>{
  if(e.target.id==='btnPriceRefresh'){e.target.disabled=true;try{await api('prices/refresh',{});await loadState();toast('유가를 새로 가져왔습니다')}catch(err){toast(err.message)}finally{if(F('btnPriceRefresh'))F('btnPriceRefresh').disabled=false}}
  if(e.target.id==='btnPriceApply'){const p=S.settings.prices||{};
    if(!confirm(`주유비 산정 기준을 ${p.label||'현재'} 유가로 바꿀까요?\n휘발유 ${p.gas} / 경유 ${p.diesel} / LPG ${p.lpg}\n\n작성기준상 기준유가는 분기 첫 주에 다시 산정하는 것이 원칙입니다.`))return;
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
      const inner=r.type==='application/pdf'?'PDF':`<img src="${href}" alt="${esc(r.name||'영수증')}" loading="lazy" onerror="this.replaceWith(document.createTextNode('IMG'))">`;
      return `<div class="th" title="${esc(r.name||'')}"><a href="${href}" target="_blank" rel="noopener">${inner}</a><button type="button" class="x" data-k="${k}" data-i="${i}" aria-label="영수증 삭제">×</button></div>`;
    }).join('');
  });
}
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
const ROLE_LABEL={admin:'정산 담당',approver:'대표 승인',staff:'직원'};
// 직원 추가·퇴사·비밀번호는 포털 계정에서 한다. 여기서는 출장여비 권한만 고른다.
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
  F('sApprover').value=s.approverName||'';F('sHandler').value=s.handlerName||'';
  F('sGas').value=s.fuel.gas;F('sDiesel').value=s.fuel.diesel;F('sLpg').value=s.fuel.lpg;F('sEff').value=s.eff;F('sBase').value=s.priceBase;F('sMeal').value=s.mealUnit;
  F('sKey').value='';F('keyState').textContent=s.hasOpinetKey?'API 키가 등록되어 있습니다. 바꿀 때만 새 키를 입력하세요. 누군가 화면을 열 때 마지막 확인에서 3시간이 지났으면 유가를 새로 받아 옵니다.':'오피넷(opinet.co.kr) 회원가입 후 \'무료 API 이용 신청\'에서 키를 받아 붙여넣으세요.';
  F('userList').innerHTML='<span class="hint">불러오는 중…</span>';F('dlg').showModal();
  loadUsers().catch(e=>toast(e.message));
});
F('dlgCancel').addEventListener('click',()=>F('dlg').close());
F('dlgSave').addEventListener('click',async()=>{
  const d={opinetKey:F('sKey').value.trim(),approverName:F('sApprover').value.trim()||'대표',handlerName:F('sHandler').value.trim()||'김지안',
    fuel:{gas:parseNum(F('sGas').value),diesel:parseNum(F('sDiesel').value),lpg:parseNum(F('sLpg').value)},eff:parseNum(F('sEff').value)||10,
    priceBase:F('sBase').value.trim(),mealUnit:parseNum(F('sMeal').value)||10000};
  try{await store.saveSettings(d);if(d.opinetKey){api('prices/refresh',{}).then(loadState).catch(e=>toast(e.message))}updateFuelInfo();updateMealHint();F('dlg').close();toast('설정을 저장했습니다')}catch(e){toast(e.message||'설정을 저장하지 못했습니다')}
});

/* ---------- 시작 ---------- */
clearForm();
render();
initStore();
})();
