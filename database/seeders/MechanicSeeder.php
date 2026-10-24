<?php

namespace Database\Seeders;

use App\Enums\MechanicStatus;
use App\Models\Mechanic;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Fichas de mecánico vinculadas a los usuarios con rol mechanic
 * (AdminUserSeeder): cuatro activas, una inactiva y una de licencia, con
 * distinto costo por hora y comisión.
 */
class MechanicSeeder extends Seeder
{
    public function run(): void
    {
        // [email del usuario, código, especialidad, años de antigüedad, costo por hora, comisión %, estado, notas]
        $mechanics = [
            ['jperez@mail.com.py', 'MEC-001', 'Inyección Electrónica y Motor', 2, 45000, 10.00, MechanicStatus::ACTIVE, 'Especialista senior del taller.'],
            ['cgomez@mail.com.py', 'MEC-002', 'Frenos y Suspensión', 1, 35000, 8.50, MechanicStatus::ACTIVE, 'Encargado del área de alineación.'],
            ['mecanico3@mail.com.py', 'MEC-003', 'Electricidad del automotor', 4, 40000, 9.00, MechanicStatus::ACTIVE, null],
            ['mecanico4@mail.com.py', 'MEC-004', 'Chapería y pintura', 3, 38000, 7.50, MechanicStatus::ACTIVE, 'Cabina de pintura.'],
            ['mecanico5@mail.com.py', 'MEC-005', 'Mecánica general', 6, 30000, 6.00, MechanicStatus::INACTIVE, 'Dejó el taller; se conserva su historial.'],
            ['mecanico6@mail.com.py', 'MEC-006', 'Neumáticos y tren delantero', 1, 28000, 5.00, MechanicStatus::ON_LEAVE, 'De licencia por vacaciones.'],
        ];

        foreach ($mechanics as [$email, $code, $specialty, $years, $hourCost, $commission, $status, $notes]) {
            $userId = User::where('email', $email)->value('id');

            if (!$userId) {
                continue;
            }

            // Idempotente: una ficha existente (por usuario o por código) no se toca.
            if (Mechanic::withTrashed()->where('user_id', $userId)->orWhere('employee_code', $code)->exists()) {
                continue;
            }

            Mechanic::create([
                'user_id' => $userId,
                'employee_code' => $code,
                'specialty' => $specialty,
                'hire_date' => now()->subYears($years)->format('Y-m-d'),
                'hour_cost' => $hourCost,
                'commission_percentage' => $commission,
                'status' => $status->value,
                'notes' => $notes,
            ]);
        }
    }
}
