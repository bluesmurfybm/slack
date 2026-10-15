"""업무 시스템 탭 파비콘 생성기 — 포털 카드의 색 배지를 그대로 줄인 것

    python dev/gen_favicons.py

core/worksystems.json 의 key·color 와 styles/icons/<key>.svg(카드 아이콘)를 합쳐
styles/favicons/<key>.svg 를 만든다. 서비스 색 둥근 사각 위에 흰 아이콘이고, 16px 에서
선이 뭉개지지 않게 카드(2)보다 굵게(2.6) 그린다. 포털 루트는 지금의 favicon.ico 를 그대로 쓴다.

시스템을 더하거나 색·아이콘을 바꾸면 다시 돌린다. 결과 SVG 는 커밋한다.
아이콘 파일이 없는 key 는 건너뛰고 알린다.
"""
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ICONS = ROOT / "styles" / "icons"
OUT = ROOT / "styles" / "favicons"

TEMPLATE = (
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
    '<rect width="32" height="32" rx="8" fill="{color}"/>'
    '<g transform="translate(5 5) scale(.9167)" fill="none" stroke="#fff" stroke-width="2.6"'
    ' stroke-linecap="round" stroke-linejoin="round">{inner}</g></svg>\n'
)


def inner(svg: str) -> str:
    return re.sub(r"</svg>\s*$", "", re.sub(r"^<svg[^>]*>", "", svg.strip()))


def main() -> None:
    systems = json.loads((ROOT / "core" / "worksystems.json").read_text(encoding="utf-8"))
    OUT.mkdir(exist_ok=True)
    for sys in systems:
        icon = ICONS / f"{sys['key']}.svg"
        if not icon.exists():
            print("skip", sys["key"], "— 아이콘 없음:", icon)
            continue
        out = OUT / f"{sys['key']}.svg"
        out.write_text(TEMPLATE.format(color=sys["color"], inner=inner(icon.read_text(encoding="utf-8"))), encoding="utf-8")
        print("wrote", out)


if __name__ == "__main__":
    main()
