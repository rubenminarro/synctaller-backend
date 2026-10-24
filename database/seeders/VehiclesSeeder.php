<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Brand;
use App\Models\Client;
use App\Models\Vehicle;
use App\Models\VehicleModel;

/**
 * Vehículos de ejemplo de los clientes de ClientsSeeder. Uno no tiene placa
 * (no_plate) y se identifica por el chasis.
 */
class VehiclesSeeder extends Seeder
{
    /** Chasis del vehículo sin placa. */
    public const WITHOUT_PLATE_CHASSIS = '9BWZZZ377VT004251';

    public function run(): void
    {
        // [documento del cliente, marca, modelo, chasis, placa (null: sin placa), color (App\Enums\VehicleColor), año, kilometraje]
        $data = [
            ['12345678', 'Toyota', 'Hilux', '7H4G92', 'AAA111', 'white', 2019, 125000],
            ['23456789', 'Hyundai', 'HB20S', '2J8F12', 'BBB222', 'gray', 2020, 89000],
            ['34567890', 'Kia', 'Rio', '9K3L45', 'CCC333', 'black', 2016, 156000],
            ['45678901', 'Chevrolet', 'Tracker', '1M2N56', 'DDD444', 'red', 2021, 74000],
            ['56789012', 'Volkswagen', 'Nivus', '5P6Q78', 'EEE555', 'blue', 2022, 45000],
            ['12345678', 'Nissan', 'Leaf', '3S4T90', 'FFF666', 'silver', 2021, 38000],
            ['23456789', 'Mitsubishi', 'Pajero Full', '8V7W12', 'GGG777', 'green', 2014, 210000],
            ['34567890', 'Ford', 'Ranger', '4X5Y34', 'HHH888', 'red', 2018, 132000],
            ['45678901', 'BMW', 'X1', '6Z1A56', 'III999', 'white', 2020, 61000],
            ['56789012', 'Honda', 'Fit', '2B3C78', 'JJJ000', 'gray', 2015, 143000],
            ['12345678', 'Suzuki', 'Jimny', '9D4E90', 'KKK123', 'blue', 2023, 12000],
            ['23456789', 'Mazda', 'CX-30', '7F5G12', 'LLL456', 'black', 2022, 28000],
            ['34567890', 'Mercedes-Benz', 'E-Class', '1H6I34', 'MMM789', 'beige', 2017, 98000],
            ['45678901', 'Audi', 'e-tron', '5J7K56', 'NNN012', 'white', 2023, 15000],
            ['56789012', 'Jeep', 'Gladiator', '3L8M78', 'PPP345', 'black', 2021, 52000],
            ['3456123', 'Toyota', 'Corolla', '8N2P10', 'QAB101', 'silver', 2018, 118000],
            ['4123987', 'Kia', 'Sportage', '6Q4R21', 'QAB102', 'white', 2020, 76000],
            ['4789321', 'Volkswagen', 'Gol', '4S6T32', 'QAB103', 'red', 2012, 231000],
            ['5012345', 'Hyundai', 'Creta', '2U8V43', 'QAB104', 'gray', 2021, 49000],
            ['5234567', 'Chevrolet', 'Onix', '9W1X54', 'QAB105', 'blue', 2023, 18000],
            ['5456789', 'Ford', 'Ranger', '7Y3Z65', 'QAB106', 'white', 2016, 189000],
            ['5678901', 'Nissan', 'Kicks', '5A5B76', 'QAB107', 'orange', 2022, 34000],
            ['5890123', 'Isuzu', 'D-Max', '3C7D87', 'QAB108', 'gray', 2019, 142000],
            ['6012345', 'Fiat', 'Strada', '1E9F98', 'QAB109', 'white', 2021, 67000],
            ['6234567', 'Renault', 'Duster', '8G2H09', 'QAB110', 'brown', 2017, 121000],
            ['6456789', 'Honda', 'HR-V', '6J4K10', 'QAB111', 'black', 2020, 58000],
            ['6678901', 'Toyota', 'Hilux', '4L6M21', 'QAB112', 'silver', 2015, 254000],
            ['6890123', 'BYD', 'Dolphin', '2N8P32', 'QAB113', 'red', 2024, 6000],
            ['7012345', 'Mitsubishi', 'L200 Triton', '9Q1R43', 'QAB114', 'white', 2018, 167000],
            ['7234567', 'Suzuki', 'Swift', '7S3T54', 'QAB115', 'red', 2019, 72000],
            ['7456789', 'Chery', 'Tiggo 4 Pro', '5U5V65', 'QAB116', 'gray', 2023, 21000],
            ['7678901', 'Geely', 'Coolray', '3W7X76', 'QAB117', 'blue', 2022, 31000],
            ['7890123', 'GWM', 'Haval H6', '1Y9Z87', 'QAB118', 'black', 2023, 24000],
            ['8012345', 'Volkswagen', 'Amarok', '8A2B98', 'QAB119', 'gray', 2017, 176000],
            ['8234567', 'Hyundai', 'Tucson', '6C4D09', 'QAB120', 'white', 2019, 93000],
            ['80012345-6', 'Isuzu', 'NPR', '4E6F10', 'QAB121', 'white', 2016, 312000],
            ['80012345-6', 'Isuzu', 'NQR', '2G8H21', 'QAB122', 'white', 2018, 254000],
            ['80012345-6', 'Toyota', 'Hilux', '9J1K32', 'QAB123', 'white', 2022, 88000],
            ['80023456-7', 'Ford', 'Transit', '7L3M43', 'QAB124', 'white', 2020, 145000],
            ['80023456-7', 'Fiat', 'Fiorino', '5N5P54', 'QAB125', 'white', 2021, 99000],
            ['8456789', 'Kia', 'Picanto', '3Q7R65', 'QAB126', 'yellow', 2018, 84000],
            [ClientsSeeder::WITHOUT_EMAIL, 'Chevrolet', 'S10', '1S9T76', 'QAB127', 'black', 2014, 278000],
            [ClientsSeeder::NOTIFICATIONS_DISABLED, 'Toyota', 'Yaris', '8U2V87', 'QAB128', 'red', 2020, 61000],
            ['7012345', 'Mazda', 'BT-50', '6W4X98', 'QAB129', 'gray', 2016, 198000],
            ['5012345', 'Volkswagen', 'Saveiro', self::WITHOUT_PLATE_CHASSIS, null, 'white', 2024, 300],
        ];

        foreach ($data as [$document, $brandName, $modelName, $chassis, $plate, $color, $year, $mileage]) {
            $clientId = Client::where('document_number', $document)->value('id');
            $brandId = Brand::where('name', $brandName)->value('id');
            $modelId = VehicleModel::where('brand_id', $brandId)->where('name', $modelName)->value('id');

            if (!$clientId || !$brandId || !$modelId) {
                continue;
            }

            // Idempotente: omite los vehículos cuya placa (o, sin placa, cuyo chasis) ya existe.
            Vehicle::firstOrCreate(
                $plate ? ['plate' => $plate] : ['chassis' => $chassis],
                [
                    'client_id' => $clientId,
                    'brand_id' => $brandId,
                    'vehicle_model_id' => $modelId,
                    'chassis' => $chassis,
                    'no_plate' => $plate === null,
                    'color' => $color,
                    'year' => $year,
                    'mileage' => $mileage,
                ]
            );
        }
    }
}
