<?php

return [

    // Hora de la tarea diaria de recordatorios (notifications:send-reminders).
    'reminders_at' => '08:00',

    // Facturas a crédito con saldo: aviso previo (días antes del vencimiento)
    // y, una vez vencidas, un aviso cada tantos días.
    'invoice_due_soon_days' => 3,
    'invoice_overdue_repeat_days' => 7,

    // Roles que reciben cada aviso interno (bandeja de la app).
    'internal_recipients' => [
        'budget_approved' => ['recepcionista', 'jefe-taller'],
        'budget_rejected' => ['recepcionista', 'jefe-taller'],
        'work_order_completed' => ['cajero', 'recepcionista'],
        'part_out_of_stock' => ['deposito', 'jefe-taller'],
        'extra_part_awaiting_approval' => ['recepcionista', 'jefe-taller'],
        'accounts_payable_overdue' => ['cajero'],
    ],

];
