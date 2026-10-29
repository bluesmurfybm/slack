#!/bin/bash
# envlog_remote.sh — 로그 서버에서 실행되는 보조 스크립트 (bash + GNU coreutils/awk/grep 만 사용, python 불필요)
#   ls    DIR                       : 파일/폴더 목록  "type \t size \t mtime \t name" (링크는 따라감)
#   logdirs [SERVERROOT]            : 아파치 설정의 CustomLog/ErrorLog 경로  "conf \t 지시어 \t 경로"
#   info  FILE                      : "size mtime firstkey lastkey"
#   chunk FILE START END            : 바이트 구간의 '완전한 줄'만  "#start OFF" / "offset \t line" ... / "#end OFF"
#   seek  FILE KEY(YYYYMMDDHHMMSS)  : 시간키 >= KEY 인 첫 줄의 바이트 오프셋
#   grep  FILE PATTERN MAX FROM MODE: MODE=F(부분일치)|W(단어일치, IP용)|E(정규식)  → "offset \t line"
export LC_ALL=C
cmd=$1; shift

# 타임스탬프 → 정렬키(YYYYMMDDHHMMSS)
#   access_log "[29/Oct/2026:14:03:22 +0900]" / error_log "[Wed Oct 29 14:03:22.123456 2026]" / PHP "[29-Oct-2026 17:22:34 Asia/Seoul]"
AWK_KEY='
function mon(m){ return (index("JanFebMarAprMayJunJulAugSepOctNovDec", m) + 2) / 3 }
function key(line,   s, rest, y, d) {
  if (match(line, /\[[0-9][0-9]\/[A-Z][a-z][a-z]\/[0-9][0-9][0-9][0-9]:[0-9][0-9]:[0-9][0-9]:[0-9][0-9]/)) {
    s = substr(line, RSTART+1, RLENGTH-1)
    return substr(s,8,4) sprintf("%02d", mon(substr(s,4,3))) substr(s,1,2) substr(s,13,2) substr(s,16,2) substr(s,19,2)
  }
  if (match(line, /\[[0-9][0-9]-[A-Z][a-z][a-z]-[0-9][0-9][0-9][0-9] [0-9][0-9]:[0-9][0-9]:[0-9][0-9]/)) {
    s = substr(line, RSTART+1, RLENGTH-1)          # PHP 오류 로그 "29-Oct-2026 17:22:34" — 자리수가 access_log 와 같다
    return substr(s,8,4) sprintf("%02d", mon(substr(s,4,3))) substr(s,1,2) substr(s,13,2) substr(s,16,2) substr(s,19,2)
  }
  if (match(line, /\[[A-Z][a-z][a-z] [A-Z][a-z][a-z] [0-9 ][0-9] [0-9][0-9]:[0-9][0-9]:[0-9][0-9]/)) {
    s = substr(line, RSTART+1, RLENGTH-1)
    rest = substr(line, RSTART+RLENGTH, 20)
    if (!match(rest, /[0-9][0-9][0-9][0-9]\]/)) return ""
    y = substr(rest, RSTART, 4)
    d = substr(s,9,2); gsub(/ /, "0", d)
    return y sprintf("%02d", mon(substr(s,5,3))) d substr(s,12,2) substr(s,15,2) substr(s,18,2)
  }
  return ""
}'

fsize() { stat -c %s "$1" 2>/dev/null || echo 0; }

# off 직전 바이트가 줄바꿈이면 off 는 줄 시작 → 첫 줄을 버리지 않는다
skip_first() {  # FILE OFF → 0|1
  [ "$2" -le 0 ] && { echo 0; return; }
  local p; p=$(tail -c +"$2" "$1" | head -c 1)
  [ -z "$p" ] && echo 0 || echo 1
}

# off 이후 첫 '완전한 + 시간키 있는' 줄 → "key offset"
line_key_at() {  # FILE OFF
  local f=$1 off=$2 skip; skip=$(skip_first "$f" "$off")
  tail -c +$((off+1)) "$f" | head -c 65536 | awk -v off="$off" -v skip="$skip" "$AWK_KEY"'
    NR==1 && skip==1 { off += length($0) + 1; next }
    { k = key($0); if (k != "") { print k " " off; exit }; off += length($0) + 1 }'
}

# 파일 확인 — '없음'(2) 과 '권한 없음'(13) 을 구분한다. 권한이면 호출 쪽이 sudo 로 재시도.
# (디렉터리에 들어갈 권한이 없으면 -e 도 거짓이 되므로, 상위 디렉터리 x 권한까지 본다)
need_file() {
  local f=$1 d; d=$(dirname "$f")
  if [ ! -e "$f" ]; then
    if [ ! -x "$d" ] || [ ! -r "$d" ]; then echo "#err 접근 권한 없음($(id -un)): $d" >&2; exit 13; fi
    echo "#err 파일이 없습니다: $f" >&2; exit 2
  fi
  [ -r "$f" ] || { echo "#err 읽기 권한 없음($(id -un)): $f" >&2; exit 13; }
}

# 텍스트 파일만 연다 — 압축/바이너리는 거부(65). 앞 8KB 에 NUL 이 있으면 바이너리로 본다 (gzip 등은 확장자로 먼저 거른다)
need_text() {
  local f=$1 n1 n2
  case "$f" in
    *.gz|*.bz2|*.xz|*.zst|*.lz4|*.zip|*.tar|*.tgz|*.tbz|*.7z|*.rar|*.Z) echo "#err 텍스트 파일만 열 수 있습니다 (압축 파일): $(basename "$f")" >&2; exit 65 ;;
  esac
  # 실행 파일은 열지 않는다 — 실행 비트가 있거나 ELF/스크립트(#!) 머리면 거부. (이 도구는 어떤 파일도 실행하지 않는다; 읽기만 한다)
  if [ -x "$f" ]; then echo "#err 실행 파일은 열 수 없습니다: $(basename "$f")" >&2; exit 65; fi
  case "$(head -c 4 "$f" | tr -d '\000')" in $'\177ELF'|'#!'*) echo "#err 실행 파일은 열 수 없습니다: $(basename "$f")" >&2; exit 65 ;; esac
  n1=$(head -c 8192 "$f" | wc -c | tr -d ' '); n2=$(head -c 8192 "$f" | tr -d '\000' | wc -c | tr -d ' ')
  if [ "$n1" != "$n2" ]; then echo "#err 텍스트 파일만 열 수 있습니다 (바이너리): $(basename "$f")" >&2; exit 65; fi
}

do_ls() {
  local d=$1
  if [ ! -d "$d" ]; then
    if [ ! -x "$(dirname "$d")" ]; then echo "#err 접근 권한 없음($(id -un)): $d" >&2; exit 13; fi
    echo "#err 디렉터리가 없습니다: $d" >&2; exit 2
  fi
  if [ ! -r "$d" ] || [ ! -x "$d" ]; then echo "#err 디렉터리 읽기 권한 없음($(id -un)): $d" >&2; exit 13; fi
  # 심볼릭 링크는 따라가서(-L) 실제 종류로: "type(f|d) \t size \t mtime \t name"
  find -L "$d" -mindepth 1 -maxdepth 1 \( -type f -o -type d \) -printf '%y\t%s\t%T@\t%f\n' 2>/dev/null
}

# 아파치 설정에서 실제 로그 위치 찾기: CustomLog / ErrorLog / TransferLog (rotatelogs 파이프 포함)
#   출력: "conf파일 \t 지시어 \t 로그경로(패턴)" — 상대경로는 ServerRoot 기준으로 절대화
do_logdirs() {
  local root=$1 confs c sr
  [ -n "$root" ] || root=/usr/local/apache2
  confs=$(ls "$root"/conf/httpd.conf "$root"/conf/*.conf "$root"/conf/extra/*.conf "$root"/conf/vhosts/*.conf "$root"/conf.d/*.conf \
             /etc/httpd/conf/httpd.conf /etc/httpd/conf.d/*.conf /etc/apache2/apache2.conf /etc/apache2/sites-enabled/* 2>/dev/null | sort -u)
  for c in $confs; do
    sr=$(grep -i '^[[:space:]]*ServerRoot' "$c" 2>/dev/null | head -1 | awk '{print $2}' | tr -d '"')
    [ -n "$sr" ] || sr=$root
    grep -i '^[[:space:]]*\(CustomLog\|ErrorLog\|TransferLog\)[[:space:]]' "$c" 2>/dev/null | awk -v c="$c" -v sr="$sr" '
      { d = $1; p = $2; gsub(/"/, "", p)
        if (p ~ /^\|/) { sub(/^\|+/, "", p); if (p ~ /rotatelogs|cronolog/) { for (i = 3; i <= NF; i++) { q = $i; gsub(/"/, "", q); if (q ~ /^\//) { p = q; break } } } else p = "|" p }
        if (p !~ /^[\/|]/) p = sr "/" p
        if (p == "" || p ~ /^(syslog|\/dev\/null)/) next
        print c "\t" d "\t" p }'
  done | sort -u -t$'\t' -k3,3
}

do_info() {
  local f=$1 size mtime fk lk
  need_file "$f"
  need_text "$f"
  size=$(fsize "$f"); mtime=$(stat -c %Y "$f")
  fk=$(head -c 65536 "$f" | awk "$AWK_KEY"'{ k=key($0); if (k!="") { print k; exit } }')
  lk=$(tail -c 65536 "$f" | awk "$AWK_KEY"'{ k=key($0); if (k!="") last=k } END { print last }')
  echo "$size $mtime ${fk:--} ${lk:--}"
}

do_chunk() {
  local f=$1 start=$2 end=$3 size n T lastnl skip
  need_file "$f"
  need_text "$f"
  size=$(fsize "$f")
  [ "$start" -lt 0 ] && start=0
  [ "$end" -gt "$size" ] && end=$size
  n=$((end - start))
  if [ "$n" -le 0 ]; then echo "#size $size"; echo "#start $start"; echo "#end $start"; return; fi
  T=$(mktemp /tmp/.envlog.XXXXXX)
  tail -c +$((start+1)) "$f" | head -c "$n" > "$T"
  lastnl=0
  if [ "$(tail -c 1 "$T" | wc -c)" = "1" ] && [ -z "$(tail -c 1 "$T")" ]; then lastnl=1; fi
  skip=$(skip_first "$f" "$start")
  echo "#size $size"
  awk -v off="$start" -v skip="$skip" -v lastnl="$lastnl" -v end="$end" -v size="$size" '
    { L[NR] = $0 }
    END {
      first = 1; last = NR
      if (NR == 0) { print "#start " off; print "#end " off; exit }
      if (skip == 1) { off += length(L[1]) + 1; first = 2 }
      if (lastnl == 0 && end < size) last = NR - 1
      s = off; print "#start " s
      for (i = first; i <= last; i++) { print s "\t" L[i]; s += length(L[i]) + 1 }
      if (s > size) s = size
      print "#end " s
    }' "$T"
  rm -f "$T"
}

do_seek() {
  local f=$1 target=$2 size lo hi mid res k o
  need_file "$f"
  need_text "$f"
  size=$(fsize "$f"); lo=0; hi=$size
  while [ "$lo" -lt "$hi" ]; do
    mid=$(( (lo + hi) / 2 ))
    res=$(line_key_at "$f" "$mid")
    if [ -z "$res" ]; then hi=$mid; continue; fi
    k=${res%% *}; o=${res##* }
    if [ "$k" \< "$target" ]; then lo=$((o + 1)); else hi=$mid; fi
  done
  res=$(line_key_at "$f" "$lo")
  if [ -z "$res" ]; then echo "$size"; else echo "${res##* }"; fi
}

do_grep() {
  local f=$1 pat=$2 max=$3 from=$4 mode=$5 opts
  need_file "$f"
  need_text "$f"
  case "$mode" in W) opts="-F -w" ;; E) opts="-E" ;; *) opts="-F" ;; esac
  [ "$from" -gt 0 ] 2>/dev/null || from=0
  tail -c +$((from+1)) "$f" | grep -a -b -m "$max" $opts -- "$pat" | awk -v add="$from" '
    { i = index($0, ":"); print (substr($0, 1, i-1) + add) "\t" substr($0, i+1) }'
  return 0
}

case "$cmd" in
  ls)    do_ls "$@" ;;
  logdirs) do_logdirs "$@" ;;
  info)  do_info "$@" ;;
  chunk) do_chunk "$@" ;;
  seek)  do_seek "$@" ;;
  grep)  do_grep "$@" ;;
  *) echo "#err unknown command: $cmd" >&2; exit 1 ;;
esac
