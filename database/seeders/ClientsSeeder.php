<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Client;

/**
 * Clientes de ejemplo. Dos casos especiales para los avisos por email: uno sin
 * email (los avisos quedan omitidos; la API exige email, así que para editarlo
 * hay que cargarle uno) y uno dado de baja de los avisos. Los emails usan el
 * dominio reservado example.com (nunca recibe correo); no pasan la validación
 * con EMAIL_DNS_CHECK=true.
 */
class ClientsSeeder extends Seeder
{
    /** Documento del cliente sin email. */
    public const WITHOUT_EMAIL = '30111222';

    /** Documento del cliente dado de baja de los avisos. */
    public const NOTIFICATIONS_DISABLED = '30222333';

    public function run(): void
    {
        // [documento, nombre, apellido, teléfono, email]
        $clients = [
            ['12345678', 'Juan', 'Pérez', '595981111222', 'juan@example.com'],
            ['23456789', 'María', 'García', '595982222333', 'maria@example.com'],
            ['34567890', 'Carlos', 'López', '595983333444', 'carlos@example.com'],
            ['45678901', 'Ana', 'Martínez', '595984444555', 'ana@example.com'],
            ['56789012', 'Luis', 'Rodríguez', '595985555666', 'luis@example.com'],
            ['3456123', 'Rosa', 'Aquino', '595971234501', 'rosa.aquino@example.com'],
            ['4123987', 'Miguel', 'Benítez', '595972345602', 'miguel.benitez@example.com'],
            ['4789321', 'Silvia', 'Cardozo', '595973456703', 'silvia.cardozo@example.com'],
            ['5012345', 'Jorge', 'Duarte', '595974567804', 'jorge.duarte@example.com'],
            ['5234567', 'Gabriela', 'Espínola', '595975678905', 'gabriela.espinola@example.com'],
            ['5456789', 'Ricardo', 'Franco', '595976789006', 'ricardo.franco@example.com'],
            ['5678901', 'Liliana', 'González', '595977890107', 'liliana.gonzalez@example.com'],
            ['5890123', 'Óscar', 'Ibarra', '595978901208', 'oscar.ibarra@example.com'],
            ['6012345', 'Natalia', 'Jara', '595979012309', 'natalia.jara@example.com'],
            ['6234567', 'Pablo', 'Ledesma', '595981123410', 'pablo.ledesma@example.com'],
            ['6456789', 'Carolina', 'Martínez', '595982234511', 'carolina.martinez@example.com'],
            ['6678901', 'Daniel', 'Núñez', '595983345612', 'daniel.nunez@example.com'],
            ['6890123', 'Fabiola', 'Ocampos', '595984456713', 'fabiola.ocampos@example.com'],
            ['7012345', 'Ramón', 'Paredes', '595985567814', 'ramon.paredes@example.com'],
            ['7234567', 'Teresa', 'Quiñónez', '595986678915', 'teresa.quinonez@example.com'],
            ['7456789', 'Alberto', 'Riquelme', '595991789016', 'alberto.riquelme@example.com'],
            ['7678901', 'Cynthia', 'Servín', '595992890117', 'cynthia.servin@example.com'],
            ['7890123', 'Héctor', 'Torres', '595993901218', 'hector.torres@example.com'],
            ['8012345', 'Marta', 'Valdez', '595994012319', 'marta.valdez@example.com'],
            ['8234567', 'Iván', 'Zárate', '595995123420', 'ivan.zarate@example.com'],
            // Empresas (RUC): el nombre y el apellido solo admiten letras y espacios.
            ['80012345-6', 'Transportes', 'del Sur', '59521600100', 'flota@transportesdelsur.example.com'],
            ['80023456-7', 'Logística', 'Guaraní', '59521600200', 'taller@logisticaguarani.example.com'],
            ['8456789', 'Celeste', 'Amarilla', '595996234521', 'celeste.amarilla@example.com'],
            [self::WITHOUT_EMAIL, 'Bernardo', 'Galeano', '595997345622', null],
            [self::NOTIFICATIONS_DISABLED, 'Estela', 'Florentín', '595998456723', 'estela.florentin@example.com'],
        ];

        // Idempotente: omite los clientes cuyo documento ya existe.
        foreach ($clients as [$document, $firstName, $lastName, $phone, $email]) {
            Client::firstOrCreate(
                ['document_number' => $document],
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                    'email' => $email,
                    'notifications_enabled' => $document !== self::NOTIFICATIONS_DISABLED,
                    'notes' => $document === self::NOTIFICATIONS_DISABLED ? 'Pidió no recibir avisos por email.' : null,
                ]
            );
        }
    }
}
