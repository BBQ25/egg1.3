from __future__ import annotations

import argparse
import json
import re
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlparse


MOCK_DATA = {
    "weight": 61.4,
    "eggType": "Large",
    "status": "Sorting",
    "message": "Preview mode with mocked ESP32 data.",
    "lastEggType": "Medium",
    "lastEggWeight": 58.7,
    "state": "MOVING_PAN",
    "gate1": "Closed",
    "gate2": "Closed",
    "feedMode": "AUTO",
    "cycleInfo": "Sorting",
    "speedMode": "FAST",
    "total": 248,
    "feedCycles": 259,
    "released": 251,
    "recordCount": 25,
    "feedMiss": 8,
    "xs": 11,
    "small": 37,
    "medium": 74,
    "large": 83,
    "xl": 29,
    "jumbo": 14,
    "unknown": 4,
    "ip": "192.168.1.77",
    "rssi": -58,
    "wifiText": "Strong",
    "cloudStatus": "Uploaded",
    "cloudMessage": "Latest event accepted by Laravel.",
    "cloudBatchCode": "BATCH-2026-001",
    "cloudLastSync": "2026-03-29T13:52:00Z",
    "cloudSuccess": 243,
    "cloudFailure": 3,
    "cloudDropped": 0,
    "cloudValidationErrors": 0,
    "cloudPending": False,
    "cloudClockSynced": True,
    "cloudConfigLoaded": True,
    "uptime": "2h 14m 08s",
    "panAngle": 130,
    "tiltAngle": 52,
    "freeHeap": 188744,
    "autoFeed": True,
    "paused": False,
    "busy": True,
    "intervalMs": 5000,
    "targetEggsPerMinute": 12,
    "statusClass": "status-sorting",
}

MOCK_RECORDS = {
    "count": 5,
    "capacity": 25,
    "timestampSource": "uptime",
    "records": [
        {
            "RecordID": 244,
            "Timestamp": "2h 13m 11s",
            "Weight_g": 61.4,
            "ClassLabel": "Large",
            "SortResult": "Sorted",
            "Notes": "Sent to Large tray",
        },
        {
            "RecordID": 243,
            "Timestamp": "2h 12m 54s",
            "Weight_g": 58.7,
            "ClassLabel": "Medium",
            "SortResult": "Sorted",
            "Notes": "Sent to Medium tray",
        },
        {
            "RecordID": 242,
            "Timestamp": "2h 12m 32s",
            "Weight_g": 69.3,
            "ClassLabel": "XL",
            "SortResult": "Sorted",
            "Notes": "Sent to XL tray",
        },
        {
            "RecordID": 241,
            "Timestamp": "2h 12m 11s",
            "Weight_g": 46.2,
            "ClassLabel": "XS",
            "SortResult": "Sorted",
            "Notes": "Sent to XS tray",
        },
        {
            "RecordID": 240,
            "Timestamp": "2h 11m 48s",
            "Weight_g": 34.6,
            "ClassLabel": "Unknown",
            "SortResult": "Rejected",
            "Notes": "Weight out of range",
        },
    ],
}


def extract_dashboard_html(ino_path: Path) -> str:
    source = ino_path.read_text(encoding="utf-8")
    match = re.search(r'String html = R"rawliteral\((.*?)\)rawliteral";', source, re.S)
    if not match:
        raise RuntimeError(f"Could not find buildDashboardHTML raw literal in {ino_path}")

    return match.group(1).strip() + "\n"


def write_preview_html(target: Path, html: str) -> None:
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(html, encoding="utf-8")


def build_handler(html: str):
    class FirmwareDashboardPreviewHandler(BaseHTTPRequestHandler):
        def do_GET(self) -> None:
            path = urlparse(self.path).path

            if path in {"/", "/dashboard.html"}:
                self._send_text(200, html, "text/html; charset=utf-8")
                return

            if path == "/data":
                self._send_json(200, MOCK_DATA)
                return

            if path == "/records":
                self._send_json(200, MOCK_RECORDS)
                return

            if path in {
                "/reset",
                "/tare",
                "/feed",
                "/toggleFeed",
                "/pause",
                "/speed/slow",
                "/speed/normal",
                "/speed/fast",
            }:
                self._send_json(200, {"ok": True, "preview": True, "path": path})
                return

            self._send_text(404, "404 - Not Found", "text/plain; charset=utf-8")

        def log_message(self, fmt: str, *args) -> None:
            return

        def _send_json(self, status: int, payload: dict) -> None:
            body = json.dumps(payload).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def _send_text(self, status: int, text: str, content_type: str) -> None:
            body = text.encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", content_type)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

    return FirmwareDashboardPreviewHandler


def main() -> None:
    parser = argparse.ArgumentParser(description="Preview the ESP32 firmware dashboard locally.")
    parser.add_argument(
        "--ino",
        default=str(Path(__file__).resolve().parents[1] / "firmware" / "AESM" / "AESM.ino"),
        help="Path to the official firmware sketch.",
    )
    parser.add_argument(
        "--host",
        default="127.0.0.1",
        help="Host interface to bind.",
    )
    parser.add_argument(
        "--port",
        default=8765,
        type=int,
        help="Port for the local preview server.",
    )
    parser.add_argument(
        "--write-html",
        default=str(Path(__file__).resolve().parents[1] / "output" / "firmware-dashboard-preview" / "dashboard.html"),
        help="Path to write the extracted preview HTML.",
    )
    args = parser.parse_args()

    ino_path = Path(args.ino).resolve()
    html = extract_dashboard_html(ino_path)
    write_preview_html(Path(args.write_html).resolve(), html)

    server = ThreadingHTTPServer((args.host, args.port), build_handler(html))
    print(f"Preview HTML written to: {Path(args.write_html).resolve()}")
    print(f"Firmware dashboard preview running at: http://{args.host}:{args.port}/")
    server.serve_forever()


if __name__ == "__main__":
    main()
