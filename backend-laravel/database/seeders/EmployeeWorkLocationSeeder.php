<?php

namespace Database\Seeders;

use App\Enums\WorkAreaType;
use App\Models\EmployeeWorkLocation;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT ONLY — demo employee work locations (city + work area).
 *
 * Idempotent: keyed by `code`. Rows without coordinates must be completed
 * from the dashboard before they can serve as a geofence.
 */
class EmployeeWorkLocationSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * @var list<array{code: string, name: string, city: string, area_type: WorkAreaType, address: string|null, latitude?: float, longitude?: float, radius_meters?: float}>
     */
    protected const LOCATIONS = [
        [
            'code' => 'EWL-DEV-HO-JKT',
            'name' => 'Head Office Jakarta',
            'city' => 'Jakarta',
            'area_type' => WorkAreaType::HeadOffice,
            'address' => 'Rukan Golf Island Blok E Theme Park RGIE No. 92, RT.96-98/RW.112-116, Kamal Muara, Kecamatan Penjaringan, Daerah Khusus Ibukota Jakarta 14460',
            'latitude' => -6.08831825073565,
            'longitude' => 106.74385095344795,
            'radius_meters' => 150,
        ],
        ['code' => 'EWL-DEV-PBK-CKR', 'name' => 'Pabrik Cikarang', 'city' => 'Bekasi', 'area_type' => WorkAreaType::Factory, 'address' => 'Kawasan Industri Jababeka, Cikarang'],
        ['code' => 'EWL-DEV-PBK-SDA', 'name' => 'Pabrik Sidoarjo', 'city' => 'Sidoarjo', 'area_type' => WorkAreaType::Factory, 'address' => 'Jl. Raya Industri, Sidoarjo'],
        ['code' => 'EWL-DEV-CBG-BDG', 'name' => 'Cabang Bandung', 'city' => 'Bandung', 'area_type' => WorkAreaType::Branch, 'address' => 'Jl. Asia Afrika No. 10, Bandung'],
        ['code' => 'EWL-DEV-CBG-SBY', 'name' => 'Cabang Surabaya', 'city' => 'Surabaya', 'area_type' => WorkAreaType::Branch, 'address' => 'Jl. Basuki Rahmat No. 5, Surabaya'],
        ['code' => 'EWL-DEV-CBG-SMG', 'name' => 'Cabang Semarang', 'city' => 'Semarang', 'area_type' => WorkAreaType::Branch, 'address' => 'Jl. Pemuda No. 20, Semarang'],
        ['code' => 'EWL-DEV-CBG-MDN', 'name' => 'Cabang Medan', 'city' => 'Medan', 'area_type' => WorkAreaType::Branch, 'address' => 'Jl. Gatot Subroto No. 8, Medan'],
        ['code' => 'EWL-DEV-CBG-YOG', 'name' => 'Cabang Yogyakarta', 'city' => 'Yogyakarta', 'area_type' => WorkAreaType::Branch, 'address' => 'Jl. Malioboro No. 3, Yogyakarta'],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        foreach (self::LOCATIONS as $location) {
            $row = EmployeeWorkLocation::withTrashed()->firstOrNew(['code' => $location['code']]);
            $row->fill([
                'name' => $location['name'],
                'city' => $location['city'],
                'area_type' => $location['area_type']->value,
                'address' => $location['address'],
                'latitude' => $location['latitude'] ?? null,
                'longitude' => $location['longitude'] ?? null,
                'radius_meters' => $location['radius_meters'] ?? null,
                'status' => 'active',
            ]);
            $row->deleted_at = null;
            $row->save();
            $row->syncLocationPoint();
        }
    }
}
