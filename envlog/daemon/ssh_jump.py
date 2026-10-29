# -*- coding: utf-8 -*-
"""
ssh_jump.py — 점프(테스트)서버 경유 다운스트림(운영)서버 접속 재사용 모듈 (asyncssh, 순수 파이썬)
  pip install asyncssh
공개 API:
  load_key(path)                                   : .ppk(암호없는 ssh-rsa)/OpenSSH 키 → asyncssh 키
  await connect(host, port, username, password=, client_keys=, tunnel=) : 모던→레거시 폴백 접속
  await run(conn, cmd, timeout=)                   : 원격 명령 → stdout(str)
  tunnel(conn, local_port, rhost, rport)           : async with 로 로컬 포트포워딩(DB 등)
  await read_remote_file(conn, path, sudo_pw=)     : 파일 읽기(권한 없으면 sudo 재시도)
  await run_downstream(conn, host, user, cmd, ...) : ★ 점프서버 경유 다운스트림 명령(터널 우선, expect 폴백)
동기 코드에서: loop=asyncio.new_event_loop(); loop.run_until_complete(coro)
"""
import asyncio, base64, struct, shlex
import asyncssh

# 모던 + 레거시(구형 OpenSSH 5.3: ssh-rsa/ssh-dss) 합집합 — 2차 폴백에서만 사용
HOSTKEY_ALGS = ['ssh-ed25519','ecdsa-sha2-nistp256','ecdsa-sha2-nistp384','ecdsa-sha2-nistp521',
                'rsa-sha2-512','rsa-sha2-256','ssh-rsa','ssh-dss']
KEX_ALGS = ['curve25519-sha256','curve25519-sha256@libssh.org','ecdh-sha2-nistp256','ecdh-sha2-nistp384',
            'ecdh-sha2-nistp521','diffie-hellman-group-exchange-sha256','diffie-hellman-group16-sha512',
            'diffie-hellman-group14-sha256','diffie-hellman-group14-sha1',
            'diffie-hellman-group-exchange-sha1','diffie-hellman-group1-sha1']
ENC_ALGS = ['chacha20-poly1305@openssh.com','aes256-gcm@openssh.com','aes128-gcm@openssh.com',
            'aes256-ctr','aes192-ctr','aes128-ctr','aes256-cbc','aes192-cbc','aes128-cbc','3des-cbc']
MAC_ALGS = ['hmac-sha2-256','hmac-sha2-512','hmac-sha1','hmac-sha1-96']


# ---------- PPK → asyncssh 키 (순수 파이썬, puttygen 불필요) ----------
def _rd_str(b, o): n = struct.unpack('>I', b[o:o+4])[0]; return b[o+4:o+4+n], o+4+n
def _rd_mpint(b, o): v, o = _rd_str(b, o); return int.from_bytes(v, 'big'), o

def load_key(path):
    """PuTTY .ppk(암호없는 ssh-rsa)면 파싱해 asyncssh 키 반환. 그 외(OpenSSH PEM 등)는 경로 그대로."""
    if not path.lower().endswith('.ppk'):
        return path
    lines = open(path, 'r', encoding='utf-8', errors='replace').read().splitlines()
    hdr = {}; pub_b = priv_b = ''; i = 0
    while i < len(lines):
        ln = lines[i]
        if ln.startswith('Public-Lines:'):
            c = int(ln.split(':',1)[1]); pub_b = ''.join(lines[i+1:i+1+c]); i += 1+c; continue
        if ln.startswith('Private-Lines:'):
            c = int(ln.split(':',1)[1]); priv_b = ''.join(lines[i+1:i+1+c]); i += 1+c; continue
        if ':' in ln: k, v = ln.split(':',1); hdr[k.strip()] = v.strip()
        i += 1
    if hdr.get('Encryption', 'none') != 'none':
        raise ValueError('암호 걸린 PPK 미지원 — OpenSSH 키로 변환 후 그 경로를 지정')
    algo = hdr.get('PuTTY-User-Key-File-3') or hdr.get('PuTTY-User-Key-File-2')
    if algo != 'ssh-rsa':
        raise ValueError('ssh-rsa PPK 만 지원(현재: %s)' % algo)
    from cryptography.hazmat.primitives.asymmetric import rsa
    from cryptography.hazmat.primitives import serialization
    pub = base64.b64decode(pub_b); priv = base64.b64decode(priv_b)
    _, o = _rd_str(pub, 0); e, o = _rd_mpint(pub, o); n, o = _rd_mpint(pub, o)
    d, o = _rd_mpint(priv, 0); p, o = _rd_mpint(priv, o); q, o = _rd_mpint(priv, o)
    pubn = rsa.RSAPublicNumbers(e, n)
    for (pp, qq) in ((p, q), (q, p)):
        try:
            key = rsa.RSAPrivateNumbers(pp, qq, d, d % (pp-1), d % (qq-1), pow(qq, -1, pp), pubn).private_key()
            pem = key.private_bytes(serialization.Encoding.PEM,
                                    serialization.PrivateFormat.TraditionalOpenSSL,
                                    serialization.NoEncryption())
            return asyncssh.import_private_key(pem)
        except Exception:
            continue
    raise ValueError('RSA 키 재구성 실패')


# ---------- 접속: 모던 기본 → 실패 시 레거시 폴백 ----------
async def connect(host, port=22, username=None, password=None, client_keys=None, timeout=12, tunnel=None):
    """password 만 주면 비번 접속(로컬키 시도 안 함), client_keys=[키] 면 키 접속.
       tunnel=기존 연결 을 주면 그 연결 위로(direct-tcpip) 접속 = 점프 경유."""
    base = dict(port=port, username=username, password=password,
                client_keys=client_keys, known_hosts=None, agent_path=None)
    if tunnel is not None: base['tunnel'] = tunnel
    try:
        return await asyncio.wait_for(asyncssh.connect(host, **base), timeout)
    except (asyncssh.Error, OSError, asyncio.TimeoutError):
        return await asyncio.wait_for(asyncssh.connect(
            host, **base, server_host_key_algs=HOSTKEY_ALGS, kex_algs=KEX_ALGS,
            encryption_algs=ENC_ALGS, mac_algs=MAC_ALGS), timeout + 3)


# ---------- 원격 명령 실행 ----------
async def run(conn, cmd, timeout=15):
    try:
        r = await asyncio.wait_for(conn.run(cmd, check=False, encoding=None), timeout)
        out = r.stdout
        return out.decode('utf-8', 'replace') if isinstance(out, (bytes, bytearray)) else (out or '')
    except Exception:
        return ''

async def read_remote_file(conn, path, sudo_pw=None, timeout=15):
    """파일 내용. 소유권/권한으로 못 읽으면 sudo(-S, 계정 비번)로 재시도."""
    out = await run(conn, f"cat {shlex.quote(path)} 2>/dev/null", timeout)
    if out.strip() or not sudo_pw: return out
    return await run(conn, f"echo {shlex.quote(sudo_pw)} | sudo -S cat {shlex.quote(path)} 2>/dev/null", timeout)


# ---------- 로컬 포트포워딩(터널) ----------
class _Tunnel:
    def __init__(self, conn, lh, lp, rh, rp): self.conn, self.lh, self.lp, self.rh, self.rp = conn, lh, lp, rh, rp
    async def __aenter__(self):
        self.listener = await self.conn.forward_local_port(self.lh, self.lp, self.rh, self.rp); return self
    async def __aexit__(self, *a): self.listener.close()

def tunnel(conn, local_port, remote_host, remote_port, local_host='127.0.0.1'):
    """async with tunnel(conn, 13306, '127.0.0.1', 3306):  → 127.0.0.1:13306 로 내부 서비스 접근"""
    return _Tunnel(conn, local_host, local_port, remote_host, remote_port)


# ---------- ★ 다운스트림(운영)서버 명령 ----------
def _b64cmd(cmd):   # 임의 명령을 base64로 감싸 따옴표/특수문자 안전하게 전달
    return "echo %s | base64 -d | bash" % base64.b64encode(cmd.encode('utf-8')).decode('ascii')

def _pw_script(host, user, cmd, port, password):
    """[폴백] 점프서버에서 expect 로 ssh 비번 입력. base64 명령은 중괄호가 없어 Tcl {literal} 1개 인자로 안전."""
    tmpl = ('set timeout 8\n'
            'spawn ssh -p $env(OPr) -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            '-o ConnectTimeout=5 -o PreferredAuthentications=password,keyboard-interactive '
            '$env(OU)@$env(OH) {' + _b64cmd(cmd) + '}\n'
            'expect {\n'
            '  -nocase -re {password:} { sleep 1; send -- "$env(OPSPW)\\r"; exp_continue }\n'
            '  eof {}\n  timeout {}\n}\n')
    exports = "export OPSPW=%s OH=%s OU=%s OPr=%s\n" % (
        shlex.quote(password or ''), shlex.quote(host), shlex.quote(user), port)
    return exports + "expect -c " + shlex.quote(tmpl)

def _nopw_script(host, user, cmd, port):
    """[폴백] 비번도 키도 없는 경우: 점프서버의 ssh 가 자기 ~/.ssh 키(또는 agent/config)로 인증. expect 불필요."""
    return ('ssh -p ' + str(port) +
            " -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 -o BatchMode=yes "
            + shlex.quote(f"{user}@{host}") + " " + shlex.quote(_b64cmd(cmd)) + "\n")

async def _jump_default_keys(conn):
    """점프서버 계정의 기본 키(~/.ssh/id_*)를 읽어 asyncssh 키 목록으로. 암호 걸린 키는 건너뜀."""
    out = await run(conn, 'for f in "$HOME/.ssh/id_ed25519" "$HOME/.ssh/id_ecdsa" "$HOME/.ssh/id_rsa" "$HOME/.ssh/id_dsa"; '
                          'do [ -f "$f" ] && { echo ___K___; cat "$f"; }; done 2>/dev/null')
    keys = []
    for blk in out.split('___K___')[1:]:
        try: keys.append(asyncssh.import_private_key(blk.strip() + '\n'))
        except Exception: pass
    return keys

def _key_script(host, user, cmd, port, keypath, site_dir, sudo_pw):
    """[폴백] 점프서버에서 키 임시 0600 복사(root 소유면 sudo) 후 ssh -i."""
    kq = shlex.quote(keypath); tgt = shlex.quote(f"{user}@{host}")
    cd = ("cd " + shlex.quote(site_dir) + " 2>/dev/null\n") if site_dir else ""
    sudocp = (" || { echo " + shlex.quote(sudo_pw) + " | sudo -S cp " + kq + ' "$TK" 2>/dev/null; }') if sudo_pw else ""
    return (cd + "TK=$(mktemp)\ncp " + kq + ' "$TK" 2>/dev/null' + sudocp + "\n"
            'chmod 600 "$TK" 2>/dev/null\n'
            'ssh -i "$TK" -p ' + str(port) +
            " -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 -o BatchMode=yes "
            + tgt + " " + shlex.quote(cmd) + "\nrm -f \"$TK\"\n")

async def run_downstream(conn, host, user, cmd, port=22, password=None,
                         keypath=None, site_dir=None, sudo_pw=None, timeout=25, info=None):
    """점프서버(conn) 경유로 다운스트림 host 에서 cmd 실행 → 출력 문자열.
    1) [권장] asyncssh 터널: conn 위로 direct-tcpip 열어 운영서버 sshd 와 '직접' 협상.
       expect/PTY/타이밍/임시키 불필요, 출력도 깨끗(stdout 그대로). 레거시 sshd 도 connect() 폴백이 처리.
       키 방식이면 점프서버의 키 파일을 읽어(sudo 가능) 메모리에서 로드 → 임시파일 0600 복사 불필요.
    2) [폴백] 점프서버의 sshd 가 TCP 포워딩을 막은 경우 등 → 기존 expect / ssh -i 스크립트.
    3) 비번·키 둘 다 없음 = "점프서버 자체 키(~/.ssh/id_*)로 인증되는" 경우: 그 키를 읽어 터널로 시도,
       안 되면 점프서버의 ssh(BatchMode, 자기 키/agent/config 사용)로 폴백. 이 경우는 인증 실패여도 폴백한다.
    info={} 를 넘기면 info['path'] = 'tunnel' | 'fallback' | 'auth_failed', 폴백 사유는 info['tunnel_error'].
    """
    if info is None: info = {}
    nocred = not password and not keypath
    try:
        keys = None
        if keypath:
            kp = keypath if keypath.startswith('/') else ((site_dir.rstrip('/') + '/') if site_dir else '') + keypath
            pem = await read_remote_file(conn, kp, sudo_pw)
            if not pem.strip(): raise RuntimeError('key read failed: ' + kp)
            keys = [asyncssh.import_private_key(pem)]
        elif nocred:
            keys = await _jump_default_keys(conn)
            if not keys: raise RuntimeError('no jump default keys')      # → 점프서버 ssh 폴백
        c2 = await connect(host, port, user, password=(None if keys else password),
                           client_keys=keys, timeout=12, tunnel=conn)
        try:
            info['path'] = 'tunnel'
            return await run(c2, cmd, timeout)
        finally:
            c2.close()
    except asyncssh.PermissionDenied as e:
        if not nocred:
            info['path'] = 'auth_failed'
            return f"Permission denied (asyncssh tunnel): {e}"  # 비번/키가 있는데 거부 → 폴백해도 같음, 즉시 보고
        info['path'] = 'fallback'; info['tunnel_error'] = repr(e)   # 점프서버의 agent/config 키일 수 있음 → 폴백
    except Exception as e:
        info['path'] = 'fallback'; info['tunnel_error'] = repr(e)   # 포워딩 차단 등 → 폴백
    if keypath:  script = _key_script(host, user, cmd, port, keypath, site_dir, sudo_pw)
    elif nocred: script = _nopw_script(host, user, cmd, port)
    else:        script = _pw_script(host, user, cmd, port, password)
    return await run(conn, _b64cmd(script) + " 2>&1", timeout)
