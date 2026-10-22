(function(){
const $=s=>document.querySelector(s);
const F=id=>document.getElementById(id);
const won=n=>(Math.round(n||0)).toLocaleString('ko-KR');
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const today=()=>{const d=new Date();d.setMinutes(d.getMinutes()-d.getTimezoneOffset());return d.toISOString().slice(0,10)};
const parseNum=v=>{const n=Number(String(v??'').replace(/[^\d.-]/g,''));return isFinite(n)?n:0};
const dot=d=>d?d.replace(/-/g,'.'):'';
const WD='일월화수목금토';
const wd=d=>d?WD[new Date(d+'T00:00:00').getDay()]:'';
const dayDiff=(a,b)=>Math.round((new Date(b+'T00:00:00')-new Date(a+'T00:00:00'))/864e5);
const uid=()=>'r'+Date.now().toString(36)+Math.random().toString(36).slice(2,6);
function toast(m){const t=F('toast');t.textContent=m;t.classList.add('on');clearTimeout(toast._t);toast._t=setTimeout(()=>t.classList.remove('on'),2400)}

/* ---------- 기간 ---------- */
const periodOf=d=>{if(!d)return '';const y=+d.slice(0,4),m=+d.slice(5,7);return String(m>=5?y:y-1)};
const pStart=p=>`${p}-05-01`, pEnd=p=>`${+p+1}-04-30`;
const recPeriod=r=>r.period||periodOf(r.ci);

/* ---------- 기본 설정 ---------- */
const NOTICE_DEFAULT=`안녕하세요. 😊

리조트 이용 전 참고 부탁드립니다.

📌 체크인 안내
* 이용 당일 지점별 체크인 절차에 따라 체크인을 진행해 주세요.
* 체크인 방식은 지점별로 다를 수 있으므로, 프론트 또는 키오스크 안내에 따라 진행해 주시면 됩니다.
* 체크인 시 카카오톡으로 발송된 예약 내역을 확인용으로 제시해 주세요.

📌 식대용 법인카드 안내
* 이용일 전에 식대용 법인카드를 개별적으로 전달드릴 예정입니다.
* 지급된 카드는 식사 및 음료 구매에 사용해 주세요.
* 사용 후에는 영수증과 함께 법인카드를 반납해 주시면 됩니다.
* 영수증을 분실하신 경우에는 사용하신 곳과 금액만 말씀해 주세요.

즐거운 시간 보내시고, 안전하게 다녀오세요. 😊`;
const DEF_LIM={stay:1500000,meal:500000,nights:4};
const STAFF_2026=['김호영','박성철','김태주','조성훈','진소현','안정민','유병문','김아랑','박화랑','이한재','유승인','김지안','이준영'];
function defaultSettings(){
  const st=STAFF_2026.map(n=>n==='이준영'?{name:n,stay:750000,meal:250000,nights:2}:{name:n,...DEF_LIM});
  st.push({name:'블루소프트',company:true,stay:0,meal:0,nights:0});
  return {
    handler:'김지안',
    staffByPeriod:{'2026':st},
    accounts:[{id:'bluesoft',no:'33508700',nights:30},{id:'bluesoft1',no:'23976300',nights:30}],
    cards:[{code:'2960',label:'법인카드 (끝 2960)'},{code:'2972',label:'법인카드 (끝 2972)'},{code:'2987',label:'법인카드 (끝 2987)'},{code:'계좌이체',label:'계좌이체'}],
    notice:NOTICE_DEFAULT
  };
}

const S={recs:[],settings:defaultSettings(),period:periodOf(today()),view:'req',editing:null,status:'신청',settle:'미정산',meals:[],docs:[],role:'local',uid:null,me:'',q:'',fStatus:'all',fSettle:'all'};
let store=null,assets=null,downloads=null;
const isAdmin=()=>S.role!=='staff';
const REVIEW_KEYS=['status','reason','resvId','card','resvDate','payDate','roomFee','addFee','mealCard','cardOut','cardIn','settle','settledAt','adminNote','decidedAt'];
function pick(o,k){const r={};k.forEach(x=>{if(o[x]!==undefined)r[x]=o[x]});return r}
function omit(o,k){const r={...o};k.forEach(x=>delete r[x]);return r}

/* ---------- 직원 · 한도 ---------- */
function staffOf(p){
  const sp=S.settings.staffByPeriod||{};
  if(sp[p])return sp[p];
  const keys=Object.keys(sp).sort();const prev=keys.filter(k=>k<p).pop()||keys.pop();
  return prev?sp[prev].map(s=>s.company?{...s}:{name:s.name,...DEF_LIM}):[];
}
const people=p=>staffOf(p).filter(s=>!s.company);
const limitOf=(name,p)=>staffOf(p).find(s=>s.name===name)||{name,...DEF_LIM};
const roomNights=r=>(+r.rooms||1)*(+r.nights||0);
const stayCost=r=>parseNum(r.roomFee)+parseNum(r.addFee);
const mealCost=r=>(r.meals||[]).reduce((a,m)=>a+parseNum(m.amount),0);
const otherCost=r=>parseNum(r.facFee)+parseNum(r.etcFee);
const live=r=>r.status!=='반려'&&r.status!=='취소';
function usage(name,p,exceptId){
  const rs=S.recs.filter(r=>r.name===name&&recPeriod(r)===p&&r.id!==exceptId);
  const ok=rs.filter(r=>r.status==='확정'),pend=rs.filter(r=>r.status==='신청');
  return {
    stay:ok.reduce((a,r)=>a+stayCost(r),0), nights:ok.reduce((a,r)=>a+roomNights(r),0),
    meal:rs.filter(live).reduce((a,r)=>a+mealCost(r),0),
    pendNights:pend.reduce((a,r)=>a+roomNights(r),0), pend:pend.length
  };
}

/* ---------- 저장소 ---------- */
/* ---------- 서버 연결 ---------- */
let CSRF=(document.querySelector('meta[name="csrf-token"]')||{}).content||'';
async function api(a,body){
  const isForm=body instanceof FormData;
  const opt={method:body?'POST':'GET',credentials:'same-origin',headers:{'X-CSRF-Token':CSRF}};
  if(body){opt.body=isForm?body:JSON.stringify(body);if(!isForm)opt.headers['Content-Type']='application/json'}
  let r;
  try{r=await fetch('api.php?a='+encodeURIComponent(a),opt)}catch(e){const er=new Error('서버에 연결할 수 없습니다. 인터넷 연결을 확인해 주세요.');er.code='network';throw er}
  let j=null;try{j=await r.json()}catch(e){}
  if(r.status===401){location.reload();const er=new Error('로그인이 필요합니다');er.status=401;throw er}
  if(!r.ok||!j||j.ok===false){const er=new Error((j&&j.error)||'요청을 처리하지 못했습니다.');er.code=j&&j.code;er.status=r.status;throw er}
  return j;
}
let lastLoad=0,formDirty=false;
F('main').addEventListener('input',e=>{if(e.target.closest('#form,#form2'))formDirty=true});
async function reload(){
  const j=await api('data');
  S.settings={...defaultSettings(),...j.settings};
  S.recs=j.bookings||[];S.users=j.users||[];lastLoad=Date.now();
  afterSettings();
  if(S.editing){const r=S.recs.find(x=>x.id===S.editing);if(!r){if(!formDirty)clearForm()}else if(!formDirty)loadForm(r);else refreshLock()}
  if(F('dlgSet').open)renderUsers();
}
async function initStore(){
  const ME=window.RESORT_ME||null;
  if(!ME){F('conn').textContent='로그인 정보를 확인할 수 없습니다';return}
  // 관리자도 처음엔 직원과 같은 화면(내 예약)으로 연다. [관리자 화면] 버튼으로 바꾼다
  S.canAdmin=ME.role==='admin';
  S.role='staff';S.uid=ME.id;S.me=ME.name;S.locked=true;
  document.body.dataset.role=S.role;
  F('modeSeg').hidden=!S.canAdmin;
  F('conn').textContent=S.role==='admin'?'관리자 화면 · 전체 예약':'직원 화면 · 내 예약';
  assets={upload:true};downloads={save:true};
  store={
    save:async(id,data,prev)=>{const r=await api('save',{id,data,prevVer:prev?prev.ver:null});formDirty=false;await reload();return r},
    remove:async id=>{await api('delete',{id});formDirty=false;await reload()},
    saveSettings:async d=>{await api('settings',d);await reload()}
  };
  try{await reload()}catch(e){F('conn').textContent=e.message;toast(e.message)}
  applyRole();
  // 다른 사람이 바꾼 내용을 1분마다 다시 불러옴 (입력 중일 때는 건너뜀)
  setInterval(()=>{if(!document.hidden&&!formDirty&&!document.querySelector('dialog[open]')&&Date.now()-lastLoad>55000)reload().catch(()=>{})},15000);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden&&!formDirty&&Date.now()-lastLoad>30000)reload().catch(()=>{})});
}

function afterSettings(){renderPeriodSel();renderSelects();if(!S.editing)presetMe();renderAll();if(!F('nBase').matches(':focus'))F('nBase').value=S.settings.notice||NOTICE_DEFAULT;renderNotice()}

/* 관리자 ↔ 직원 화면 전환 (관리자 계정만). 서버 권한은 그대로이고 화면만 바뀐다 */
function setMode(mode){
  if(!S.canAdmin||mode===S.role)return;
  S.role=mode==='admin'?'admin':'staff';
  document.body.dataset.role=S.role;
  F('modeSeg').querySelectorAll('[data-mode]').forEach(b=>b.setAttribute('aria-selected',b.dataset.mode===S.role));
  setView('req');setPane('req');applyRole();renderSelects();
}
F('modeSeg').addEventListener('click',e=>{const b=e.target.closest('[data-mode]');if(b)setMode(b.dataset.mode)});
function applyRole(){
  const staff=!isAdmin();
  F('queueTitle').firstChild.textContent=staff?'내 예약 진행 상황':'처리할 일';
  F('boardTitle').firstChild.textContent=staff?'내 이용 현황':'직원별 이용 현황';
  if(staff){renderSelects();presetMe()}
  clearForm();renderAll();
  if(staff&&ADMIN_VIEWS.includes(S.view))setView('req');
}
function presetMe(){if(isAdmin()||S.editing)return;if(S.me)F('name').value=S.me;updateForm()}
const visible=()=>isAdmin()?S.recs:S.recs.filter(r=>(S.uid&&r._uid===S.uid)||(S.locked&&S.me&&r._src==='ledger'&&r.name===S.me));

/* ---------- 선택 목록 ---------- */
function renderPeriodSel(){
  const set=new Set([periodOf(today()),...Object.keys(S.settings.staffByPeriod||{}),...S.recs.map(recPeriod).filter(Boolean)]);
  const cur=periodOf(today());set.add(String(+cur+1));
  const ps=[...set].sort().reverse();
  F('periodSel').innerHTML=ps.map(p=>`<option value="${p}">${p}년도 (${p.slice(2)}.05~${String(+p+1).slice(2)}.04)</option>`).join('');
  F('periodSel').value=S.period;
}
function renderSelects(){
  const p=S.period;
  const names=staffOf(p).map(s=>s.name);
  const nm=F('name'),cur=nm.value;
  const list=isAdmin()?names:(S.me?[S.me]:[]);
  if(isAdmin()&&cur&&!list.includes(cur))list.push(cur);
  nm.innerHTML='<option value="">선택</option>'+list.map(n=>`<option>${esc(n)}</option>`).join('');nm.value=isAdmin()?cur:(S.me||'');
  nm.disabled=!isAdmin();
  const acc=S.settings.accounts||[];
  const rv=F('resvId').value;F('resvId').innerHTML='<option value="">선택</option>'+acc.map(a=>`<option>${esc(a.id)}</option>`).join('');F('resvId').value=rv;
  const cards=S.settings.cards||[];
  ['card','mealCard'].forEach(id=>{const v=F(id).value;F(id).innerHTML='<option value="">선택</option>'+cards.map(c=>`<option value="${esc(c.code)}">${esc(c.label)}</option>`).join('');F(id).value=v});
  const rs=[...new Set(S.recs.map(r=>r.resort).filter(Boolean))].sort();
  F('resortList').innerHTML=rs.map(r=>`<option value="${esc(r)}">`).join('');
}
const cardLabel=c=>{if(!c)return '';const x=(S.settings.cards||[]).find(k=>k.code===c);return x?x.label:c};
const cardShort=c=>!c?'':/^\d{4}$/.test(c)?c:c;

/* ---------- 폼 ---------- */
function setSeg(id,v){document.querySelectorAll(`#${id} button`).forEach(b=>b.setAttribute('aria-pressed',b.dataset.v===v))}
F('statusSeg').addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;S.status=b.dataset.v;setSeg('statusSeg',S.status);
  if(S.status==='확정'&&!F('resvDate').value)F('resvDate').value=today();
  F('reasonWrap').hidden=!(S.status==='반려'||S.status==='취소');updateForm()});
F('settleSeg').addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;S.settle=b.dataset.v;setSeg('settleSeg',S.settle);
  if(S.settle==='정산완료'&&!F('settledAt').value)F('settledAt').value=today()});
['roomFee','addFee','facFee','etcFee'].forEach(id=>{const el=F(id);
  el.addEventListener('input',updateForm);
  el.addEventListener('blur',()=>{const v=parseNum(el.value);el.value=v?won(v):''});
  el.addEventListener('focus',()=>{const v=parseNum(el.value);el.value=v||''});
});
['ci','co','rooms','name'].forEach(id=>F(id).addEventListener('input',updateForm));
F('name').addEventListener('change',updateForm);
// 신청서에서 날짜를 직접 바꿔도 달력의 '고른 날짜'가 따라간다
F('co').addEventListener('change',()=>{calPick=null;if(!isAdmin())renderReqList()});
F('ci').addEventListener('change',()=>{calPick=null;if(F('ci').value&&(!F('co').value||F('co').value<=F('ci').value)){const d=new Date(F('ci').value+'T00:00:00');d.setDate(d.getDate()+1);F('co').value=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')}updateForm();if(!isAdmin())renderReqList()});

/* 소노호텔앤리조트 리조트 목록 (공식 홈페이지 기준) */
const SONO='https://www.sonohotelsresorts.com/';
const RESORTS=[
  ['소노펠리체',[['소노펠리체 비발디파크','felice_vp'],['소노펠리체 빌리지 비발디파크','village_vp'],['소노펠리체 델피노','felice_dp'],['소노펠리체 빌리지 델피노','village_dp']]],
  ['쏠비치',[['쏠비치 양양','solbeach_yy'],['쏠비치 삼척','solbeach_sc'],['쏠비치 진도','solbeach_jd'],['쏠비치 남해','solbeach_nh']]],
  ['소노캄',[['소노캄 비발디파크','calm_vp'],['소노캄 델피노','calm_dp'],['소노캄 고양','calm_gy'],['소노캄 여수','calm_ys'],['소노캄 거제','calm_gj'],['소노캄 제주','calm_jj'],['소노캄 경주','calm_gju']]],
  ['소노벨',[['소노벨 비발디파크','belle_vp'],['소노벨 델피노','belle_dp'],['소노벨 천안','belle_ca'],['소노벨 청송','belle_cs'],['소노벨 변산','belle_bs'],['소노벨 제주','belle_jj'],['소노벨 단양','belle_dy'],['소노벨 양평','belle_yp'],['소노벨 경주 감포','belle_fcgp']]],
  ['소노문',[['소노문 해운대','moon_hd']]],
  ['쏠비치 운영',[['파나크 영덕 바이 소노벨','panaq_yd'],['팔라티움 해운대 바이 소노펠리체','palatium_hd'],['르네블루 바이 쏠비치','renebleu_sb']]]
];
const RFLAT=RESORTS.flatMap(([g,l])=>l.map(([n,c])=>({n,c,key:n.replace(/ 바이 .*/,'').replace(/\s/g,'')})));
F('resortPick').innerHTML='<option value="">목록에서 고르기 (선택)</option>'+RESORTS.map(([g,l])=>`<optgroup label="${g}">${l.map(([n,c])=>`<option value="${c}">${n}</option>`).join('')}</optgroup>`).join('');
function syncResort(){
  const v=F('resort').value.replace(/\s/g,'');
  const hit=v?RFLAT.filter(r=>v.includes(r.key)).sort((a,b)=>b.key.length-a.key.length)[0]:null;
  if(F('resortPick')!==document.activeElement)F('resortPick').value=hit?hit.c:'';
  const a=F('resortLink');
  // 객실은 홈페이지에서 참고만 한다(잔여객실은 로그인해야 보여서 안내하지 않음)
  a.href=SONO.slice(0,-1);
  a.textContent='소노호텔앤리조트 홈페이지에서 객실 참고하기';
}
F('resortPick').addEventListener('change',()=>{
  const r=RFLAT.find(x=>x.c===F('resortPick').value);
  if(r){const cur=F('resort').value.trim();const old=RFLAT.find(x=>cur.replace(/\s/g,'').startsWith(x.key));
    const rest=old?cur.replace(new RegExp('^'+old.n.replace(/ 바이 .*/,'').split('').join('\\s*')),'').trim():'';
    F('resort').value=r.n.replace(/ 바이 .*/,'')+(rest?' '+rest:' ');F('resort').focus();}
  syncResort();updateForm();
});
F('resort').addEventListener('input',syncResort);
function nightsVal(){const a=F('ci').value,b=F('co').value;if(!a||!b)return 0;return Math.max(0,dayDiff(a,b))}
function updateForm(){
  syncResort();
  const n=nightsVal();F('nights').value=n?n+'박':'';
  const ci=F('ci').value,co=F('co').value,h=F('dateHint');
  const p=ci?periodOf(ci):S.period;
  let msg='',warn=false;
  if(ci&&co&&co<=ci){msg='체크아웃은 체크인 다음 날 이후여야 합니다.';warn=true}
  else if(ci){
    const ps=pStart(p),pe=pEnd(p);
    msg=`${p}년도 이용기간 (${dot(ps)} ~ ${dot(pe)})에 포함됩니다.`;
    if(co&&co>pe&&dayDiff(pe,co)>1){msg+=' 체크아웃이 이용기간 끝을 넘습니다.';warn=true}
    if(p!==periodOf(today()))msg+=` (현재 이용기간이 아닌 ${p}년도 한도로 계산됩니다)`;
    if(!isAdmin()&&!S.editing&&ci<today()){msg='지난 날짜입니다. 체크인 날짜를 확인해 주세요.';warn=true}
  }
  h.textContent=msg;h.className='hint'+(warn?' warn':'');
  renderLim(p);
  const meal=S.meals.reduce((a,m)=>a+parseNum(m.amount),0);
  F('mealSum').textContent=S.meals.length?`식대 ${S.meals.length}건 · ${won(meal)}원`:'';
  F('total').textContent=won(parseNum(F('roomFee').value)+parseNum(F('addFee').value))+'원';
  F('mealTotal').textContent=won(meal+parseNum(F('facFee').value)+parseNum(F('etcFee').value))+'원';
}
function renderLim(p){
  const name=F('name').value,box=F('limBox');
  const mbox=F('mealLim');
  if(!name){box.innerHTML='';mbox.innerHTML='';return}
  const L=limitOf(name,p);
  if(L.company){box.innerHTML=mbox.innerHTML=`<div class="hint">회사 공용 예약은 개인 한도에 포함되지 않습니다.</div>`;return}
  const u=usage(name,p,S.editing);
  const thisN=(Math.max(1,parseNum(F('rooms').value)||1))*nightsVal();
  const thisStay=(S.status==='확정'||!isAdmin())?parseNum(F('roomFee').value)+parseNum(F('addFee').value):0;
  const thisMeal=S.meals.reduce((a,m)=>a+parseNum(m.amount),0);
  const row=(t,used,add,lim,color,unit)=>{
    const tot=used+add,over=lim>0&&tot>lim;
    const w1=lim?Math.min(100,used/lim*100):0,w2=lim?Math.min(100-w1,add/lim*100):0;
    const v=unit==='박'?`${tot} / ${lim}박`:`잔여 ${won(lim-tot)}원`;
    return `<div class="lrow${over?' over':''}"><span class="t">${t}</span><div class="meter"><i style="width:${w1}%;background:${color}"></i><i class="add" style="width:${w2}%;background:${color}"></i></div><span class="v num">${v}</span></div>`;
  };
  box.innerHTML=`<div class="hint" style="margin:0"><b style="color:var(--ink)">${esc(name)}</b> · ${p}년도 한도 (옅은 부분은 이번 예약)</div>`+
    row('숙박일수',u.nights,thisN,L.nights,'var(--night)','박')+
    row('숙박비',u.stay,thisStay,L.stay,'var(--sea)')+
    row('식대',u.meal,thisMeal,L.meal,'var(--meal)')+
    (u.pend&&isAdmin()?`<div class="hint">처리 전 다른 신청 ${u.pend}건 (${u.pendNights}박)은 위 숫자에 빠져 있습니다.</div>`:'');
  mbox.innerHTML=S.editing?`<div class="hint" style="margin:0"><b style="color:var(--ink)">${esc(name)}</b> · ${p}년도 식대 한도 ${won(L.meal)}원 (옅은 부분은 이 예약)</div>`+row('식대',u.meal,thisMeal,L.meal,'var(--meal)'):'';
}

/* 식대 줄 */
function renderMeals(){
  const box=F('meals');
  box.innerHTML=S.meals.map((m,i)=>`<div class="mrow" data-i="${i}">
    <input type="date" data-k="date" value="${esc(m.date||'')}" aria-label="사용일">
    <input data-k="place" value="${esc(m.place||'')}" placeholder="사용처" aria-label="사용처">
    <input data-k="amount" class="r num" inputmode="numeric" value="${m.amount?won(m.amount):''}" placeholder="0" aria-label="금액">
    <span style="display:flex;gap:2px;align-items:center"><label class="att mat" title="영수증 첨부"><span>📎</span><input type="file" accept="image/*,application/pdf" multiple data-mi="${i}"></label><button type="button" class="mx" data-del="${i}" aria-label="이 줄 삭제">×</button></span>
    <div class="thumbs">${thumbs(m.rc||[],'m'+i)}</div></div>`).join('');
  if(!assets)box.querySelectorAll('.mat').forEach(a=>a.hidden=true);
  updateForm();
}
F('meals').addEventListener('input',e=>{const el=e.target.closest('input[data-k]');if(!el)return;const i=+el.closest('.mrow').dataset.i,k=el.dataset.k;
  S.meals[i][k]=k==='amount'?parseNum(el.value):el.value;updateForm()});
F('meals').addEventListener('focusout',e=>{const el=e.target.closest('input[data-k="amount"]');if(el){const v=parseNum(el.value);el.value=v?won(v):''}});
F('meals').addEventListener('click',e=>{const d=e.target.closest('[data-del]');if(d){const m=S.meals[+d.dataset.del];if((m.place||m.amount)&&!confirm('이 식대 줄을 지울까요?'))return;S.meals.splice(+d.dataset.del,1);renderMeals()}});
F('btnMeal').addEventListener('click',()=>{const ci=F('ci').value;S.meals.push({id:uid(),date:ci||today(),place:'',amount:0,rc:[]});renderMeals();
  const rows=F('meals').querySelectorAll('.mrow');const last=rows[rows.length-1];if(last)last.querySelector('[data-k="place"]').focus()});

/* 영수증 */
function thumbs(list,key){
  return (list||[]).map((r,i)=>{const href='api.php?a=file&id='+encodeURIComponent(r.id);
    const inner=r.type==='application/pdf'?'PDF':`<img src="${href}" alt="${esc(r.name||'영수증')}" loading="lazy" onerror="this.replaceWith(document.createTextNode('IMG'))">`;
    return `<div class="th" title="${esc(r.name||'')}"><a href="${href}" target="_blank" rel="noopener">${inner}</a><button type="button" class="x" data-rk="${key}" data-ri="${i}" aria-label="영수증 빼기">×</button></div>`}).join('');
}
function renderDocs(){F('th-doc').innerHTML=thumbs(S.docs,'doc')}
async function prepFile(f){
  if(f.type==='application/pdf')return {blob:f,type:'application/pdf'};
  if(!/^image\//.test(f.type))return {blob:f,type:f.type||'application/octet-stream'};
  try{const bmp=await createImageBitmap(f);const sc=Math.min(1,1800/Math.max(bmp.width,bmp.height));
    const c=document.createElement('canvas');c.width=Math.round(bmp.width*sc);c.height=Math.round(bmp.height*sc);
    c.getContext('2d').drawImage(bmp,0,0,c.width,c.height);
    const b=await new Promise(r=>c.toBlob(r,'image/jpeg',0.82));return b?{blob:b,type:'image/jpeg'}:{blob:f,type:f.type}}catch(e){return {blob:f,type:f.type}}
}
async function uploadFiles(files,lab){
  const out=[];if(!assets||!files.length)return out;
  lab.classList.add('busy');
  for(const f of files){
    try{const {blob,type}=await prepFile(f);const fd=new FormData();
      fd.append('f',blob,type==='image/jpeg'?f.name.replace(/\.[^.]+$/,'')+'.jpg':f.name);
      const r=await api('upload',fd);out.push({id:r.id,type:r.type,name:r.name});formDirty=true}
    catch(e){toast(`${f.name}: ${e.message||'올리지 못했습니다'}`)}
  }
  lab.classList.remove('busy');
  if(out.length)toast(`영수증 ${out.length}장 첨부 · 저장해야 반영됩니다`);
  return out;
}
F('main').addEventListener('change',async e=>{
  const inp=e.target.closest('.att input');if(!inp)return;
  const files=[...inp.files],lab=inp.closest('.att');inp.value='';
  if(inp.dataset.mi!==undefined){const i=+inp.dataset.mi;const got=await uploadFiles(files,lab);if(S.meals[i]){S.meals[i].rc=[...(S.meals[i].rc||[]),...got];renderMeals()}}
  else{const got=await uploadFiles(files,lab);S.docs.push(...got);renderDocs()}
});
F('main').addEventListener('click',e=>{const b=e.target.closest('.th .x');if(!b)return;
  if(!confirm('이 영수증을 뺄까요?'))return;
  const k=b.dataset.rk,i=+b.dataset.ri;
  if(k==='doc'){S.docs.splice(i,1);renderDocs()}else{const mi=+k.slice(1);S.meals[mi].rc.splice(i,1);renderMeals()}});

function clearForm(){
  S.editing=null;S.status=isAdmin()?'확정':'신청';S.settle='미정산';S.meals=[];S.docs=[];
  F('form').reset();F('form2').reset();F('rooms').value=1;F('purpose').value='임직원 복리후생';
  ['roomFee','addFee','facFee','etcFee'].forEach(id=>F(id).value='');
  setSeg('statusSeg',S.status);setSeg('settleSeg',S.settle);F('reasonWrap').hidden=true;
  F('formMode').textContent=isAdmin()?'새 예약':'새 신청';
  calPick=null;
  F('btnSave').textContent=isAdmin()?'예약 저장':'신청하기';
  F('btnDel').hidden=true;F('btnDoc').hidden=true;F('btnDoc2').hidden=true;F('mealMode').textContent='예약 선택';F('mealPick').value='';renderPickBox();
  if(!isAdmin()&&S.me)F('name').value=S.me;
  renderMeals();renderDocs();refreshLock();highlight();
}
function loadForm(r){
  S.editing=r.id;S.status=r.status||'신청';S.settle=r.settle||'미정산';
  S.meals=structuredClone(r.meals||[]);S.docs=structuredClone(r.docs||[]);
  const nm=F('name');if(r.name&&![...nm.options].some(o=>o.value===r.name))nm.insertAdjacentHTML('beforeend',`<option>${esc(r.name)}</option>`);
  ['name','user','dept','phone','resort','ci','co','purpose','wish','reason','resvId','card','resvDate','payDate','mealCard','cardOut','cardIn','settledAt','adminNote'].forEach(k=>{const el=F(k);if(!el)return;
    if(el.tagName==='SELECT'&&r[k]&&![...el.options].some(o=>o.value===r[k]))el.insertAdjacentHTML('beforeend',`<option value="${esc(r[k])}">${esc(r[k])}</option>`);
    el.value=r[k]??''});
  F('rooms').value=r.rooms||1;
  ['roomFee','addFee','facFee','etcFee'].forEach(k=>F(k).value=parseNum(r[k])?won(r[k]):'');
  setSeg('statusSeg',S.status);setSeg('settleSeg',S.settle);F('reasonWrap').hidden=!(S.status==='반려'||S.status==='취소');
  F('formMode').textContent=`${r.status||'신청'} · ${r.name}`;
  F('btnSave').textContent=isAdmin()?'변경 저장':'수정 저장';
  F('btnDel').hidden=!isAdmin();F('btnDoc').hidden=false;F('btnDoc2').hidden=r.status!=='확정';
  F('mealMode').textContent=r.status==='확정'?`${r.name} · ${dot(r.ci).slice(5)}`:`${r.status||'신청'} 상태`;
  if(![...F('mealPick').options].some(o=>o.value===r.id))renderMealPick();F('mealPick').value=r.id;renderPickBox();
  renderMeals();renderDocs();refreshLock();highlight();
}
function refreshLock(){
  const staff=!isAdmin(),r=S.recs.find(x=>x.id===S.editing);
  const st=r?(r.status||'신청'):'신청';
  const infoLocked=staff&&r&&st!=='신청';
  const allLocked=staff&&r&&(st==='반려'||st==='취소'||r.settle==='정산완료'||r._src==='ledger');
  const noBook=!r||st!=='확정';
  F('fsInfo').disabled=!!infoLocked;F('fsResv').disabled=false;
  F('fsMeal').disabled=!!allLocked||noBook;F('fsCard').disabled=noBook;F('fsSettle').disabled=noBook;
  F('btnSave').hidden=!!(staff&&r&&(st!=='신청'||r._src==='ledger'));
  F('btnSave2').hidden=!!allLocked||noBook;
  const m2=F('lockMsg2');m2.hidden=!noBook&&!allLocked;
  m2.textContent=noBook?(r?`이 예약은 '${st}' 상태입니다. 식대는 확정된 예약에만 등록할 수 있습니다.`:'위에서 확정된 예약을 고르면 식대와 영수증을 등록할 수 있습니다.')
    :r._src==='ledger'&&staff?'정산 담당자가 등록한 예약입니다. 영수증은 법인카드와 함께 반납해 주세요.':r.settle==='정산완료'?'정산이 끝난 예약입니다.':'';
  const m=F('lockMsg');
  if(!staff){m.hidden=true}
  else{m.hidden=false;
    m.textContent=!r?'신청하면 정산 담당자(김지안)가 확인 후 예약하고 결과를 알려 드립니다. 확정 전까지는 내용을 고칠 수 있습니다.'
      :st==='반려'||st==='취소'?`${st}된 예약입니다${r.reason?` (사유: ${r.reason})`:''}. 다시 신청하려면 [새로 쓰기]를 눌러 주세요.`
      :r._src==='ledger'?'정산 담당자가 등록한 예약입니다. 영수증은 법인카드와 함께 반납해 주세요.'
      :r.settle==='정산완료'?'정산이 끝난 예약입니다.'
      :st==='확정'?'예약이 확정되었습니다. 이용 후 오른쪽 [식대 · 부대비용 지출]에서 식대 사용처 · 금액과 영수증 사진을 올려 주세요.':'확인 대기 중입니다. 확정 전까지는 내용을 고칠 수 있습니다.';
  }
  document.querySelectorAll('#form2 .att').forEach(a=>a.style.visibility=(!assets||allLocked||noBook)?'hidden':'');
  document.querySelectorAll('#form2 .th .x').forEach(b=>b.hidden=!!(allLocked||noBook));
}
function collect(){
  const ci=F('ci').value,co=F('co').value;
  const d={
    name:F('name').value,user:F('user').value.trim(),dept:F('dept').value.trim(),phone:F('phone').value.trim(),
    resort:F('resort').value.trim(),ci,co,rooms:Math.max(1,parseNum(F('rooms').value)||1),nights:nightsVal(),
    purpose:F('purpose').value.trim(),wish:F('wish').value.trim(),period:periodOf(ci),
    meals:S.meals.filter(m=>m.place||m.amount||(m.rc||[]).length).map(m=>({id:m.id||uid(),date:m.date||'',place:(m.place||'').trim(),amount:parseNum(m.amount),rc:m.rc||[]})),
    facFee:parseNum(F('facFee').value),etcFee:parseNum(F('etcFee').value),docs:S.docs,
    status:S.status,reason:F('reason').value.trim(),resvId:F('resvId').value,card:F('card').value,resvDate:F('resvDate').value,payDate:F('payDate').value,
    roomFee:parseNum(F('roomFee').value),addFee:parseNum(F('addFee').value),mealCard:F('mealCard').value,cardOut:F('cardOut').value,cardIn:F('cardIn').value,
    settle:S.settle,settledAt:F('settledAt').value,adminNote:F('adminNote').value.trim()
  };
  return d;
}
async function doSave(){
  if(!store)return;
  const d=collect(),prev=S.recs.find(r=>r.id===S.editing);
  if(!d.name){toast('성명을 선택해 주세요');F('name').focus();return}
  if(!d.resort){toast('리조트 · 객실을 적어 주세요');F('resort').focus();return}
  if(!d.ci||!d.co||d.nights<1){toast('체크인 · 체크아웃 날짜를 확인해 주세요');F('ci').focus();return}
  const L=limitOf(d.name,d.period),u=usage(d.name,d.period,S.editing);
  if(!L.company&&live(d)){
    const warns=[];
    if(u.nights+(d.status==='확정'||!isAdmin()?d.rooms*d.nights:0)>L.nights)warns.push(`숙박일수 ${L.nights}박 초과`);
    if(u.stay+stayCost(d)>L.stay)warns.push(`숙박비 한도 ${won(L.stay)}원 초과`);
    if(u.meal+mealCost(d)>L.meal)warns.push(`식대 한도 ${won(L.meal)}원 초과`);
    if(warns.length&&!confirm(`${d.name}님 ${d.period}년도 ${warns.join(', ')}입니다. 그래도 저장할까요?`))return;
  }
  if(prev&&prev.status!==d.status)d.decidedAt=new Date().toISOString();else if(prev)d.decidedAt=prev.decidedAt||'';
  const id=S.editing||uid();
  d.createdAt=prev?.createdAt||new Date().toISOString();d.updatedAt=new Date().toISOString();
  if(!isAdmin()){d.name=S.me;d.status=prev?.status||'신청'}
  F('btnSave').disabled=true;
  try{
    const res=await store.save(id,d,prev);
    S.editing=(res&&res.id)||id;const savedId=S.editing;
    toast(!prev?(isAdmin()?'예약을 저장했습니다':'신청했습니다. 확인 후 알려 드립니다'):'저장했습니다');
    if(d.period&&d.period!==S.period){S.period=d.period;renderPeriodSel();renderSelects()}
    const r=S.recs.find(x=>x.id===savedId);if(r)loadForm(r);
  }catch(e){toast(e.message||'저장하지 못했습니다. 잠시 후 다시 시도해 주세요');if(e.status===409){formDirty=false;reload().then(()=>{const r=S.recs.find(x=>x.id===S.editing);r?loadForm(r):clearForm()}).catch(()=>{})}}
  finally{F('btnSave').disabled=false}
}
F('btnSave').addEventListener('click',doSave);F('btnSave2').addEventListener('click',doSave);
F('btnDel').addEventListener('click',async()=>{
  const r=S.recs.find(x=>x.id===S.editing);if(!r)return;
  if(!confirm(`${r.name}님 ${dot(r.ci)} ${r.resort} 예약을 삭제할까요?\n기록을 남기려면 삭제 대신 [취소]로 바꿔 저장하세요.`))return;
  try{await store.remove(r.id,r);toast('삭제했습니다');clearForm()}catch(e){toast(e.message||'삭제하지 못했습니다')}
});
F('btnClear').addEventListener('click',()=>{clearForm();presetMe()});

F('periodSel').addEventListener('change',()=>{S.period=F('periodSel').value;renderSelects();updateForm();renderAll()});

/* ---------- 화면 전환 ---------- */
function setView(v){
  // 예약 신청과 식대 지출은 한 화면(req)이다. 'meal' 은 그 화면의 오른쪽을 식대 지출로 바꾸라는 뜻
  let pane=null;
  if(v==='meal'){v='req';pane='meal'}
  if(!isAdmin()&&ADMIN_VIEWS.includes(v))v='req';
  S.view=v;F('main').dataset.view=v;
  document.querySelectorAll('.vtabs button').forEach(b=>b.setAttribute('aria-selected',b.dataset.v===v));
  document.querySelectorAll('.view').forEach(el=>el.hidden=el.dataset.view!==v);
  if(pane)setPane(pane);
  window.scrollTo(0,0);
}
/* 오른쪽 칸: 'req'(리조트 예약 신청서) 또는 'meal'(식대 · 부대비용 지출) */
function setPane(p){
  document.querySelectorAll('.rightcol [data-pane]').forEach(el=>el.hidden=el.dataset.pane!==p);
  document.querySelectorAll('.paneseg [data-pane-btn]').forEach(b=>b.setAttribute('aria-selected',b.dataset.paneBtn===p));
}
document.querySelector('.paneseg').addEventListener('click',e=>{const b=e.target.closest('[data-pane-btn]');if(b)setPane(b.dataset.paneBtn)});
document.querySelector('.vtabs').addEventListener('click',e=>{const b=e.target.closest('button');if(b)setView(b.dataset.v)});
F('goHome').addEventListener('click',e=>{e.preventDefault();setView('req')});
function openRec(id,v){const r=S.recs.find(x=>x.id===id);if(!r)return;loadForm(r);v=v||(r.status==='확정'&&r.co<=today()?'meal':'req');setView(v);if(v==='req')setPane('req')}
function highlight(){document.querySelectorAll('#tbody tr').forEach(tr=>tr.classList.toggle('sel',tr.dataset.id===S.editing))}

/* ---------- 렌더 ---------- */
const badge=s=>`<span class="badge ${s==='확정'?'b-ok':s==='반려'?'b-no':s==='취소'?'b-none':'b-wait'}">${s||'신청'}</span>`;
function renderAll(){renderPeriod();renderKpis();renderBoard();renderQueue();renderAccts();renderList();renderSheet();renderNoticeSel();renderReqList();renderMealPick();renderMealStat();renderMealLines();renderPickBox();updateForm()}
function renderPeriod(){
  const p=S.period,ps=pStart(p),pe=pEnd(p),t=today();
  const total=dayDiff(ps,pe)+1,el=Math.min(total,Math.max(0,dayDiff(ps,t)+1));
  F('pLab').textContent=`${p}년도 이용기간`;F('pRng').textContent=`${dot(ps)} ~ ${dot(pe)}`;
  F('pBar').style.width=(el/total*100)+'%';
  F('pLeft').textContent=t<ps?`${dayDiff(t,ps)}일 뒤 시작`:t>pe?'이용기간 종료':`${el}일 지남 · 남은 기간 ${total-el}일`;
}
function periodRecs(){return visible().filter(r=>recPeriod(r)===S.period)}
function renderKpis(){
  const p=S.period,rs=periodRecs(),ok=rs.filter(r=>r.status==='확정');
  let html='';
  if(isAdmin()){
    const pend=rs.filter(r=>(r.status||'신청')==='신청').length;
    const st=people(p),limStay=st.reduce((a,s)=>a+(+s.stay||0),0),limMeal=st.reduce((a,s)=>a+(+s.meal||0),0),limN=st.reduce((a,s)=>a+(+s.nights||0),0);
    const usedStay=ok.filter(r=>!limitOf(r.name,p).company).reduce((a,r)=>a+stayCost(r),0);
    const usedMeal=rs.filter(live).filter(r=>!limitOf(r.name,p).company).reduce((a,r)=>a+mealCost(r),0);
    const usedN=ok.filter(r=>!limitOf(r.name,p).company).reduce((a,r)=>a+roomNights(r),0);
    html=`<div class="kpi click${pend?' alert':''}" data-go="pend"><div class="k">확인할 예약 신청</div><div class="v num">${pend}건</div><div class="s">예약 · 반려 · 취소 처리</div></div>
      <div class="kpi"><div class="k">확정 예약 · 사용 숙박일수</div><div class="v num">${ok.length}건 · ${usedN}박</div><div class="s">직원 제공 ${limN}박 중</div></div>
      <div class="kpi"><div class="k">숙박비 사용</div><div class="v num">${won(usedStay)}</div><div class="s">한도 합계 ${won(limStay)}원</div></div>
      <div class="kpi"><div class="k">식대 사용</div><div class="v num">${won(usedMeal)}</div><div class="s">한도 합계 ${won(limMeal)}원</div></div>`;
  }else{
    if(!S.me){html=`<div class="kpi alert" style="grid-column:1/-1"><div class="k">처음 오셨나요?</div><div class="v" style="font-size:16px">오른쪽 위 [내 이름]에서 이름을 골라 주세요</div><div class="s">내 한도와 예약 현황이 표시됩니다.</div></div>`}
    else{const L=limitOf(S.me,p),u=usage(S.me,p);const pend=rs.filter(r=>(r.status||'신청')==='신청').length;
      html=`<div class="kpi${pend?' alert':''}"><div class="k">확인 대기 중인 신청</div><div class="v num">${pend}건</div><div class="s">확정 ${ok.length}건</div></div>
      <div class="kpi"><div class="k">잔여숙박</div><div class="v num">${Math.max(0,L.nights-u.nights)}박</div><div class="s">제공 ${L.nights}박 · 사용 ${u.nights}박</div></div>
      <div class="kpi"><div class="k">잔여숙박비</div><div class="v num">${won(L.stay-u.stay)}</div><div class="s">한도 ${won(L.stay)}원</div></div>
      <div class="kpi"><div class="k">잔여식대</div><div class="v num">${won(L.meal-u.meal)}</div><div class="s">한도 ${won(L.meal)}원</div></div>`}
  }
  F('kpis').innerHTML=html;
}
F('kpis').addEventListener('click',e=>{const k=e.target.closest('[data-go="pend"]');if(!k)return;F('fStatus').value='신청';S.fStatus='신청';setView('list');renderList()});
function renderBoard(){
  const p=S.period,ps=pStart(p),total=dayDiff(ps,pEnd(p))+1,t=today();
  let st=staffOf(p);
  if(!isAdmin())st=st.filter(s=>s.name===S.me);
  const months=Array.from({length:12},(_,i)=>((i+4)%12)+1);
  const head=`<div class="brow head"><span>성명</span><div class="months">${months.map(m=>`<span>${m}월</span>`).join('')}</div><span>숙박비</span><span>식대</span><span style="text-align:center">박수</span></div>`;
  const tPos=(t>=ps&&t<=pEnd(p))?dayDiff(ps,t)/total*100:null;
  const rows=st.map(s=>{
    const rs=S.recs.filter(r=>r.name===s.name&&recPeriod(r)===p&&(r.status==='확정'||(r.status||'신청')==='신청'));
    const blocks=rs.map(r=>{const a=Math.max(0,dayDiff(ps,r.ci)),b=Math.min(total,dayDiff(ps,r.co));const l=a/total*100,w=Math.max(.6,(b-a)/total*100);
      return `<button class="stay${r.status==='확정'?'':' pend'}" style="left:${l}%;width:${w}%" data-id="${esc(r.id)}" title="${esc(`${r.resort} · ${dot(r.ci)}~${dot(r.co)} · ${r.rooms||1}실 ${r.nights}박 (${r.status||'신청'})${r.user?' · 이용자 '+r.user:''}`)}" aria-label="${esc(r.resort)} ${esc(dot(r.ci))}"></button>`}).join('');
    const strip=`<div class="strip">${blocks}${tPos!==null?`<span class="today" style="left:${tPos}%"></span>`:''}</div>`;
    if(s.company){const sum=rs.filter(r=>r.status==='확정').reduce((a,r)=>a+stayCost(r),0);
      return `<div class="brow"><span class="nm">${esc(s.name)}<small>회사 공용</small></span>${strip}<div class="mini"><span><b>${won(sum)}</b>원</span><span>한도 없음</span></div><div class="mini"></div><div class="nights"></div></div>`}
    const u=usage(s.name,p);
    const mini=(used,lim,color)=>{const over=used>lim;return `<div class="mini${over?' over':''}"><div class="meter"><i style="width:${lim?Math.min(100,used/lim*100):0}%;background:${color}"></i></div><span>잔여 <b class="num">${won(lim-used)}</b></span></div>`};
    return `<div class="brow"><span class="nm">${esc(s.name)}${u.pend?`<small>신청 ${u.pend}건</small>`:''}</span>${strip}${mini(u.stay,s.stay,'var(--sea)')}${mini(u.meal,s.meal,'var(--meal)')}<div class="nights num" style="${u.nights>s.nights?'color:var(--no)':''}">${u.nights}/${s.nights}<small>박</small></div></div>`;
  }).join('');
  F('board').innerHTML=head+(rows||`<div class="empty">${isAdmin()?'설정에서 직원을 추가해 주세요.':'[내 이름]을 고르면 내 일정이 표시됩니다.'}</div>`);
  F('boardMeta').textContent=isAdmin()?`직원 ${people(p).length}명 · 막대를 누르면 예약이 열립니다`:'';
}
F('board').addEventListener('click',e=>{const b=e.target.closest('.stay');if(b)openRec(b.dataset.id)});
function renderQueue(){
  const rs=periodRecs(),t=today();
  const line=(r,txt,v)=>`<button class="qrow" data-id="${esc(r.id)}" data-v="${v||''}"><span class="who">${esc(r.name)}</span><span class="what">${esc(txt)}</span>${badge(r.status||'신청')}</button>`;
  let html='';
  if(isAdmin()){
    const pend=rs.filter(r=>(r.status||'신청')==='신청').sort((a,b)=>(a.createdAt||'').localeCompare(b.createdAt||''));
    const giveCard=rs.filter(r=>r.status==='확정'&&r.ci>=t&&!r.cardOut&&dayDiff(t,r.ci)<=14).sort((a,b)=>a.ci.localeCompare(b.ci));
    const back=rs.filter(r=>r.status==='확정'&&r.cardOut&&!r.cardIn);
    const settle=rs.filter(r=>r.status==='확정'&&r.co<=t&&r.settle!=='정산완료');
    const grp=(t2,arr,f,v)=>arr.length?`<div class="qgrp">${t2} ${arr.length}건</div>`+arr.slice(0,8).map(r=>line(r,f(r),v)).join(''):'';
    html=grp('예약 신청 확인',pend,r=>`${r.resort} · ${dot(r.ci)}~${dot(r.co).slice(5)} · ${r.rooms||1}실 ${r.nights}박`,'req')+
      grp('식대카드 전달 (2주 내 이용)',giveCard,r=>`${dot(r.ci)}(${wd(r.ci)}) ${r.resort}`,'meal')+
      grp('카드 · 영수증 반납 대기',back,r=>`${dot(r.cardOut)} 전달 · ${cardLabel(r.mealCard)}`,'meal')+
      grp('이용 끝 · 정산 대기',settle,r=>`${dot(r.co)} 체크아웃 · ${r.resort}`,'meal');
    F('queue').innerHTML=html||'<div class="empty">지금 처리할 일이 없습니다.</div>';
  }else{
    const mine=[...rs].sort((a,b)=>(b.ci||'').localeCompare(a.ci||''));
    F('queue').innerHTML=mine.length?mine.map(r=>line(r,`${dot(r.ci)}~${dot(r.co).slice(5)} ${r.resort}`)).join(''):'<div class="empty">아직 예약이 없습니다. [예약 신청]으로 시작하세요.</div>';
  }
}
F('queue').addEventListener('click',e=>{const b=e.target.closest('.qrow');if(b)openRec(b.dataset.id,b.dataset.v)});

/* ---------- 예약 신청 탭 목록 ---------- */
const ADMIN_VIEWS=['dash','list','merged','notice'];
function myLimits(){
  if(!S.me)return '';
  const p=S.period,L=limitOf(S.me,p),u=usage(S.me,p);
  // act: 누르면 아래에 펼칠 목록('stay' 숙박 · 'meal' 식대). 같은 걸 다시 누르면 접힌다
  const cell=(t,v,s,act)=>{const on=act&&openUse===act;return `<div class="kpi${act?' kpi-btn'+(on?' on':''):''}" style="padding:10px 12px"${act?` role="button" tabindex="0" data-use="${act}" aria-expanded="${on}"`:''}><div class="k">${t}</div><div class="v num" style="font-size:18px">${v}</div><div class="s">${s}${act?`<br>${on?'접기 ▴':(act==='meal'?'내 사용내역 ▾':'내 숙박내역 ▾')}`:''}</div></div>`};
  // [잔여숙박 / 잔여숙박비(위아래 한 칸)] | 잔여식대 | 내 예약 달력 2개월. 화면이 좁으면 달력이 아랫줄로 내려간다
  const on=openUse==='stay';
  const half=(t,v,s)=>`<div class="kstack"><div class="k">${t}</div><div class="v num" style="font-size:18px">${v}</div><div class="s">${s}</div></div>`;
  return `<div class="mykpis">`+
    `<div class="kpi kpi-btn kpi-stay${on?' on':''}" style="padding:10px 12px" role="button" tabindex="0" data-use="stay" aria-expanded="${on}">`+
      half('잔여숙박',Math.max(0,L.nights-u.nights)+'박',`제공 ${L.nights}박`)+
      half('잔여숙박비',won(L.stay-u.stay)+'원',`한도 ${won(L.stay)}원`)+
      `<div class="s">${on?'접기 ▴':'내 숙박내역 ▾'}</div></div>`+
    cell('잔여식대',won(L.meal-u.meal)+'원',`한도 ${won(L.meal)}원`,'meal')+myCalendar()+'</div>'+
    (openUse==='stay'?myStayUse(p,L,u):openUse==='meal'?myMealUse(p,L,u):'');
}
/* 내 예약 달력(이번 달 · 다음 달 두 달) — 묵는 밤(체크인~체크아웃 전날)을 칠한다. 확정은 초록, 신청은 노랑.
   누르면 오른쪽 리조트 예약 신청서로: 칠한 날은 그 예약을, 오늘 이후 빈 날은 그날 체크인으로 새 신청서를 연다 */
let calMonth=today().slice(0,7);
function myCalendar(){
  const [y,m]=calMonth.split('-').map(Number);
  const nx=new Date(y,m,1),next=`${nx.getFullYear()}-${String(nx.getMonth()+1).padStart(2,'0')}`;
  const mine=S.recs.filter(r=>r.name===S.me&&live(r));
  const sel=calSel(),n=sel.ci&&sel.co?dayDiff(sel.ci,sel.co):0;
  const tip=calPick==='co'&&sel.ci?`체크인 <b>${dot(sel.ci)}</b> · 이제 <b>체크아웃</b> 날짜를 누르세요`
    :sel.ci&&sel.co?`체크인 <b>${dot(sel.ci)}</b> → 체크아웃 <b>${dot(sel.co)}</b> · ${n}박`
    :'날짜를 눌러 <b>체크인</b> → <b>체크아웃</b> 순서로 고르세요';
  return `<div class="kpi mycal" style="padding:8px 10px"><div class="calh"><button type="button" class="calnav" data-act="calprev" aria-label="이전 달">‹</button><span class="calk"><i class="c-ok"></i>확정 <i class="c-wait"></i>신청 <i class="c-in"></i>고른 날짜</span><button type="button" class="calnav" data-act="calnext" aria-label="다음 달">›</button></div>`+
    `<div class="caltip${calPick==='co'?' pick':''}">${tip}</div>`+
    `<div class="cal2">${calMonthGrid(calMonth,mine)}${calMonthGrid(next,mine)}</div></div>`;
}
/* 달력에 칠할 '고른 날짜' — 새 신청서(저장 전)의 체크인 · 체크아웃 칸 값 그대로 */
let calPick=null;   // 'co' = 체크인을 골랐고 체크아웃을 기다리는 중
function calSel(){return S.editing?{ci:'',co:''}:{ci:F('ci').value,co:F('co').value}}
function calMonthGrid(ym,mine){
  const [y,m]=ym.split('-').map(Number);
  const first=new Date(y,m-1,1),days=new Date(y,m,0).getDate(),pad=first.getDay();
  const t=today(),sel=calSel();
  let cells='<span class="cw">일</span><span class="cw">월</span><span class="cw">화</span><span class="cw">수</span><span class="cw">목</span><span class="cw">금</span><span class="cw">토</span>'+'<span></span>'.repeat(pad);
  for(let d=1;d<=days;d++){
    const ds=`${ym}-${String(d).padStart(2,'0')}`;
    const r=mine.find(x=>x.ci<=ds&&ds<x.co);
    const pick=ds===sel.ci?'c-in':ds===sel.co?'c-out':sel.ci&&sel.co&&sel.ci<ds&&ds<sel.co?'c-mid':'';
    const cls=['cd',r?(r.status==='확정'?'c-ok':'c-wait'):'',pick,ds===t?'c-today':'',(pad+d-1)%7===0?'c-sun':''].filter(Boolean).join(' ');
    const lab=ds===sel.ci?' (체크인)':ds===sel.co?' (체크아웃)':'';
    const day=ds>=t?` data-cal-day="${ds}"`:'';
    cells+=r?`<button type="button" class="${cls}" data-cal-id="${esc(r.id)}"${day} title="${esc(r.resort)} · ${r.status||'신청'}${lab}">${d}</button>`
      :ds>=t?`<button type="button" class="${cls} c-free"${day} title="${dot(ds)}${lab||(calPick==='co'?' 체크아웃으로':' 체크인으로')}">${d}</button>`:`<span class="${cls}">${d}</span>`;
  }
  return `<div class="calm"><b class="calt">${y}.${String(m).padStart(2,'0')}</b><div class="calg">${cells}</div></div>`;
}
/* 달력 누르기: 첫 번째는 체크인, 두 번째(그 뒤 날짜)는 체크아웃. 고른 날짜는 오른쪽 신청서 칸에 바로 들어간다.
   체크인을 고르기 전에 칠한 날(내 예약)을 누르면 그 예약을 신청서에서 연다 */
function calClick(ds,id){
  const ci=F('ci').value;
  if(calPick==='co'&&!S.editing&&ds&&ci&&ds>ci){
    const mine=S.recs.filter(r=>r.name===S.me&&live(r));
    if(mine.some(r=>r.ci<ds&&ci<r.co)){toast('고른 기간에 이미 내 예약이 있습니다. 체크아웃을 다시 골라 주세요');return}
    F('co').value=ds;calPick=null;calDatesChanged('co');return;
  }
  if(id){calPick=null;openRec(id,'req');return}
  if(!ds)return;
  if(S.editing){clearForm();presetMe()}
  setView('req');setPane('req');
  F('ci').value=ds;F('co').value='';calPick='co';calDatesChanged('ci');
}
function calDatesChanged(which){
  updateForm();renderReqList();
  // 신청서의 체크인 · 체크아웃 칸을 잠깐 반짝여 어디에 들어갔는지 보인다
  [which==='ci'?'ci':'co'].concat(which==='co'?['ci']:[]).forEach(id=>{const el=F(id);el.classList.remove('flash');void el.offsetWidth;el.classList.add('flash')});
}
function moveCal(n){const [y,m]=calMonth.split('-').map(Number);const d=new Date(y,m-1+n,1);calMonth=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;renderReqList()}
/* 잔여식대를 누르면 펼치는 '내 사용내역' — 한도에 잡히는 것(반려 · 취소 제외)과 같은 기준으로 줄마다 보여준다 */
let openUse=null;
/* 잔여숙박 · 잔여숙박비를 누르면 펼치는 '내 숙박내역' — 이 연도 내 예약 전부. 한도에는 확정만 잡힌다. 줄을 누르면 예약 자세히 */
function myStayUse(p,L,u){
  const rs=S.recs.filter(r=>r.name===S.me&&recPeriod(r)===p).sort((a,b)=>a.ci.localeCompare(b.ci));
  const rows=rs.map(r=>{const ok=r.status==='확정';return `<tr data-id="${esc(r.id)}" class="${ok?'':'muted'}"><td>${dot(r.ci)}(${wd(r.ci)}) ~ ${dot(r.co).slice(5)}</td><td>${esc(r.resort)}</td><td class="r num">${r.rooms||1}실 ${r.nights}박</td><td class="r num">${ok?roomNights(r)+'박':'-'}</td><td class="r num">${ok&&stayCost(r)?won(stayCost(r)):'-'}</td><td>${badge(r.status||'신청')}</td></tr>`});
  return `<div class="myuse"><div class="qgrp">내 숙박내역 <span class="meta">${p}년도 · 확정 예약만 한도에 잡힘</span></div>`+
    (rows.length?`<div class="tablewrap"><table class="stbl"><thead><tr><th>기간</th><th>리조트</th><th class="r">객실 · 박</th><th class="r">사용 박수</th><th class="r">숙박비</th><th>상태</th></tr></thead><tbody>${rows.join('')}</tbody>
      <tfoot><tr><td colspan="3">사용 ${u.nights}박 / 제공 ${L.nights}박 · 잔여 ${Math.max(0,L.nights-u.nights)}박</td><td class="r num">${u.nights}박</td><td class="r num">${won(u.stay)}</td><td>잔여 ${won(L.stay-u.stay)}원</td></tr></tfoot></table></div>`
     :'<div class="empty">아직 신청한 예약이 없습니다. 오른쪽 신청서를 작성해 주세요.</div>')+'</div>';
}
function myMealUse(p,L,u){
  const rs=S.recs.filter(r=>r.name===S.me&&recPeriod(r)===p&&live(r)&&(r.meals||[]).length).sort((a,b)=>a.ci.localeCompare(b.ci));
  const rows=rs.flatMap(r=>(r.meals||[]).map(m=>`<tr><td>${dot(m.date||r.ci)}</td><td>${esc(r.resort)}</td><td>${esc(m.place)}</td><td class="r num">${won(parseNum(m.amount))}</td><td class="c">${(m.rc||[]).length?'✓':''}</td><td>${esc(r.settle||'미정산')}</td></tr>`));
  return `<div class="myuse"><div class="qgrp">내 사용내역 <span class="meta">${p}년도 · 식대</span></div>`+
    (rows.length?`<div class="tablewrap"><table class="stbl"><thead><tr><th>사용일</th><th>리조트</th><th>사용처</th><th class="r">금액</th><th class="c">영수증</th><th>정산</th></tr></thead><tbody>${rows.join('')}</tbody>
      <tfoot><tr><td colspan="3">사용 ${won(u.meal)}원 / 한도 ${won(L.meal)}원</td><td class="r num">${won(u.meal)}</td><td colspan="2">잔여 ${won(L.meal-u.meal)}원</td></tr></tfoot></table></div>`
     :'<div class="empty">아직 사용한 식대가 없습니다.</div>')+'</div>';
}
function renderReqList(){
  const rs=periodRecs();
  const line=r=>`<button class="qrow" data-id="${esc(r.id)}"><span class="who">${esc(r.name)}</span><span class="what">${dot(r.ci)}(${wd(r.ci)})~${dot(r.co).slice(5)} · ${esc(r.resort)} · ${r.rooms||1}실 ${r.nights}박${r.reason?' · '+esc(r.reason):''}</span>${badge(r.status||'신청')}</button>`;
  let html='';
  if(isAdmin()){
    const pend=rs.filter(r=>(r.status||'신청')==='신청').sort((a,b)=>(a.createdAt||'').localeCompare(b.createdAt||''));
    const up=rs.filter(r=>r.status==='확정'&&r.ci>=today()).sort((a,b)=>a.ci.localeCompare(b.ci));
    const done=rs.filter(r=>r.status==='반려'||r.status==='취소').sort((a,b)=>(b.decidedAt||b.updatedAt||'').localeCompare(a.decidedAt||a.updatedAt||''));
    html=`<div class="qgrp">확인할 신청 ${pend.length}건</div>`+(pend.map(line).join('')||'<div class="empty">새 신청이 없습니다.</div>')+
      (up.length?`<div class="qgrp">다가오는 확정 예약 ${up.length}건</div>`+up.map(line).join(''):'')+
      (done.length?`<div class="qgrp">반려 · 취소 ${done.length}건</div>`+done.slice(0,10).map(line).join(''):'');
    F('reqMeta').textContent='누르면 자세한 내용 · 오른쪽 신청서에서 확정 · 반려 · 취소';
  }else{
    // 직원은 신청 내역 줄 목록 없이 잔여 칸 · 달력만. 내역은 잔여숙박 · 잔여숙박비를 누르면 펼쳐진다
    html=myLimits();
    F('reqMeta').textContent='';
  }
  F('reqTitle').firstChild.textContent=isAdmin()?'예약 신청 현황':'내 예약 신청';
  F('reqList').innerHTML=html;
}
F('reqList').addEventListener('click',e=>{
  if(e.target.closest('[data-act=calprev]')){moveCal(-1);return}
  if(e.target.closest('[data-act=calnext]')){moveCal(1);return}
  const cc=e.target.closest('[data-cal-day],[data-cal-id]');if(cc){calClick(cc.dataset.calDay,cc.dataset.calId);return}
  const use=e.target.closest('[data-use]');if(use){openUse=openUse===use.dataset.use?null:use.dataset.use;renderReqList();return}
  const tr=e.target.closest('.myuse tr[data-id]');if(tr){showRec(tr.dataset.id);return}
  const b=e.target.closest('.qrow');if(b)showRec(b.dataset.id)});
F('reqList').addEventListener('keydown',e=>{const use=(e.key==='Enter'||e.key===' ')&&e.target.closest('[data-use]');if(use){e.preventDefault();openUse=openUse===use.dataset.use?null:use.dataset.use;renderReqList()}});

/* ---------- 예약 자세히 (신청 현황에서 한 줄을 누르면) ---------- */
let recId=null;
function showRec(id){
  const r=S.recs.find(x=>x.id===id);if(!r)return;recId=id;
  const st=r.status||'신청',meal=(r.meals||[]).reduce((a,m)=>a+(+m.amount||0),0);
  const row=(k,v)=>v===''||v==null?'':`<span>${k}</span><b>${esc(v)}</b>`;
  const sec=t=>`<div class="sec">${t}</div>`;
  let h=row('성명',r.name)+row('실이용자',r.user)+row('부서 / 직위',r.dept)+row('연락처',r.phone)+
    sec('예약 내용')+row('리조트 · 객실',r.resort)+
    row('기간',`${dot(r.ci)}(${wd(r.ci)}) ~ ${dot(r.co)}(${wd(r.co)}) · ${r.nights}박 · 객실 ${r.rooms||1}실`)+
    row('사용 목적',r.purpose)+row('요청사항',r.wish)+row('신청일',(r.createdAt||'').slice(0,10).replace(/-/g,'.'))+
    sec('처리 결과')+row('상태',st+(r.reason?` — ${r.reason}`:''))+row('처리일',(r.decidedAt||'').slice(0,10).replace(/-/g,'.'));
  if(st==='확정'){
    h+=row('예약 아이디',r.resvId)+row('결제 카드',cardLabel(r.card))+
      row('숙박비',(r.roomFee||r.addFee)?`${won((r.roomFee||0)+(r.addFee||0))}원`+(r.addFee?` (객실 ${won(r.roomFee)} + 추가 ${won(r.addFee)})`:''):'')+
      sec('식대 · 정산')+row('식대',`${won(meal)}원 · ${(r.meals||[]).length}건`)+
      row('부대비용',(r.facFee||r.etcFee)?`${won((r.facFee||0)+(r.etcFee||0))}원`:'')+
      row('식대 카드',cardLabel(r.mealCard)+(r.cardOut?` · 전달 ${dot(r.cardOut)}`:'')+(r.cardIn?` · 반납 ${dot(r.cardIn)}`:''))+
      row('정산',(r.settle||'미정산')+(r.settledAt?` (${dot(r.settledAt)})`:''));
  }
  F('recBody').innerHTML=h;F('recBadge').innerHTML=badge(st);
  // 고칠 수 있을 때만 '신청서에서 열기' (직원은 신청 상태인 본인 신청만)
  const canEdit=isAdmin()||(st==='신청'&&r._src==='req');
  F('recOpen').hidden=!canEdit;F('recOpen').textContent=isAdmin()?'신청서에서 처리':'신청서에서 고치기';
  // 확정된 예약은 오른쪽 식대 지출에서 식대 · 영수증을 넣거나 본다
  F('recMeal').hidden=st!=='확정';
  F('dlgRec').showModal();
}
F('recClose').addEventListener('click',()=>F('dlgRec').close());
F('recMeal').addEventListener('click',()=>{F('dlgRec').close();if(recId)openRec(recId,'meal')});
F('recOpen').addEventListener('click',()=>{F('dlgRec').close();if(recId)openRec(recId,'req')});

/* ---------- 식대비 지출현황 탭 ---------- */
function renderMealPick(){
  const sel=F('mealPick');
  const rs=periodRecs().filter(r=>r.status==='확정').sort((a,b)=>(b.ci||'').localeCompare(a.ci||''));
  const cur=S.editing;
  sel.innerHTML='<option value="">예약을 고르세요</option>'+rs.map(r=>`<option value="${esc(r.id)}">${esc(r.name)} · ${dot(r.ci).slice(5)}~${dot(r.co).slice(5)} ${esc(r.resort).slice(0,20)}${mealCost(r)?' · 식대 '+won(mealCost(r)):''}</option>`).join('');
  sel.value=cur&&rs.some(r=>r.id===cur)?cur:'';
}
function renderPickBox(){
  const r=S.recs.find(x=>x.id===S.editing),b=F('pickBox');
  if(!r||r.status!=='확정'){b.innerHTML='';return}
  b.innerHTML=`<span>이용자</span><div>${esc(r.name)}${r.user?` (실이용자 ${esc(r.user)})`:''}</div><span>리조트</span><div>${esc(r.resort)}</div><span>일정</span><div class="num">${dot(r.ci)}(${wd(r.ci)}) ~ ${dot(r.co)}(${wd(r.co)}) · ${r.rooms||1}실 ${r.nights}박</div><span>숙박비</span><div class="num">${won(stayCost(r))}원</div>`;
}
F('mealPick').addEventListener('change',()=>{const v=F('mealPick').value;if(v)openRec(v,'meal');else{clearForm();presetMe()}});
function renderMealStat(){
  const p=S.period,rs=S.recs.filter(r=>recPeriod(r)===p);
  let st=people(p);if(!isAdmin())st=st.filter(s=>s.name===S.me);
  let tL=0,tU=0,tN=0;
  F('mstatBody').innerHTML=st.map(s=>{
    const mine=rs.filter(r=>r.name===s.name&&live(r));const used=mine.reduce((a,r)=>a+mealCost(r),0),n=mine.reduce((a,r)=>a+(r.meals||[]).length,0);
    const out=mine.filter(r=>r.status==='확정'&&r.cardOut&&!r.cardIn).length;
    tL+=+s.meal||0;tU+=used;tN+=n;const rem=(+s.meal||0)-used,pct=s.meal?Math.min(100,used/s.meal*100):0;
    return `<tr data-n="${esc(s.name)}" class="${rem<0?'over':''}"><td><b>${esc(s.name)}</b></td><td class="r num">${won(s.meal)}</td><td class="r num">${used?won(used):'-'}</td>
      <td><div class="meter"><i style="width:${pct}%;background:var(--meal)"></i></div></td><td class="r num rem">${won(rem)}</td><td class="c num">${n||''}</td>
      <td>${out?`<span class="badge b-wait">미반납 ${out}</span>`:''}</td></tr>`}).join('')||`<tr><td colspan="7" class="empty">${isAdmin()?'설정에서 직원을 추가해 주세요.':'[내 이름]을 고르면 내 식대 현황이 보입니다.'}</td></tr>`;
  F('mstatFoot').innerHTML=st.length>1?`<tr><td>합계</td><td class="r num">${won(tL)}</td><td class="r num">${won(tU)}</td><td></td><td class="r num">${won(tL-tU)}</td><td class="c num">${tN}</td><td></td></tr>`:'';
  F('mstatMeta').textContent=isAdmin()?'이름을 누르면 아래 내역이 그 직원으로 바뀝니다':'';
  F('mstatTitle').firstChild.textContent=isAdmin()?'직원별 식대 지출현황':'내 식대 지출현황';
  const w=F('mealWho'),cur=w.value;
  w.innerHTML=(isAdmin()?'<option value="">전체 직원</option>':'')+st.map(s=>`<option>${esc(s.name)}</option>`).join('');
  w.value=st.some(s=>s.name===cur)?cur:(isAdmin()?'':(st[0]?.name||''));
}
function renderMealLines(){
  const who=F('mealWho').value;
  const rows=periodRecs().filter(live).filter(r=>!who||r.name===who).flatMap(r=>(r.meals||[]).map(m=>({...m,r}))).sort((a,b)=>(b.date||'').localeCompare(a.date||''));
  F('mlBody').innerHTML=rows.map(x=>`<tr data-id="${esc(x.r.id)}"><td class="num">${dot(x.date)}</td><td>${esc(x.r.name)}</td><td>${esc(x.place)}</td><td class="r num">${won(x.amount)}</td><td class="ell">${esc(x.r.resort)}</td><td class="c">${(x.rc||[]).length?'📎 '+x.rc.length:''}</td></tr>`).join('')
    ||'<tr><td colspan="6" class="empty">등록된 식대 사용 내역이 없습니다.</td></tr>';
  F('mlFoot').innerHTML=rows.length?`<tr><td colspan="3">${rows.length}건</td><td class="r num">${won(rows.reduce((a,x)=>a+parseNum(x.amount),0))}</td><td colspan="2"></td></tr>`:'';
}
F('mealWho').addEventListener('change',renderMealLines);
F('mstatBody').addEventListener('click',e=>{const tr=e.target.closest('tr[data-n]');if(!tr)return;F('mealWho').value=tr.dataset.n;renderMealLines()});
F('mlBody').addEventListener('click',e=>{const tr=e.target.closest('tr[data-id]');if(tr)openRec(tr.dataset.id,'meal')});
F('btnDoc2').addEventListener('click',()=>F('btnDoc').click());
function renderAccts(){
  if(!isAdmin())return;
  const ok=periodRecs().filter(r=>r.status==='확정');
  const acc=S.settings.accounts||[];
  F('accts').innerHTML=acc.length?acc.map(a=>{const rs=ok.filter(r=>r.resvId===a.id);const used=rs.reduce((x,r)=>x+roomNights(r),0);const lim=+a.nights||0;
    return `<div class="row"><span><b>${esc(a.id)}</b><small>회원권 ${esc(a.no||'-')}</small></span><div class="meter" style="height:10px"><i style="width:${lim?Math.min(100,used/lim*100):0}%;background:var(--night)"></i></div><span class="num">${used} / ${lim}박 <small>잔여 ${lim-used}박 · ${rs.length}건</small></span></div>`}).join('')
    +(()=>{const none=ok.filter(r=>!r.resvId).length;return none?`<div class="hint">예약 아이디가 비어 있는 확정 예약 ${none}건</div>`:''})()
    :'<div class="empty">설정에서 회원권을 추가해 주세요.</div>';
}
function filtered(){
  const q=S.q.trim().toLowerCase();
  return periodRecs().filter(r=>{
    if(S.fStatus!=='all'&&(r.status||'신청')!==S.fStatus)return false;
    if(S.fSettle!=='all'&&(r.settle||'미정산')!==S.fSettle)return false;
    if(!q)return true;
    return [r.name,r.user,r.resort,r.wish,r.adminNote,...(r.meals||[]).map(m=>m.place)].join(' ').toLowerCase().includes(q);
  }).sort((a,b)=>(a.ci||'').localeCompare(b.ci||'')||(a.name||'').localeCompare(b.name||''));
}
function renderList(){
  const rs=filtered();
  F('tbody').innerHTML=rs.map((r,i)=>{const rc=(r.docs||[]).length+(r.meals||[]).reduce((a,m)=>a+(m.rc||[]).length,0);
    return `<tr data-id="${esc(r.id)}"><td>${i+1}</td><td>${badge(r.status||'신청')}</td><td>${esc(r.name)}</td><td class="ell" title="${esc(r.resort)}">${esc(r.resort)}</td>
    <td class="num">${dot(r.ci)} ~ ${dot(r.co)}</td><td class="c num">${r.rooms||1}/${r.nights}</td><td class="r num">${roomNights(r)}</td>
    <td class="r num">${won(r.roomFee)}</td><td class="r num">${r.addFee?won(r.addFee):''}</td><td class="r num"><b>${won(stayCost(r))}</b></td>
    <td class="ell">${esc([r.user?'이용자: '+r.user:'',r.reason,r.adminNote].filter(Boolean).join(' · '))}</td>
    <td class="r num">${mealCost(r)?won(mealCost(r)):''}${(r.meals||[]).length?` <small style="color:var(--sub)">(${r.meals.length})</small>`:''}</td><td class="r num">${otherCost(r)?won(otherCost(r)):''}</td><td class="c">${rc?'📎 '+rc:''}</td>
    <td>${esc(r.resvId||'')}</td><td>${esc(cardShort(r.card))}</td>
    <td>${r.cardOut?dot(r.cardOut).slice(5)+' 전달':''}</td><td>${r.cardIn?'<span class="badge b-ok">반납</span>':r.cardOut?'<span class="badge b-wait">미반납</span>':''}</td>
    <td>${r.status==='확정'?`<span class="badge ${r.settle==='정산완료'?'b-sea':'b-none'}">${r.settle||'미정산'}</span>`:''}</td></tr>`}).join('')
    ||`<tr><td colspan="19" class="empty">${S.q||S.fStatus!=='all'?'조건에 맞는 예약이 없습니다.':'이 이용기간에 등록된 예약이 없습니다.'}</td></tr>`;
  const ok=rs.filter(r=>r.status==='확정');
  F('tfoot').innerHTML=rs.length?`<tr><td colspan="6">확정 ${ok.length}건 합계</td><td class="r num">${ok.reduce((a,r)=>a+roomNights(r),0)}</td><td class="r num">${won(ok.reduce((a,r)=>a+parseNum(r.roomFee),0))}</td><td class="r num">${won(ok.reduce((a,r)=>a+parseNum(r.addFee),0))}</td><td class="r num">${won(ok.reduce((a,r)=>a+stayCost(r),0))}</td><td></td><td class="r num">${won(rs.filter(live).reduce((a,r)=>a+mealCost(r),0))}</td><td class="r num">${won(rs.filter(live).reduce((a,r)=>a+otherCost(r),0))}</td><td colspan="6"></td></tr>`:'';
  F('listMeta').textContent=`${rs.length}건`;
  highlight();
}
F('tbody').addEventListener('click',e=>{const tr=e.target.closest('tr[data-id]');if(tr)openRec(tr.dataset.id)});
F('q').addEventListener('input',()=>{S.q=F('q').value;renderList()});
F('fStatus').addEventListener('change',()=>{S.fStatus=F('fStatus').value;renderList()});
F('fSettle').addEventListener('change',()=>{S.fSettle=F('fSettle').value;renderList()});

/* ---------- 통합내역 ---------- */
function mergedData(){
  const p=S.period;let st=staffOf(p);
  if(!isAdmin())st=st.filter(s=>s.name===S.me);
  const all=S.recs.filter(r=>recPeriod(r)===p);
  return st.map((s,i)=>{
    const rs=all.filter(r=>r.name===s.name&&r.status==='확정').sort((a,b)=>a.ci.localeCompare(b.ci));
    const meals=all.filter(r=>r.name===s.name&&live(r)).flatMap(r=>(r.meals||[]).map(m=>({...m,resort:r.resort,rid:r.id}))).sort((a,b)=>(a.date||'').localeCompare(b.date||''));
    return {no:i+1,s,rs,meals,stay:rs.reduce((a,r)=>a+stayCost(r),0),nights:rs.reduce((a,r)=>a+roomNights(r),0),meal:meals.reduce((a,m)=>a+parseNum(m.amount),0)};
  });
}
function stayRows(r){
  const rows=[{place:r.resort,date:`${dot(r.ci)}~${dot(r.co)}`,rn:`${r.rooms||1}/${r.nights}`,fee:parseNum(r.roomFee),note:r.user?'이용자: '+r.user:'',pay:[r.resvId,r.card?cardShort(r.card):''].filter(Boolean).join(' / '),id:r.id}];
  if(parseNum(r.addFee))rows.push({place:`숙박료 추가결제${r.payDate?`(${dot(r.payDate)})`:''}`,date:'',rn:'',fee:parseNum(r.addFee),note:'',pay:r.card?cardShort(r.card)+' 결제':'',id:r.id});
  return rows;
}
function renderSheet(){
  const p=S.period,data=mergedData();
  const head1=`<thead><tr><th>No.</th><th>성명</th><th>제공<br>숙박일수</th><th>사용<br>숙박일수</th><th>투숙 지역명</th><th>체크인 / 체크아웃</th><th>객실수/박수</th><th>객실요금</th><th>비고</th><th>잔여한도</th><th>예약아이디 / 결제카드</th></tr></thead>`;
  let b1='',tN=0,tU=0,tF=0;
  data.forEach(d=>{
    const rows=d.rs.flatMap(stayRows),span=Math.max(1,rows.length)+1;
    const nm=`<td class="c" rowspan="${span}">${d.no}</td><td class="nm" rowspan="${span}">${esc(d.s.name)}</td><td class="c num" rowspan="${span}">${d.s.company?'':d.s.nights}</td><td class="c num" rowspan="${span}">${d.nights||''}</td>`;
    if(!rows.length)b1+=`<tr>${nm}<td colspan="7"></td></tr>`;
    rows.forEach((x,i)=>{b1+=`<tr data-id="${esc(x.id)}">${i===0?nm:''}<td>${esc(x.place)}</td><td class="num">${esc(x.date)}</td><td class="c num">${esc(x.rn)}</td><td class="r num">${won(x.fee)}</td><td>${esc(x.note)}</td><td></td><td>${esc(x.pay)}</td></tr>`});
    const rem=d.s.company?'':d.s.stay-d.stay;
    b1+=`<tr class="sub"><td colspan="3" style="text-align:right">소계</td><td class="r num">${won(d.stay)}</td><td></td><td class="r num rem${rem<0?' neg':''}">${rem===''?'':won(rem)}</td><td></td></tr>`;
    if(!d.s.company){tN+=+d.s.nights||0}tU+=d.nights;tF+=d.stay;
  });
  b1+=`<tr class="tot"><td colspan="2">합계</td><td class="c num">${tN}</td><td class="c num">${tU}</td><td colspan="3"></td><td class="r num">${won(tF)}</td><td colspan="3"></td></tr>`;
  const head2=`<thead><tr><th>No.</th><th>성명</th><th>사용일</th><th>사용처</th><th>사용금액</th><th>방문리조트</th><th>비고</th><th>식대사용 잔여한도</th></tr></thead>`;
  let b2='',tM=0;
  data.filter(d=>!d.s.company||d.meals.length).forEach(d=>{
    const span=Math.max(1,d.meals.length)+1;
    const nm=`<td class="c" rowspan="${span}">${d.no}</td><td class="nm" rowspan="${span}">${esc(d.s.name)}</td>`;
    if(!d.meals.length)b2+=`<tr>${nm}<td colspan="6"></td></tr>`;
    d.meals.forEach((m,i)=>{b2+=`<tr data-id="${esc(m.rid)}">${i===0?nm:''}<td class="num">${dot(m.date)}</td><td>${esc(m.place)}</td><td class="r num">${won(m.amount)}</td><td>${esc(m.resort)}</td><td>${(m.rc||[]).length?'영수증 '+m.rc.length:''}</td><td></td></tr>`});
    const rem=d.s.company?'':d.s.meal-d.meal;
    b2+=`<tr class="sub"><td colspan="2" style="text-align:right">소계</td><td class="r num">${won(d.meal)}</td><td colspan="2"></td><td class="r num rem${rem<0?' neg':''}">${rem===''?'':won(rem)}</td></tr>`;
    tM+=d.meal;
  });
  b2+=`<tr class="tot"><td colspan="4">합계</td><td class="r num">${won(tM)}</td><td colspan="3"></td></tr>`;
  F('sheet').innerHTML=`<h3>${p}년 리조트 이용내역</h3><div class="cap">${p}년 블루소프트 직원 리조트 이용내역 (${dot(pStart(p))}~${dot(pEnd(p))}) · 확정 예약만 반영 · 숙박 한도 ${won(DEF_LIM.stay)}원 기준(직원별 설정 적용)</div>
    <div class="tablewrap"><table>${head1}<tbody>${b1}</tbody></table></div>
    <h3>${p}년 리조트 식대</h3><div class="cap">${p}년 블루소프트 직원 리조트 식대 사용내역 · 반려 · 취소 예약 제외</div>
    <div class="tablewrap"><table>${head2}<tbody>${b2}</tbody></table></div>`;
}
F('sheet').addEventListener('click',e=>{const tr=e.target.closest('tr[data-id]');if(tr)openRec(tr.dataset.id)});

/* ---------- 엑셀 ---------- */
async function saveFile(filename,arr){
  const blob=new Blob([arr],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
  const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=filename;document.body.appendChild(a);a.click();a.remove();
  setTimeout(()=>URL.revokeObjectURL(url),4000);toast('엑셀 파일을 내려받았습니다');
}
function exportMerged(){
  if(typeof XLSX==='undefined'){toast('엑셀 도구를 불러오지 못했습니다. 새로고침해 주세요');return}
  const p=S.period,data=mergedData();
  const a1=[[`${p}년 블루소프트 직원 리조트 이용내역(${dot(pStart(p))}~${dot(pEnd(p))})`],
    ['No.','성명','제공 숙박일수','사용 숙박일수','투숙 지역명','체크인/체크아웃','객실수/박수','객실요금','비고','잔여한도','예약아이디/결제카드']];
  let tN=0,tU=0,tF=0;
  data.forEach(d=>{
    const rows=d.rs.flatMap(stayRows);
    if(!rows.length)a1.push([d.no,d.s.name,d.s.company?'':+d.s.nights,'','','','','','','','']);
    rows.forEach((x,i)=>a1.push([i?'':d.no,i?'':d.s.name,i||d.s.company?'':+d.s.nights,i?'':(d.nights||''),x.place,x.date,x.rn,x.fee,x.note,'',x.pay]));
    a1.push(['','','','','','','소계',d.stay,'',d.s.company?'':d.s.stay-d.stay,'']);
    if(!d.s.company)tN+=+d.s.nights||0;tU+=d.nights;tF+=d.stay;
  });
  a1.push(['합계','',tN,tU,'','','',tF,'','','']);
  const a2=[[`${p}년 블루소프트 직원 리조트 식대 사용내역(${dot(pStart(p)).slice(0,7)}~${dot(pEnd(p)).slice(0,7)})`],['No.','성명','사용일','사용처','사용금액','방문리조트','비고','식대사용 잔여한도']];
  let tM=0;
  data.filter(d=>!d.s.company||d.meals.length).forEach(d=>{
    if(!d.meals.length)a2.push([d.no,d.s.name,'','','','','','']);
    d.meals.forEach((m,i)=>a2.push([i?'':d.no,i?'':d.s.name,dot(m.date),m.place,parseNum(m.amount),m.resort,'','']));
    a2.push(['','','','소계',d.meal,'','',d.s.company?'':d.s.meal-d.meal]);tM+=d.meal;
  });
  a2.push(['합계','','','',tM,'','','']);
  const wb=XLSX.utils.book_new();
  const w1=XLSX.utils.aoa_to_sheet(a1);w1['!cols']=[6,10,9,9,40,24,10,12,18,12,18].map(w=>({wch:w}));w1['!merges']=[{s:{r:0,c:0},e:{r:0,c:10}}];
  const w2=XLSX.utils.aoa_to_sheet(a2);w2['!cols']=[6,10,12,26,12,28,16,14].map(w=>({wch:w}));w2['!merges']=[{s:{r:0,c:0},e:{r:0,c:7}}];
  numFmt(w1,7);numFmt(w1,9);numFmt(w2,4);numFmt(w2,7);
  XLSX.utils.book_append_sheet(wb,w1,`${p}년 리조트 이용내역`);XLSX.utils.book_append_sheet(wb,w2,`${p}년 리조트 식대`);
  saveFile(`${p}년_리조트_통합내역_${today().replace(/-/g,'')}.xlsx`,XLSX.write(wb,{bookType:'xlsx',type:'array'}));
}
function numFmt(ws,col){const rg=XLSX.utils.decode_range(ws['!ref']);for(let r=rg.s.r;r<=rg.e.r;r++){const c=ws[XLSX.utils.encode_cell({r,c:col})];if(c&&c.t==='n')c.z='#,##0'}}
F('btnXlsx').addEventListener('click',exportMerged);

/* ---------- 지출확인서 ---------- */
function docData(){
  const r=S.recs.find(x=>x.id===S.editing);if(!r)return null;
  return r;
}
function renderDoc(r){
  const meals=(r.meals||[]);const mealTot=mealCost(r);
  const stay=stayCost(r),tot=stay+parseNum(r.facFee)+parseNum(r.etcFee);
  F('docBody').innerHTML=`<h3>리조트 사용 및 지출확인서</h3>
  <table><tr><th>사용자</th><td>${esc(r.name)}${r.user?` (실이용자 ${esc(r.user)})`:''}</td><th>작성일자</th><td>${dot(today())}</td></tr>
  <tr><th>부서/직위</th><td>${esc(r.dept||'')}</td><th>연락처</th><td>${esc(r.phone||'')}</td></tr></table>
  <div class="st">■ 리조트 사용내역</div>
  <table><tr><th>리조트명</th><td>${esc(r.resort)}</td></tr><tr><th>예약일시</th><td>${dot(r.resvDate)}</td></tr>
  <tr><th>사용일시</th><td>${dot(r.ci)} ~ ${dot(r.co)} (${r.rooms||1}실 ${r.nights}박)</td></tr><tr><th>사용목적</th><td>${esc(r.purpose||'임직원 복리후생')}</td></tr></table>
  <div class="st">■ 지출내역</div>
  <table><tr><th>숙박대금</th><td class="r num">${won(stay)}</td><td>${r.addFee?`객실 ${won(r.roomFee)} + 추가결제 ${won(r.addFee)}`:''}</td></tr>
  <tr><th>부대시설 이용료</th><td class="r num">${won(r.facFee)}</td><td></td></tr><tr><th>기 타</th><td class="r num">${won(r.etcFee)}</td><td></td></tr>
  <tr><th>합계</th><td class="r num"><b>${won(tot)}</b></td><td>식대 ${won(mealTot)}원 별도 (식대사용내역서)</td></tr></table>
  <div class="st">■ 결제정보</div>
  <table><tr><th>결제수단</th><td>${esc(cardLabel(r.card))}</td></tr><tr><th>결제일자</th><td>${dot(r.payDate)}</td></tr></table>
  <div class="st">■ 복리후생 식대 사용 내역</div>
  <table><tr><th style="width:40px">NO.</th><th style="width:auto">사용일자</th><th style="width:auto">사용처</th><th style="width:auto">사용금액</th><th style="width:auto">결제수단</th></tr>
  ${meals.map((m,i)=>`<tr><td class="c">${i+1}</td><td>${dot(m.date)}</td><td>${esc(m.place)}</td><td class="r num">${won(m.amount)}</td><td>${esc(cardLabel(r.mealCard))}</td></tr>`).join('')||'<tr><td colspan="5" class="c">사용 내역 없음</td></tr>'}
  <tr><th colspan="3">총 계</th><td class="r num"><b>${won(mealTot)}</b></td><td></td></tr></table>
  <div class="hint" style="margin-top:10px">상기와 같이 복리후생 목적의 리조트 사용 및 관련비용 지출 내용을 확인합니다.</div>
  <div class="sign"><span>작성자: ${esc(r.name)} (서명)</span><span>확인: 경영지원 ${esc(S.settings.handler||'김지안')} (서명)</span></div>`;
}
F('btnDoc').addEventListener('click',()=>{const r=docData();if(!r){toast('먼저 저장해 주세요');return}renderDoc(r);F('dlgDoc').showModal()});
F('docClose').addEventListener('click',()=>F('dlgDoc').close());
F('docXlsx').addEventListener('click',()=>{
  const r=docData();if(!r||typeof XLSX==='undefined')return;
  const stay=stayCost(r),fac=parseNum(r.facFee),etc=parseNum(r.etcFee);
  const a=[['리조트 사용 및 지출확인서'],[],['사용자',r.name+(r.user?` (실이용자 ${r.user})`:''),'','작성일자',dot(today())],['부서/직위',r.dept||'','','연락처',r.phone||''],[],
    ['■ 리조트 사용내역'],['항목','','내용'],['리조트명','',r.resort],['예약일시','',dot(r.resvDate)],['사용일시','',`${dot(r.ci)} ~ ${dot(r.co)}`],['사용목적','',r.purpose||'임직원 복리후생'],[],
    ['■ 지출내역'],['지출항목','','금액','','비고'],['숙박대금','',stay,'',r.addFee?`추가결제 ${won(r.addFee)} 포함`:''],['부대시설 이용료','',fac],['기 타','',etc],['합계','',stay+fac+etc,'','식대 별도'],[],
    ['■ 결제정보'],['결제수단','',cardLabel(r.card)],['결제일자','',dot(r.payDate)],[],
    ['■ 첨부서류'],[`${(r.docs||[]).length?'■':'□'} 카드영수증`],['□ 예약확인내역'],['□ 사용확인내역'],[`${(r.meals||[]).length?'■':'□'} 기타(식대사용내역서 별도)`],
    ['상기와 같이 복리후생 목적의 리조트 사용 및 관련비용 지출 내용을 확인합니다.'],[],[],['작성자:',r.name,'(서명)','확인: 경영지원',S.settings.handler||'김지안','(서명)']];
  const b=[['복리후생 식대 사용 내역'],[],['NO.','사용일자','사용처','사용금액','결제수단']];
  (r.meals||[]).forEach((m,i)=>b.push([i+1,dot(m.date),m.place,parseNum(m.amount),cardLabel(r.mealCard)]));
  for(let i=(r.meals||[]).length;i<10;i++)b.push([i+1,'','','','']);
  b.push(['총 계','','',mealCost(r),'']);
  const wb=XLSX.utils.book_new();
  const w1=XLSX.utils.aoa_to_sheet(a);w1['!cols']=[16,14,30,14,14,10].map(w=>({wch:w}));w1['!merges']=[{s:{r:0,c:0},e:{r:0,c:5}}];numFmt(w1,2);
  const w2=XLSX.utils.aoa_to_sheet(b);w2['!cols']=[8,14,28,14,24].map(w=>({wch:w}));w2['!merges']=[{s:{r:0,c:0},e:{r:0,c:4}}];numFmt(w2,3);
  XLSX.utils.book_append_sheet(wb,w1,'리조트사용및지출확인서');XLSX.utils.book_append_sheet(wb,w2,'식대사용내역서');
  saveFile(`리조트지출확인서_${r.name}_${r.ci.replace(/-/g,'')}.xlsx`,XLSX.write(wb,{bookType:'xlsx',type:'array'}));
});

/* ---------- 안내문 ---------- */
function renderNoticeSel(){
  const sel=F('nSel'),cur=sel.value;
  const rs=periodRecs().sort((a,b)=>(b.ci||'').localeCompare(a.ci||''));
  sel.innerHTML='<option value="">예약 선택 (없으면 기본 문구)</option>'+rs.map(r=>`<option value="${esc(r.id)}">${esc(r.name)} · ${dot(r.ci).slice(5)} ${esc(r.resort).slice(0,18)} (${r.status||'신청'})</option>`).join('');
  sel.value=cur&&rs.some(r=>r.id===cur)?cur:(S.editing&&rs.some(r=>r.id===S.editing)?S.editing:'');
  renderNotice();
}
function renderNotice(){
  const r=S.recs.find(x=>x.id===F('nSel').value),kind=F('nKind').value,base=F('nBase').value||S.settings.notice||NOTICE_DEFAULT;
  if(!r){F('nOut').value=base;return}
  const L=limitOf(r.name,recPeriod(r)),u=usage(r.name,recPeriod(r));
  const lim=F('nLimit').checked&&!L.company?`\n\n📌 ${recPeriod(r)}년도 잔여 한도\n* 숙박비 ${won(L.stay-u.stay)}원 · 식대 ${won(L.meal-u.meal)}원 · 숙박일수 ${Math.max(0,L.nights-u.nights)}박`:'';
  const when=`${dot(r.ci)}(${wd(r.ci)}) ~ ${dot(r.co)}(${wd(r.co)}), ${r.rooms||1}실 ${r.nights}박`;
  let t='';
  if(kind==='ok'){
    const body=base.replace(/^안녕하세요\.?\s*😊?\s*\n+/,'');
    t=`안녕하세요, ${r.name}님. 😊\n신청하신 리조트 예약이 확정되어 안내드립니다.\n\n📌 예약 내역\n* 리조트: ${r.resort}\n* 일정: ${when}${r.user?`\n* 이용자: ${r.user}`:''}${lim}\n\n${body}`;
  }else if(kind==='return'){
    t=`안녕하세요, ${r.name}님. 😊\n${r.resort} (${dot(r.ci)}~${dot(r.co)}) 이용은 즐거우셨나요?\n\n식대용 법인카드와 영수증 반납 부탁드립니다.\n영수증을 분실하신 경우에는 사용하신 곳과 금액만 말씀해 주세요.${(r.meals||[]).length?`\n\n현재 등록된 식대: ${r.meals.length}건 · ${won(mealCost(r))}원`:''}${lim}\n\n감사합니다.`;
  }else{
    const what=r.status==='취소'?'취소':'반려';
    t=`안녕하세요, ${r.name}님.\n신청하신 리조트 예약 건 안내드립니다.\n\n* 리조트: ${r.resort}\n* 일정: ${when}\n* 결과: ${what}${r.reason?`\n* 사유: ${r.reason}`:''}\n\n다른 일정이나 리조트로 다시 신청해 주시면 확인 후 예약 도와드리겠습니다.${lim}`;
  }
  F('nOut').value=t;
}
['nSel','nKind','nLimit'].forEach(id=>F(id).addEventListener('change',()=>{const r=S.recs.find(x=>x.id===F('nSel').value);if(id==='nSel'&&r)F('nKind').value=r.status==='반려'||r.status==='취소'?'no':(r.co<today()?'return':'ok');renderNotice()}));
F('nBase').addEventListener('input',renderNotice);
async function copy(el){
  try{await navigator.clipboard.writeText(el.value);toast('복사했습니다')}
  catch(e){el.focus();el.select();try{document.execCommand('copy');toast('복사했습니다')}catch(_){toast('글을 선택해 두었습니다. Ctrl+C로 복사해 주세요')}}
}
F('btnCopyP').addEventListener('click',()=>copy(F('nOut')));
F('btnCopyB').addEventListener('click',()=>copy(F('nBase')));
F('btnSaveN').addEventListener('click',async()=>{try{await store.saveSettings({...S.settings,notice:F('nBase').value});toast('안내 문구를 저장했습니다')}catch(e){toast(e.message||'저장하지 못했습니다')}});

/* ---------- 설정 ---------- */
let draft=null;
function renderUsers(){
  const us=S.users||[];
  F('usBody').innerHTML=us.map(u=>`<tr data-email="${esc(u.email)}"><td>${esc(u.name)}</td><td style="font-size:12px;color:var(--sub)">${esc(u.email)}</td>
    <td><select data-k="role" class="btn sm"${u.email===S.uid?' disabled title="본인 권한은 직접 내릴 수 없습니다"':''}><option value="staff"${u.role==='staff'?' selected':''}>직원</option><option value="admin"${u.role==='admin'?' selected':''}>관리자</option></select></td></tr>`).join('')
    ||'<tr><td colspan="3" class="empty">포털 계정이 없습니다.</td></tr>';
}
F('usBody').addEventListener('change',async e=>{
  const sel=e.target.closest('select[data-k=role]');if(!sel)return;
  const email=sel.closest('tr').dataset.email;
  try{const r=await api('role',{email,role:sel.value});S.users=r.users||S.users;toast('권한을 바꿨습니다');renderUsers()}
  catch(err){toast(err.message);renderUsers()}
});

function openSettings(){
  draft=structuredClone(S.settings);const p=S.period;
  if(!draft.staffByPeriod)draft.staffByPeriod={};
  if(!draft.staffByPeriod[p])draft.staffByPeriod[p]=structuredClone(staffOf(p));
  F('setPeriodLab').textContent=`${p}년도 (${dot(pStart(p))} ~ ${dot(pEnd(p))})`;
  F('sHandler').value=draft.handler||'';
  renderSet();renderUsers();F('dlgSet').showModal();
}
function renderSet(){
  const st=draft.staffByPeriod[S.period];
  F('stBody').innerHTML=st.map((s,i)=>`<tr data-i="${i}"><td><input data-k="name" value="${esc(s.name)}"></td><td><input data-k="stay" class="r num" value="${won(s.stay)}"></td><td><input data-k="meal" class="r num" value="${won(s.meal)}"></td><td><input data-k="nights" class="r num" value="${s.nights||0}" style="max-width:70px"></td><td class="c"><input type="checkbox" data-k="company" ${s.company?'checked':''} aria-label="회사 공용"></td><td><button class="xb" data-x="${i}" aria-label="삭제">×</button></td></tr>`).join('');
  F('acBody').innerHTML=(draft.accounts||[]).map((a,i)=>`<tr data-i="${i}"><td><input data-k="id" value="${esc(a.id)}"></td><td><input data-k="no" value="${esc(a.no||'')}"></td><td><input data-k="nights" class="r num" value="${a.nights||0}" style="max-width:80px"></td><td><button class="xb" data-x="${i}" aria-label="삭제">×</button></td></tr>`).join('');
  F('cdBody').innerHTML=(draft.cards||[]).map((c,i)=>`<tr data-i="${i}"><td><input data-k="code" value="${esc(c.code)}" style="max-width:110px"></td><td><input data-k="label" value="${esc(c.label)}"></td><td><button class="xb" data-x="${i}" aria-label="삭제">×</button></td></tr>`).join('');
}
function bindTbl(id,getArr,num){
  F(id).addEventListener('input',e=>{const el=e.target.closest('input[data-k]');if(!el)return;const i=+el.closest('tr').dataset.i,k=el.dataset.k;
    getArr()[i][k]=el.type==='checkbox'?el.checked:num.includes(k)?parseNum(el.value):el.value});
  F(id).addEventListener('change',e=>{const el=e.target.closest('input[type=checkbox]');if(el){const i=+el.closest('tr').dataset.i;getArr()[i][el.dataset.k]=el.checked}});
  F(id).addEventListener('click',e=>{const b=e.target.closest('[data-x]');if(!b)return;getArr().splice(+b.dataset.x,1);renderSet()});
}
bindTbl('stBody',()=>draft.staffByPeriod[S.period],['stay','meal','nights']);
bindTbl('acBody',()=>draft.accounts,['nights']);
bindTbl('cdBody',()=>draft.cards,[]);
F('stAdd').addEventListener('click',()=>{draft.staffByPeriod[S.period].push({name:'',...DEF_LIM});renderSet();const ins=F('stBody').querySelectorAll('input[data-k="name"]');ins[ins.length-1].focus()});
F('acAdd').addEventListener('click',()=>{(draft.accounts=draft.accounts||[]).push({id:'',no:'',nights:30});renderSet()});
F('cdAdd').addEventListener('click',()=>{(draft.cards=draft.cards||[]).push({code:'',label:''});renderSet()});
F('setCancel').addEventListener('click',()=>F('dlgSet').close());
F('setSave').addEventListener('click',async()=>{
  draft.handler=F('sHandler').value.trim()||'김지안';
  draft.staffByPeriod[S.period]=draft.staffByPeriod[S.period].filter(s=>s.name.trim()).map(s=>({...s,name:s.name.trim()}));
  draft.accounts=(draft.accounts||[]).filter(a=>a.id.trim());draft.cards=(draft.cards||[]).filter(c=>c.code.trim());
  try{await store.saveSettings(draft);F('dlgSet').close();toast('설정을 저장했습니다')}catch(e){toast(e.message||'설정을 저장하지 못했습니다')}
});
F('btnSettings').addEventListener('click',openSettings);

/* ---------- 포털 상단바 메뉴 ---------- */
F('hdrChip').addEventListener('click',e=>{e.stopPropagation();F('hdrUserMenu').classList.toggle('open')});
document.addEventListener('click',e=>{if(!F('hdrUserMenu').contains(e.target))F('hdrUserMenu').classList.remove('open')});

/* ---------- 시작 ---------- */
F('nBase').value=NOTICE_DEFAULT;
renderPeriodSel();renderSelects();clearForm();renderAll();
initStore();
})();
