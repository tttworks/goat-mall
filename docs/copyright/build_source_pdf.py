#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
GOAT 潮玩交易平台 / GOAT 潮玩数据采集
Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
Author: Aloysius Luo

软著源程序 PDF 生成器（前 30 页 + 后 30 页，每页 50 行，页码连续 1–60）。

为什么不是「导出全部再截前 30 页」：
- 软著要求「前 30 页 + 后 30 页」，后 30 页必须是**整份源程序的末尾**，不是前 1500 行的尾部；
- 所以先算全文总页数，再从 `(总页数 - 30)` 起截，页码重排为 31–60 连续。

排版口径（与申报要求一致）：
- A4 / 单栏 / 等宽 8pt / 每页固定 50 行；
- 页眉「GOAT 潮玩交易平台 V1.0 源程序」，页脚「第 N 页 / 共 60 页」；
- 超宽行按显示宽度折行（CJK 记 2 个单位），保证「一行就是一行」，不会挤爆版心。

依赖：仅 Python 3 标准库 + 本机 Edge/Chrome（headless 打印）。
用法：python docs/copyright/build_source_pdf.py
"""

from __future__ import annotations

import html
import math
import os
import re
import subprocess
import sys
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BACKEND = ROOT / "backend"
OUT_DIR = Path(__file__).resolve().parent

HEADER = "GOAT 潮玩交易平台 V1.0 源程序"
PAGE_LINES = 50
LINE_UNITS = 100  # 版心宽度（ASCII 记 1，CJK 记 2）
HEAD_PAGES = 30
TAIL_PAGES = 30
TOTAL_PAGES = HEAD_PAGES + TAIL_PAGES

BROWSERS = [
    r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
]


def collect_files() -> list[Path]:
    """按「接口 → 业务 → 数据」排序，保证评审从头翻就能看懂系统边界。"""
    api_root = BACKEND / "app" / "Http" / "Controllers" / "Api"
    api_files = sorted(api_root.rglob("*.php"))

    files: list[Path] = [BACKEND / "routes" / "api.php"]
    # 站点对外接口在前，后台（Admin）接口紧随其后
    files += [p for p in api_files if "Admin" not in p.parts]
    files += [p for p in api_files if "Admin" in p.parts]
    files += sorted((BACKEND / "app" / "Services").rglob("*.php"))

    for name in ("Models", "Enums", "Exceptions"):
        files += sorted((BACKEND / "app" / name).rglob("*.php"))

    files += sorted((BACKEND / "database" / "migrations").glob("*.php"))

    # 其余 app/ 目录（Requests / Middleware / Resources / Support / Events / Providers）
    used = {"Controllers", "Services", "Models", "Enums", "Exceptions"}
    for child in sorted((BACKEND / "app").iterdir()):
        if child.is_dir() and child.name not in used:
            files += sorted(child.rglob("*.php"))
        elif child.is_file() and child.suffix == ".php":
            files.append(child)

    files += [BACKEND / "routes" / "channels.php", BACKEND / "routes" / "web.php", BACKEND / "routes" / "console.php"]
    files.append(BACKEND / "public" / "index.php")

    seen: set[Path] = set()
    ordered: list[Path] = []
    for path in files:
        resolved = path.resolve()
        if resolved in seen or not path.exists():
            continue
        seen.add(resolved)
        ordered.append(path)
    return ordered


def char_units(ch: str) -> int:
    return 2 if unicodedata.east_asian_width(ch) in ("W", "F") else 1


def wrap_line(line: str, budget: int = LINE_UNITS) -> list[str]:
    """按显示宽度折行：宁可多一行，也不让 PDF 里出现被裁掉的半行代码。"""
    line = line.replace("\t", "    ").rstrip()
    if not line:
        return [""]

    chunks: list[str] = []
    buf: list[str] = []
    used = 0
    for ch in line:
        w = char_units(ch)
        if used + w > budget and buf:
            chunks.append("".join(buf))
            buf, used = [], 0
        buf.append(ch)
        used += w
    if buf:
        chunks.append("".join(buf))
    return chunks


def build_lines(files: list[Path]) -> list[str]:
    out: list[str] = []
    for path in files:
        text = path.read_text(encoding="utf-8", errors="replace")
        for raw in text.splitlines():
            out.extend(wrap_line(raw))
    return out


def page_html(number: int, lines: list[str]) -> str:
    # 必须转义：PHP 源码开头的 `<?php` 在 HTML 里会被解析成 bogus comment，
    # 一路吞到下一个 `>` —— 实测第 1 页会凭空少掉 40 行。转义后渲染结果完全一致。
    body = html.escape("\n".join(lines) if lines else " ", quote=False)
    return (
        '<section class="page">'
        f'<div class="head">{HEADER}</div>'
        f'<pre class="code">{body}</pre>'
        f'<div class="foot">第 {number} 页 / 共 {TOTAL_PAGES} 页</div>'
        "</section>"
    )


CSS = f"""
@page {{ size: A4; margin: 0; }}
html, body {{ margin: 0; padding: 0; background: #fff; }}
.page {{
  position: relative;
  width: 210mm;
  height: 297mm;
  box-sizing: border-box;
  padding: 18mm 15mm 15mm 15mm;
  page-break-after: always;
  break-after: page;
  overflow: hidden;
}}
.page:last-child {{ page-break-after: auto; break-after: auto; }}
.head {{
  position: absolute;
  top: 7mm;
  left: 15mm;
  right: 15mm;
  height: 7mm;
  line-height: 7mm;
  text-align: center;
  font-family: "Microsoft YaHei", "SimSun", sans-serif;
  font-size: 9pt;
  color: #333;
  border-bottom: 0.4pt solid #999;
}}
.foot {{
  position: absolute;
  bottom: 7mm;
  left: 15mm;
  right: 15mm;
  height: 6mm;
  line-height: 6mm;
  text-align: center;
  font-family: "Microsoft YaHei", "SimSun", sans-serif;
  font-size: 9pt;
  color: #333;
}}
pre.code {{
  margin: 0;
  font-family: Consolas, "Courier New", NSimSun, monospace;
  font-size: 8pt;
  line-height: 5.2mm;
  white-space: pre;
  color: #000;
}}
"""


def find_browser() -> str:
    for candidate in BROWSERS:
        if os.path.exists(candidate):
            return candidate
    raise SystemExit("未找到 Edge/Chrome，无法打印 PDF")


def print_pdf(html_path: Path, pdf_path: Path) -> None:
    browser = find_browser()
    command = [
        browser,
        "--headless=new",
        "--disable-gpu",
        "--disable-extensions",
        "--no-pdf-header-footer",
        "--run-all-compositor-stages-before-draw",
        f"--print-to-pdf={pdf_path}",
        html_path.as_uri(),
    ]
    if pdf_path.exists():
        pdf_path.unlink()

    result = subprocess.run(
        command,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=180,
    )
    if not pdf_path.exists():
        sys.stderr.write(result.stdout + result.stderr)
        raise SystemExit("PDF 生成失败（浏览器未输出文件）")


def pdf_page_count(pdf_path: Path) -> int:
    """不引第三方库：直接数 Chromium 写出的页面对象。"""
    data = pdf_path.read_bytes()
    return len(re.findall(rb"/Type\s*/Page[^s]", data))


def main() -> None:
    OUT_DIR.mkdir(parents=True, exist_ok=True)

    files = collect_files()
    lines = build_lines(files)
    total_pages = math.ceil(len(lines) / PAGE_LINES)

    if total_pages <= TOTAL_PAGES:
        raise SystemExit(f"源程序仅 {total_pages} 页（{len(lines)} 行），不足 60 页，按「全部提交」处理")

    head = lines[: HEAD_PAGES * PAGE_LINES]
    tail_start = (total_pages - TAIL_PAGES) * PAGE_LINES
    tail = lines[tail_start:]

    pages: list[str] = []
    for index in range(HEAD_PAGES):
        pages.append(page_html(index + 1, head[index * PAGE_LINES : (index + 1) * PAGE_LINES]))
    for index in range(TAIL_PAGES):
        pages.append(page_html(HEAD_PAGES + index + 1, tail[index * PAGE_LINES : (index + 1) * PAGE_LINES]))

    html_path = OUT_DIR / "source-60p.html"
    html_path.write_text(
        "<!doctype html><html lang=\"zh-CN\"><head><meta charset=\"utf-8\">"
        f"<title>{HEADER}</title><style>{CSS}</style></head><body>"
        + "".join(pages)
        + "</body></html>",
        encoding="utf-8",
    )

    # 中间产物：便于人工抽查「第 30 页最后一行」与「第 31 页第一行」是否接得上
    (OUT_DIR / "source-all.txt").write_text("\n".join(lines), encoding="utf-8")
    (OUT_DIR / "source-head-30p.txt").write_text("\n".join(head), encoding="utf-8")
    (OUT_DIR / "source-tail-30p.txt").write_text("\n".join(tail), encoding="utf-8")

    pdf_path = OUT_DIR / "GOAT-V1.0-源程序-前30页后30页.pdf"
    print_pdf(html_path, pdf_path)

    pages_in_pdf = pdf_page_count(pdf_path)
    print(f"源文件      : {len(files)} 个")
    print(f"折行后总行数: {len(lines)}")
    print(f"全文页数    : {total_pages}")
    print(f"PDF 页数    : {pages_in_pdf}（期望 {TOTAL_PAGES}）")
    print(f"第 30 页末行: {head[-1][:80]}")
    print(f"第 31 页首行（后 30 页起点）: {tail[0][:80]}")
    print(f"输出        : {pdf_path}")


if __name__ == "__main__":
    main()
