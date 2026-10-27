"""PWA 아이콘 생성기 — favicon.ico 와 같은 블루소프트 심볼(가로 막대 세 줄)

    python dev/gen_icons.py

만드는 것 (styles/):
    icon-192.png, icon-512.png   manifest 의 일반 아이콘 — 흰 둥근 사각 위에 심볼
    icon-maskable-512.png        안드로이드가 원·물방울 모양으로 잘라 쓰는 판 — 바탕을 끝까지 채우고
                                 심볼을 안전 영역(가운데 지름 80% 원) 안에 들인다
    apple-touch-icon.png         iOS 홈 화면용 180 — iOS 는 투명을 검게 칠하고 모서리를 알아서 깎으므로
                                 꽉 찬 흰 사각으로 둔다

심볼은 styles/favicon.ico(16x16)를 그대로 옮긴 것이다. 16칸 격자에서
막대는 가로 3~12칸(10칸), 세로 2~3·7~8·12~13칸(두께 2, 간격 3)이고 색은 #2175D5 다.
favicon 을 바꾸면 BARS·BLUE 를 맞춰 고치고 다시 돌린다. 결과 PNG 는 커밋한다.
"""
from pathlib import Path

from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "styles"

BLUE = (33, 117, 213, 255)      # #2175D5 — favicon.ico 의 색
WHITE = (255, 255, 255, 255)
GRID = 16
# (x0, y0, x1, y1) — 16칸 격자, 끝 칸 포함하지 않음
BARS = [(3, 2, 13, 4), (3, 7, 13, 9), (3, 12, 13, 14)]


def draw(size: int, *, scale: float, rounded: bool) -> Image.Image:
    """scale: 16칸 격자가 아이콘 한 변에서 차지하는 비율(1.0 이면 favicon 과 같은 여백)."""
    s = size * 4                # 크게 그려 줄이면 가장자리가 매끄럽다
    img = Image.new("RGBA", (s, s), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    if rounded:
        d.rounded_rectangle((0, 0, s - 1, s - 1), radius=int(s * 0.22), fill=WHITE)
    else:
        d.rectangle((0, 0, s, s), fill=WHITE)

    unit = s * scale / GRID
    off = (s - unit * GRID) / 2
    # 막대 묶음(가로 3~13, 세로 2~14)이 격자 한가운데가 아니어서 위아래를 맞춘다
    dy = (GRID - (2 + 14)) / 2 * unit
    dx = (GRID - (3 + 13)) / 2 * unit
    for x0, y0, x1, y1 in BARS:
        d.rectangle((off + dx + x0 * unit, off + dy + y0 * unit,
                     off + dx + x1 * unit - 1, off + dy + y1 * unit - 1), fill=BLUE)
    return img.resize((size, size), Image.LANCZOS)


def main() -> None:
    targets = {
        "icon-192.png": draw(192, scale=0.9, rounded=True),
        "icon-512.png": draw(512, scale=0.9, rounded=True),
        # 막대 묶음(10x12칸)의 대각선 절반이 안전 반지름(0.4) 안에 들도록 0.8
        "icon-maskable-512.png": draw(512, scale=0.8, rounded=False),
        "apple-touch-icon.png": draw(180, scale=0.85, rounded=False),
    }
    for name, img in targets.items():
        img.save(OUT / name, optimize=True)
        print("wrote", OUT / name)


if __name__ == "__main__":
    main()
