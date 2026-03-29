#!/usr/bin/env python3
from __future__ import annotations

import re
import sys
from pathlib import Path
from urllib.parse import urljoin, urlparse
from urllib.request import Request, urlopen

BASE_TEMPLATE_URL = "https://demos.themeselection.com/sneat-bootstrap-html-admin-template/html/vertical-menu-template/"
BASE_ASSETS_URL = "https://demos.themeselection.com/sneat-bootstrap-html-admin-template/assets/"

ROOT = Path(r"c:\laragon\www\sumacot\egg1.3")
PAGES_DIR = ROOT / "public" / "vendor" / "sneat" / "html" / "vertical-menu-template"
ASSETS_DIR = ROOT / "public" / "vendor" / "sneat" / "assets"

# Inclusive range from index to maps-leaflet as requested
PAGES = [
    "index.html",
    "dashboards-analytics.html",
    "dashboards-crm.html",
    "app-ecommerce-dashboard.html",
    "app-logistics-dashboard.html",
    "app-academy-dashboard.html",
    "layouts-collapsed-menu.html",
    "layouts-content-navbar.html",
    "layouts-content-navbar-with-sidebar.html",
    "layouts-without-menu.html",
    "layouts-without-navbar.html",
    "layouts-fluid.html",
    "layouts-container.html",
    "layouts-blank.html",
    "app-email.html",
    "app-chat.html",
    "app-calendar.html",
    "app-kanban.html",
    "app-ecommerce-product-list.html",
    "app-ecommerce-product-add.html",
    "app-ecommerce-category-list.html",
    "app-ecommerce-order-list.html",
    "app-ecommerce-order-details.html",
    "app-ecommerce-customer-all.html",
    "app-ecommerce-customer-details-overview.html",
    "app-ecommerce-customer-details-security.html",
    "app-ecommerce-customer-details-billing.html",
    "app-ecommerce-customer-details-notifications.html",
    "app-ecommerce-manage-reviews.html",
    "app-ecommerce-referral.html",
    "app-ecommerce-settings-detail.html",
    "app-ecommerce-settings-payments.html",
    "app-ecommerce-settings-checkout.html",
    "app-ecommerce-settings-shipping.html",
    "app-ecommerce-settings-locations.html",
    "app-ecommerce-settings-notifications.html",
    "app-academy-course.html",
    "app-academy-course-details.html",
    "app-logistics-fleet.html",
    "app-invoice-list.html",
    "app-invoice-preview.html",
    "app-invoice-edit.html",
    "app-invoice-add.html",
    "app-user-list.html",
    "app-user-view-account.html",
    "app-user-view-security.html",
    "app-user-view-billing.html",
    "app-user-view-notifications.html",
    "app-user-view-connections.html",
    "app-access-roles.html",
    "app-access-permission.html",
    "pages-profile-user.html",
    "pages-profile-teams.html",
    "pages-profile-projects.html",
    "pages-profile-connections.html",
    "pages-account-settings-account.html",
    "pages-account-settings-security.html",
    "pages-account-settings-billing.html",
    "pages-account-settings-notifications.html",
    "pages-account-settings-connections.html",
    "pages-faq.html",
    "pages-pricing.html",
    "pages-misc-error.html",
    "pages-misc-under-maintenance.html",
    "pages-misc-comingsoon.html",
    "pages-misc-not-authorized.html",
    "auth-login-basic.html",
    "auth-login-cover.html",
    "auth-register-basic.html",
    "auth-register-cover.html",
    "auth-register-multisteps.html",
    "auth-verify-email-basic.html",
    "auth-verify-email-cover.html",
    "auth-reset-password-basic.html",
    "auth-reset-password-cover.html",
    "auth-forgot-password-basic.html",
    "auth-forgot-password-cover.html",
    "auth-two-steps-basic.html",
    "auth-two-steps-cover.html",
    "wizard-ex-checkout.html",
    "wizard-ex-property-listing.html",
    "wizard-ex-create-deal.html",
    "modal-examples.html",
    "cards-basic.html",
    "cards-advance.html",
    "cards-statistics.html",
    "cards-analytics.html",
    "cards-gamifications.html",
    "cards-actions.html",
    "ui-accordion.html",
    "ui-alerts.html",
    "ui-badges.html",
    "ui-buttons.html",
    "ui-carousel.html",
    "ui-collapse.html",
    "ui-dropdowns.html",
    "ui-footer.html",
    "ui-list-groups.html",
    "ui-modals.html",
    "ui-navbar.html",
    "ui-offcanvas.html",
    "ui-pagination-breadcrumbs.html",
    "ui-progress.html",
    "ui-spinners.html",
    "ui-tabs-pills.html",
    "ui-toasts.html",
    "ui-tooltips-popovers.html",
    "ui-typography.html",
    "extended-ui-avatar.html",
    "extended-ui-blockui.html",
    "extended-ui-drag-and-drop.html",
    "extended-ui-media-player.html",
    "extended-ui-perfect-scrollbar.html",
    "extended-ui-star-ratings.html",
    "extended-ui-sweetalert2.html",
    "extended-ui-text-divider.html",
    "extended-ui-timeline-basic.html",
    "extended-ui-timeline-fullscreen.html",
    "extended-ui-tour.html",
    "extended-ui-treeview.html",
    "extended-ui-misc.html",
    "icons-boxicons.html",
    "icons-font-awesome.html",
    "forms-basic-inputs.html",
    "forms-input-groups.html",
    "forms-custom-options.html",
    "forms-editors.html",
    "forms-file-upload.html",
    "forms-pickers.html",
    "forms-selects.html",
    "forms-sliders.html",
    "forms-switches.html",
    "forms-extras.html",
    "form-layouts-vertical.html",
    "form-layouts-horizontal.html",
    "form-layouts-sticky.html",
    "form-wizard-numbered.html",
    "form-wizard-icons.html",
    "form-validation.html",
    "tables-basic.html",
    "tables-datatables-basic.html",
    "tables-datatables-advanced.html",
    "tables-datatables-extensions.html",
    "charts-apex.html",
    "charts-chartjs.html",
    "maps-leaflet.html",
]

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36"
}


def fetch_bytes(url: str) -> bytes:
    req = Request(url, headers=HEADERS)
    with urlopen(req, timeout=60) as resp:
        return resp.read()


def fetch_text(url: str) -> str:
    data = fetch_bytes(url)
    return data.decode("utf-8", errors="replace")


def ensure_parent(path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)


def write_text(path: Path, text: str) -> None:
    ensure_parent(path)
    path.write_text(text, encoding="utf-8", newline="\n")


def write_bytes(path: Path, data: bytes) -> None:
    ensure_parent(path)
    path.write_bytes(data)


def to_asset_rel(url_or_path: str) -> str | None:
    p = url_or_path.strip().strip('"\'')
    if not p or p.startswith("data:") or p.startswith("javascript:"):
        return None
    if p.startswith("http://") or p.startswith("https://"):
        u = urlparse(p)
        marker = "/assets/"
        if marker not in u.path:
            return None
        rel = u.path.split(marker, 1)[1]
        return rel.lstrip("/")
    if p.startswith("../../assets/"):
        return p[len("../../assets/"):]
    if p.startswith("/assets/"):
        return p[len("/assets/"):]
    return None


def normalize_ref(url: str) -> str:
    return url.split("#", 1)[0].split("?", 1)[0]


def collect_asset_refs_from_html(html: str) -> set[str]:
    refs: set[str] = set()
    for m in re.finditer(r"(?:href|src)\s*=\s*\"([^\"]+)\"", html, flags=re.IGNORECASE):
        rel = to_asset_rel(m.group(1))
        if rel:
            refs.add(normalize_ref(rel))
    for m in re.finditer(r"(?:href|src)\s*=\s*'([^']+)'", html, flags=re.IGNORECASE):
        rel = to_asset_rel(m.group(1))
        if rel:
            refs.add(normalize_ref(rel))
    return refs


def localize_html(html: str) -> str:
    # Remove GTM script + noscript blocks to keep runtime local
    html = re.sub(r"\s*<!-- \? PROD Only: Google Tag Manager.*?<!-- End Google Tag Manager -->\s*", "\n", html, flags=re.DOTALL)
    html = re.sub(r"\s*<!-- \?PROD Only: Google Tag Manager \(noscript\).*?<!-- End Google Tag Manager \(noscript\) -->\s*", "\n", html, flags=re.DOTALL)

    # Local favicon
    html = re.sub(
        r"<link\s+rel=\"icon\"\s+type=\"image/x-icon\"\s+href=\"[^\"]*\"\s*/>",
        '<link rel="icon" type="image/png" href="../../assets/img/logo.png?v=20260220" />',
        html,
        flags=re.IGNORECASE,
    )

    # Replace Google Fonts/preconnect with local Figtree
    html = re.sub(r"\s*<link\s+rel=\"preconnect\"\s+href=\"https://fonts\.googleapis\.com\"\s*/>\s*", "\n", html, flags=re.IGNORECASE)
    html = re.sub(r"\s*<link\s+rel=\"preconnect\"\s+href=\"https://fonts\.gstatic\.com\"\s+crossorigin\s*/>\s*", "\n", html, flags=re.IGNORECASE)
    html = re.sub(r"\s*<link\s+href=\"https://fonts\.googleapis\.com[^\"]+\"\s+rel=\"stylesheet\"\s*/>\s*", "\n", html, flags=re.IGNORECASE)
    html = re.sub(r"\s*<link\s+rel=\"stylesheet\"\s+href=\"\.\./\.\./fonts/figtree\.css\"\s*/>\s*", "\n", html, flags=re.IGNORECASE)

    # Ensure brand.css is loaded
    if "../../assets/css/brand.css" not in html:
        html = html.replace(
            '<link rel="stylesheet" href="../../assets/css/demo.css" />',
            '<link rel="stylesheet" href="../../assets/css/demo.css" />\n    <link rel="stylesheet" href="../../assets/css/brand.css" />',
        )

    # Insert Figtree after core/demo/theme CSS so its CSS vars override template defaults.
    if "../../fonts/figtree.css" not in html:
        figtree_link = '    <link rel="stylesheet" href="../../fonts/figtree.css" />\n'
        if "<!-- Helpers -->" in html:
            html = html.replace("    <!-- Helpers -->", figtree_link + "    <!-- Helpers -->", 1)
        elif "</head>" in html:
            html = html.replace("</head>", figtree_link + "</head>", 1)

    # Replace Sneat inline brand logo block with local logo image
    html = re.sub(
        r"<span\s+class=\"app-brand-logo\s+demo\">[\s\S]*?</span>",
        '<span class="app-brand-logo demo">\n        <img src="../../assets/img/logo.png" alt="APEWSD logo" class="app-brand-logo-img" />\n      </span>',
        html,
        count=1,
        flags=re.IGNORECASE,
    )
    html = re.sub(r"</span>\s*</span>\s*(<span class=\"app-brand-text)", r"</span>\n      \1", html, flags=re.IGNORECASE)

    # Replace brand text label where present
    html = re.sub(r">\s*Sneat\s*<", ">APEWSD<", html)

    # Keep landing on local dashboard variant from mirrored pages
    html = html.replace('href="index.html" class="app-brand-link"', 'href="app-ecommerce-dashboard.html" class="app-brand-link"')

    return html


def download_asset(asset_rel: str) -> bytes:
    url = urljoin(BASE_ASSETS_URL, asset_rel)
    return fetch_bytes(url)


def parse_css_refs(content: str) -> list[str]:
    refs: list[str] = []
    for m in re.finditer(r"url\(([^)]+)\)", content, flags=re.IGNORECASE):
        raw = m.group(1).strip().strip('"\'')
        if not raw or raw.startswith("data:") or raw.startswith("#") or raw.startswith("http://") or raw.startswith("https://"):
            continue
        refs.append(raw)
    for m in re.finditer(r"@import\s+(?:url\()?['\"]([^'\")]+)['\"]\)?", content, flags=re.IGNORECASE):
        raw = m.group(1).strip()
        if not raw or raw.startswith("http://") or raw.startswith("https://"):
            continue
        refs.append(raw)
    return refs


def resolve_asset_rel(current_asset_rel: str, ref: str) -> str | None:
    ref = ref.split("#", 1)[0].split("?", 1)[0]
    if not ref:
        return None
    if ref.startswith("/assets/"):
        return ref[len("/assets/"):]

    current_dir = Path(current_asset_rel).parent
    resolved = (current_dir / ref).resolve().as_posix()

    # Windows Path.resolve() goes absolute with drive if used directly; do manual normalize instead
    parts = []
    for part in (current_dir / ref).as_posix().split("/"):
        if part in ("", "."):
            continue
        if part == "..":
            if parts:
                parts.pop()
            continue
        parts.append(part)
    rel = "/".join(parts)
    return rel if rel else None


def main() -> int:
    PAGES_DIR.mkdir(parents=True, exist_ok=True)

    all_asset_refs: set[str] = set()

    print(f"Mirroring {len(PAGES)} pages...")
    for i, page in enumerate(PAGES, start=1):
        url = urljoin(BASE_TEMPLATE_URL, page)
        html = fetch_text(url)
        localized = localize_html(html)
        write_text(PAGES_DIR / page, localized)
        all_asset_refs.update(collect_asset_refs_from_html(localized))
        print(f"[{i:03d}/{len(PAGES)}] {page}")

    # Always include key shared assets we rely on
    all_asset_refs.update(
        {
            "css/brand.css",
            "img/logo.png",
            "img/favicon/favicon.ico",
        }
    )

    # Download direct asset refs from HTML
    downloaded: set[str] = set()
    css_queue: list[str] = []

    print(f"Downloading referenced assets from HTML ({len(all_asset_refs)})...")
    for idx, rel in enumerate(sorted(all_asset_refs), start=1):
        rel_norm = rel.lstrip("/")
        local_path = ASSETS_DIR / rel_norm
        if local_path.exists() and local_path.stat().st_size > 0:
            downloaded.add(rel_norm)
            if rel_norm.endswith(".css"):
                css_queue.append(rel_norm)
            continue
        data = download_asset(rel_norm)
        write_bytes(local_path, data)
        downloaded.add(rel_norm)
        if rel_norm.endswith(".css"):
            css_queue.append(rel_norm)
        if idx % 50 == 0:
            print(f"  assets: {idx}/{len(all_asset_refs)}")

    # Recursive CSS asset fetch (fonts/images/imports)
    print("Resolving nested CSS assets...")
    seen_css: set[str] = set()
    while css_queue:
        css_rel = css_queue.pop(0)
        if css_rel in seen_css:
            continue
        seen_css.add(css_rel)

        css_path = ASSETS_DIR / css_rel
        if not css_path.exists():
            continue
        content = css_path.read_text(encoding="utf-8", errors="replace")
        for ref in parse_css_refs(content):
            nested_rel = resolve_asset_rel(css_rel, ref)
            if not nested_rel:
                continue
            if nested_rel in downloaded:
                continue
            local_nested = ASSETS_DIR / nested_rel
            if local_nested.exists() and local_nested.stat().st_size > 0:
                downloaded.add(nested_rel)
                if nested_rel.endswith(".css"):
                    css_queue.append(nested_rel)
                continue
            try:
                data = download_asset(nested_rel)
            except Exception:
                # skip optional assets gracefully
                continue
            write_bytes(local_nested, data)
            downloaded.add(nested_rel)
            if nested_rel.endswith(".css"):
                css_queue.append(nested_rel)

    print(f"Done. Pages: {len(PAGES)}, Assets downloaded/verified: {len(downloaded)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
