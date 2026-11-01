<?php

namespace App\Support;

/**
 * Catálogo de la configuración del taller: cada campo de la API y la clave de
 * config que reemplaza. Esa clave es también la de system_settings. Lo que no
 * esté guardado sale del .env (o del archivo de config).
 */
final class WorkshopSettings
{
    /**
     * @var array<string, string>
     */
    public const FIELDS = [
        'company_name' => 'company.name',
        'company_legal_name' => 'company.legal_name',
        'company_tax_id' => 'company.tax_id',
        'company_activity' => 'company.activity',
        'company_address' => 'company.address',
        'company_city' => 'company.city',
        'company_phone' => 'company.phone',
        'company_email' => 'company.email',
        'default_tax_rate' => 'invoice.default_tax_rate',
        'mail_from_name' => 'mail.from.name',
        'mail_from_address' => 'mail.from.address',
        'reminders_at' => 'notifications.reminders_at',
        'invoice_due_soon_days' => 'notifications.invoice_due_soon_days',
        'invoice_overdue_repeat_days' => 'notifications.invoice_overdue_repeat_days',
    ];

    /**
     * Campos numéricos: system_settings guarda texto.
     */
    public const INTEGERS = ['default_tax_rate', 'invoice_due_soon_days', 'invoice_overdue_repeat_days'];
}
