<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Brand;
use App\Models\VehicleModel;

class VehicleModelSeeder extends Seeder
{
    /**
     * Modelos por marca (por nombre, no por id). VehiclesSeeder los usa.
     */
    public const MODELS_BY_BRAND = [
        'Toyota' => ['Hilux', 'Corolla Cross', 'Corolla', 'Etios', 'Yaris', 'RAV4', 'SW4', 'Land Cruiser Prado', 'Land Cruiser 300', 'Tacoma'],
        'Hyundai' => ['HB20', 'HB20S', 'Creta', 'Tucson', 'Santa Fe', 'Palisade', 'Kona Electric', 'Ioniq 5', 'Venue', 'Accent'],
        'Kia' => ['Picanto', 'Soluto', 'Rio', 'Seltos', 'Sportage', 'Sorento', 'Sonet', 'Carens', 'EV6', 'Cerato'],
        'Chevrolet' => ['S10', 'Onix', 'Onix Plus', 'Tracker', 'Captiva', 'Montana', 'Silverado', 'Cruze', 'Spin', 'Equinox'],
        'Volkswagen' => ['Gol', 'Polo', 'Virtus', 'T-Cross', 'Nivus', 'Taos', 'Tiguan Allspace', 'Amarok', 'Saveiro', 'ID.4'],
        'BYD' => ['Yuan Plus', 'Song Plus DM-i', 'Dolphin', 'Seal', 'Han EV', 'Tang EV', 'Yuan Pro', 'D1', 'Seagull', 'Qin Plus'],
        'Changan' => ['CS15', 'CS35 Plus', 'CS55 Plus', 'UNI-T', 'UNI-K', 'Alsvin', 'Hunter', 'X7 Plus', 'Eado Plus', 'Lumin'],
        'Isuzu' => ['D-Max', 'Mu-X', 'NLR', 'NMR', 'NPR', 'NQR', 'FSR', 'FVR', 'FVZ', 'CYZ'],
        'Nissan' => ['Kicks', 'Frontier', 'Versa', 'Sentra', 'X-Trail', 'Qashqai', 'Patrol', 'Urvan', 'Leaf', 'Pathfinder'],
        'Mitsubishi' => ['L200 Triton', 'Montero Sport', 'Outlander', 'ASX', 'Eclipse Cross', 'XPANDER', 'Mirage', 'Attrage', 'Lancer', 'Pajero Full'],
        'Ford' => ['Ranger', 'F-150', 'Maverick', 'Everest', 'Territory', 'Bronco Sport', 'Mustang', 'Explorer', 'Transit', 'Edge'],
        'Mercedes-Benz' => ['C-Class', 'E-Class', 'S-Class', 'GLA', 'GLB', 'GLC', 'GLE', 'GLS', 'EQE', 'EQS'],
        'BMW' => ['3 Series', '5 Series', 'X1', 'X3', 'X5', 'X6', 'X7', 'iX', 'i4', 'M3'],
        'GWM' => ['Haval H6', 'Haval Jolion', 'Poer', 'Wingle 7', 'Tank 300', 'Tank 500', 'Ora 03', 'Haval M6', 'Haval Dargo', 'Wingle 5'],
        'Chery' => ['Tiggo 2 Pro', 'Tiggo 4 Pro', 'Tiggo 7 Pro', 'Tiggo 8 Pro', 'Arrizo 5', 'Arrizo 6', 'Omoda 5', 'EQ1', 'Tiggo 3', 'Tiggo 5x'],
        'Jeep' => ['Renegade', 'Compass', 'Commander', 'Grand Cherokee', 'Wrangler', 'Gladiator', 'Avenger', 'Meridian', 'Cherokee', 'Rubicon'],
        'JAC Motors' => ['T6', 'T8', 'JS2', 'JS3', 'JS4', 'JS6', 'JS8', 'E10X', 'Sunray', 'M4'],
        'Audi' => ['A3', 'A4', 'A6', 'Q3', 'Q5', 'Q7', 'Q8', 'e-tron', 'RS e-tron GT', 'Q2'],
        'Fiat' => ['Strada', 'Toro', 'Cronos', 'Pulse', 'Fastback', 'Mobi', 'Argo', 'Fiorino', 'Ducato', '500e'],
        'Honda' => ['Civic', 'HR-V', 'CR-V', 'City', 'City Hatchback', 'WR-V', 'Accord', 'Pilot', 'ZR-V', 'Fit'],
        'Suzuki' => ['Jimny', 'Swift', 'Vitara', 'Grand Vitara', 'S-Presso', 'Ertiga', 'Baleno', 'Dzire', 'Celerio', 'Fronx'],
        'Mazda' => ['CX-5', 'CX-30', 'CX-3', 'CX-50', 'CX-60', 'CX-90', 'Mazda 2', 'Mazda 3', 'BT-50', 'MX-5'],
        'Geely' => ['Coolray', 'Azkarra', 'Okavango', 'Emgrand', 'GX3 Pro', 'Tugella', 'Monjaro', 'Geometry C', 'Starray', 'Binray'],
        'Land Rover' => ['Defender', 'Range Rover Evoque', 'Range Rover Sport', 'Range Rover Velar', 'Discovery', 'Discovery Sport', 'Range Rover', 'Defender 110', 'Defender 90', 'Freelander'],
        'Renault' => ['Kwid', 'Stepway', 'Duster', 'Oroch', 'Kardian', 'Logan', 'Sandero', 'Captur', 'Megane E-Tech', 'Master'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $brandIds = Brand::whereIn('name', array_keys(self::MODELS_BY_BRAND))->pluck('id', 'name');
        $now = now();
        $rows = [];

        foreach (self::MODELS_BY_BRAND as $brandName => $models) {
            foreach ($brandIds->has($brandName) ? $models : [] as $modelName) {
                $rows[] = ['brand_id' => $brandIds[$brandName], 'name' => $modelName, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        // Una sola consulta (antes, una búsqueda y un alta por modelo). Idempotente: los únicos (brand_id, name) y
        // name omiten los modelos que ya existen, también los dados de baja.
        VehicleModel::insertOrIgnore($rows);
    }
}
