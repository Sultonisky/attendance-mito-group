#!/usr/bin/env python3
"""E2E verify data/stores.json against the FIXED Excel source."""

from __future__ import annotations

import json
import re
from collections import Counter
from pathlib import Path

import openpyxl

ROOT = Path(__file__).resolve().parents[1]
XLSX = ROOT / "data" / "Data titik lokasi kerja karyawan OS (FIXED).xlsx"
JSON_PATH = ROOT / "data" / "stores.json"


def orient(lat: float, lon: float) -> tuple[float, float]:
    # FIXED sheet stores LONGITUDE then LATITUDE correctly for IDN;
    # still accept swapped pairs defensively.
    if -15 <= lon <= 15 and 90 <= lat <= 150:
        return lon, lat
    if abs(lon) <= 90 and abs(lat) > 90:
        return lon, lat
    return lat, lon


def main() -> int:
    wb = openpyxl.load_workbook(XLSX, data_only=True)
    ws = wb.active
    headers = [ws.cell(1, c).value for c in range(1, ws.max_column + 1)]

    excel: list[tuple[int, dict]] = []
    for r in range(2, ws.max_row + 1):
        vals = {headers[c - 1]: ws.cell(r, c).value for c in range(1, ws.max_column + 1)}
        if all(v is None or str(v).strip() == "" for v in vals.values()):
            continue
        if not vals.get("NAMA KARYAWAN") and not vals.get("NAMA TOKO"):
            continue
        excel.append((r, vals))

    stores = json.loads(JSON_PATH.read_text(encoding="utf-8"))
    by_src: dict[int, list[dict]] = {}
    for s in stores:
        by_src.setdefault(s["source_row"], []).append(s)

    errors: list[str] = []

    for r, vals in excel:
        if r not in by_src:
            errors.append(f"missing json for excel row {r}")
            continue

        pins = by_src[r]
        eid = str(vals["EMPLOYEE ID"]).strip()
        pin_raw = vals["PIN"]
        pin = str(int(pin_raw)) if isinstance(pin_raw, (int, float)) else str(pin_raw).strip()
        name = str(vals["NAMA KARYAWAN"]).strip()
        city = str(vals["LIST CABANG"]).strip().upper()
        store = str(vals["NAMA TOKO"]).strip()
        # Sheet columns: LONGITUDE, LATITUDE
        lon_v = float(vals["LONGITUDE"])
        lat_v = float(vals["LATITUDE"])
        lat, lon = orient(lat_v, lon_v)

        for p in pins:
            if p.get("employee_id") != eid:
                errors.append(f"row {r} employee_id {p.get('employee_id')!r} != {eid!r}")
            if p.get("password") != pin:
                errors.append(f"row {r} password mismatch")
            if p.get("employee") != name:
                errors.append(f"row {r} name {p.get('employee')!r} != {name!r}")
            if p.get("city") != city:
                errors.append(f"row {r} city {p.get('city')!r} != {city!r}")
            if p.get("store") != store:
                errors.append(f"row {r} store mismatch")
            if p.get("lat") is None or p.get("lon") is None:
                errors.append(f"row {r} missing coords")
            elif abs(p["lat"] - lat) > 1e-6 or abs(p["lon"] - lon) > 1e-6:
                errors.append(
                    f"row {r} coords json=({p['lat']},{p['lon']}) excel=({lat},{lon})"
                )
            if p.get("incomplete_reason") is not None:
                errors.append(f"row {r} unexpected incomplete")
            if p.get("confidence") != "high":
                errors.append(f"row {r} confidence {p.get('confidence')}")

    excel_rows = {r for r, _ in excel}
    orphans = [sr for sr in by_src if sr not in excel_rows]
    if orphans:
        errors.append(f"orphan source_rows {orphans[:10]}")

    dup = [i for i, c in Counter(s["employee_id"] for s in stores).items() if c > 1]
    # Same employee_id on multiple pin rows is OK (multi-location). Same name must not
    # map to multiple IDs, and one ID must not map to multiple names.
    by_name: dict[str, set[str]] = {}
    by_id: dict[str, set[str]] = {}
    for s in stores:
        name = re.sub(r"\s+", " ", str(s["employee"]).strip()).casefold()
        by_name.setdefault(name, set()).add(s["employee_id"])
        by_id.setdefault(s["employee_id"], set()).add(name)
    for name, ids in by_name.items():
        if len(ids) > 1:
            errors.append(f"person {name!r} has multiple employee_ids {sorted(ids)}")
    for eid, names in by_id.items():
        if len(names) > 1:
            errors.append(f"employee_id {eid} maps to multiple people {sorted(names)}")

    # Note: dup employee_id across pin rows is expected for multi-pin people.
    _ = dup

    print(f"excel_rows={len(excel)}")
    print(f"json_rows={len(stores)}")
    print(f"cities={len({s['city'] for s in stores})}")
    print(f"store_keys={len({(s['city'], s['store']) for s in stores})}")
    print(f"employees={len({s['employee_id'] for s in stores})}")
    print(f"people={len(by_name)}")
    print(f"all_have_password={all(s.get('password') for s in stores)}")
    print(f"all_have_employee_id={all(s.get('employee_id') for s in stores)}")
    print(f"incomplete={sum(1 for s in stores if s.get('incomplete_reason'))}")
    print(f"errors={len(errors)}")
    for e in errors[:40]:
        print(f" - {e}")

    if errors:
        return 1

    print("E2E OK: every excel row maps 1:1 to stores.json with matching credentials+coords")
    print("FIRST", json.dumps(stores[0], ensure_ascii=False))
    print("LAST", json.dumps(stores[-1], ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
