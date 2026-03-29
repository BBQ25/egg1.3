#!/usr/bin/env python
"""Convert a .docx file to a standalone .html file.

This is a lightweight converter focused on template-style Word documents:
- extracts visible text from body/header/footer
- embeds linked images as data URIs
- preserves page size/margins from section properties when available
"""

from __future__ import annotations

import argparse
import base64
import html
import mimetypes
import posixpath
import zipfile
from dataclasses import dataclass
from pathlib import Path
from typing import Dict, Iterable, List, Optional
import xml.etree.ElementTree as ET


NS = {
    "w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main",
    "a": "http://schemas.openxmlformats.org/drawingml/2006/main",
    "r": "http://schemas.openxmlformats.org/officeDocument/2006/relationships",
    "rel": "http://schemas.openxmlformats.org/package/2006/relationships",
}


@dataclass
class PageLayout:
    width_in: float = 11.0
    height_in: float = 8.5
    margin_top_in: float = 1.0
    margin_right_in: float = 1.0
    margin_bottom_in: float = 1.0
    margin_left_in: float = 1.0


def twips_to_inches(value: Optional[str], fallback: float) -> float:
    if not value:
        return fallback
    try:
        return int(value) / 1440.0
    except ValueError:
        return fallback


def parse_xml(zf: zipfile.ZipFile, part_name: str) -> ET.Element:
    return ET.fromstring(zf.read(part_name))


def part_relationships(zf: zipfile.ZipFile, part_name: str) -> Dict[str, str]:
    rel_part = posixpath.join(
        posixpath.dirname(part_name),
        "_rels",
        posixpath.basename(part_name) + ".rels",
    )
    if rel_part not in zf.namelist():
        return {}

    root = parse_xml(zf, rel_part)
    rels: Dict[str, str] = {}
    for rel in root.findall("rel:Relationship", NS):
        rid = rel.attrib.get("Id")
        target = rel.attrib.get("Target")
        mode = rel.attrib.get("TargetMode")
        if not rid or not target or mode == "External":
            continue
        base = posixpath.dirname(part_name)
        rels[rid] = posixpath.normpath(posixpath.join(base, target))
    return rels


def parse_page_layout(document_root: ET.Element) -> PageLayout:
    layout = PageLayout()
    sect = document_root.find(".//w:sectPr", NS)
    if sect is None:
        return layout

    pg_sz = sect.find("w:pgSz", NS)
    if pg_sz is not None:
        w = twips_to_inches(pg_sz.attrib.get(f"{{{NS['w']}}}w"), layout.width_in)
        h = twips_to_inches(pg_sz.attrib.get(f"{{{NS['w']}}}h"), layout.height_in)
        orient = pg_sz.attrib.get(f"{{{NS['w']}}}orient", "")
        if orient == "landscape" and w < h:
            w, h = h, w
        layout.width_in = w
        layout.height_in = h

    pg_mar = sect.find("w:pgMar", NS)
    if pg_mar is not None:
        layout.margin_top_in = twips_to_inches(
            pg_mar.attrib.get(f"{{{NS['w']}}}top"), layout.margin_top_in
        )
        layout.margin_right_in = twips_to_inches(
            pg_mar.attrib.get(f"{{{NS['w']}}}right"), layout.margin_right_in
        )
        layout.margin_bottom_in = twips_to_inches(
            pg_mar.attrib.get(f"{{{NS['w']}}}bottom"), layout.margin_bottom_in
        )
        layout.margin_left_in = twips_to_inches(
            pg_mar.attrib.get(f"{{{NS['w']}}}left"), layout.margin_left_in
        )

    return layout


def paragraph_text(p: ET.Element) -> str:
    parts: List[str] = []

    def walk(node: ET.Element) -> None:
        tag = node.tag
        if tag == f"{{{NS['w']}}}t":
            parts.append(node.text or "")
        elif tag == f"{{{NS['w']}}}tab":
            parts.append("\t")
        elif tag == f"{{{NS['w']}}}br" or tag == f"{{{NS['w']}}}cr":
            parts.append("\n")
        for child in list(node):
            walk(child)

    walk(p)
    return "".join(parts)


def extract_text_lines(part_root: ET.Element) -> List[str]:
    lines: List[str] = []
    seen = set()
    para_tag = f"{{{NS['w']}}}p"
    for p in part_root.findall(".//w:p", NS):
        # Skip wrapper paragraphs that contain nested text-box paragraphs.
        if any(desc is not p for desc in p.iter(para_tag)):
            continue
        text = paragraph_text(p).strip()
        if not text:
            continue
        if text in seen:
            # Word's AlternateContent often duplicates visible text in fallback nodes.
            continue
        seen.add(text)
        lines.append(text)
    return lines


def image_to_data_uri(blob: bytes, filename: str) -> str:
    mime = mimetypes.guess_type(filename)[0] or "application/octet-stream"
    encoded = base64.b64encode(blob).decode("ascii")
    return f"data:{mime};base64,{encoded}"


def extract_images(
    zf: zipfile.ZipFile, part_root: ET.Element, rels: Dict[str, str]
) -> List[str]:
    images: List[str] = []
    seen = set()
    for blip in part_root.findall(".//a:blip", NS):
        rid = blip.attrib.get(f"{{{NS['r']}}}embed")
        if not rid or rid in seen:
            continue
        target = rels.get(rid)
        if not target or target not in zf.namelist():
            continue
        seen.add(rid)
        images.append(image_to_data_uri(zf.read(target), target))
    return images


def html_section(title: str, lines: Iterable[str], images: Iterable[str], css_class: str) -> str:
    line_html = "\n".join(f"<p>{html.escape(line)}</p>" for line in lines)
    image_html = "\n".join(
        f'<img src="{src}" alt="{html.escape(title)} image" loading="lazy" />' for src in images
    )
    if not line_html and not image_html:
        return ""
    return (
        f'<section class="{css_class}">\n'
        f"<h2>{html.escape(title)}</h2>\n"
        f'<div class="text-block">\n{line_html}\n</div>\n'
        f'<div class="image-strip">\n{image_html}\n</div>\n'
        f"</section>"
    )


def convert_docx_to_html(docx_path: Path, out_path: Path) -> None:
    with zipfile.ZipFile(docx_path, "r") as zf:
        document_part = "word/document.xml"
        document_root = parse_xml(zf, document_part)
        doc_rels = part_relationships(zf, document_part)
        layout = parse_page_layout(document_root)

        body_node = document_root.find("w:body", NS)
        body_lines = extract_text_lines(body_node if body_node is not None else document_root)
        body_images = extract_images(zf, document_root, doc_rels)

        header_lines: List[str] = []
        header_images: List[str] = []
        footer_lines: List[str] = []
        footer_images: List[str] = []

        for ref in document_root.findall(".//w:headerReference", NS):
            rid = ref.attrib.get(f"{{{NS['r']}}}id")
            part = doc_rels.get(rid or "")
            if not part or part not in zf.namelist():
                continue
            root = parse_xml(zf, part)
            rels = part_relationships(zf, part)
            header_lines.extend(extract_text_lines(root))
            header_images.extend(extract_images(zf, root, rels))

        for ref in document_root.findall(".//w:footerReference", NS):
            rid = ref.attrib.get(f"{{{NS['r']}}}id")
            part = doc_rels.get(rid or "")
            if not part or part not in zf.namelist():
                continue
            root = parse_xml(zf, part)
            rels = part_relationships(zf, part)
            footer_lines.extend(extract_text_lines(root))
            footer_images.extend(extract_images(zf, root, rels))

    # De-duplicate while preserving order.
    def dedupe(items: List[str]) -> List[str]:
        seen = set()
        out: List[str] = []
        for item in items:
            if item in seen:
                continue
            seen.add(item)
            out.append(item)
        return out

    header_lines = dedupe(header_lines)
    header_images = dedupe(header_images)
    body_lines = dedupe(body_lines)
    body_images = dedupe(body_images)
    footer_lines = dedupe(footer_lines)
    footer_images = dedupe(footer_images)

    title = docx_path.stem
    page_width = f"{layout.width_in:.3f}in"
    page_height = f"{layout.height_in:.3f}in"
    content_width = max(layout.width_in - layout.margin_left_in - layout.margin_right_in, 1.0)
    content_width_css = f"{content_width:.3f}in"

    header_html = html_section("Header", header_lines, header_images, "doc-header")
    body_html = html_section("Body", body_lines, body_images, "doc-body")
    footer_html = html_section("Footer", footer_lines, footer_images, "doc-footer")

    if not body_html:
        body_html = (
            '<section class="doc-body">\n'
            "<h2>Body</h2>\n"
            '<div class="text-block"><p>(No body text found in this template.)</p></div>\n'
            "</section>"
        )

    html_out = f"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{html.escape(title)}</title>
  <style>
    :root {{
      --page-width: {page_width};
      --page-height: {page_height};
      --content-width: {content_width_css};
    }}
    * {{ box-sizing: border-box; }}
    body {{
      margin: 0;
      padding: 2rem 1rem;
      background: #e9edf2;
      color: #1a1a1a;
      font-family: "Segoe UI", Tahoma, Arial, sans-serif;
    }}
    .page {{
      width: min(var(--page-width), 100%);
      min-height: var(--page-height);
      margin: 0 auto;
      padding: 1rem;
      background: #fff;
      border: 1px solid #d0d7de;
      box-shadow: 0 8px 28px rgba(0, 0, 0, 0.1);
    }}
    .page-inner {{
      width: min(var(--content-width), 100%);
      margin: 0 auto;
    }}
    section {{
      margin: 0 0 1.25rem 0;
      padding: 0.75rem 0.9rem;
      border: 1px solid #dbe2ea;
      border-radius: 8px;
      background: #fafcff;
    }}
    h1 {{
      margin: 0 0 1rem 0;
      font-size: 1.3rem;
      font-weight: 700;
    }}
    h2 {{
      margin: 0 0 0.6rem 0;
      font-size: 1rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #24456d;
    }}
    .text-block p {{
      margin: 0 0 0.5rem 0;
      line-height: 1.35;
      white-space: pre-wrap;
    }}
    .image-strip {{
      display: flex;
      flex-wrap: wrap;
      gap: 0.6rem;
      margin-top: 0.55rem;
    }}
    .image-strip img {{
      max-height: 92px;
      max-width: 100%;
      border: 1px solid #d6dce3;
      border-radius: 6px;
      background: #fff;
      padding: 0.2rem;
    }}
  </style>
</head>
<body>
  <div class="page">
    <div class="page-inner">
      <h1>{html.escape(title)}</h1>
      {header_html}
      {body_html}
      {footer_html}
    </div>
  </div>
</body>
</html>
"""

    out_path.write_text(html_out, encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser(description="Convert a DOCX template to standalone HTML.")
    parser.add_argument("docx", type=Path, help="Input .docx path")
    parser.add_argument(
        "-o",
        "--output",
        type=Path,
        help="Output .html path (default: same directory, same filename)",
    )
    args = parser.parse_args()

    docx = args.docx.resolve()
    if not docx.exists():
        raise SystemExit(f"Input file not found: {docx}")
    if docx.suffix.lower() != ".docx":
        raise SystemExit(f"Input must be a .docx file: {docx}")

    output = args.output.resolve() if args.output else docx.with_suffix(".html")
    convert_docx_to_html(docx, output)
    print(output)


if __name__ == "__main__":
    main()
