# -*- coding: utf-8 -*-
"""
envlogd.py — Coursemos EnvLog 의 SSH 로그 엔진 (포털 서버에서 상주하는 데몬)

  python envlogd.py                       : 127.0.0.1:8766 에서 대기 (ENVLOG_PORT / --port 로 변경)
  python envlogd.py --local-test <폴더>…  : 실서버 없이 로컬 폴더를 서버로 간주 (개발/검증용)
  python envlogd.py --iworks-db           : [개발 PC 용] 대학 목록을 iWorks 서버(배스천) SSH 터널로 slack_db 에서
                                            직접 읽는다. 로컬 포털 DB 에 대학 접속 정보가 없을 때 쓴다 (아래 참고)

구조
  브라우저 ──▶ envlog/api.php(PHP, 포털 로그인 확인·대학 DB 조회) ──HTTP(127.0.0.1)──▶ 이 데몬
      PHP 가 config.php 로 slack_db 에 직접 붙어 schools + school_access(test_srv 복호화)를 읽고,
      접속할 때만 테스트서버 접속 정보를 이 데몬에 넘긴다. 이 데몬은 DB 를 전혀 모른다.
      데몬: 점프(테스트)서버 SSH → 운영서버 N대 (asyncssh 터널, ssh_jump.py)
            운영서버 목록은 테스트서버의 WebRoot/*/checkWeb.sh 에서 읽음 (php2shell 과 동일)
  원격 서버에서는 envlog_remote.sh(bash/awk/grep) 만 실행 → 바이트 구간 읽기/시간 이진탐색/grep 으로
  수 GB 로그도 전체를 읽지 않는다.

  세션은 포털 사용자(X-Envlog-User 헤더, api.php 가 붙임)마다 하나씩이다. 두 사람이 다른 대학을
  동시에 볼 수 있고, 30분 동안 아무 요청이 없으면 그 사람의 SSH 연결은 자동으로 닫힌다.
  이 데몬은 127.0.0.1 에만 묶는다 — 인증은 api.php(포털 세션)가 한다.

개발 PC 모드 (--iworks-db)
  운영에서는 PHP 가 같은 서버의 DB 를 읽지만, 개발 PC 의 포털 DB(SQLite)에는 대학 접속 정보가 없다.
  이 옵션을 주면 데몬이 iWorks 서버에 SSH 로 붙어 MySQL 을 로컬 포트로 포워딩해 schools + school_access
  를 읽고, test_srv 복호화 키도 그 서버의 config.local.php / config.php 에서 읽는다(로컬에 아무것도 안 남김).
  SSH 비밀번호(키 파일이 없을 때)와 DB 비밀번호는 실행할 때 콘솔에서 묻는다(환경변수로도 줄 수 있음).
  api.php 는 로컬 시험(SQLite) 포털이면 자동으로 ?a=schools 와 접속(school_id)을 데몬에 넘긴다.
    설정: 환경변수 또는 인자 — ENVLOG_BASTION / --bastion  amitoa@35.184.155.46[:22]
          ENVLOG_BASTION_KEY / --key  …/gcp_key.ppk     ENVLOG_BASTION_PASSWORD (키 대신)
          ENVLOG_REMOTE_ROOT / --remote-root  /home/blueapp_core (서버의 포털 루트)
          ENVLOG_DB / --db  slack_db    ENVLOG_DB_USER / --db-user  root    ENVLOG_DB_PASSWORD
"""
import asyncio, base64, json, os, posixpath, re, shlex, sys, threading, time

from flask import Flask, jsonify, request

import ssh_jump as J

for _s in (sys.stdout, sys.stderr):      # 로그 파일/콘솔 인코딩이 무엇이든 한글 출력이 죽지 않게
    try: _s.reconfigure(encoding='utf-8', errors='replace')
    except Exception: pass

HERE = os.path.dirname(os.path.abspath(__file__))
REMOTE_SH = open(os.path.join(HERE, 'envlog_remote.sh'), 'r', encoding='utf-8').read().replace('\r\n', '\n')
REMOTE_B64 = base64.b64encode(REMOTE_SH.encode('utf-8')).decode('ascii')
KNOWN_BASES = ['/data/WebRoot', '/home/WebRoot']
DEFAULT_LOG_DIR = os.environ.get('ENVLOG_LOG_DIR', '/usr/local/apache2/logs')
# 볼 수 있는 곳은 로그 폴더뿐 — 기본 로그 폴더 + 아파치 설정에서 찾은 로그 폴더(서버마다) + 아래 추가분. 그 밖은 거부한다
EXTRA_LOG_DIRS = [d for d in os.environ.get('ENVLOG_ALLOWED_DIRS', '').split(':') if d]
IDLE_SECONDS = int(os.environ.get('ENVLOG_IDLE', '1800'))      # 요청 없이 이만큼 지나면 세션을 닫는다
EXIT_IDLE = int(os.environ.get('ENVLOG_EXIT_IDLE', '3600'))   # 아무 요청도 이만큼 없으면 프로세스를 끝낸다 (api.php 가 다시 띄운다). 0 = 안 끝냄
LAST_REQUEST = time.time()
LOCAL_FOLDERS = []                                              # --local-test
MANUAL = False                                                  # 손으로 띄운 모드(--local-test/--iworks-db)면 유휴 종료 안 함

app = Flask(__name__)


# ---------------------------------------------------------------------------
# 전용 asyncio 루프 (작업 스레드) — Flask 핸들러는 call() 로 코루틴을 넘긴다
# ---------------------------------------------------------------------------
LOOP = asyncio.new_event_loop()
threading.Thread(target=LOOP.run_forever, daemon=True).start()


def call(coro, timeout=60):
    return asyncio.run_coroutine_threadsafe(coro, LOOP).result(timeout)


# ---------------------------------------------------------------------------
# 서버(테스트/운영) — 원격 보조 스크립트 실행
# ---------------------------------------------------------------------------
def parse_checkweb_sh(content):
    """checkWeb.sh(expect) 에서 운영서버 접속 정보 추출 (php2shell 과 동일 규칙)"""
    servers, current = [], None
    for line in content.splitlines():
        s = line.strip()
        if s.startswith('spawn'):
            if current and current.get('host'):
                servers.append(current)
            current = None
            pm = re.search(r'ssh\s+-p\s+(\d+)', s)
            port = int(pm.group(1)) if pm else 22
            um = re.search(r'([\w][\w.-]*)@([\d.]+):', s)
            if um:
                current = {'user': um.group(1), 'host': um.group(2), 'port': port, 'password': ''}
        elif s.startswith('send') and current:
            pm = re.search(r'send\s+"([^"]*)"', s)
            if pm:
                current['password'] = re.sub(r'(\\[rn])+$', '', pm.group(1))
    if current and current.get('host'):
        servers.append(current)
    seen, uniq = set(), []
    for srv in servers:
        k = (srv['host'], srv['port'])
        if k not in seen:
            seen.add(k); uniq.append(srv)
    return uniq


class RemoteError(Exception):
    pass


class Server:
    def __init__(self, sid, name, kind, host, port, user, password, jump=None):
        self.id, self.name, self.kind = sid, name, kind
        self.host, self.port, self.user, self.password = host, port, user, password
        self.jump = jump            # 운영서버: 점프 연결 (None 이면 직접 연결된 테스트서버)
        self.conn = None
        self.path = 'direct' if jump is None else ''
        self.status = ''
        self.use_sudo = False
        self.allowed = None         # 허용 로그 폴더(절대경로) — 처음 쓸 때 logdirs 로 채운다

    def info(self):
        return {'id': self.id, 'name': self.name, 'kind': self.kind, 'host': self.host, 'port': self.port,
                'user': self.user, 'path': self.path, 'status': self.status}

    async def open(self):
        """운영서버: 점프 연결 위로 영구 터널 연결. 실패하면 run_downstream(expect 폴백)으로 명령마다 접속."""
        if self.jump is None:
            return
        try:
            self.conn = await J.connect(self.host, self.port, self.user, password=self.password, timeout=12, tunnel=self.jump)
            self.path = 'tunnel'; self.status = 'ok'
        except Exception as e:
            self.conn = None; self.path = 'fallback'; self.status = f'터널 실패, 폴백 사용: {e}'

    def close(self):
        if self.conn is not None and self.jump is not None:
            try: self.conn.close()
            except Exception: pass
        self.conn = None

    def _script(self, args, sudo):
        q = ' '.join(shlex.quote(str(a)) for a in args)
        runner = (f"echo {shlex.quote(self.password)} | sudo -S -p '' bash" if sudo else 'bash')
        return (f'T=$(mktemp /tmp/.envlog.XXXXXX) && echo {REMOTE_B64} | base64 -d > "$T" && '
                f'{runner} "$T" {q}; rc=$?; rm -f "$T"; exit $rc')

    async def _exec_once(self, args, sudo, timeout):
        cmd = self._script(args, sudo)
        if self.conn is not None:
            r = await asyncio.wait_for(self.conn.run(cmd, check=False, encoding=None), timeout)
            out = r.stdout.decode('utf-8', 'replace') if isinstance(r.stdout, (bytes, bytearray)) else (r.stdout or '')
            err = r.stderr.decode('utf-8', 'replace') if isinstance(r.stderr, (bytes, bytearray)) else (r.stderr or '')
            return r.exit_status or 0, out, err
        # 폴백: 점프서버의 expect/ssh 로 명령마다 접속 (stdout 만, 종료코드 없음)
        out = await J.run_downstream(self.jump, self.host, self.user, cmd, port=self.port,
                                     password=self.password, timeout=timeout)
        m = re.search(r'^#err .*$', out, re.M)
        if m:
            return (13 if '권한' in m.group(0) else 2), out, m.group(0)
        return 0, out, ''

    async def allowed_dirs(self):
        """이 서버에서 볼 수 있는 폴더: 기본 로그 폴더 + 아파치 설정의 로그 폴더 + ENVLOG_ALLOWED_DIRS"""
        if self.allowed is None:
            dirs = {posixpath.normpath(DEFAULT_LOG_DIR)} | {posixpath.normpath(d) for d in EXTRA_LOG_DIRS}
            try:
                for ln in (await self.exec(['logdirs', ''], 30)).splitlines():
                    p = ln.split('\t')
                    if len(p) == 3 and p[2].startswith('/'):
                        dirs.add(posixpath.normpath(p[2].rsplit('/', 1)[0]))
            except Exception:
                pass
            self.allowed = sorted(dirs)
        return self.allowed

    async def check_path(self, path):
        """path(폴더 또는 파일)가 허용 폴더 안인지. 아니면 RemoteError"""
        if self.path == 'local':
            return path
        n = '/' + posixpath.normpath('/' + str(path).strip()).lstrip('/')     # '..' 정리 + 앞의 '//' 제거
        for d in await self.allowed_dirs():
            if n == d or n.startswith(d.rstrip('/') + '/'):
                return n
        raise RemoteError(f'[{self.name}] 로그 폴더 밖은 볼 수 없습니다: {n} — 허용: ' + ', '.join(self.allowed))

    async def exec(self, args, timeout=60):
        """원격 스크립트 실행 → stdout. 실패하면 그 서버 비밀번호로 sudo 재시도(성공하면 이후 계속 sudo).
        아파치 로그 디렉터리는 root 전용인 경우가 많아 일반 계정으로는 '없음'처럼 보일 수 있다."""
        rc, out, err = await self._exec_once(args, self.use_sudo, timeout)
        if rc != 0 and rc != 65 and not self.use_sudo and self.password:     # 65 = 텍스트 파일 아님(권한 문제 아님)
            rc2, out2, err2 = await self._exec_once(args, True, timeout)
            if rc2 == 0:
                self.use_sudo = True
                return out2
            # sudo 도 실패: 둘 중 더 설명이 되는 쪽을 보여준다
            if '#err' in err2 or '#err' in out2:
                rc, out, err = rc2, out2, err2
            else:
                err = (err or out).strip() + '\n(sudo 재시도도 실패: ' + (err2 or out2).strip()[:200] + ')'
        if rc != 0:
            msg = err.strip() or out.strip() or f'원격 명령 실패 (rc={rc})'
            msg = re.sub(r'^\[sudo\][^\n]*\n?', '', msg, flags=re.M).replace('#err ', '')
            raise RemoteError(f'[{self.name} {self.host}] ' + msg[:500])
        return out


def _git_bash():
    """Windows 에서 WSL 의 System32\\bash.exe 가 아닌 Git Bash 를 고른다 (리눅스/맥은 그냥 bash)."""
    import shutil
    if os.name != 'nt':
        return 'bash'
    cands = [shutil.which('bash')] + [os.path.join(d, 'Git', sub, 'bash.exe')
                                       for d in (os.environ.get('ProgramFiles', r'C:\Program Files'), r'C:\Program Files', r'D:\Program Files')
                                       for sub in ('usr\\bin', 'bin')]
    for c in cands:
        if c and os.path.isfile(c) and 'system32' not in c.lower():
            return c
    return 'bash'


class LocalServer(Server):
    """--local-test: 로컬 폴더를 서버로 간주, 보조 스크립트를 로컬 bash 로 실행 (개발/검증용)"""
    def __init__(self, sid, name, folder):
        super().__init__(sid, name, 'ops', folder, 0, os.environ.get('USERNAME', ''), '')
        self.folder = folder; self.path = 'local'; self.status = 'ok'

    async def _exec_once(self, args, sudo, timeout):
        import subprocess
        def posix(p):  # Git Bash 용 경로: G:\dir\x → /g/dir/x
            p = str(p).replace('\\', '/')
            return re.sub(r'^([A-Za-z]):/', lambda m: '/' + m.group(1).lower() + '/', p)
        sh = posix(os.path.join(HERE, 'envlog_remote.sh'))
        bash = _git_bash()
        def run():
            # 경로 인자: 로그 디렉터리/파일 경로를 로컬 폴더로 치환 (어떤 디렉터리를 넣어도 이 폴더를 본다)
            a = [str(x) for x in args]
            if a[0] == 'ls':
                a[1] = self.folder
            elif len(a) > 1:
                a[1] = os.path.join(self.folder, os.path.basename(a[1]))
            a = [posix(x) if i == 1 else x for i, x in enumerate(a)]
            r = subprocess.run([bash, sh] + a, capture_output=True, timeout=timeout)
            return r.returncode, r.stdout.decode('utf-8', 'replace'), r.stderr.decode('utf-8', 'replace')
        return await asyncio.get_running_loop().run_in_executor(None, run)


# ---------------------------------------------------------------------------
# [개발 PC 용] 대학 DB — iWorks 서버 SSH 터널 → MySQL. 운영에서는 쓰지 않는다(PHP 가 읽음)
# ---------------------------------------------------------------------------
IWORKS_QUERY = ("SELECT s.id, s.name, s.ver, s.ops AS site, s.dev, a.vpn, a.test_srv "
                "FROM schools s JOIN school_access a ON a.school_id = s.id "
                "WHERE s.active = 1 AND COALESCE(a.test_srv, '') <> '' ORDER BY s.name, a.sort_no")


def parse_test_srv(text):
    """test_srv 원문 → {host, port, user, password, dir, no_ops} — api.php 의 envlog_parse_test_srv() 와 같은 규칙"""
    lines = [l.strip() for l in text.replace('\r', '').split('\n') if l.strip()]
    body = [l for l in lines if not l.startswith('--')]
    r = {'host': '', 'port': 22, 'user': '', 'password': '', 'dir': '',
         'no_ops': any(re.match(r'^--\s*운영서버', l) for l in lines)}
    if len(body) >= 2:
        m = re.match(r'^([A-Za-z0-9.\-]+)(?:\s*:\s*(\d{1,5}))?$', body[0])
        a = re.match(r'^([^/\s:]+)\s*(?:/|:|\s)\s*(.+)$', body[1])
        if m and a:
            r['host'] = m.group(1); r['port'] = int(m.group(2) or 22)
            r['user'] = a.group(1); r['password'] = a.group(2).strip()
    for l in body[2:]:
        if l.startswith('/') or l.startswith('~'):
            r['dir'] = l; break
    return r


class SchoolDB:
    """배스천(iWorks 서버) SSH → 127.0.0.1:3306 포워딩 → pymysql. 복호화 키는 서버의 config 에서 읽는다."""
    def __init__(self):
        spec = os.environ.get('ENVLOG_BASTION', 'amitoa@35.184.155.46')
        m = re.match(r'^(?:([^@]+)@)?([^:]+)(?::(\d+))?$', spec)
        self.user, self.host, self.port = (m.group(1) or 'amitoa'), m.group(2), int(m.group(3) or 22)
        self.key = os.environ.get('ENVLOG_BASTION_KEY', '')
        self.ssh_pw = os.environ.get('ENVLOG_BASTION_PASSWORD', '')
        self.remote_root = os.environ.get('ENVLOG_REMOTE_ROOT', '/home/blueapp_core').rstrip('/')
        self.db_name = os.environ.get('ENVLOG_DB', 'slack_db')
        self.db_user = os.environ.get('ENVLOG_DB_USER', 'root')
        self.db_pw = os.environ.get('ENVLOG_DB_PASSWORD', '')
        self.local_port = int(os.environ.get('ENVLOG_DB_LOCAL_PORT', '13366'))
        self.bastion = None; self.listener = None; self.enc_key = b''
        self.rows = []; self.loaded_at = 0; self.error = ''
        self.lock = threading.Lock()

    async def _open(self):
        if self.bastion is not None:
            return
        keys = [J.load_key(self.key)] if self.key else None
        self.bastion = await J.connect(self.host, self.port, self.user, password=(None if keys else self.ssh_pw), client_keys=keys)
        self.listener = await self.bastion.forward_local_port('127.0.0.1', self.local_port, '127.0.0.1', 3306)
        # 복호화 키: 서버의 config.local.php → config.php 순서로 'key' => '...' 를 찾는다 (파일은 메모리에서만)
        for name in ('config.local.php', 'config.php'):
            txt = await J.read_remote_file(self.bastion, f'{self.remote_root}/{name}')
            m = re.search(r"""['"]key['"]\s*=>\s*['"]([A-Za-z0-9+/=]{20,})['"]""", txt)
            if m:
                try: self.enc_key = base64.b64decode(m.group(1), validate=True)
                except Exception: self.enc_key = m.group(1).encode()
                break
        if len(self.enc_key) not in (16, 24, 32):
            raise RuntimeError(f"iWorks 서버 {self.remote_root}/config.local.php 에서 복호화 키('key')를 읽지 못했습니다")

    def _dec(self, v):
        """access/db.php 의 access_dec() 와 동일: 'enc:v1:' + base64(iv12 + tag16 + ciphertext), AES-256-GCM"""
        v = '' if v is None else str(v)
        if not v.startswith('enc:v1:'):
            return v
        from cryptography.hazmat.primitives.ciphers.aead import AESGCM
        raw = base64.b64decode(v[7:])
        if len(raw) < 29:
            return ''
        try:
            return AESGCM(self.enc_key).decrypt(raw[:12], raw[28:] + raw[12:28], None).decode('utf-8', 'replace')
        except Exception:
            return ''

    def load(self, force=False):
        with self.lock:
            if not force and self.rows and time.time() - self.loaded_at < 600:
                return self.rows
            try:
                import pymysql
                call(self._open(), 40)
                cn = pymysql.connect(host='127.0.0.1', port=self.local_port, user=self.db_user, password=self.db_pw,
                                     database=self.db_name, charset='utf8mb4', connect_timeout=15)
                try:
                    with cn.cursor(pymysql.cursors.DictCursor) as cur:
                        cur.execute(IWORKS_QUERY)
                        rows = []
                        for d in cur.fetchall():
                            t = parse_test_srv(self._dec(d.get('test_srv')))
                            vpn = str(d.get('vpn') or '').strip()
                            reason = ('테스트 서버 정보를 읽지 못함 (EnvHub 의 형식: IP[:포트] / 아이디 / 비밀번호)' if not t['host']
                                      else f'VPN({vpn}) 이 필요한 대학 — 포털 서버에서 접속할 수 없습니다' if vpn else '')
                            rows.append({'id': int(d['id']), 'name': str(d.get('name') or ''), 'ver': str(d.get('ver') or ''),
                                         'site': str(d.get('site') or d.get('dev') or ''), 'vpn': vpn,
                                         'ok': reason == '', 'reason': reason, **t})
                finally:
                    cn.close()
                self.rows = rows; self.loaded_at = time.time(); self.error = ''
            except Exception as e:
                self.error = f'iWorks DB 조회 실패: {e}'
                self.rows = []
            return self.rows


SCHOOLS = None   # --iworks-db 일 때만 SchoolDB()


class Session:
    """포털 사용자 한 명이 접속한 대학 하나: 점프(테스트)서버 + 운영서버 N대"""
    def __init__(self, uid):
        self.uid = uid
        self.jump = None
        self.servers = []
        self.school = None
        self.lock = threading.Lock()
        self.touched = time.time()

    def touch(self):
        self.touched = time.time()

    async def _connect(self, school):
        self._close()
        self.school = {k: v for k, v in school.items() if k != 'password'}
        jump = await J.connect(school['host'], school['port'], school['user'], password=school['password'], timeout=15)
        self.jump = jump
        test = Server(0, '테스트서버', 'test', school['host'], school['port'], school['user'], school['password'])
        test.conn = jump; test.status = 'ok'
        servers = [test]
        # WebRoot 판별 → checkWeb.sh → 운영서버
        base = (await J.run(jump, 'for d in ' + ' '.join(KNOWN_BASES) + '; do [ -d "$d" ] && { echo "$d"; break; }; done')).strip()
        found = []
        if base and not school.get('no_ops'):
            out = await J.run(jump, f"find {shlex.quote(base)} -maxdepth 2 -name 'checkWeb*.sh' -type f 2>/dev/null")
            paths = out.split()
            # 다중 사이트 테스트서버: test_srv 의 사이트 폴더(dir)와 맞는 checkWeb 만 쓴다
            d = (school.get('dir') or '').rstrip('/')
            if d:
                mine = [p for p in paths if p.startswith(d + '/') or ('/' + d.strip('/').split('/')[-1] + '/') in p]
                if mine:
                    paths = mine
            self.school['sites'] = sorted({p.rsplit('/', 2)[-2] for p in paths})
            for p in paths:
                content = await J.read_remote_file(jump, p, sudo_pw=school['password'])
                for srv in parse_checkweb_sh(content):
                    if (srv['host'], srv['port']) not in [(f['host'], f['port']) for f in found]:
                        found.append(srv)
        for i, srv in enumerate(found, 1):
            s = Server(i, f'운영서버 {i}', 'ops', srv['host'], srv['port'], srv['user'], srv['password'], jump=jump)
            servers.append(s)
        await asyncio.gather(*(s.open() for s in servers if s.kind == 'ops'))
        self.servers = servers
        return self.state(webroot=base)

    def state(self, webroot=None):
        d = {'connected': self.school is not None, 'school': self.school, 'servers': [s.info() for s in self.servers]}
        if webroot is not None:
            d['webroot'] = webroot
        return d

    def connect(self, school):
        with self.lock:
            if LOCAL_FOLDERS:
                return self.connect_local(LOCAL_FOLDERS)
            return call(self._connect(school), 120)

    def connect_local(self, folders):
        self._close()
        self.school = {'id': -2, 'name': '로컬 테스트', 'site': '', 'host': 'localhost', 'port': 0, 'user': ''}
        self.servers = [LocalServer(i, f'로컬 {i}', f) for i, f in enumerate(folders, 1)]
        return self.state(webroot='')

    def _close(self):
        for s in self.servers:
            s.close()
        if self.jump is not None:
            try: self.jump.close()
            except Exception: pass
        self.jump = None; self.servers = []; self.school = None

    def close(self):
        with self.lock:
            self._close()

    def server(self, sid):
        for s in self.servers:
            if s.id == sid:
                return s
        raise RemoteError(f'서버 {sid} 없음 (접속이 끊겼거나 30분 동안 쓰지 않아 닫혔을 수 있습니다 — 다시 접속하세요)')


SESSIONS = {}
SESSIONS_LOCK = threading.Lock()


def session_for(uid, create=True):
    with SESSIONS_LOCK:
        s = SESSIONS.get(uid)
        if s is None and create:
            s = SESSIONS[uid] = Session(uid)
        if s is not None:
            s.touch()
        return s


def reaper():
    """IDLE_SECONDS 동안 요청이 없던 세션의 SSH 연결을 닫는다 (운영서버에 세션을 오래 열어 두지 않도록).
    EXIT_IDLE 동안 아무 요청도 없으면 프로세스 자체를 끝낸다 — 상주 서비스로 등록하지 않아도 되게.
    (--iworks-db / --local-test 처럼 손으로 띄운 경우는 끝내지 않는다)"""
    while True:
        time.sleep(60)
        now = time.time()
        if EXIT_IDLE and not MANUAL and now - LAST_REQUEST > EXIT_IDLE:
            print(f'[envlogd] {EXIT_IDLE}초 동안 요청이 없어 종료합니다 (필요하면 api.php 가 다시 띄움)', flush=True)
            for s in list(SESSIONS.values()):
                try: s.close()
                except Exception: pass
            os._exit(0)
        with SESSIONS_LOCK:
            idle = [s for s in SESSIONS.values() if s.school is not None and now - s.touched > IDLE_SECONDS]
        for s in idle:
            try:
                s.close()
                print(f'[envlogd] 사용자 {s.uid} 세션을 닫음 (유휴 {IDLE_SECONDS}초)')
            except Exception:
                pass


threading.Thread(target=reaper, daemon=True).start()


# ---------------------------------------------------------------------------
# API — 모든 요청은 X-Envlog-User(포털 사용자 id) 헤더가 있어야 한다. api.php 만 붙인다.
# ---------------------------------------------------------------------------
def api_error(e, code=500):
    return jsonify({'error': str(e)}), code


@app.before_request
def _need_user():
    global LAST_REQUEST
    LAST_REQUEST = time.time()
    if not request.headers.get('X-Envlog-User', '').strip():
        return api_error('X-Envlog-User 헤더가 없습니다 — 이 데몬은 envlog/api.php 를 통해서만 씁니다', 400)


def _uid():
    return request.headers['X-Envlog-User'].strip()


@app.route('/state')
def api_state():
    s = session_for(_uid(), create=False)
    d = s.state() if s else {'connected': False, 'school': None, 'servers': []}
    d['log_dir'] = DEFAULT_LOG_DIR
    d['local_test'] = bool(LOCAL_FOLDERS)
    d['schools_from_daemon'] = SCHOOLS is not None
    d['schools_error'] = SCHOOLS.error if SCHOOLS is not None else ''
    return jsonify(d)


@app.route('/schools')
def api_schools():
    """[개발 PC 용 --iworks-db] 대학 자동완성. 운영에서는 api.php 가 DB 에서 직접 만든다"""
    if SCHOOLS is None:
        return api_error('이 데몬은 --iworks-db 로 뜨지 않았습니다 — 대학 목록은 api.php 가 DB 에서 읽습니다', 400)
    q = (request.args.get('q') or '').strip().lower()
    rows = SCHOOLS.load(force=request.args.get('reload') == '1')
    if SCHOOLS.error and not rows:
        return jsonify({'error': SCHOOLS.error, 'items': [], 'total': 0})
    items = [r for r in rows if not q or q in r['name'].lower() or q in r['site'].lower() or q in r['host']]
    return jsonify({'items': [{k: v for k, v in r.items() if k != 'password'} for r in items[:30]], 'total': len(rows)})


@app.route('/connect', methods=['POST'])
def api_connect():
    """api.php 가 DB 에서 복호화한 테스트서버 접속 정보(또는 직접 입력값)를 넘긴다.
    --iworks-db 모드에서는 {school_id} 만 와도 데몬이 직접 찾는다."""
    d = request.get_json(force=True) or {}
    try:
        if 'school_id' in d and SCHOOLS is not None:
            d = next((r for r in SCHOOLS.load() if r['id'] == int(d['school_id'])), None)
            if not d:
                return api_error('대학을 찾지 못했습니다' + (f' ({SCHOOLS.error})' if SCHOOLS.error else ''), 404)
            if not d.get('ok', True):
                return api_error(f"{d['name']}: {d['reason']}", 400)
        host = str(d.get('host') or '').strip()
        if not host and not LOCAL_FOLDERS:
            return api_error('서버 주소가 없습니다', 400)
        school = {'id': int(d.get('id') or -1), 'name': str(d.get('name') or host), 'ver': str(d.get('ver') or ''),
                  'site': str(d.get('site') or ''), 'vpn': str(d.get('vpn') or ''),
                  'host': host, 'port': int(d.get('port') or 22), 'user': str(d.get('user') or 'ubion'),
                  'password': str(d.get('password') or ''), 'dir': str(d.get('dir') or ''), 'no_ops': bool(d.get('no_ops'))}
        return jsonify(session_for(_uid()).connect(school))
    except Exception as e:
        return api_error(f'접속 실패: {e}')


@app.route('/shutdown', methods=['POST'])
def api_shutdown():
    """자동 기동된 보통 모드 데몬을 끝낸다 — 개발 PC 에서 --iworks-db 데몬이 같은 포트를 쓰려고 할 때.
    127.0.0.1 에서만 닿는 데몬이므로 별도 인증은 없다. api.php 는 이 경로를 중계하지 않는다."""
    for s in list(SESSIONS.values()):
        try: s.close()
        except Exception: pass
    threading.Timer(0.3, lambda: os._exit(0)).start()
    return jsonify({'ok': True, 'bye': True})


@app.route('/disconnect', methods=['POST'])
def api_disconnect():
    s = session_for(_uid(), create=False)
    if s:
        s.close()
    return jsonify({'ok': True})


def _srv():
    s = session_for(_uid(), create=False)
    if s is None or s.school is None:
        raise RemoteError('접속되어 있지 않습니다 — 대학을 선택해 접속하세요')
    return s.server(int(request.args.get('server', '0')))


@app.route('/files')
def api_files():
    path = request.args.get('path') or DEFAULT_LOG_DIR
    try:
        srv = _srv(); path = call(srv.check_path(path), 40)
        out = call(srv.exec(['ls', path], 30), 40)
        files = []
        for ln in out.splitlines():
            p = ln.split('\t')
            if len(p) == 4:
                files.append({'name': p[3], 'type': 'd' if p[0] == 'd' else 'f', 'size': int(p[1]), 'mtime': int(float(p[2]))})
        files.sort(key=lambda f: (f['type'] != 'd', -f['mtime']))
        return jsonify({'path': path, 'files': files})
    except Exception as e:
        return api_error(e)


@app.route('/logdirs')
def api_logdirs():
    """아파치 설정에서 찾은 실제 로그 경로 (서버마다 다를 수 있음)"""
    root = request.args.get('root') or ''
    try:
        srv = _srv()
        out = call(srv.exec(['logdirs', root], 30), 40)
        allowed = call(srv.allowed_dirs(), 40)
        items = []
        for ln in out.splitlines():
            p = ln.split('\t')
            if len(p) == 3:
                items.append({'conf': p[0], 'directive': p[1], 'path': p[2], 'dir': p[2].rsplit('/', 1)[0] if p[2].startswith('/') else ''})
        return jsonify({'items': items, 'allowed': allowed})
    except Exception as e:
        return api_error(e)


@app.route('/file/info')
def api_file_info():
    try:
        srv = _srv(); p = call(srv.check_path(request.args['path']), 40)
        out = call(srv.exec(['info', p], 60), 70).split()
        return jsonify({'size': int(out[0]), 'mtime': int(out[1]), 'first': out[2] if out[2] != '-' else '',
                        'last': out[3] if out[3] != '-' else ''})
    except Exception as e:
        return api_error(e)


@app.route('/file/chunk')
def api_file_chunk():
    try:
        start, end = int(request.args['start']), int(request.args['end'])
        srv = _srv(); p = call(srv.check_path(request.args['path']), 40)
        out = call(srv.exec(['chunk', p, start, end], 60), 70)
        lines, meta = [], {}
        for ln in out.split('\n'):
            if not ln:
                continue
            if ln.startswith('#'):
                k, _, v = ln[1:].partition(' ')
                meta[k] = int(v)
            else:
                off, _, text = ln.partition('\t')
                lines.append([int(off), text])
        return jsonify({'start': meta.get('start', start), 'end': meta.get('end', end),
                        'size': meta.get('size', end), 'lines': lines})
    except Exception as e:
        return api_error(e)


@app.route('/file/seek')
def api_file_seek():
    try:
        key = re.sub(r'\D', '', request.args.get('key', ''))[:14].ljust(14, '0')
        srv = _srv(); p = call(srv.check_path(request.args['path']), 40)
        out = call(srv.exec(['seek', p, key], 120), 130)
        return jsonify({'offset': int(out.strip() or 0)})
    except Exception as e:
        return api_error(e)


@app.route('/file/grep')
def api_file_grep():
    try:
        q = request.args.get('q', '')
        mode = request.args.get('mode', 'F')
        mx = min(int(request.args.get('max', '500')), 5000)
        frm = int(request.args.get('from', '0'))
        srv = _srv(); p = call(srv.check_path(request.args['path']), 40)
        out = call(srv.exec(['grep', p, q, mx, frm, mode], 300), 310)
        hits = []
        for ln in out.split('\n'):
            if ln and not ln.startswith('#'):
                off, _, text = ln.partition('\t')
                hits.append([int(off), text])
        return jsonify({'hits': hits, 'capped': len(hits) >= mx})
    except Exception as e:
        return api_error(e)


def takeover_port(port):
    """같은 포트에 api.php 가 자동 기동한 보통 모드 데몬이 떠 있으면 끝내고 자리를 받는다 (개발 PC 용)"""
    import urllib.request
    try:
        req = urllib.request.Request(f'http://127.0.0.1:{port}/state', headers={'X-Envlog-User': '0'})
        st = json.loads(urllib.request.urlopen(req, timeout=3).read().decode('utf-8'))
    except Exception:
        return                                     # 아무것도 안 떠 있음
    if st.get('schools_from_daemon') or st.get('local_test'):
        print(f'[envlogd] 포트 {port} 에 손으로 띄운 데몬이 이미 있습니다 — 그쪽을 끄고 다시 실행하세요'); sys.exit(1)
    print(f'[envlogd] 포트 {port} 의 자동 기동 데몬을 끝내고 자리를 받습니다')
    try:
        req = urllib.request.Request(f'http://127.0.0.1:{port}/shutdown', data=b'{}', method='POST',
                                     headers={'X-Envlog-User': '0', 'Content-Type': 'application/json'})
        urllib.request.urlopen(req, timeout=3).read()
    except Exception:
        pass
    time.sleep(1.5)


# ---------------------------------------------------------------------------
def main():
    port = int(os.environ.get('ENVLOG_PORT', '8766'))
    if '--port' in sys.argv:
        port = int(sys.argv[sys.argv.index('--port') + 1])
    global MANUAL
    MANUAL = '--local-test' in sys.argv or '--iworks-db' in sys.argv
    if '--local-test' in sys.argv:
        rest = [a for a in sys.argv[sys.argv.index('--local-test') + 1:] if not a.startswith('--') and not a.isdigit()]
        folders = [os.path.abspath(a) for a in rest if os.path.isdir(a)]
        if len(folders) != len(rest):
            print('[envlogd] 폴더가 아니어서 제외:', [a for a in rest if not os.path.isdir(a)])
        if not folders:
            print('사용법: python envlogd.py --local-test <로그폴더> [<로그폴더2> ...]'); return
        print('[envlogd] 로컬 테스트 모드 (어느 대학에 접속해도 이 폴더를 본다):', folders)
        LOCAL_FOLDERS.extend(folders)
    if '--iworks-db' in sys.argv:
        global SCHOOLS
        import getpass
        for flag, env in (('--bastion', 'ENVLOG_BASTION'), ('--key', 'ENVLOG_BASTION_KEY'), ('--remote-root', 'ENVLOG_REMOTE_ROOT'),
                          ('--db', 'ENVLOG_DB'), ('--db-user', 'ENVLOG_DB_USER')):
            if flag in sys.argv:
                os.environ[env] = sys.argv[sys.argv.index(flag) + 1]
        SCHOOLS = SchoolDB()
        if not SCHOOLS.key and not SCHOOLS.ssh_pw:
            SCHOOLS.ssh_pw = getpass.getpass(f'[iWorks 서버 SSH] {SCHOOLS.user}@{SCHOOLS.host} 비밀번호 (키 파일을 쓰려면 --key 경로): ')
        if not SCHOOLS.db_pw:
            SCHOOLS.db_pw = getpass.getpass(f'[iWorks DB] {SCHOOLS.db_user}@{SCHOOLS.db_name} 비밀번호: ')
        print(f'[envlogd] 개발 PC 모드: 대학 목록을 {SCHOOLS.user}@{SCHOOLS.host} 터널로 {SCHOOLS.db_name} 에서 읽는다 (비밀번호는 저장하지 않음)')
        takeover_port(port)
        rows = SCHOOLS.load(force=True)
        print(f'[envlogd] 대학 {len(rows)}개' if not SCHOOLS.error else f'[envlogd] {SCHOOLS.error} — 화면에서 다시 시도할 수 있음')
    print(f'[envlogd] http://127.0.0.1:{port}  (종료: Ctrl+C) — envlog/api.php 가 이 주소로 넘긴다')
    try:
        app.run(host='127.0.0.1', port=port, debug=False, threaded=True)
    finally:
        for s in list(SESSIONS.values()):
            s.close()


if __name__ == '__main__':
    main()
