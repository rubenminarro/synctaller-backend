<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * Proveedores de ejemplo; el último está inactivo.
 */
class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            [
                'name' => 'Distribuidora de Repuestos Central',
                'tax_id' => '80000001-1',
                'contact_name' => 'Departamento de ventas',
                'phone' => '021 000 001',
                'email' => 'ventas.repuestoscentral@mail.com.py',
            ],
            [
                'name' => 'Lubricantes del Este',
                'tax_id' => '80000002-2',
                'contact_name' => 'Atención comercial',
                'phone' => '021 000 002',
                'email' => 'contacto.lubricanteseste@mail.com.py',
            ],
            [
                'name' => 'Frenos y Suspensiones Asunción',
                'tax_id' => '80000003-3',
                'contact_name' => 'Ventas mayoristas',
                'phone' => '021 000 003',
                'email' => 'ventas.frenosasuncion@mail.com.py',
            ],
            [
                'name' => 'Electro Auto Paraguay',
                'tax_id' => '80000004-4',
                'contact_name' => 'Mostrador',
                'phone' => '021 000 004',
                'email' => 'pedidos.electroauto@mail.com.py',
            ],
            [
                'name' => 'Neumáticos Mariscal',
                'tax_id' => '80000005-5',
                'contact_name' => 'Administración',
                'phone' => '021 000 005',
                'email' => 'administracion.neumaticosmariscal@mail.com.py',
                'is_active' => false,
                'notes' => 'Proveedor dado de baja: entregas incumplidas.',
            ],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::firstOrCreate(
                ['tax_id' => $supplier['tax_id']],
                ['is_active' => true, ...$supplier]
            );
        }
    }
}
