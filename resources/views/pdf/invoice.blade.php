@extends('pdf.layout')

@section('document-box')
    <h1>FACTURA</h1>
    @if ($invoice->stamp_number)
        <div><span class="strong">Timbrado N°:</span> {{ $invoice->stamp_number }}</div>
        <div><span class="strong">Vigencia hasta:</span> {{ $invoice->stamp_valid_until?->format('d/m/Y') }}</div>
    @endif
    <div><span class="strong">N°:</span> {{ $invoice->number ?? 'Sin emitir' }}</div>
@endsection

@section('content')
    <table style="margin-top: 10px;" class="box">
        <tr>
            <td style="width: 60%;">
                <div><span class="strong">Fecha de emisión:</span> {{ $invoice->issue_date?->format('d/m/Y') ?? '—' }}</div>
                <div><span class="strong">Nombre o razón social:</span> {{ $invoice->customer_name }}</div>
                <div><span class="strong">RUC / CI:</span> {{ $invoice->customer_tax_id ?? '—' }}</div>
                @if ($invoice->customer_address)<div><span class="strong">Dirección:</span> {{ $invoice->customer_address }}</div>@endif
            </td>
            <td style="width: 40%;">
                <div><span class="strong">Condición:</span> {{ $invoice->condition->label() }}</div>
                @if ($invoice->due_date)<div><span class="strong">Vencimiento:</span> {{ $invoice->due_date->format('d/m/Y') }}</div>@endif
                @if ($invoice->workOrder)<div><span class="strong">OT:</span> {{ $invoice->workOrder->code }}</div>@endif
                @if ($vehicle)<div><span class="strong">Vehículo:</span> {{ $vehicle['name'] }} {{ $vehicle['plate'] ?? $vehicle['chassis'] }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="grid" style="margin-top: 8px;">
        <thead>
            <tr>
                <th class="num">Cant.</th>
                <th>Descripción</th>
                <th class="num">Precio unit.</th>
                <th class="num">Descuento</th>
                <th class="num">Exentas</th>
                <th class="num">5%</th>
                <th class="num">10%</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td class="num">{{ $item['quantity'] }}</td>
                    <td>{{ $item['description'] }}</td>
                    <td class="num">{{ $item['unit_price'] }}</td>
                    <td class="num">{{ $item['discount'] }}</td>
                    <td class="num">{{ $item['exempt'] }}</td>
                    <td class="num">{{ $item['taxed_5'] }}</td>
                    <td class="num">{{ $item['taxed_10'] }}</td>
                </tr>
            @endforeach
            <tr class="strong">
                <td colspan="4" class="num">Subtotales</td>
                <td class="num">{{ \App\Support\Format::integer($invoice->total_exempt) }}</td>
                <td class="num">{{ \App\Support\Format::integer($invoice->total_5) }}</td>
                <td class="num">{{ \App\Support\Format::integer($invoice->total_10) }}</td>
            </tr>
            <tr class="strong">
                <td colspan="6" class="num">Total a pagar</td>
                <td class="num">{{ \App\Support\Format::money($invoice->total) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals" style="margin-top: 6px;">
        <tr>
            <td><span class="strong">Liquidación del IVA:</span>
                (5%) {{ \App\Support\Format::money($invoice->iva_5) }} ·
                (10%) {{ \App\Support\Format::money($invoice->iva_10) }} ·
                Total IVA {{ \App\Support\Format::money($ivaTotal) }}
            </td>
        </tr>
    </table>

    @if ($creditNotes->isNotEmpty())
        <h2>Notas de crédito registradas</h2>
        <table class="grid" style="margin-top: 4px;">
            <thead>
                <tr>
                    <th>Número</th>
                    <th>Fecha</th>
                    <th>Motivo</th>
                    <th class="num">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($creditNotes as $creditNote)
                    <tr>
                        <td>{{ $creditNote->number }}</td>
                        <td>{{ $creditNote->issue_date->format('d/m/Y') }}</td>
                        <td>{{ $creditNote->reason }}</td>
                        <td class="num">{{ \App\Support\Format::money($creditNote->total) }}</td>
                    </tr>
                @endforeach
                <tr class="strong">
                    <td colspan="3" class="num">Saldo</td>
                    <td class="num">{{ \App\Support\Format::money($invoice->balance()) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    @if ($invoice->cancellation_reason)
        <h2>Anulación</h2>
        <div>{{ $invoice->cancelled_at?->format('d/m/Y H:i') }} — {{ $invoice->cancellation_reason }}</div>
    @endif

    <div class="legend">Representación del comprobante – no válido como documento fiscal.</div>
@endsection
