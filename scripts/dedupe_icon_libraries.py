import argparse
import hashlib
import json
import shutil
import time
from pathlib import Path


ASSET_EXTENSIONS = {".png", ".gif"}


def file_sha1(path: Path) -> str:
    digest = hashlib.sha1()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def collect_assets(root: Path) -> list[Path]:
    return sorted(
        path
        for path in root.rglob("*")
        if path.is_file() and path.suffix.lower() in ASSET_EXTENSIONS
    )


def backup_destination(icons_root: Path, backup_root: Path, source: Path) -> Path:
    relative = source.relative_to(icons_root)
    return backup_root / relative


def dedupe_roots(icons_root: Path, roots: list[Path], backup_root: Path) -> dict:
    files: list[Path] = []
    for root in roots:
        files.extend(collect_assets(root))

    by_hash: dict[str, list[Path]] = {}
    for path in files:
        by_hash.setdefault(file_sha1(path), []).append(path)

    manifest = {
        "started_at": time.strftime("%Y-%m-%dT%H:%M:%S"),
        "icons_root": str(icons_root),
        "backup_root": str(backup_root),
        "roots": [str(root) for root in roots],
        "files_scanned": len(files),
        "unique_files_kept": 0,
        "duplicate_files_moved": 0,
        "duplicate_groups": 0,
        "groups": [],
    }

    for digest, paths in sorted(by_hash.items(), key=lambda item: (len(item[1]), str(item[1][0]))):
        ordered = sorted(paths)
        keeper = ordered[0]
        duplicates = ordered[1:]
        manifest["unique_files_kept"] += 1

        if not duplicates:
            continue

        manifest["duplicate_groups"] += 1
        moved = []
        for duplicate in duplicates:
            destination = backup_destination(icons_root, backup_root, duplicate)
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.move(str(duplicate), str(destination))
            moved.append(
                {
                    "from": str(duplicate),
                    "to": str(destination),
                }
            )
            manifest["duplicate_files_moved"] += 1

        manifest["groups"].append(
            {
                "sha1": digest,
                "keeper": str(keeper),
                "moved": moved,
            }
        )

    manifest["finished_at"] = time.strftime("%Y-%m-%dT%H:%M:%S")
    return manifest


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Move duplicate icon files into resources/icons/back-ups while keeping one copy in place."
    )
    parser.add_argument(
        "--icons-root",
        default="resources/icons",
        help="Base icons folder containing the icon libraries.",
    )
    parser.add_argument(
        "--roots",
        nargs="+",
        default=["Windows All", "macOS All", "dusk"],
        help="Library folders under the icons root to deduplicate.",
    )
    parser.add_argument(
        "--backup-root",
        default="back-ups",
        help="Backup folder name under the icons root.",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    icons_root = Path(args.icons_root).resolve()
    roots = [icons_root / root for root in args.roots]
    backup_root = icons_root / args.backup_root
    backup_root.mkdir(parents=True, exist_ok=True)

    manifest = dedupe_roots(icons_root, roots, backup_root)
    timestamp = time.strftime("%Y%m%d-%H%M%S")
    manifest_path = backup_root / f"_dedupe_manifest_{timestamp}.json"
    latest_manifest_path = backup_root / "_dedupe_manifest.json"
    manifest_path.write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    latest_manifest_path.write_text(json.dumps(manifest, indent=2), encoding="utf-8")

    print(
        json.dumps(
            {
                "files_scanned": manifest["files_scanned"],
                "unique_files_kept": manifest["unique_files_kept"],
                "duplicate_groups": manifest["duplicate_groups"],
                "duplicate_files_moved": manifest["duplicate_files_moved"],
                "manifest": str(manifest_path),
                "latest_manifest": str(latest_manifest_path),
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
