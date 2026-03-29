import argparse
import concurrent.futures
import json
import os
import re
import threading
import time
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path
from typing import Any

import requests
from bs4 import BeautifulSoup


BASE_URL = "https://icons8.com"
ALL_DUSK_URL = f"{BASE_URL}/icons/all--style-dusk"
IMG_BASE_URL = "https://img.icons8.com/dusk/100"
SCRIPT_DIR = Path(__file__).resolve().parent
CATEGORY_INDEX_PATH = SCRIPT_DIR / "dusk_categories.json"
USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0 Safari/537.36"
HEADERS = {"User-Agent": USER_AGENT}


@dataclass(frozen=True)
class IconRecord:
    category_slug: str
    category_name: str
    subcategory_name: str
    icon_id: str
    name: str
    common_name: str
    is_animated: bool
    icon_url: str


class Downloader:
    def __init__(
        self,
        root: Path,
        max_workers: int = 24,
        retries: int = 3,
        request_timeout: int = 30,
        category_filter: set[str] | None = None,
    ) -> None:
        self.root = root
        self.max_workers = max_workers
        self.retries = retries
        self.request_timeout = request_timeout
        self.category_filter = category_filter or set()
        self.max_category_workers = max(1, min(8, max_workers // 4 or 1))
        self.session_local = threading.local()
        self.lock = threading.Lock()
        self.stats: dict[str, Any] = {
            "categories_total": 0,
            "categories_processed": 0,
            "icons_expected": 0,
            "png_downloaded": 0,
            "png_skipped": 0,
            "png_failed": 0,
            "gif_expected": 0,
            "gif_downloaded": 0,
            "gif_skipped": 0,
            "gif_failed": 0,
            "started_at": time.strftime("%Y-%m-%dT%H:%M:%S"),
            "category_reports": [],
        }

    def session(self) -> requests.Session:
        session = getattr(self.session_local, "session", None)
        if session is None:
            session = requests.Session()
            session.headers.update(HEADERS)
            self.session_local.session = session
        return session

    def fetch_html(self, url: str) -> str:
        last_error = None
        for attempt in range(1, self.retries + 1):
            try:
                response = self.session().get(url, timeout=self.request_timeout)
                response.raise_for_status()
                return response.text
            except Exception as exc:  # noqa: BLE001
                last_error = exc
                if attempt == self.retries:
                    break
                time.sleep(1.25 * attempt)
        raise RuntimeError(f"Failed to fetch {url}: {last_error}") from last_error

    def get_dusk_categories(self) -> list[tuple[str, str]]:
        if CATEGORY_INDEX_PATH.exists():
            payload = json.loads(CATEGORY_INDEX_PATH.read_text(encoding="utf-8"))
            return [(str(item["href"]), str(item["label"])) for item in payload]

        html = self.fetch_html(ALL_DUSK_URL)
        soup = BeautifulSoup(html, "html.parser")
        categories: dict[str, str] = {}

        for anchor in soup.select('a[href*="--style-dusk"]'):
            href = anchor.get("href", "").strip()
            match = re.match(r"^/icons/set/([^/?#]+)--style-dusk$", href)
            if not match:
                continue
            slug = match.group(1)
            label = " ".join(anchor.get_text(" ", strip=True).split()) or slug
            categories[href] = label

        category_items = sorted(
            [(href, label) for href, label in categories.items()],
            key=lambda item: item[0],
        )
        return category_items

    def parse_category_page(self, category_href: str) -> tuple[str, str, list[IconRecord]]:
        category_url = BASE_URL + category_href
        html = self.fetch_html(category_url)
        nuxt_json = self.extract_nuxt_json(html)
        page_key = f"search:{category_href}"
        page_data = nuxt_json.get(page_key, {})

        slug_match = re.match(r"^/icons/set/([^/?#]+)--style-dusk$", category_href)
        category_slug = slug_match.group(1) if slug_match else category_href.rsplit("/", 1)[-1]
        category_payload = page_data.get("categoryData") or {}
        category_data = (category_payload.get("category")) or {}

        records: list[IconRecord] = []

        if category_payload:
            category_name = str(category_data.get("name") or category_slug)
            category_code = str((category_payload.get("pack") or {}).get("code") or category_data.get("code") or category_slug)
            paged_category_data = self.fetch_category_api(category_code)
            if paged_category_data:
                category_data = paged_category_data
                category_name = str(category_data.get("name") or category_name)
            records = self.icon_records_from_category(category_slug, category_name, category_data)
            return category_slug, category_name, records

        icons_payload = page_data.get("iconsData") or {}
        category_name = str(icons_payload.get("beautyTerm") or category_slug)
        records = self.fetch_search_results(category_slug, category_name)
        return category_slug, category_name, records

    @staticmethod
    def icon_records_from_category(category_slug: str, category_name: str, category_data: dict[str, Any]) -> list[IconRecord]:
        records: list[IconRecord] = []
        for subcategory in category_data.get("subcategory") or []:
            subcategory_name = str(subcategory.get("name") or "")
            for icon in subcategory.get("icons") or []:
                common_name = str(icon.get("commonName") or "").strip()
                icon_id = str(icon.get("id") or "").strip()
                icon_url = str(icon.get("url") or "").strip()
                if not common_name or not icon_id or not icon_url:
                    continue
                records.append(
                    IconRecord(
                        category_slug=category_slug,
                        category_name=category_name,
                        subcategory_name=subcategory_name,
                        icon_id=icon_id,
                        name=str(icon.get("name") or common_name),
                        common_name=common_name,
                        is_animated=bool(icon.get("isAnimated")),
                        icon_url=icon_url,
                    )
                )
        return records

    def fetch_search_results(self, category_slug: str, category_name: str) -> list[IconRecord]:
        records: list[IconRecord] = []
        seen_ids: set[str] = set()

        for offset in range(0, 10000, 60):
            url = (
                "https://search-app.icons8.com/api/iconsets/v7/search"
                f"?style=dusk&language=en&analytics=true&spellcheck=false&amount=60&isOuch=true"
                f"&replaceNameWithSynonyms=true&offset={offset}&term={category_slug}"
            )
            response = self.session().get(url, timeout=self.request_timeout)
            response.raise_for_status()
            payload = response.json()
            icons = payload.get("icons") or []
            if not icons:
                break

            for icon in icons:
                icon_id = str(icon.get("id") or "").strip()
                common_name = str(icon.get("commonName") or "").strip()
                if not icon_id or not common_name or icon_id in seen_ids:
                    continue
                seen_ids.add(icon_id)
                records.append(
                    IconRecord(
                        category_slug=category_slug,
                        category_name=category_name,
                        subcategory_name=str(icon.get("subcategory") or str(icon.get("category") or "")),
                        icon_id=icon_id,
                        name=str(icon.get("name") or common_name),
                        common_name=common_name,
                        is_animated=bool(icon.get("isAnimated")),
                        icon_url=str(icon.get("url") or f"/icon/{icon_id}/{self.sanitize_filename(str(icon.get('name') or common_name))}"),
                    )
                )

            count_all = int(((payload.get("parameters") or {}).get("countAll")) or len(records))
            if offset + 60 >= count_all:
                break

        return records

    def fetch_category_api(self, category_code: str) -> dict[str, Any]:
        merged_category: dict[str, Any] | None = None
        merged_subcategories: dict[str, dict[str, Any]] = {}

        for offset in range(0, 10000, 100):
            url = (
                "https://api-icons.icons8.com/siteApi/icons/v1/packs/demarcation"
                f"?amount=100&offset={offset}&style=dusk&language=en-US&category={category_code}"
            )
            response = self.session().get(url, timeout=self.request_timeout)
            if response.status_code == 404:
                break
            response.raise_for_status()

            payload = response.json()
            category = payload.get("category") or {}
            subcategories = category.get("subcategory") or []
            if not subcategories:
                break

            if merged_category is None:
                merged_category = {key: value for key, value in category.items() if key != "subcategory"}

            page_icon_total = 0
            for subcategory in subcategories:
                code = str(subcategory.get("code") or subcategory.get("name") or f"subcategory-{len(merged_subcategories)}")
                existing = merged_subcategories.setdefault(
                    code,
                    {
                        "code": subcategory.get("code"),
                        "name": subcategory.get("name"),
                        "description": subcategory.get("description"),
                        "icons": [],
                    },
                )
                existing_ids = {str(icon.get("id")) for icon in existing["icons"]}
                for icon in subcategory.get("icons") or []:
                    icon_id = str(icon.get("id") or "")
                    if icon_id and icon_id not in existing_ids:
                        existing["icons"].append(icon)
                        existing_ids.add(icon_id)
                        page_icon_total += 1

            if page_icon_total < 100:
                break

        if merged_category is None:
            return {}

        merged_category["subcategory"] = list(merged_subcategories.values())
        return merged_category

    @staticmethod
    def extract_nuxt_json(html: str) -> dict[str, Any]:
        soup = BeautifulSoup(html, "html.parser")
        script = soup.find("script", id="__NUXT_DATA__")
        if script is None:
            raise RuntimeError("Could not find __NUXT_DATA__ script.")

        nuxt_payload = json.loads(script.string or script.text)
        if not isinstance(nuxt_payload, list):
            raise RuntimeError("Unexpected __NUXT_DATA__ payload shape.")

        @lru_cache(maxsize=None)
        def resolve_ref(index: int) -> Any:
            value = nuxt_payload[index]
            if isinstance(value, list):
                if len(value) == 2 and value[0] in {"ShallowReactive", "Reactive", "Ref", "ShallowRef"} and isinstance(value[1], int):
                    return resolve_ref(value[1])
                return [resolve_value(item) for item in value]
            if isinstance(value, dict):
                return {key: resolve_value(item) for key, item in value.items()}
            return value

        def resolve_value(value: Any) -> Any:
            if isinstance(value, int) and 0 <= value < len(nuxt_payload):
                return resolve_ref(value)
            if isinstance(value, list):
                return [resolve_value(item) for item in value]
            if isinstance(value, dict):
                return {key: resolve_value(item) for key, item in value.items()}
            return value

        decoded_root = resolve_ref(0)
        if not isinstance(decoded_root, dict):
            raise RuntimeError("Decoded __NUXT_DATA__ root is not an object.")
        return decoded_root.get("data", {})

    @staticmethod
    def sanitize_filename(value: str) -> str:
        safe = re.sub(r"[^A-Za-z0-9._-]+", "-", value.strip().lower()).strip("-")
        return safe or "icon"

    def ensure_category_dirs(self, category_slug: str) -> tuple[Path, Path, Path]:
        category_root = self.root / category_slug
        icons_dir = category_root / "icons"
        animated_dir = category_root / "animated"
        category_root.mkdir(parents=True, exist_ok=True)
        icons_dir.mkdir(parents=True, exist_ok=True)
        animated_dir.mkdir(parents=True, exist_ok=True)
        return category_root, icons_dir, animated_dir

    def download_file(self, url: str, destination: Path) -> str:
        if destination.exists() and destination.stat().st_size > 0:
            return "skipped"

        last_error = None
        for attempt in range(1, self.retries + 1):
            try:
                response = self.session().get(url, timeout=self.request_timeout)
                response.raise_for_status()
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_bytes(response.content)
                return "downloaded"
            except Exception as exc:  # noqa: BLE001
                last_error = exc
                if attempt == self.retries:
                    break
                time.sleep(0.75 * attempt)
        return f"failed: {last_error}"

    def build_paths(self, record: IconRecord, icons_dir: Path, animated_dir: Path) -> tuple[Path, Path | None]:
        base_name = self.sanitize_filename(f"icons8-{record.common_name}")
        png_path = icons_dir / f"{base_name}.png"
        gif_path = animated_dir / f"{base_name}.gif" if record.is_animated else None
        return png_path, gif_path

    def process_category(self, category_href: str) -> dict[str, Any]:
        category_slug, category_name, records = self.parse_category_page(category_href)
        category_root, icons_dir, animated_dir = self.ensure_category_dirs(category_slug)

        report: dict[str, Any] = {
            "category_slug": category_slug,
            "category_name": category_name,
            "category_url": BASE_URL + category_href,
            "icons_total": len(records),
            "animated_total": sum(1 for record in records if record.is_animated),
            "png_downloaded": 0,
            "png_skipped": 0,
            "png_failed": 0,
            "gif_downloaded": 0,
            "gif_skipped": 0,
            "gif_failed": 0,
            "failed_assets": [],
        }

        metadata_records = []
        for record in records:
            png_url = f"{IMG_BASE_URL}/{record.common_name}.png"
            gif_url = f"{IMG_BASE_URL}/{record.common_name}.gif" if record.is_animated else None
            png_path, gif_path = self.build_paths(record, icons_dir, animated_dir)
            metadata_records.append(
                {
                    "id": record.icon_id,
                    "name": record.name,
                    "common_name": record.common_name,
                    "subcategory": record.subcategory_name,
                    "page_url": BASE_URL + record.icon_url,
                    "png_url": png_url,
                    "png_file": str(png_path.relative_to(category_root)).replace("\\", "/"),
                    "is_animated": record.is_animated,
                    "gif_url": gif_url,
                    "gif_file": str(gif_path.relative_to(category_root)).replace("\\", "/") if gif_path else None,
                }
            )

        tasks: list[tuple[str, str, Path]] = []
        for record in records:
            png_url = f"{IMG_BASE_URL}/{record.common_name}.png"
            gif_url = f"{IMG_BASE_URL}/{record.common_name}.gif" if record.is_animated else None
            png_path, gif_path = self.build_paths(record, icons_dir, animated_dir)
            tasks.append(("png", png_url, png_path))
            if gif_url and gif_path:
                tasks.append(("gif", gif_url, gif_path))

        with concurrent.futures.ThreadPoolExecutor(max_workers=self.max_workers) as executor:
            future_map = {
                executor.submit(self.download_file, url, destination): (asset_kind, url, destination)
                for asset_kind, url, destination in tasks
            }

            for future in concurrent.futures.as_completed(future_map):
                asset_kind, url, destination = future_map[future]
                result = future.result()
                if result == "downloaded":
                    report[f"{asset_kind}_downloaded"] += 1
                elif result == "skipped":
                    report[f"{asset_kind}_skipped"] += 1
                else:
                    report[f"{asset_kind}_failed"] += 1
                    report["failed_assets"].append({"kind": asset_kind, "url": url, "file": str(destination), "error": result})

        metadata_path = category_root / "_category_report.json"
        metadata_path.write_text(json.dumps({"category": report, "icons": metadata_records}, indent=2), encoding="utf-8")
        return report

    def run(self) -> None:
        self.root.mkdir(parents=True, exist_ok=True)
        categories = self.get_dusk_categories()
        if self.category_filter:
            filtered_categories = []
            for href, label in categories:
                match = re.match(r"^/icons/set/([^/?#]+)--style-dusk$", href)
                if match and match.group(1) in self.category_filter:
                    filtered_categories.append((href, label))
            categories = filtered_categories
        self.stats["categories_total"] = len(categories)

        with concurrent.futures.ThreadPoolExecutor(max_workers=self.max_category_workers) as executor:
            future_map = {
                executor.submit(self.process_category, href): href
                for href, _label in categories
            }
            for future in concurrent.futures.as_completed(future_map):
                report = future.result()
                with self.lock:
                    self.stats["categories_processed"] += 1
                    self.stats["icons_expected"] += report["icons_total"]
                    self.stats["gif_expected"] += report["animated_total"]
                    self.stats["png_downloaded"] += report["png_downloaded"]
                    self.stats["png_skipped"] += report["png_skipped"]
                    self.stats["png_failed"] += report["png_failed"]
                    self.stats["gif_downloaded"] += report["gif_downloaded"]
                    self.stats["gif_skipped"] += report["gif_skipped"]
                    self.stats["gif_failed"] += report["gif_failed"]
                    self.stats["category_reports"].append(report)
                    print(
                        f"[{self.stats['categories_processed']}/{self.stats['categories_total']}] "
                        f"{report['category_slug']}: {report['icons_total']} png, {report['animated_total']} animated"
                    )

        self.stats["finished_at"] = time.strftime("%Y-%m-%dT%H:%M:%S")
        summary_path = self.root / "_download_summary.json"
        summary_path.write_text(json.dumps(self.stats, indent=2), encoding="utf-8")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Download all Icons8 Dusk icon categories into structured folders.")
    parser.add_argument(
        "--root",
        default="resources/icons/dusk",
        help="Destination root folder for downloaded dusk icons.",
    )
    parser.add_argument(
        "--max-workers",
        type=int,
        default=min(32, (os.cpu_count() or 8) * 2),
        help="Concurrent download workers per category.",
    )
    parser.add_argument(
        "--retries",
        type=int,
        default=3,
        help="Retry attempts per request.",
    )
    parser.add_argument(
        "--categories",
        nargs="*",
        default=[],
        help="Optional list of specific dusk category slugs to process.",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    downloader = Downloader(
        root=Path(args.root),
        max_workers=args.max_workers,
        retries=args.retries,
        category_filter=set(args.categories),
    )
    downloader.run()


if __name__ == "__main__":
    main()
