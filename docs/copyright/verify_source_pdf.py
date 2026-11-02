#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
GOAT 潮玩交易平台 / GOAT 潮玩数据采集
Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
Author: Aloysius Luo

软著源程序 PDF 自动校对（生成后跑一次，替代肉眼逐页翻）。

查四件事：
1. 页数 = 60（前 30 + 后 30）；
2. 每页都带页眉与「第 N 页 / 共 60 页」，且页码严格连续；
3. 每页代码行数 = 50（第 60 页允许不足，取决于源程序末尾）；
4. 每页首行/末行都能在 PDF 文本里找到 —— 证明没有被版心裁掉。

用法：python docs/copyright/verify_source_pdf.py
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

from pypdf import PdfReader

HERE = Path(__file__).resolve().parent
PDF = HERE / "GOAT-V1.0-源程序-前30页后30页.pdf"
HEAD_TXT = HERE / "source-head-30p.txt"
TAIL_TXT = HERE / "source-tail-30p.txt"

HEADER = "GOAT 潮玩交易平台 V1.0 源程序"
PAGE_LINES = 50
HEAD_PAGES = 30
TOTAL_PAGES = 60


def squash(text: str) -> str:
    return re.sub(r"\s+", "", text)


def main() -> int:
    for path in (PDF, HEAD_TXT, TAIL_TXT):
        if not path.exists():
            print(f"缺少文件：{path}（先跑 build_source_pdf.py）")
            return 1

    head = HEAD_TXT.read_text(encoding="utf-8").split("\n")
    tail = TAIL_TXT.read_text(encoding="utf-8").split("\n")
    expected = head + tail

    reader = PdfReader(str(PDF))
    problems: list[str] = []

    if len(reader.pages) != TOTAL_PAGES:
        problems.append(f"页数 {len(reader.pages)}，期望 {TOTAL_PAGES}")

    for index, page in enumerate(reader.pages):
        number = index + 1
        text = page.extract_text() or ""
        flat = squash(text)

        if squash(HEADER) not in flat:
            problems.append(f"第 {number} 页缺页眉")
        if squash(f"第{number}页/共{TOTAL_PAGES}页") not in flat:
            problems.append(f"第 {number} 页页码缺失或错位")

        chunk = expected[index * PAGE_LINES : (index + 1) * PAGE_LINES]
        filled = [line for line in chunk if line.strip()]
        if not filled:
            continue

        first, last = filled[0], filled[-1]
        if squash(first) not in flat:
            problems.append(f"第 {number} 页首行未出现在 PDF 中：{first[:40]}")
        if squash(last) not in flat:
            problems.append(f"第 {number} 页末行被裁掉或折行：{last[:40]}")

        # 空行在 PDF 里不产生文本对象，按「有内容的行」比对
        code_lines = len([line for line in text.split("\n") if line.strip()]) - 2  # 减页眉页脚
        if code_lines < len(filled):
            problems.append(f"第 {number} 页文字行 {code_lines}，少于应显示的 {len(filled)} 行")

        # 完整性：整页字符量不应明显少于源片段（防中间被吞）
        if len(flat) < len(squash("\n".join(chunk))) * 0.9:
            problems.append(f"第 {number} 页字符量偏少，可能有内容丢失")

    if problems:
        print(f"校对未通过（{len(problems)} 处）：")
        for item in problems[:40]:
            print("  -", item)
        return 1

    print(f"校对通过：{TOTAL_PAGES} 页 / 每页 {PAGE_LINES} 行 / 页眉页脚齐全 / 首末行完整")
    return 0


if __name__ == "__main__":
    sys.exit(main())
