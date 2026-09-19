import json
import re
import time
import urllib.parse
import urllib.request
import urllib.error

# Prefer Excel pin-points when available:
#   python data/geocode_from_excel.py
# This Nominatim store-name scraper is a secondary fallback.

CITY_CENTERS = {
    'BALIKPAPAN': (-1.2694616, 116.8543264),
    'BANDUNG': (-6.914744, 107.609810),
    'BANGKA': (-2.170, 106.120),
    'BANJAR BARU': (-3.442, 114.842),
    'BANJARMASIN': (-3.318, 114.594),
    'BANYUMAS': (-7.460, 109.287),
    'BANYUWANGI': (-8.219, 114.369),
    'BATAM': (1.130, 104.051),
    'BATU': (-7.866, 112.534),
    'BOGOR': (-6.597, 106.806),
    'MALANG': (-7.983, 112.630),
    'BONTANG': (0.133, 117.481),
    'CIREBON': (-6.706, 108.557),
    'DEPOK': (-6.402, 106.794),
    'GARUT': (-7.227, 107.908),
    'JAKARTA': (-6.2088, 106.8456),
    'JEMBER': (-8.184, 113.668),
    'KEDIRI': (-7.816, 112.016),
    'KETAPANG': (-1.849, 109.971),
    'KLATEN': (-7.705, 110.603),
    'KOTAWARINGIN BARAT': (-2.677, 111.616),
    'KUDUS': (-6.804, 110.840),
    'LAMPUNG-METRO': (-5.117, 105.307),
    'LUBUKLINGGAU': (-3.293, 102.851),
    'MAKASSAR': (-5.147, 119.432),
    'MATARAM': (-8.583, 116.118),
    'MEDAN': (3.595, 98.672),
    'MUARA ENIM': (-3.650, 103.782),
    'PADANG': (-0.949, 100.353),
    'PALEMBANG': (-2.990, 104.757),
    'PALU': (-0.891, 119.870),
    'PASER': (-1.741, 116.427),
    'PATI': (-6.756, 111.036),
    'PEKALONGAN': (-6.888, 109.675),
    'PEKANBARU': (0.507, 101.447),
    'PONOROGO': (-7.867, 111.468),
    'PONTIANAK': (-0.026, 109.342),
    'SAMARINDA': (-0.502, 117.153),
    'SAMPIT': (-2.539, 112.949),
    'SEMARANG': (-6.966, 110.414),
    'SIDOARJO': (-7.4478, 112.7183),
    'SUKOHARJO': (-7.683, 110.84),
    'SURABAYA': (-7.2575, 112.7521),
    'TABALONG': (-2.18, 115.43),
    'TANGERANG': (-6.1783, 106.6319),
    'TULUNGAGUNG': (-8.0667, 111.9),
    'YOGYAKARTA': (-7.7956, 110.3695),
}

CITY_ALIASES = {
    'BANJAR BARU': ['BANJAR BARU', 'BANJARBARU', 'MARTAPURA'],
    'BANJARMASIN': ['BANJARMASIN', 'BANJAR MASIN'],
    'LAMPUNG-METRO': ['LAMPUNG-METRO', 'LAMPUNG METRO', 'METRO'],
    'KOTAWARINGIN BARAT': ['KOTAWARINGIN BARAT', 'PANGKALANBUN'],
    'MUARA ENIM': ['MUARA ENIM', 'MUARAENIM'],
    'BANGKA': ['BANGKA', 'KABUPATEN BANGKA'],
}


def normalize_city(city):
    value = (city or '').upper().strip()
    value = value.replace('KOTA ', '').replace('KABUPATEN ', '')
    value = re.sub(r'\s+', ' ', value)
    for canonical, aliases in CITY_ALIASES.items():
        if value == canonical.upper() or value in [a.upper() for a in aliases]:
            return canonical
    return value.title() if value else value


def city_coords(city):
    key = normalize_city(city)
    if key in CITY_CENTERS:
        return CITY_CENTERS[key]
    key_norm = re.sub(r'[^A-Z0-9]+', '', key.upper())
    for known, coords in CITY_CENTERS.items():
        known_norm = re.sub(r'[^A-Z0-9]+', '', known.upper())
        if key_norm in known_norm or known_norm in key_norm:
            return coords
    return (-2.5489, 118.0149)


def build_queries(city, name):
    city = (city or '').strip()
    name = (name or '').strip()
    clean_name = re.sub(r'\s*\([^)]*\)', ' ', name)
    clean_name = re.sub(r'\b(ONLINE|ADMIN|SALES\s+B2B|SPG|SPB|MT)\b', ' ', clean_name, flags=re.IGNORECASE)
    clean_name = re.sub(r'\s+', ' ', clean_name).strip(' -')
    city_variants = [city]
    canonical = normalize_city(city)
    city_variants.extend(CITY_ALIASES.get(canonical, []))
    base = [
        f'{place} {candidate}'
        for place in city_variants
        for candidate in [name, clean_name]
        if candidate
    ]
    base.extend([
        f'{clean_name} toko {city}',
        f'{clean_name} supermarket {city}',
        f'{clean_name} elektronik {city}',
        f'{clean_name} houseware {city}',
    ])
    seen = set()
    out = []
    for q in base:
        q = re.sub(r'\s+', ' ', q).strip()
        if q and q not in seen:
            out.append(q)
            seen.add(q)
    return out


def query_nominatim(query):
    url = 'https://nominatim.openstreetmap.org/search'
    params = {'q': query, 'format': 'json', 'limit': 10, 'addressdetails': 1, 'countrycodes': 'id'}
    headers = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'}
    req = urllib.request.Request(f'{url}?{urllib.parse.urlencode(params)}', headers=headers, method='GET')
    for attempt in range(2):
        try:
            with urllib.request.urlopen(req, timeout=8) as response:
                data = json.loads(response.read().decode('utf-8'))
            return data if isinstance(data, list) else []
        except (urllib.error.HTTPError, urllib.error.URLError, TimeoutError):
            if attempt == 0:
                time.sleep(2)
    return []


def pick_best_result(city, name, results):
    city_key = normalize_city(city).lower()
    name_tokens = set(re.findall(r'[a-z0-9]+', (name or '').lower()))
    best = None
    best_score = -999

    for item in results:
        display = (item.get('display_name') or '').lower()
        address = item.get('address') or {}
        city_name = (address.get('city') or address.get('town') or address.get('village') or '').lower()
        state_name = (address.get('state') or '').lower()
        if not display:
            continue

        score = 0
        if city_key in display:
            score += 30
        if city_key in city_name or city_key in state_name:
            score += 20
        for token in name_tokens:
            if token and token in display:
                score += 5
        if any(word in display for word in ['toko', 'swalayan', 'supermarket', 'mall', 'elektronik', 'houseware', 'grosir', 'store']):
            score += 2
        if score > best_score:
            best = item
            best_score = score

    return best


def search_place(city, name):
    for query in build_queries(city, name):
        results = query_nominatim(query)
        if not results:
            continue
        best = pick_best_result(city, name, results)
        if best:
            return {
                'lat': float(best['lat']),
                'lon': float(best['lon']),
                'address': best.get('display_name', query),
                'source': 'nominatim',
                'is_fallback': False,
                'confidence': 'review',
                'query': query,
            }

    lat, lon = city_coords(city)
    return {
        'lat': lat,
        'lon': lon,
        'address': f'{city}, Indonesia (fallback by city)',
        'source': 'city_fallback',
        'is_fallback': True,
        'confidence': 'low',
        'query': None,
    }


stores = [
    ('BALIKPAPAN', 'NUANSA BALIKPAPAN'),
    ('BALIKPAPAN', 'NUANSA BALIKPAPAN KILO'),
    ('BALIKPAPAN', 'SURYA JAYA BALIKPAPAN'),
    ('BALIKPAPAN', 'TOKO GYS JAYA'),
    ('BANDUNG', 'MUTIARA SUPER KITCHEN UJUNG BERUNG'),
    ('BANDUNG', 'BLIBLI ELEKTRONIK MOCH TOHA'),
    ('BANDUNG', 'HARTONO'),
    ('BANDUNG', 'LOGIN MEGASTORE (ABC)'),
    ('BANDUNG', 'MUTIARA SUPER KITCHEN'),
    ('BANDUNG', 'STEIN HARTONO'),
    ('BANDUNG', 'TOKO PANTES AGUNG (ONLINE)'),
    ('BANGKA', 'MITO - SALES B2B'),
    ('BANJAR BARU', 'NUANSA MARTAPURA'),
    ('BANJARMASIN', 'CENTRAL PLASTIK PALANGKARAYA'),
    ('BANJARMASIN', 'GOLDEN 10'),
    ('BANJARMASIN', 'PONDOK ELEKTRONIK'),
    ('BANJARMASIN', 'POS ELEKTRONIK'),
    ('BANJARMASIN', 'PONDOK ELEKTRONIK BANJARBARU'),
    ('BANYUMAS', 'DEPO PELITA'),
    ('BANYUWANGI', 'BARES'),
    ('BANYUWANGI', 'BARES ROGOJAMPI'),
    ('BATAM', "JACKSON'S HOMEWARE MARKET"),
    ('BATAM', 'UNIVERSAL PASIR PUTIH'),
    ('BATU', 'TOP 100 SWALAYAN'),
    ('BOGOR', 'CAHAYA BARU'),
    ('MALANG', 'OGAN BANDULAN & OGAN BONDOWOSO'),
    ('BONTANG', 'NUANSA ELEKTRONIK BONTANG'),
    ('CIREBON', 'SINAR JAYA'),
    ('DEPOK', 'HARAPAN BARU'),
    ('GARUT', 'TOKO SINAR GUNTUR'),
    ('JAKARTA', 'Admin National'),
    ('JAKARTA', 'JABODETABEK'),
    ('JAKARTA', 'HARTONO PONDOK INDAH'),
    ('JAKARTA', 'JAWA ELEKTRONIK'),
    ('JAKARTA', 'SPN HOUSEWARE'),
    ('JAKARTA', 'STEIN - SPG/SPB MT'),
    ('JAKARTA', 'TOKO ATRIA KELAPA GADING'),
    ('JAKARTA', 'TOKO EMETAL ASIA JAYA (ONLINE)'),
    ('JAKARTA', 'TOKO PALAPA'),
    ('JAKARTA', 'MALL KELAPA GADING'),
    ('JEMBER', 'TOKO RAMAI JAYA JEMBER'),
    ('JEMBER', 'TOKO RAMAI JAYA RAMBIPUJI'),
    ('KEDIRI', 'UFO ELEKTRONIK KEDIRI'),
    ('KETAPANG', 'OSCAR GEMILANG'),
    ('KLATEN', 'LARIS KLATEN'),
    ('KOTAWARINGIN BARAT', 'NUANSA PANGKALANBUN'),
    ('KUDUS', 'TOKO DEPO MURAH JAYA SENTOSA KUDUS'),
    ('LAMPUNG-METRO', 'CV GEMBIRA HOUSEWARE'),
    ('LUBUKLINGGAU', 'ANUGERAH SUKSES'),
    ('MAKASSAR', 'MAKASSAR'),
    ('MAKASSAR', 'ALASKA'),
    ('MAKASSAR', 'GRAND TOSERBA PENGAYOMAN'),
    ('MAKASSAR', 'MAXI ALAUDDIN'),
    ('MAKASSAR', 'MAXI PERINTIS'),
    ('MAKASSAR', 'SEMERU PERINTIS'),
    ('MAKASSAR', 'SEMERU SARAPPO'),
    ('MAKASSAR', 'TOKO IWATA'),
    ('MALANG', 'HARTONO MALANG'),
    ('MATARAM', 'DAPUR KITA MATARAM'),
    ('MATARAM', 'TOKO JAYA KITCHEN LOMBOK'),
    ('MEDAN', 'Kota Medan'),
    ('MEDAN', 'BALIKADO MENTENG'),
    ('MEDAN', 'IRAMA HOUSEWARE'),
    ('MEDAN', 'SH MART ELEKTRONIK'),
    ('MEDAN', 'WIEGO HOUSEWARE TRITURA'),
    ('MUARA ENIM', 'CV ANEKA JAYA ELEKTRONIK'),
    ('PADANG', 'A FAUZI'),
    ('PALEMBANG', 'PALEMBANG'),
    ('PALEMBANG', 'DAPOER MUTIARA (CV PELITA MAS)'),
    ('PALEMBANG', 'MDP SUPERSTORE'),
    ('PALU', 'ANUGRAH JAYA PALU'),
    ('PALU', 'HOKKY HOUSE WARE'),
    ('PALU', 'SMART KITCHEN'),
    ('PALU', 'STAR KITCHEN'),
    ('PASER', 'NUANSA ELEKTRONIC TANAH GROGOT'),
    ('PATI', 'PUSAT ELEKTRONIK'),
    ('PEKALONGAN', 'BERKAH JAYA ELECTRONICS'),
    ('PEKANBARU', 'BERKAT ELEKTRONIK'),
    ('PEKANBARU', 'GOLDEN DRAGON ELEKTRONIK'),
    ('PONOROGO', 'CV SAMI JAYA'),
    ('PONTIANAK', 'HIBURAN BARU SINGKAWANG'),
    ('PONTIANAK', 'TOKO PUTRA ELEKTRONIK'),
    ('SAMARINDA', 'MAMASUKA'),
    ('SAMARINDA', 'NUANSA BERAU'),
    ('SAMARINDA', 'NUANSA IMAM BONJOL'),
    ('SAMARINDA', 'NUANSA MATOS SAMARINDA'),
    ('SAMARINDA', 'TOKO SURYA JAYA'),
    ('SAMPIT', 'HOKKY OMEGA SAMPIT'),
    ('SEMARANG', 'ADA SILIWANGI'),
    ('SEMARANG', 'ADA SWALAYAN SETIABUDI'),
    ('SEMARANG', 'ATLANTA MAJAPAHIT'),
    ('SEMARANG', 'ATLANTA MATARAM'),
    ('SEMARANG', 'CANDI ELEKTRONIC 310'),
]

output = []
output_path = 'stores.json'
try:
    with open(output_path, 'r', encoding='utf-8') as f:
        existing = json.load(f)
    if isinstance(existing, list):
        output = existing
except (FileNotFoundError, json.JSONDecodeError):
    pass

completed_keys = {
    (item.get('city'), item.get('store'))
    for item in output
    if not item.get('is_fallback', False)
}
output_indexes = {
    (item.get('city'), item.get('store')): index
    for index, item in enumerate(output)
}
for idx, (city, name) in enumerate(stores, start=1):
    if (city, name) in completed_keys:
        print(f'[{idx}/{len(stores)}] {city} - {name} => already saved')
        continue

    data = search_place(city, name)
    record = {'store': name, 'city': city, **data}
    key = (city, name)
    if key in output_indexes:
        output[output_indexes[key]] = record
    else:
        output_indexes[key] = len(output)
        output.append(record)
    completed_keys.add((city, name))
    print(f'[{idx}/{len(stores)}] {city} - {name} => {data["lat"]:.6f}, {data["lon"]:.6f}')
    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(output, f, ensure_ascii=False, indent=2)
    time.sleep(0.5)

with open(output_path, 'w', encoding='utf-8') as f:
    json.dump(output, f, ensure_ascii=False, indent=2)

print(f'\nDone: {len(output)} stores written to {output_path}')