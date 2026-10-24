<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Usuarios de ejemplo por rol. El administrador principal usa ADMIN_PASSWORD;
 * el resto, TEST_PASSWORD (ver .env.example). Los roles los crea
 * RolesAndPermissionsSeeder.
 */
class AdminUserSeeder extends Seeder
{
    /**
     * [email, usuario, nombre, apellido, rol]. Los mecánicos se vinculan a su
     * ficha en MechanicSeeder.
     */
    public const USERS = [
        ['admin@mail.com.py', 'rminarro', 'Ruben', 'Minarro', 'administrador'],
        ['admin2@mail.com.py', 'mfernandez', 'Mónica', 'Fernández', 'administrador'],

        ['recepcion1@mail.com.py', 'lbenitez', 'Laura', 'Benítez', 'recepcionista'],
        ['recepcion2@mail.com.py', 'dortiz', 'Diego', 'Ortiz', 'recepcionista'],
        ['recepcion3@mail.com.py', 'sgimenez', 'Sofía', 'Giménez', 'recepcionista'],

        ['jefetaller1@mail.com.py', 'rvillalba', 'Roberto', 'Villalba', 'jefe-taller'],
        ['jefetaller2@mail.com.py', 'aacosta', 'Andrea', 'Acosta', 'jefe-taller'],

        ['caja1@mail.com.py', 'pcaceres', 'Patricia', 'Cáceres', 'cajero'],
        ['caja2@mail.com.py', 'fsosa', 'Fernando', 'Sosa', 'cajero'],

        ['deposito1@mail.com.py', 'mrojas', 'Marcelo', 'Rojas', 'deposito'],
        ['deposito2@mail.com.py', 'vduarte', 'Verónica', 'Duarte', 'deposito'],

        ['jperez@mail.com.py', 'jperez', 'Juan', 'Pérez', 'mechanic'],
        ['cgomez@mail.com.py', 'cgomez', 'Carlos', 'Gómez', 'mechanic'],
        ['mecanico3@mail.com.py', 'hcabrera', 'Hugo', 'Cabrera', 'mechanic'],
        ['mecanico4@mail.com.py', 'gbaez', 'Gustavo', 'Báez', 'mechanic'],
        ['mecanico5@mail.com.py', 'ealvarez', 'Elías', 'Álvarez', 'mechanic'],
        ['mecanico6@mail.com.py', 'nramirez', 'Néstor', 'Ramírez', 'mechanic'],
    ];

    public function run(): void
    {
        $adminPassword = Hash::make((string) env('ADMIN_PASSWORD'));
        $testPassword = Hash::make((string) env('TEST_PASSWORD', env('ADMIN_PASSWORD')));

        foreach (self::USERS as [$email, $name, $firstName, $lastName, $role]) {
            // Idempotente: un usuario existente conserva su contraseña y sus datos.
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'password' => $email === 'admin@mail.com.py' ? $adminPassword : $testPassword,
                ]
            );

            if (!$user->hasRole($role)) {
                $user->assignRole($role);
            }
        }
    }
}
