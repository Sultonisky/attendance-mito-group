#!/usr/bin/env python3
"""Normalize outsource Excel into data/stores.json (single source of truth).

One JSON file drives `php artisan outsource:import`:
  - city/cabang + employee (always)
  - employee_id + password from Excel (EMPLOYEE ID / PIN)
  - pin = address + lat/lng when coordinates exist
  - incomplete employees included with lat/lon = null (master only)

Usage:
  pip install -r data/requirements.txt
  python scripts/normalize_outsource_os_locations.py
  python scripts/normalize_outsource_os_locations.py path/to/file.xlsx
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

import openpyxl

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_SRC = ROOT / "data" / "Data titik lokasi kerja karyawan OS (FIXED).xlsx"
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


def credential_fields(vals: dict) -> tuple[str | None, str | None]:
    """EMPLOYEE ID → employee_id; PIN → password (login PIN)."""
    employee_id = norm_str(vals.get("EMPLOYEE ID") or vals.get("employee_id") or vals.get("outsource_code"))
    pin_raw = vals.get("PIN")
    if pin_raw is None:
        pin_raw = vals.get("password")
    password = norm_str(pin_raw)
    # Excel may store PIN as int (123456) — keep digits only as string.
    if password and re.fullmatch(r"\d+\.0", password):
        password = password[:-2]
    return (employee_id or None, password or None)


def base_row(
    row_num: int,
    city: str,
    store: str,
    employee: str,
    employee_id: str | None,
    password: str | None,
) -> dict:
    return {
        "city": city,
        "store": store,
        "employee": employee,
        "employee_id": employee_id,
        "password": password,
    }


def master_only_row(
    row_num: int,
    city: str,
    store: str,
    employee: str,
    employee_id: str | None,
    password: str | None,
    reason: str,
    address_raw: str,
) -> dict:
    return {
        **base_row(row_num, city, store, employee, employee_id, password),
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
    employee_id, password = credential_fields(vals)
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
        return [], master_only_row(
            row_num, city, store, employee, employee_id, password, "missing_coordinates", norm_str(address_raw)
        )

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
        elif len(pairs) == 1 and store:
            pin_name = store

        pins.append(
            {
                **base_row(row_num, city, store, employee, employee_id, password),
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
            row_num,
            city,
            store,
            employee,
            employee_id,
            password,
            "invalid_coordinates_after_orient",
            norm_str(address_raw),
        )
    return pins, None


def norm_person_key(name: str) -> str:
    return re.sub(r"\s+", " ", norm_str(name)).casefold()


def collapse_employee_ids(rows: list[dict]) -> tuple[list[dict], dict[str, str]]:
    """Rule: 1 person (name) = 1 employee_id, sequentially DM20260001..N by first appearance."""
    canonical: dict[str, str] = {}
    mapping: dict[str, str] = {}  # old_id -> canonical_id
    seq = 0

    ordered = sorted(rows, key=lambda r: (r.get("source_row") or 10**9, r.get("employee_id") or ""))
    for row in ordered:
        name_key = norm_person_key(row.get("employee") or "")
        eid = row.get("employee_id")
        if not name_key:
            continue
        if name_key not in canonical:
            seq += 1
            new_id = f"DM2026{seq:04d}"
            canonical[name_key] = new_id
            if eid and eid != new_id:
                mapping[str(eid)] = new_id
        elif eid and eid != canonical[name_key]:
            mapping[str(eid)] = canonical[name_key]

    for row in rows:
        name_key = norm_person_key(row.get("employee") or "")
        if name_key in canonical:
            row["employee_id"] = canonical[name_key]

    # Re-number pins per person (multi-location allowlist).
    by_person: dict[str, list[dict]] = {}
    for row in rows:
        key = norm_person_key(row.get("employee") or "") or f"id:{row.get('employee_id')}"
        by_person.setdefault(key, []).append(row)

    for person_rows in by_person.values():
        person_rows.sort(key=lambda r: (r.get("source_row") or 10**9, r.get("pin_index") or 0))
        # Deduplicate exact same lat/lon pins for one person (keep first).
        seen_coords: set[tuple[float | None, float | None]] = set()
        unique_rows: list[dict] = []
        for r in person_rows:
            lat = r.get("lat")
            lon = r.get("lon")
            coord = (round(lat, 7) if lat is not None else None, round(lon, 7) if lon is not None else None)
            if lat is not None and lon is not None and coord in seen_coords:
                r["_drop"] = True
                continue
            if lat is not None and lon is not None:
                seen_coords.add(coord)
            unique_rows.append(r)

        count = len(unique_rows)
        for i, r in enumerate(unique_rows, start=1):
            r["pin_index"] = i
            r["pin_count"] = count
            if count > 1 and r.get("address"):
                short = str(r["address"]).split(",")[0].strip()
                if 3 < len(short) < 60:
                    r["pin_name"] = short
                elif r.get("store"):
                    r["pin_name"] = f"{r['store']} ({i})"
            elif r.get("store"):
                r["pin_name"] = r["store"]

    collapsed = [r for r in rows if not r.pop("_drop", False)]
    return collapsed, mapping


def main() -> None:
    src = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_SRC
    if not src.is_file():
        raise SystemExit(f"Excel not found: {src}")

    wb = openpyxl.load_workbook(src, data_only=True)
    ws = wb["Sheet1"] if "Sheet1" in wb.sheetnames else wb.active
    headers = [ws.cell(1, c).value for c in range(1, ws.max_column + 1)]

    all_rows = []
    incomplete_count = 0
    source_rows = 0
    missing_employee_id = 0

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
            if not miss.get("employee_id"):
                missing_employee_id += 1
        else:
            all_rows.extend(pins)
            for p in pins:
                if not p.get("employee_id"):
                    missing_employee_id += 1

    all_rows, id_mapping = collapse_employee_ids(all_rows)

    OUT_JSON.parent.mkdir(parents=True, exist_ok=True)
    OUT_JSON.write_text(
        json.dumps(all_rows, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )

    pin_rows = sum(1 for r in all_rows if r.get("lat") is not None and r.get("lon") is not None)
    with_creds = sum(1 for r in all_rows if r.get("employee_id") and r.get("password"))
    people = {norm_person_key(r.get("employee") or "") for r in all_rows if r.get("employee")}
    ids = {r.get("employee_id") for r in all_rows if r.get("employee_id")}
    multi_pin_people = len({r["employee_id"] for r in all_rows if (r.get("pin_count") or 0) > 1})

    print(f"source={src}")
    print(f"source_rows={source_rows}")
    print(f"json_rows={len(all_rows)} (pins={pin_rows}, incomplete={incomplete_count})")
    print(f"people={len(people)} employee_ids={len(ids)}")
    print(f"collapsed_extra_ids={len(id_mapping)}")
    print(f"with_employee_id_and_password={with_creds}")
    print(f"missing_employee_id={missing_employee_id}")
    print(f"multi_pin_employees={multi_pin_people}")
    print(f"wrote {OUT_JSON} (SOT for outsource:import)")
    if len(people) != len(ids):
        raise SystemExit(f"Rule violated: people={len(people)} != employee_ids={len(ids)}")


if __name__ == "__main__":
    main()
