#!/usr/bin/env python3
"""
Generate a colorful SVG folder tree for the current project.

Usage:
  python tools/generate_tree_svg.py --root . --output docs/project-tree-palette.svg --max-depth 3
"""

from __future__ import annotations

import argparse
import html
import os
from pathlib import Path
from typing import Iterable, List, Sequence, Tuple


EXCLUDE_DIRS = {
    ".git",
    ".idea",
    ".vscode",
    "__pycache__",
    "node_modules",
    "vendor",
}


def sorted_entries(path: Path) -> List[Path]:
    entries = list(path.iterdir())
    entries.sort(key=lambda p: (not p.is_dir(), p.name.lower()))
    return entries


def collect_tree_lines(root: Path, max_depth: int, max_items_per_dir: int) -> List[Tuple[str, int, bool]]:
    lines: List[Tuple[str, int, bool]] = []
    lines.append((f"{root.name}/", 0, True))

    def walk(directory: Path, prefix: str, depth: int) -> None:
        if depth > max_depth:
            return

        try:
            entries = sorted_entries(directory)
        except Exception:
            return

        if depth == max_depth and entries:
            shown = [e for e in entries if not (e.is_dir() and e.name in EXCLUDE_DIRS)]
            hidden_count = max(0, len(shown))
            lines.append((f"{prefix}└── ... ({hidden_count} items)", depth + 1, False))
            return

        filtered: List[Path] = []
        for entry in entries:
            if entry.is_dir() and entry.name in EXCLUDE_DIRS:
                continue
            filtered.append(entry)

        omitted_count = 0
        if max_items_per_dir > 0 and len(filtered) > max_items_per_dir:
            omitted_count = len(filtered) - max_items_per_dir
            filtered = filtered[:max_items_per_dir]

        for idx, entry in enumerate(filtered):
            is_last = idx == len(filtered) - 1
            connector = "└── " if is_last else "├── "
            line_prefix = prefix + connector
            is_dir = entry.is_dir()
            label = entry.name + ("/" if is_dir else "")
            lines.append((line_prefix + label, depth + 1, is_dir))
            if is_dir:
                extension = "    " if is_last else "│   "
                walk(entry, prefix + extension, depth + 1)

        if omitted_count > 0:
            lines.append((prefix + f"└── ... ({omitted_count} more items)", depth + 1, False))

    walk(root, "", 0)
    return lines


def choose_color(depth: int, is_dir: bool) -> str:
    if is_dir:
        palette = [
            "#a5b4fc",
            "#93c5fd",
            "#6ee7b7",
            "#fcd34d",
            "#fca5a5",
            "#c4b5fd",
        ]
        return palette[depth % len(palette)]
    return "#e2e8f0"


def estimate_width(lines: Sequence[Tuple[str, int, bool]]) -> int:
    max_chars = max((len(text) for text, _, _ in lines), default=80)
    return max(1100, min(2200, max_chars * 9 + 120))


def render_svg(lines: Sequence[Tuple[str, int, bool]], output_path: Path, title: str) -> None:
    line_height = 24
    top_padding = 88
    bottom_padding = 36
    width = estimate_width(lines)
    height = max(420, top_padding + bottom_padding + line_height * len(lines))

    y = top_padding
    rows: List[str] = []
    for text, depth, is_dir in lines:
        safe_text = html.escape(text)
        color = choose_color(depth, is_dir)
        rows.append(
            f'<text x="48" y="{y}" fill="{color}" font-size="16" '
            f'font-family="Fira Code, Consolas, Menlo, monospace">{safe_text}</text>'
        )
        y += line_height

    svg = f"""<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}" viewBox="0 0 {width} {height}">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#0f172a"/>
      <stop offset="50%" stop-color="#1e293b"/>
      <stop offset="100%" stop-color="#0b1120"/>
    </linearGradient>
    <linearGradient id="accent" x1="0%" y1="0%" x2="100%" y2="0%">
      <stop offset="0%" stop-color="#818cf8"/>
      <stop offset="25%" stop-color="#38bdf8"/>
      <stop offset="50%" stop-color="#34d399"/>
      <stop offset="75%" stop-color="#fbbf24"/>
      <stop offset="100%" stop-color="#f87171"/>
    </linearGradient>
  </defs>

  <rect width="{width}" height="{height}" fill="url(#bg)"/>
  <rect x="24" y="24" width="{width - 48}" height="{height - 48}" rx="18" fill="#0b1224" fill-opacity="0.68" stroke="#334155" stroke-width="1.2"/>
  <rect x="24" y="24" width="{width - 48}" height="14" rx="7" fill="url(#accent)"/>

  <text x="46" y="66" fill="#f8fafc" font-size="26" font-family="Segoe UI, Inter, Arial, sans-serif" font-weight="700">{html.escape(title)}</text>
  <text x="46" y="86" fill="#94a3b8" font-size="13" font-family="Segoe UI, Inter, Arial, sans-serif">Palette View · Generated from live filesystem</text>

  {"".join(rows)}
</svg>
"""
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(svg, encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser(description="Generate colorful folder tree SVG.")
    parser.add_argument("--root", default=".", help="Root directory for tree generation.")
    parser.add_argument("--output", default="docs/project-tree-palette.svg", help="Output SVG file path.")
    parser.add_argument("--max-depth", type=int, default=3, help="Max directory depth to render.")
    parser.add_argument(
        "--max-items-per-dir",
        type=int,
        default=60,
        help="Maximum number of entries shown per directory before collapsing with ellipsis.",
    )
    parser.add_argument("--title", default="Doc-Ease Project Folder Tree", help="Image title.")
    args = parser.parse_args()

    root = Path(args.root).resolve()
    lines = collect_tree_lines(
        root,
        max_depth=max(1, args.max_depth),
        max_items_per_dir=max(1, args.max_items_per_dir),
    )
    render_svg(lines, output_path=Path(args.output), title=args.title)
    print(f"Generated: {args.output} ({len(lines)} lines)")


if __name__ == "__main__":
    main()
