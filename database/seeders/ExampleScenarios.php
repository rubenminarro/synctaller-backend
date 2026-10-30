<?php

namespace Database\Seeders;

use App\Enums\FuelLevel;
use App\Models\Mechanic;
use App\Models\Reception;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Escenarios de ejemplo del flujo del taller: cada uno es una recepción y hasta
 * dónde llega (diagnóstico, presupuesto, OT, cancelación). Cada seeder de
 * módulo recorre los escenarios y hace su parte por los Services, con el reloj
 * en la fecha del evento (Carbon::setTestNow) para repartir los datos en los
 * últimos meses.
 *
 * Fechas: `days` es hace cuántos días llegó el vehículo; los eventos se ubican
 * con un desplazamiento en días desde esa llegada y nunca después de ahora.
 *
 * - H01–H17: trabajos terminados (OT completada), del más antiguo al más reciente.
 * - C01–C16: trabajos abiertos, uno por estado intermedio (C15–C16: clientes que no reciben avisos).
 * - X01–X02: recepciones canceladas.
 */
final class ExampleScenarios
{
    private static ?CarbonImmutable $now = null;

    /**
     * Diagnóstico: [código del mecánico, estado, prioridad, queja, diagnóstico, recomendación, ítems (DiagnosticItemSeeder::TEMPLATES)].
     * Presupuesto: estado final (draft|sent|approved|rejected|reopened|cancelled) e ítems
     *   [service, nombre] | [part, SKU, cantidad] | [labor, descripción, precio].
     * OT: estado final, mecánico responsable, día (desde la llegada) en que se completa, y casos especiales.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $r1 = 'recepcion1@mail.com.py';
        $r2 = 'recepcion2@mail.com.py';
        $r3 = 'recepcion3@mail.com.py';

        return [
            // ---------------------------------------------------------------- Trabajos terminados
            [
                'key' => 'H01', 'plate' => 'AAA111', 'days' => 88, 'receptionist' => $r1, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Service de los 120.000 km.',
                'diagnostics' => [['MEC-001', 'completed', 'low', 'Service programado.', 'Aceite y filtro vencidos.', 'Cambio de aceite y filtro.', ['oil_due']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Cambio de aceite y filtro'], ['part', 'LUB-5W30-001', 5], ['part', 'FIL-ACE-001', 1]]],
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-001', 'done' => 1],
            ],
            [
                'key' => 'H02', 'plate' => 'GGG777', 'days' => 85, 'receptionist' => $r2, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::QUARTER, 'problem' => 'Ruido en la distribución al arrancar en frío.',
                'diagnostics' => [['MEC-005', 'completed', 'high', 'Ruido metálico al arrancar.', 'Correa de distribución con desgaste y tensor flojo.', 'Cambiar kit de distribución y bujías.', ['timing_belt', 'spark_plugs']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Cambio de correa de distribución'], ['part', 'MOT-COR-001', 1], ['part', 'MOT-BUJ-001', 4]]],
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-005', 'done' => 3],
            ],
            [
                'key' => 'H03', 'plate' => 'HHH888', 'days' => 80, 'receptionist' => $r1, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Frena poco y chirría.',
                'diagnostics' => [['MEC-002', 'completed', 'high', 'Chirrido al frenar.', 'Pastillas al límite y líquido contaminado.', 'Cambiar pastillas y líquido de frenos.', ['brake_pads', 'brake_fluid']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Cambio de pastillas de freno'], ['part', 'FRE-PAS-001', 1], ['part', 'FRE-LIQ-001', 1]]],
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-002', 'done' => 2],
            ],
            [
                'key' => 'H04', 'plate' => 'III999', 'days' => 75, 'receptionist' => $r3, 'categories' => ['general', 'mechanical'],
                'fuel' => FuelLevel::FULL, 'problem' => 'Service y revisión general.',
                'diagnostics' => [['MEC-001', 'completed', 'low', 'Revisión general.', 'Todo en orden salvo el aceite.', 'Cambio de aceite y filtro.', ['oil_due', 'wipers_ok']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Cambio de aceite y filtro'], ['part', 'LUB-5W30-001', 5], ['part', 'FIL-ACE-001', 1]]],
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-001', 'done' => 1],
            ],
            [
                'key' => 'H05', 'plate' => 'JJJ000', 'days' => 70, 'receptionist' => $r2, 'categories' => ['tires'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Tira hacia la derecha.',
                'diagnostics' => [['MEC-006', 'completed', 'medium', 'Se va hacia un lado.', 'Desalineado y neumáticos con desgaste desparejo.', 'Alinear, balancear y rotar.', ['alignment']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Alineación y balanceo'], ['service', 'Rotación de neumáticos']]],
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-006', 'done' => 1],
            ],
            [
                'key' => 'H06', 'plate' => 'KKK123', 'days' => 65, 'receptionist' => $r1, 'categories' => ['electrical'],
                'fuel' => FuelLevel::THREE_QUARTERS, 'problem' => 'No arranca por las mañanas.',
                'diagnostics' => [['MEC-003', 'completed', 'high', 'Falla de arranque.', 'Batería agotada.', 'Reemplazar la batería.', ['battery', 'headlight']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Diagnóstico eléctrico'], ['service', 'Cambio de batería'], ['part', 'ELE-BAT-001', 1]]],
                // Extra aprobado por el cliente: lámparas quemadas encontradas durante el trabajo.
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-003', 'done' => 2, 'extras' => [['ELE-LAM-001', 2, 'approve']]],
            ],
            [
                'key' => 'H07', 'plate' => 'LLL456', 'days' => 60, 'receptionist' => $r3, 'categories' => ['bodywork'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Pintura opaca.',
                'diagnostics' => [['MEC-004', 'completed', 'low', 'Quiere recuperar el brillo.', 'Pintura oxidada superficialmente.', 'Pulido y encerado.', ['paint_dull']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Pulido y encerado']]],
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-004', 'done' => 3],
            ],
            [
                'key' => 'H08', 'plate' => 'MMM789', 'days' => 55, 'receptionist' => $r2, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Tironea al acelerar.',
                'diagnostics' => [['MEC-001', 'completed', 'medium', 'Pierde potencia.', 'Bujías y filtro de aire saturados.', 'Afinamiento de motor.', ['spark_plugs', 'air_filter']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Afinamiento de motor'], ['part', 'MOT-BUJ-001', 4], ['part', 'FIL-AIR-001', 1]]],
                // Trabajo compartido (60/40) y un filtro que no era el del modelo, devuelto al depósito.
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-001', 'done' => 3, 'shared' => ['MEC-001' => 60, 'MEC-003' => 40], 'return' => 'FIL-AIR-001'],
            ],
            [
                'key' => 'H09', 'plate' => 'NNN012', 'days' => 50, 'receptionist' => $r1, 'categories' => ['general', 'electrical'],
                'fuel' => FuelLevel::FULL, 'problem' => 'Testigo de falla encendido.',
                'diagnostics' => [['MEC-003', 'completed', 'medium', 'Luz de falla en el tablero.', 'Falso contacto en un sensor.', 'Escanear y limpiar conectores.', ['sensor_fault']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Diagnóstico general'], ['service', 'Escaneo computarizado']]],
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-003', 'done' => 1],
            ],
            [
                'key' => 'H10', 'plate' => 'PPP345', 'days' => 45, 'receptionist' => $r3, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Golpes en la suspensión.',
                'diagnostics' => [['MEC-002', 'completed', 'critical', 'Golpea en los pozos.', 'Amortiguadores y bujes vencidos.', 'Cambiar amortiguadores y bujes.', ['shock_absorber', 'bushings']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Instalación de amortiguadores'], ['part', 'SUS-AMO-001', 2], ['part', 'SUS-BUJ-001', 1]]],
                // Extra rechazado: el cliente no quiso cambiar los discos.
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-002', 'done' => 3, 'extras' => [['FRE-DIS-001', 2, 'reject']]],
            ],
            [
                'key' => 'H11', 'plate' => 'QAB110', 'days' => 35, 'receptionist' => $r2, 'categories' => ['tires'],
                'fuel' => FuelLevel::QUARTER, 'problem' => 'Vibra en ruta.',
                'diagnostics' => [['MEC-001', 'completed', 'medium', 'Vibración a más de 80 km/h.', 'Ruedas desbalanceadas.', 'Alineación y balanceo.', ['alignment']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Alineación y balanceo']]],
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-001', 'done' => 1],
            ],
            [
                'key' => 'H12', 'plate' => 'QAB111', 'days' => 30, 'receptionist' => $r1, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Service de los 60.000 km.',
                'diagnostics' => [['MEC-002', 'completed', 'low', 'Service programado.', 'Aceite y filtros vencidos.', 'Cambio de aceite y filtros.', ['oil_due', 'cabin_filter']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Cambio de aceite y filtro'], ['part', 'LUB-5W30-001', 4], ['part', 'FIL-ACE-001', 1], ['part', 'FIL-HAB-001', 1]]],
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-002', 'done' => 1],
            ],
            [
                'key' => 'H13', 'plate' => 'QAB112', 'days' => 25, 'receptionist' => $r3, 'categories' => ['general', 'mechanical'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Vibra al frenar.',
                'diagnostics' => [['MEC-001', 'completed', 'high', 'Vibración en el pedal.', 'Disco delantero alabeado y pastillas gastadas.', 'Cambiar disco y pastillas.', ['brake_disc', 'brake_pads']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Cambio de pastillas de freno'], ['part', 'FRE-PAS-001', 1], ['part', 'FRE-DIS-001', 1]]],
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-001', 'done' => 3],
            ],
            [
                'key' => 'H14', 'plate' => 'QAB113', 'days' => 20, 'receptionist' => $r2, 'categories' => ['tires'],
                'fuel' => FuelLevel::FULL, 'problem' => 'Neumáticos delanteros lisos.',
                'diagnostics' => [['MEC-002', 'completed', 'high', 'Neumáticos gastados.', 'Banda de rodamiento bajo el mínimo.', 'Cambiar los dos delanteros y rotar.', ['tires_worn']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Montaje y balanceo de neumáticos'], ['part', 'NEU-205-001', 2], ['service', 'Rotación de neumáticos']]],
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-002', 'done' => 1],
            ],
            [
                'key' => 'H15', 'plate' => 'EEE555', 'days' => 15, 'receptionist' => $r1, 'categories' => ['bodywork'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Golpe en la puerta trasera.',
                'diagnostics' => [['MEC-004', 'completed', 'medium', 'Golpe de estacionamiento.', 'Abolladura sin daño estructural.', 'Desabollar y pintar la puerta.', ['door_dent']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Desabollado y preparación de puerta'], ['service', 'Pintura general de puerta trasera']]],
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-004', 'done' => 5],
            ],
            [
                'key' => 'H16', 'plate' => 'QAB114', 'days' => 12, 'receptionist' => $r3, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Service de flota.',
                'diagnostics' => [['MEC-003', 'completed', 'low', 'Service programado.', 'Aceite vencido.', 'Cambio de aceite y filtro.', ['oil_due']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Cambio de aceite y filtro'], ['part', 'LUB-5W30-001', 7], ['part', 'FIL-ACE-001', 1]]],
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-003', 'done' => 1],
            ],
            [
                'key' => 'H17', 'plate' => 'QAB115', 'days' => 6, 'receptionist' => $r2, 'categories' => ['electrical'],
                'fuel' => FuelLevel::QUARTER, 'problem' => 'La batería se descarga.',
                'diagnostics' => [['MEC-003', 'completed', 'high', 'Se queda sin batería.', 'El alternador no carga.', 'Reparar el alternador.', ['alternator', 'battery_ok']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Escaneo computarizado'], ['service', 'Reparación de alternador']]],
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-003', 'done' => 3],
            ],

            // ---------------------------------------------------------------- Trabajos abiertos
            [
                'key' => 'C01', 'plate' => 'QAB101', 'days' => 0, 'receptionist' => $r1, 'categories' => ['general'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Revisión antes de un viaje.',
                'diagnostics' => [],
            ],
            [
                'key' => 'C02', 'plate' => 'QAB102', 'days' => 1, 'receptionist' => $r2, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::QUARTER, 'problem' => 'Pierde aceite.',
                'diagnostics' => [['MEC-001', 'pending', 'medium', 'Mancha de aceite en el piso.', null, null, []]],
            ],
            [
                'key' => 'C03', 'plate' => 'QAB103', 'days' => 2, 'receptionist' => $r3, 'categories' => ['electrical'],
                'fuel' => FuelLevel::HALF, 'problem' => 'No funcionan las luces traseras.',
                'diagnostics' => [['MEC-003', 'in_progress', 'medium', 'Luces traseras apagadas.', 'Revisando el circuito trasero.', null, ['rear_lights_check']]],
            ],
            [
                'key' => 'C04', 'plate' => 'AAA111', 'visit' => 2, 'days' => 10, 'receptionist' => $r1, 'categories' => ['general', 'mechanical'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Ruido en la suspensión delantera.', 'observations' => 'Cliente solicita revisión completa.',
                'diagnostics' => [
                    ['MEC-001', 'pending', 'high', 'Ruido en la suspensión delantera.', 'Amortiguador delantero con pérdida de aceite.', 'Cambiar ambos amortiguadores.', ['shock_absorber']],
                    ['MEC-002', 'in_progress', 'medium', 'Dirección vibrando.', 'Revisando bujes de parrilla.', null, ['bushings']],
                ],
                'budget' => ['status' => 'draft', 'items' => [['labor', 'Mano de obra - Cambio de bujes y amortiguadores', 350000], ['part', 'SUS-BUJ-001', 2]]],
            ],
            [
                'key' => 'C05', 'plate' => 'CCC333', 'days' => 7, 'receptionist' => $r2, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::FULL, 'problem' => 'Golpes al pasar lomadas.',
                'diagnostics' => [['MEC-002', 'completed', 'high', 'Golpes en lomadas.', 'Amortiguadores delanteros agotados.', 'Cambio de amortiguadores.', ['shock_absorber']]],
                // Siniestro (M3–M9): autorización en dos versiones, aprobado con la primera y re-aceptado con la segunda;
                // OT completada (después de la re-aceptación) con un extra del dueño, su reparto en tres versiones
                // (AllocationSeeder) y una factura por responsable (AllocationInvoiceSeeder).
                'budget' => ['status' => 'approved', 'items' => [['part', 'SUS-AMO-001', 2], ['service', 'Instalación de amortiguadores']]],
                'work_order' => ['status' => 'completed', 'mechanic' => 'MEC-002', 'done' => 1, 'day' => 2, 'extras' => [['ELE-LAM-001', 2, 'approve']]],
            ],
            [
                'key' => 'C06', 'plate' => 'QAB104', 'days' => 12, 'receptionist' => $r3, 'categories' => ['general', 'tires'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Revisión general y neumáticos.',
                'diagnostics' => [
                    ['MEC-006', 'cancelled', 'low', 'Revisión de neumáticos.', null, 'Lo revisa otro mecánico.', []],
                    ['MEC-002', 'completed', 'medium', 'Revisión general.', 'Neumáticos gastados.', 'Cambiar los cuatro neumáticos.', ['tires_worn']],
                ],
                'budget' => ['status' => 'rejected', 'items' => [['part', 'NEU-205-001', 4], ['service', 'Montaje y balanceo de neumáticos']]],
            ],
            [
                'key' => 'C07', 'plate' => 'QAB105', 'days' => 14, 'receptionist' => $r1, 'categories' => ['bodywork'],
                'fuel' => FuelLevel::THREE_QUARTERS, 'problem' => 'Rayones en el paragolpes.',
                'diagnostics' => [['MEC-004', 'completed', 'low', 'Rayones superficiales.', 'Rayones en el paragolpes trasero.', 'Pulido o pintura parcial.', ['paint_dull']]],
                // Rechazado por el precio y reabierto para armar una alternativa más barata.
                'budget' => ['status' => 'reopened', 'items' => [['service', 'Pintura general de puerta trasera']]],
            ],
            [
                'key' => 'C08', 'plate' => 'QAB106', 'days' => 16, 'receptionist' => $r2, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Patina el embrague.',
                'diagnostics' => [['MEC-001', 'completed', 'high', 'Patina en las subidas.', 'Disco de embrague gastado.', 'Cambiar el kit de embrague.', ['clutch_worn']]],
                // El cliente avisó que lo hace en otro taller: el presupuesto enviado se cancela.
                'budget' => ['status' => 'cancelled', 'items' => [['service', 'Cambio de embrague'], ['part', 'TRA-EMB-001', 1]]],
            ],
            [
                'key' => 'C09', 'plate' => 'BBB222', 'days' => 8, 'receptionist' => $r3, 'categories' => ['general', 'bodywork'],
                'fuel' => FuelLevel::QUARTER, 'problem' => 'Golpe en puerta trasera izquierda.', 'observations' => 'Requiere presupuesto para pintura.',
                'diagnostics' => [['MEC-004', 'completed', 'medium', 'Puerta trasera golpeada.', 'Daño superficial de chapa.', 'Chapería y pintura.', ['door_dent']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Desabollado y preparación de puerta'], ['service', 'Pintura general de puerta trasera']]],
                'work_order' => ['status' => 'pending', 'mechanic' => 'MEC-004'],
            ],
            [
                'key' => 'C10', 'plate' => 'DDD444', 'days' => 6, 'receptionist' => $r1, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::THREE_QUARTERS, 'problem' => 'Falla en sistema de frenos.', 'observations' => 'Vehículo llegó en grúa.',
                'diagnostics' => [['MEC-002', 'completed', 'critical', 'Frena poco.', 'Pastillas desgastadas y líquido bajo.', 'Cambiar pastillas y líquido.', ['brake_pads', 'brake_fluid']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Cambio de pastillas de freno'], ['part', 'FRE-PAS-001', 1], ['part', 'FRE-LIQ-001', 1]]],
                // En curso: un ítem terminado, otro en curso, repuestos reservados sin consumir.
                'work_order' => ['status' => 'in_progress', 'mechanic' => 'MEC-002', 'partial' => true],
            ],
            [
                'key' => 'C11', 'plate' => 'QAB107', 'days' => 9, 'receptionist' => $r2, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Service y ruido en el motor.',
                'diagnostics' => [['MEC-001', 'completed', 'medium', 'Ruido en el motor.', 'Aceite degradado.', 'Cambio de aceite; el ruido se revisa después.', ['oil_due']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Cambio de aceite y filtro'], ['part', 'LUB-5W30-001', 4], ['part', 'FIL-ACE-001', 1]]],
                // Pausada: se espera la autorización del cliente para seguir.
                'work_order' => ['status' => 'paused', 'mechanic' => 'MEC-001'],
            ],
            [
                'key' => 'C12', 'plate' => 'QAB108', 'days' => 5, 'receptionist' => $r3, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Recalienta en el tráfico.',
                'diagnostics' => [['MEC-003', 'completed', 'high', 'Sube la temperatura.', 'Ventilador con falla intermitente.', 'Revisar el electroventilador.', ['coolant_low', 'sensor_fault']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Diagnóstico eléctrico']]],
                // Extra pendiente de la respuesta del cliente: refrigerante.
                'work_order' => ['status' => 'in_progress', 'mechanic' => 'MEC-003', 'extras' => [['REF-REF-001', 2, 'pending']]],
            ],
            [
                'key' => 'C13', 'plate' => 'QAB109', 'days' => 4, 'receptionist' => $r1, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::QUARTER, 'problem' => 'Patina el embrague.',
                'diagnostics' => [['MEC-001', 'completed', 'high', 'Patina al acelerar.', 'Kit de embrague gastado.', 'Cambiar el kit de embrague.', ['clutch_worn']]],
                // En curso esperando el kit de embrague: sin stock, la línea queda pendiente y avisa a depósito.
                'budget' => ['status' => 'approved', 'items' => [['service', 'Cambio de embrague'], ['part', 'TRA-EMB-001', 1]]],
                'work_order' => ['status' => 'in_progress', 'mechanic' => 'MEC-001'],
            ],
            [
                'key' => 'C14', 'plate' => 'FFF666', 'days' => 3, 'receptionist' => $r2, 'categories' => ['tires'],
                'fuel' => FuelLevel::FULL, 'problem' => 'Cambio de neumáticos.',
                'diagnostics' => [['MEC-002', 'completed', 'medium', 'Neumáticos con años.', 'Neumáticos resecos.', 'Cambiar los cuatro.', ['tires_worn']]],
                // Pendiente de iniciar, con los neumáticos ya reservados.
                'budget' => ['status' => 'approved', 'items' => [['service', 'Montaje y balanceo de neumáticos'], ['part', 'NEU-205-001', 4]]],
                'work_order' => ['status' => 'pending', 'mechanic' => 'MEC-002'],
            ],

            // Presupuestos enviados a clientes que no reciben avisos: el aviso queda omitido con el motivo.
            [
                'key' => 'C15', 'plate' => 'QAB127', 'days' => 11, 'receptionist' => $r1, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Service de los 280.000 km.',
                'diagnostics' => [['MEC-001', 'completed', 'low', 'Service programado.', 'Aceite vencido.', 'Cambio de aceite y filtro.', ['oil_due']]],
                // Cliente sin email (ClientsSeeder::WITHOUT_EMAIL).
                'budget' => ['status' => 'sent', 'items' => [['service', 'Cambio de aceite y filtro'], ['part', 'LUB-5W30-001', 6], ['part', 'FIL-ACE-001', 1]]],
            ],
            [
                'key' => 'C16', 'plate' => 'QAB128', 'days' => 13, 'receptionist' => $r2, 'categories' => ['tires'],
                'fuel' => FuelLevel::FULL, 'problem' => 'Vibra en ruta.',
                'diagnostics' => [['MEC-002', 'completed', 'medium', 'Vibración en ruta.', 'Ruedas desbalanceadas.', 'Alineación y balanceo.', ['alignment']]],
                // Cliente dado de baja de los avisos (ClientsSeeder::NOTIFICATIONS_DISABLED).
                'budget' => ['status' => 'sent', 'items' => [['service', 'Alineación y balanceo']]],
            ],

            // ---------------------------------------------------------------- Canceladas
            [
                'key' => 'X01', 'plate' => 'QAB121', 'days' => 30, 'receptionist' => $r3, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::HALF, 'problem' => 'Service de flota.',
                'diagnostics' => [['MEC-003', 'completed', 'low', 'Service programado.', 'Aceite vencido.', 'Cambio de aceite y filtro.', ['oil_due']]],
                'budget' => ['status' => 'draft', 'items' => [['service', 'Cambio de aceite y filtro']]],
                // El cliente retiró el vehículo sin hacer el trabajo: el presupuesto en borrador se cancela con la recepción.
                'cancel' => 'El cliente retiró el vehículo sin hacer el trabajo.',
            ],
            [
                'key' => 'X02', 'plate' => 'QAB122', 'days' => 26, 'receptionist' => $r1, 'categories' => ['mechanical'],
                'fuel' => FuelLevel::QUARTER, 'problem' => 'Revisión de frenos.',
                'diagnostics' => [['MEC-002', 'completed', 'medium', 'Ruido al frenar.', 'Pastillas al límite.', 'Cambiar pastillas.', ['brake_pads']]],
                'budget' => ['status' => 'approved', 'items' => [['service', 'Cambio de pastillas de freno'], ['part', 'FRE-PAS-001', 1]]],
                // Aprobó, pero vendió la camioneta antes de empezar: OT cancelada y recepción cancelada.
                'work_order' => ['status' => 'cancelled', 'mechanic' => 'MEC-002'],
                'cancel' => 'El cliente vendió el vehículo antes de iniciar el trabajo.',
            ],
        ];
    }

    /**
     * Facturación, cobro y entrega de los trabajos terminados (InvoiceSeeder, PaymentSeeder, VehicleDeliverySeeder).
     * Los días se cuentan desde que se completó la OT.
     *
     * - invoice: draft (sin emitir) | cash | credit; `due`: días de plazo del crédito desde la emisión;
     *   `reissue`: se emite, se anula con ese motivo y se vuelve a facturar.
     * - payments: [día, método, monto (full|half|rest), anulado con motivo (opcional)].
     * - delivery: día de la entrega; `redeliver`: se anula la entrega con ese motivo y se vuelve a entregar.
     */
    private const BILLING = [
        'H01' => ['invoice' => 'cash', 'payments' => [[0, 'cash', 'full']], 'delivery' => 0],
        'H02' => ['invoice' => 'credit', 'due' => 30, 'payments' => [[10, 'transfer', 'half'], [25, 'transfer', 'rest']], 'delivery' => 1],
        'H03' => ['invoice' => 'cash', 'payments' => [[0, 'card', 'full']], 'delivery' => 0, 'redeliver' => 'Se registró con el kilometraje equivocado.'],
        'H04' => ['invoice' => 'cash', 'reissue' => 'Se emitió con el RUC equivocado.', 'payments' => [[1, 'transfer', 'full']], 'delivery' => 1],
        'H05' => ['invoice' => 'cash', 'payments' => [[0, 'card', 'full', 'Se cobró con la tarjeta equivocada.'], [0, 'cash', 'full']], 'delivery' => 0],
        'H06' => ['invoice' => 'credit', 'due' => 15, 'payments' => [], 'delivery' => 1],
        'H07' => ['invoice' => 'credit', 'due' => 30, 'payments' => [[5, 'cash', 'half']], 'delivery' => 0],
        'H08' => ['invoice' => 'cash', 'payments' => [[0, 'check', 'full']], 'delivery' => 1],
        'H09' => ['invoice' => 'cash', 'payments' => [[0, 'card', 'full']], 'delivery' => 0],
        'H10' => ['invoice' => 'credit', 'due' => 60, 'payments' => [[7, 'transfer', 'half']], 'delivery' => 1],
        'H11' => ['invoice' => 'cash', 'payments' => [[0, 'cash', 'full']], 'delivery' => 0],
        'H12' => ['invoice' => 'cash', 'payments' => [[1, 'transfer', 'full']], 'delivery' => 1],
        'H13' => ['invoice' => 'credit', 'due' => 30, 'payments' => [], 'delivery' => 0],
        // Pagada y lista para retirar.
        'H14' => ['invoice' => 'cash', 'payments' => [[0, 'cash', 'full']]],
        // Al contado sin cobrar: no puede entregarse.
        'H15' => ['invoice' => 'cash', 'payments' => []],
        'H16' => ['invoice' => 'draft'],
        // H17: terminado, todavía sin facturar.
    ];

    /**
     * Escenarios ordenados por fecha de llegada (los códigos BUD-/OT- salen en orden cronológico).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function chronological(): array
    {
        $scenarios = array_map(
            fn (array $scenario) => isset(self::BILLING[$scenario['key']]) ? [...$scenario, 'billing' => self::BILLING[$scenario['key']]] : $scenario,
            self::all()
        );
        usort($scenarios, fn (array $a, array $b) => $b['days'] <=> $a['days']);

        return $scenarios;
    }

    /**
     * Momento de un evento: `$offset` días después de la llegada, a la hora indicada; nunca después de ahora.
     */
    public static function at(array $scenario, int $offset = 0, int $hour = 9, int $minute = 0): CarbonImmutable
    {
        $now = self::now();
        $moment = $now->startOfDay()->subDays(max(0, $scenario['days'] - $offset))->setTime($hour, $minute);

        // Lo que caería en el futuro se adelanta a antes de ahora, respetando el orden (más tarde, más cerca de ahora).
        return $moment->greaterThan($now)
            ? $now->subMinutes(400 - min(399, $offset * 24 + $hour))->addSeconds($minute)
            : $moment;
    }

    /**
     * Ejecuta `$callback` con el reloj de la aplicación en `$moment` (timestamps, now(), fechas de los Services).
     */
    public static function travel(CarbonImmutable $moment, callable $callback): mixed
    {
        Carbon::setTestNow($moment);

        try {
            return $callback();
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Recepción de un escenario: la visita número `visit` (1 por defecto) del vehículo, por fecha de llegada. No
     * depende de la fecha de hoy, así que volver a sembrar otro día no duplica.
     */
    public static function reception(array $scenario): ?Reception
    {
        $vehicleId = Vehicle::where('plate', $scenario['plate'])->value('id');

        return $vehicleId
            ? Reception::withTrashed()
                ->where('vehicle_id', $vehicleId)
                ->orderBy('reception_date')
                ->orderBy('id')
                ->skip(($scenario['visit'] ?? 1) - 1)
                ->first()
            : null;
    }

    /**
     * Momento `$offset` días después de completada la OT del escenario (ver WorkOrderSeeder: se completa el día
     * 1 + done desde la llegada).
     */
    public static function afterCompletion(array $scenario, int $offset, int $hour): CarbonImmutable
    {
        return self::at($scenario, self::workOrderDay($scenario) + 1 + $scenario['work_order']['done'] + $offset, $hour);
    }

    /**
     * Días que se corre la OT del escenario (`work_order.day`, 0 por defecto): toda su línea de tiempo empieza más tarde.
     */
    public static function workOrderDay(array $scenario): int
    {
        return $scenario['work_order']['day'] ?? 0;
    }

    public static function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public static function mechanic(string $code): Mechanic
    {
        return Mechanic::where('employee_code', $code)->firstOrFail();
    }

    /**
     * "Ahora" real, fijado la primera vez (antes de mover el reloj).
     */
    private static function now(): CarbonImmutable
    {
        return self::$now ??= CarbonImmutable::now();
    }
}
