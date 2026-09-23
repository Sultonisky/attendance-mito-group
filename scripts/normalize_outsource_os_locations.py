#!/usr/bin/env python3
"""Normalize outsource work-location Excel into one-row-per-pin CSV/XLSX.

- Ignores jabatan / entity / cek / method
- Treats address + lat/long as pin points
- Swaps Excel LON/LAT when columns are inverted (common in this sheet)
- Expands multi-address / multi-coordinate cells into multiple pins
- Fixes known city typo BALIKOPAN -> BALIKPAPAN

Usage:
  python scripts/normalize_outsource_os_locations.py
"""

from __future__ import annotations

import csv
import re
from collections import Counter
from pathlib import Path

import openpyxl
from openpyxl import Workbook

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "Data titik lokasi kerja karyawan OS.xlsx"
OUT_XLSX = ROOT / "Data titik lokasi kerja karyawan OS.normalized.xlsx"
OUT_CSV = ROOT / "Data titik lokasi kerja karyawan OS.normalized-pins.csv"
OUT_INCOMPLETE = ROOT / "Data titik lokasi kerja karyawan OS.incomplete.csv"

CITY_FIX = {
    "BALIKOPAN": "BALIKPAPAN",
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
    """Return (lat, lon) from Excel column values that are often swapped."""

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
        return [], {
            "source_row": row_num,
            "city": city,
            "store": store,
            "employee": employee,
            "reason": "missing_coordinates",
            "address_raw": norm_str(address_raw)[:200],
        }

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
                "source_row": row_num,
                "city": city,
                "store": store,
                "employee": employee,
                "pin_name": pin_name,
                "pin_index": i + 1,
                "pin_count": len(pairs),
                "address": addr,
                "latitude": round(lat, 7),
                "longitude": round(lon, 7),
            }
        )

    if not pins:
        return [], {
            "source_row": row_num,
            "city": city,
            "store": store,
            "employee": employee,
            "reason": "invalid_coordinates_after_orient",
            "address_raw": norm_str(address_raw)[:200],
        }
    return pins, None


def main() -> None:
    wb = openpyxl.load_workbook(SRC, data_only=True)
    ws = wb["Sheet1"]
    headers = [ws.cell(1, c).value for c in range(1, ws.max_column + 1)]

    all_pins = []
    incomplete = []
    source_rows = 0
    emp_pin_count: Counter = Counter()
    emp_has: dict = {}

    for r in range(2, ws.max_row + 1):
        vals = {headers[c - 1]: ws.cell(r, c).value for c in range(1, ws.max_column + 1)}
        if all(isempty(v) for v in vals.values()):
            continue
        if isempty(vals.get("NAMA KARYAWAN")) and isempty(vals.get("NAMA TOKO")):
            continue
        source_rows += 1
        pins, miss = expand_row(r, vals)
        if miss:
            incomplete.append(miss)
            key = (miss["city"], miss["store"], miss["employee"])
            emp_has.setdefault(key, False)
            emp_pin_count.setdefault(key, 0)
        else:
            all_pins.extend(pins)
            for p in pins:
                key = (p["city"], p["store"], p["employee"])
                emp_pin_count[key] = max(emp_pin_count[key], p["pin_count"])
                emp_has[key] = True

    fields = [
        "source_row",
        "city",
        "store",
        "employee",
        "pin_name",
        "pin_index",
        "pin_count",
        "address",
        "latitude",
        "longitude",
    ]
    with OUT_CSV.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.DictWriter(f, fieldnames=fields)
        w.writeheader()
        for p in all_pins:
            w.writerow({k: p[k] for k in fields})

    with OUT_INCOMPLETE.open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.DictWriter(
            f,
            fieldnames=["source_row", "city", "store", "employee", "reason", "address_raw"],
        )
        w.writeheader()
        for row in incomplete:
            w.writerow(row)

    out = Workbook()
    sp = out.active
    sp.title = "pins"
    sp.append(fields)
    for p in all_pins:
        sp.append([p[h] for h in fields])

    sm = out.create_sheet("master_employees")
    sm.append(["LIST CABANG", "NAMA TOKO", "NAMA KARYAWAN", "PIN COUNT", "HAS COORDS"])
    for key in sorted(emp_has.keys(), key=lambda x: (x[0], x[1], x[2])):
        sm.append([key[0], key[1], key[2], emp_pin_count[key], "YES" if emp_has[key] else "NO"])

    si = out.create_sheet("incomplete")
    si.append(["source_row", "city", "store", "employee", "reason", "address_raw"])
    for row in incomplete:
        si.append(
            [
                row["source_row"],
                row["city"],
                row["store"],
                row["employee"],
                row["reason"],
                row["address_raw"],
            ]
        )

    ss = out.create_sheet("summary")
    ss.append(["metric", "value"])
    ss.append(["source_rows", source_rows])
    ss.append(["normalized_pins", len(all_pins)])
    ss.append(["incomplete_employees", len(incomplete)])
    ss.append(["multi_pin_employees", sum(1 for c in emp_pin_count.values() if c > 1)])
    out.save(OUT_XLSX)

    print(f"source_rows={source_rows}")
    print(f"pins={len(all_pins)}")
    print(f"incomplete={len(incomplete)}")
    print(f"wrote {OUT_XLSX}")
    print(f"wrote {OUT_CSV}")
    print(f"wrote {OUT_INCOMPLETE}")


if __name__ == "__main__":
    main()
