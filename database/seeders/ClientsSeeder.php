<?php

namespace Database\Seeders;

use App\Enums\ClientKind;
use Illuminate\Database\Seeder;
use App\Models\Client;

/**
 * Clientes de ejemplo, uno dado de baja de los avisos por email. Los emails usan
 * mail.com.py, como los usuarios de ejemplo (decisión del 2026-10-30): pasan la
 * validación con EMAIL_DNS_CHECK=true. Es un dominio real con correo: con un
 * SMTP configurado, un aviso reenviado de un cliente de ejemplo sale de verdad.
 */
class ClientsSeeder extends Seeder
{
    /** Documento del cliente del escenario C15 (hasta D8, el cliente sin email). */
    public const C15_OWNER = '30111222';

    /** Documento del cliente dado de baja de los avisos. */
    public const NOTIFICATIONS_DISABLED = '30222333';

    /** RUC del cliente empresa. */
    public const COMPANY = '80045678-1';

    public function run(): void
    {
        // [documento, nombre, apellido, teléfono, email]
        $clients = [
            ['12345678', 'Juan', 'Pérez', '595981111222', 'juan@mail.com.py'],
            ['23456789', 'María', 'García', '595982222333', 'maria@mail.com.py'],
            ['34567890', 'Carlos', 'López', '595983333444', 'carlos@mail.com.py'],
            ['45678901', 'Ana', 'Martínez', '595984444555', 'ana@mail.com.py'],
            ['56789012', 'Luis', 'Rodríguez', '595985555666', 'luis@mail.com.py'],
            ['3456123', 'Rosa', 'Aquino', '595971234501', 'rosa.aquino@mail.com.py'],
            ['4123987', 'Miguel', 'Benítez', '595972345602', 'miguel.benitez@mail.com.py'],
            ['4789321', 'Silvia', 'Cardozo', '595973456703', 'silvia.cardozo@mail.com.py'],
            ['5012345', 'Jorge', 'Duarte', '595974567804', 'jorge.duarte@mail.com.py'],
            ['5234567', 'Gabriela', 'Espínola', '595975678905', 'gabriela.espinola@mail.com.py'],
            ['5456789', 'Ricardo', 'Franco', '595976789006', 'ricardo.franco@mail.com.py'],
            ['5678901', 'Liliana', 'González', '595977890107', 'liliana.gonzalez@mail.com.py'],
            ['5890123', 'Óscar', 'Ibarra', '595978901208', 'oscar.ibarra@mail.com.py'],
            ['6012345', 'Natalia', 'Jara', '595979012309', 'natalia.jara@mail.com.py'],
            ['6234567', 'Pablo', 'Ledesma', '595981123410', 'pablo.ledesma@mail.com.py'],
            ['6456789', 'Carolina', 'Martínez', '595982234511', 'carolina.martinez@mail.com.py'],
            ['6678901', 'Daniel', 'Núñez', '595983345612', 'daniel.nunez@mail.com.py'],
            ['6890123', 'Fabiola', 'Ocampos', '595984456713', 'fabiola.ocampos@mail.com.py'],
            ['7012345', 'Ramón', 'Paredes', '595985567814', 'ramon.paredes@mail.com.py'],
            ['7234567', 'Teresa', 'Quiñónez', '595986678915', 'teresa.quinonez@mail.com.py'],
            ['7456789', 'Alberto', 'Riquelme', '595991789016', 'alberto.riquelme@mail.com.py'],
            ['7678901', 'Cynthia', 'Servín', '595992890117', 'cynthia.servin@mail.com.py'],
            ['7890123', 'Héctor', 'Torres', '595993901218', 'hector.torres@mail.com.py'],
            ['8012345', 'Marta', 'Valdez', '595994012319', 'marta.valdez@mail.com.py'],
            ['8234567', 'Iván', 'Zárate', '595995123420', 'ivan.zarate@mail.com.py'],
            // Empresas (RUC): el nombre y el apellido solo admiten letras y espacios.
            ['80012345-6', 'Transportes', 'del Sur', '59521600100', 'flota.transportesdelsur@mail.com.py'],
            ['80023456-7', 'Logística', 'Guaraní', '59521600200', 'taller.logisticaguarani@mail.com.py'],
            ['8456789', 'Celeste', 'Amarilla', '595996234521', 'celeste.amarilla@mail.com.py'],
            [self::C15_OWNER, 'Bernardo', 'Galeano', '595997345622', 'bernardo.galeano@mail.com.py'],
            [self::NOTIFICATIONS_DISABLED, 'Estela', 'Florentín', '595998456723', 'estela.florentin@mail.com.py'],
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

        // Empresa (M1): razón social en lugar de nombre y apellido. Sin vehículos ni recepciones en los escenarios.
        Client::firstOrCreate(
            ['document_number' => self::COMPANY],
            [
                'kind' => ClientKind::COMPANY,
                'business_name' => 'Transportes del Este S.A.',
                'phone' => '595216001234',
                'email' => 'administracion.transportes-este@mail.com.py',
                'address' => 'Ruta 2 km 15, Capiatá',
            ]
        );
    }
}
