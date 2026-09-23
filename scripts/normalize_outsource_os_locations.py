#!/usr/bin/env python3
"""Normalize outsource Excel into data/stores.json (single source of truth).

One JSON file drives `php artisan outsource:import`:
  - city/cabang + employee (always)
  - pin = address + lat/lng when coordinates exist
  - incomplete employees included with lat/lon = null (master only)

Usage:
  pip install -r data/requirements.txt
  python scripts/normalize_outsource_os_locations.py
"""

from __future__ import annotations

import json
import re
from collections import Counter
from pathlib import Path

import openpyxl

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "Data titik lokasi kerja karyawan OS.xlsx"
OUT_JSON = ROOT / "data" / "stores.json"

CITY_FIX = {
    "BALIKOPAN": "BALIKOPAN",
}


def norm_str(v) -> str:
    if v is None:
        return ""
    return str(v).replace("\u2060", "").strip()


def isempty(v) -> bool:
    s = norm_str(v)
    return s == "" or s.lower() in ("none", "null", "-", "n/a", "na")


def parse_floats(raw) -> list[float]:
    if raw is None:
        return []
    if isinstance(raw, (int, float)):
        return [float(raw)]
    text = str(raw).replace(",", ".")
    vals: list[float] = []
    for part in re.split(r"[\n;]+", text):
        part = part.strip()
        if not part:
            continue
        m = re.search(r"[-+]?\d+(?:\.\d+)?", part)
        if m:
            vals.append(float(m.group(0)))
    return vals


def split_addresses(raw) -> list[str]:
    if isempty(raw):
        return []
    text = norm_str(raw)
    if re.search(r"\bresign\b", text, re.I):
        return []
    parts = re.split(r"\n\s*\n+|\n(?=\s*\d+[\.\)\-]\s)", text)
    addrs: list[str] = []
    for p in parts:
        p = re.sub(r"^\s*\d+[\.\)\-]\s*", "", p.strip()).strip()
        if p:
            addrs.append(p)
    if len(addrs) <= 1 and "\n" in text and not re.search(r"^\s*\d+[\.\)]", text, re.M):
        return [re.sub(r"\s+", " ", text)]
    return addrs if addrs else [re.sub(r"\s+", " ", text)]


def orient_lat_lon(a: float, b: float) -> tuple[float, float]:
    def is_lat(x: float) -> bool:
        return -15 <= x <= 15

    def is_lon(x: float) -> bool:
        return 90 <= x <= 150

    if is_lat(a) and is_lon(b):
        return a, b
    if is_lat(b) and is_lon(a):
        return b, a
    if abs(a) <= 90 and abs(b) > 90:
        return a, b
    if abs(b) <= 90 and abs(a) > 90:
        return b, a
    return a, b


def master_only_row(row_num: int, city: str, store: str, employee: str, reason: str, address_raw: str) -> dict:
    return {
        "city": city,
        "store": store,
        "employee": employee,
        "pin_name": store or city or "Pin",
        "pin_index": None,
        "pin_count": 0,
        "address": norm_str(address_raw)[:200] if address_raw else "",
        "lat": None,
        "lon": None,
        "source": "excel_normalized",
        "is_fallback": False,
        "confidence": "none",
        "source_row": row_num,
        "incomplete_reason": reason,
    }


def expand_row(row_num: int, vals: dict):
    city = CITY_FIX.get(norm_str(vals.get("LIST CABANG")).upper(), norm_str(vals.get("LIST CABANG")).upper())
    store = norm_str(vals.get("NAMA TOKO"))
    employee = norm_str(vals.get("NAMA KARYAWAN"))
    address_raw = vals.get("Alamat")
    xs = parse_floats(vals.get("LONGITUDE"))
    ys = parse_floats(vals.get("LATITUDE"))
    addrs = split_addresses(address_raw)

    pairs: list[tuple[float, float]] = []
    if len(xs) == len(ys) and xs:
        pairs = [orient_lat_lon(a, b) for a, b in zip(xs, ys)]
    elif len(xs) == 1 and len(ys) > 1:
        pairs = [orient_lat_lon(xs[0], b) for b in ys]
    elif len(ys) == 1 and len(xs) > 1:
        pairs = [orient_lat_lon(a, ys[0]) for a in xs]

    if not pairs:
        return [], master_only_row(row_num, city, store, employee, "missing_coordinates", norm_str(address_raw))

    pins = []
    for i, (lat, lon) in enumerate(pairs):
        if not (-90 <= lat <= 90 and -180 <= lon <= 180) or (lat == 0 and lon == 0):
            continue
        if addrs:
            if len(addrs) == len(pairs):
                addr = addrs[i]
            elif len(addrs) == 1:
                addr = addrs[0]
            elif i < len(addrs):
                addr = addrs[i]
            else:
                addr = addrs[-1]
        else:
            addr = ""

        pin_name = f"Pin {i + 1}" if len(pairs) > 1 else (store or "Pin utama")
        if len(pairs) > 1 and addr:
            short = addr.split(",")[0].strip()
            if 3 < len(short) < 60:
                pin_name = short

        pins.append(
            {
                "city": city,
                "store": store,
                "employee": employee,
                "pin_name": pin_name,
                "pin_index": i + 1,
                "pin_count": len(pairs),
                "address": addr,
                "lat": round(lat, 7),
                "lon": round(lon, 7),
                "source": "excel_normalized",
                "is_fallback": False,
                "confidence": "high",
                "source_row": row_num,
                "incomplete_reason": None,
            }
        )

    if not pins:
        return [], master_only_row(
            row_num, city, store, employee, "invalid_coordinates_after_orient", norm_str(address_raw)
        )
    return pins, None


def main() -> None:
    wb = openpyxl.load_workbook(SRC, data_only=True)
    ws = wb["Sheet1"]
    headers = [ws.cell(1, c).value for c in range(1, ws.max_column + 1)]

    all_rows = []
    incomplete_count = 0
    source_rows = 0
    emp_pin_count: Counter = Counter()

    for r in range(2, ws.max_row + 1):
        vals = {headers[c - 1]: ws.cell(r, c).value for c in range(1, ws.max_column + 1)}
        if all(isempty(v) for v in vals.values()):
            continue
        if isempty(vals.get("NAMA KARYAWAN")) and isempty(vals.get("NAMA TOKO")):
            continue
        source_rows += 1
        pins, miss = expand_row(r, vals)
        if miss:
            incomplete_count += 1
            all_rows.append(miss)
            key = (miss["city"], miss["store"], miss["employee"])
            emp_pin_count.setdefault(key, 0)
        else:
            all_rows.extend(pins)
            for p in pins:
                key = (p["city"], p["store"], p["employee"])
                emp_pin_count[key] = max(emp_pin_count[key], p["pin_count"])

    OUT_JSON.parent.mkdir(parents=True, exist_ok=True)
    OUT_JSON.write_text(
        json.dumps(all_rows, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )

    pin_rows = sum(1 for r in all_rows if r.get("lat") is not None and r.get("lon") is not None)
    print(f"source_rows={source_rows}")
    print(f"json_rows={len(all_rows)} (pins={pin_rows}, incomplete={incomplete_count})")
    print(f"multi_pin_employees={sum(1 for c in emp_pin_count.values() if c > 1)}")
    print(f"wrote {OUT_JSON} (SOT for outsource:import)")


if __name__ == "__main__":
    main()
