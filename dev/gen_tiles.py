# 날씨 배경 타일 생성기.
# 반복해도 이음매가 없어야 하므로, 타일 가장자리에 걸치는 모양은 반대편에도 찍는다.
import random, urllib.parse, pathlib

def enc(s): return urllib.parse.quote(s, safe="/:=,()' .-")

def tile(w, h, body, defs=""):
    s = (f"<svg xmlns='http://www.w3.org/2000/svg' width='{w}' height='{h}' "
         f"viewBox='0 0 {w} {h}'>{defs}{body}</svg>")
    return f'url("data:image/svg+xml,{enc(s)}")'

def dots(seed, w, h, n, rmin, rmax, omin, omax, col="#fff"):
    r = random.Random(seed); out = []
    for _ in range(n):
        x, y = round(r.uniform(0, w), 1), round(r.uniform(0, h), 1)
        rad, op = round(r.uniform(rmin, rmax), 2), round(r.uniform(omin, omax), 2)
        # 가장자리에 걸친 것만 반대편에 한 번 더 찍는다
        xs = [x] + ([x + w] if x < rad else []) + ([x - w] if x > w - rad else [])
        ys = [y] + ([y + h] if y < rad else []) + ([y - h] if y > h - rad else [])
        for X in xs:
            for Y in ys:
                out.append(f"<circle cx='{round(X,1)}' cy='{round(Y,1)}' "
                           f"r='{rad}' fill='{col}' opacity='{op}'/>")
    return tile(w, h, "".join(out))

def streaks(seed, w, h, n, lmin, lmax, tilt, wmin, wmax, omin, omax):
    r = random.Random(seed); out = []
    for _ in range(n):
        x, y = round(r.uniform(0, w), 1), round(r.uniform(0, h), 1)
        ln, sw = round(r.uniform(lmin, lmax), 1), round(r.uniform(wmin, wmax), 2)
        op = round(r.uniform(omin, omax), 2); dx = round(ln * tilt, 2)
        def seg(X, Y):
            return (f"<line x1='{round(X,1)}' y1='{round(Y,1)}' x2='{round(X-dx,1)}' "
                    f"y2='{round(Y+ln,1)}' stroke='#fff' stroke-width='{sw}' "
                    f"stroke-linecap='round' opacity='{op}'/>")
        xs = [x] + ([x + w] if x - dx < 0 else [])
        ys = [y] + ([y - h] if y + ln > h else [])
        for X in xs:
            for Y in ys:
                out.append(seg(X, Y))
    return tile(w, h, "".join(out))

def blobs(seed, w, h, shapes, blur, col):
    """뿌연 덩어리. 좌우로만 반복하므로 x 방향만 감아 준다."""
    r = random.Random(seed); out = []
    for (cx, cy, rx, ry, op) in shapes:
        for ox in (0, w, -w):
            out.append(f"<ellipse cx='{cx+ox}' cy='{cy}' rx='{rx}' ry='{ry}' "
                       f"fill='{col}' opacity='{op}'/>")
    defs = (f"<defs><filter id='b' x='-60%' y='-60%' width='220%' height='220%'>"
            f"<feGaussianBlur stdDeviation='{blur}'/></filter></defs>")
    return tile(w, h, f"<g filter='url(#b)'>{''.join(out)}</g>", defs)

def clouds(w, h, puffs, blur, col):
    """원을 겹쳐 뭉게구름 실루엣을 만든다. 통 타원 하나로는 그냥 뿌연 얼룩이라
    구름으로 안 읽혔다. puffs = [(cx, cy, 너비, 투명도), ...]"""
    out = []
    for (cx, cy, W, op) in puffs:
        r = W / 4.4
        parts = [(-1.55*r, 0.30*r, 0.80*r), (-0.62*r, -0.22*r, 1.06*r),
                 ( 0.42*r, -0.40*r, 1.22*r), ( 1.55*r, 0.16*r, 0.92*r)]
        for ox in (0, w, -w):
            g = [f"<ellipse cx='{round(cx+ox,1)}' cy='{round(cy+0.45*r,1)}' "
                 f"rx='{round(W/2,1)}' ry='{round(0.62*r,1)}' fill='{col}'/>"]
            g += [f"<circle cx='{round(cx+ox+dx,1)}' cy='{round(cy+dy,1)}' "
                  f"r='{round(rr,1)}' fill='{col}'/>" for (dx, dy, rr) in parts]
            out.append(f"<g opacity='{op}'>{''.join(g)}</g>")
    defs = (f"<defs><filter id='b' x='-40%' y='-40%' width='180%' height='180%'>"
            f"<feGaussianBlur stdDeviation='{blur}'/></filter></defs>")
    return tile(w, h, f"<g filter='url(#b)'>{''.join(out)}</g>", defs)

css = []
# ── 눈 ──
css.append(f".wx-fx > i.s1{{--tw:260px;--th:260px;background-image:{dots(11,260,260,20,1.1,2.6,.6,1.0)}}}")
css.append(f".wx-fx > i.s2{{--tw:300px;--th:300px;background-image:{dots(22,300,300,17,0.9,1.8,.45,.85)}}}")
css.append(f".wx-fx > i.s3{{--tw:340px;--th:340px;background-image:{dots(33,340,340,13,0.6,1.2,.3,.6)}}}")
# ── 비 ──
css.append(f".wx-fx > i.r1{{--tw:240px;--th:280px;background-image:{streaks(41,240,280,17,18,34,.22,1.0,1.8,.5,.9)}}}")
css.append(f".wx-fx > i.r2{{--tw:300px;--th:340px;background-image:{streaks(52,300,340,14,13,24,.20,0.8,1.4,.3,.55)}}}")
# ── 이슬비 — 더 촘촘하고 또렷하게 ──
css.append(f".wx-fx > i.d1{{--tw:190px;--th:210px;background-image:{streaks(63,190,210,26,9,17,.17,0.9,1.4,.5,.85)}}}")
css.append(f".wx-fx > i.d2{{--tw:250px;--th:270px;background-image:{streaks(74,250,270,21,6,12,.15,0.7,1.1,.3,.55)}}}")
# ── 먼지알 · 별 ──
css.append(f".wx-fx > i.m1{{--tw:320px;--th:320px;background-image:{dots(85,320,320,11,1.0,1.9,.35,.7)}}}")
css.append(f".wx-fx > i.st{{--tw:300px;--th:300px;background-image:{dots(96,300,300,22,0.6,1.7,.35,1.0)}}}")
css.append(f".wx-fx > i.st2{{--tw:420px;--th:420px;background-image:{dots(97,420,420,16,0.5,1.2,.25,.7)}}}")
# ── 구름 (cx, cy, 너비, 투명도) ──
css.append(f".wx-fx > i.c1{{--tw:940px;--th:560px;background-image:"
           f"{clouds(940,560,[(150,110,300,.95),(470,72,205,.8),(730,140,345,.9)],11,'#fff')}}}")
css.append(f".wx-fx > i.c2{{--tw:1240px;--th:560px;background-image:"
           f"{clouds(1240,560,[(260,205,395,.62),(760,155,290,.55),(1090,225,350,.58)],15,'#fff')}}}")
css.append(f".wx-fx > i.c3{{--tw:1040px;--th:560px;background-image:"
           f"{clouds(1040,560,[(190,150,420,.5),(620,105,300,.42),(900,185,390,.46)],17,'#7C8CA8')}}}")
# ── 구름 조금 전용 — 흐림과 같은 층을 쓰면 하늘에 구름이 가득 찬다.
#    타일을 아주 넓게 잡아 화면에 두어 덩이만 뜨게 한다.
css.append(f".wx-fx > i.p1{{--tw:1700px;--th:560px;background-image:"
           f"{clouds(1700,560,[(300,115,330,.92),(1080,165,255,.78)],11,'#fff')}}}")
css.append(f".wx-fx > i.p2{{--tw:2300px;--th:560px;background-image:"
           f"{clouds(2300,560,[(820,215,300,.5)],15,'#fff')}}}")

# ── 안개 — 아주 납작하고 아주 뿌연 띠 ──
css.append(f".wx-fx > i.f1{{--tw:1100px;--th:560px;background-image:"
           f"{blobs(4,1100,560,[(180,120,430,26,.9),(700,190,390,22,.8),(1010,95,340,19,.7)],30,'#fff')}}}")
css.append(f".wx-fx > i.f2{{--tw:1400px;--th:560px;background-image:"
           f"{blobs(5,1400,560,[(330,235,530,28,.6),(960,158,480,24,.55)],36,'#A7AEBA')}}}")

def lightning(w, h, main, branches, sw):
    """번개 줄기. 갈지자 꺾임은 손으로 잡았다 — 무작위로 걸으면 잔물결만 많고
    번개처럼 안 보인다. 같은 선을 세 겹으로 그린다:
    흐린 빛무리 / 옅은 색 / 흰 심지. 아래로 갈수록 가늘어진다.
    빛무리 색은 뇌우 하늘(보랏빛 #8A82BA)에 맞춘 연보라다 — 파란 빛무리를
    얹었더니 하늘색과 따로 놀았다."""
    segs = []                                   # (x1,y1,x2,y2,굵기)

    def add(pts, w0, w1):
        n = len(pts) - 1
        for i in range(n):
            (x1, y1), (x2, y2) = pts[i], pts[i + 1]
            t = w0 + (w1 - w0) * (i / max(n - 1, 1))
            segs.append((x1, y1, x2, y2, round(t, 2)))

    add(main, sw, sw * .34)
    for (pts, f) in branches:
        add(pts, sw * f, sw * f * .35)

    def layer(mult, col, op):
        return "".join(
            f"<path d='M{x1},{y1} L{x2},{y2}' stroke='{col}' "
            f"stroke-width='{round(t * mult, 2)}' stroke-linecap='round' "
            f"fill='none' opacity='{op}'/>"
            for (x1, y1, x2, y2, t) in segs)

    defs = ("<defs><filter id='g' x='-70%' y='-30%' width='240%' height='160%'>"
            "<feGaussianBlur stdDeviation='7'/></filter></defs>")
    body = (f"<g filter='url(#g)'>{layer(3.0, '#C98FE8', .8)}</g>"
            f"{layer(1.8, '#EFD4FB', .95)}"
            f"{layer(1.0, '#FFFFFF', 1)}")
    return tile(w, h, body, defs)

# 큰 줄기. 가늘고 길게 — 굵으면 번개보다 막대처럼 보이고, 꺾임 수가 그대로면
# 길게 뽑았을 때 늘어난 것처럼 보여서 꺾임도 한 번 더 넣었다.
BOLT_A = lightning(120, 340,
    [(72, 0), (33, 70), (61, 102), (21, 176), (51, 206), (25, 270), (43, 340)],
    [([(61, 102), (95, 138), (81, 168)], .44),
     ([(51, 206), (87, 236)],            .36)], 5.6)
BOLT_B = lightning(110, 240,
    [(38, 0), (76, 58), (46, 90), (84, 148), (50, 180), (70, 240)],
    [([(46, 90), (14, 122), (26, 148)], .44)], 7.2)

css.append(f".wx-fx > u.b1{{--bolt:{BOLT_A}}}")
css.append(f".wx-fx > u.b2{{--bolt:{BOLT_B}}}")

out = pathlib.Path("tiles.css"); out.write_text("\n".join(css) + "\n", encoding="utf-8")
print("타일", len(css), "개 /", round(out.stat().st_size/1024, 1), "KB")
