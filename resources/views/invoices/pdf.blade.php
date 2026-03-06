<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            color: #000;
            line-height: 1.4;
            margin: 0;
            padding: 20px;
        }
        .invoice-box {
            max-width: 800px;
            margin: auto;
            font-size: 11px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        .border-all td, .border-all th {
            border: 1px solid #000;
            padding: 8px;
        }
        .text-center { text-align: center; }
        .text-right  { text-align: right; }
        .text-left   { text-align: left; }
        .bold        { font-weight: bold; }
        .header-title {
            font-size: 18px;
            font-weight: bold;
            text-align: center;
            padding: 10px 0;
        }
        .small-text { font-size: 9px; }
        .items-table th {
            background-color: #f0f0f0;
            font-weight: bold;
            text-align: center;
            padding: 8px 4px;
        }
        .items-table td {
            padding: 8px 4px;
        }
        .total-row { font-weight: bold; }

        /* Draft watermark */
        .watermark {
            position: fixed;
            top: 38%;
            left: 10%;
            font-size: 100px;
            font-weight: bold;
            color: rgba(200, 0, 0, 0.10);
            transform: rotate(-35deg);
            z-index: 1000;
            letter-spacing: 10px;
            pointer-events: none;
        }
        .draft-banner {
            background: #fff3cd;
            border: 2px dashed #e0a800;
            color: #856404;
            text-align: center;
            font-weight: bold;
            font-size: 12px;
            padding: 6px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
<div class="invoice-box">

    @php
        $isDraft      = $isDraft ?? ($invoice->status === 'draft');
        $isIntraState = $invoice->supply_type === 'intra_state';
    @endphp

    {{-- Draft watermark overlay --}}
    @if($isDraft)
        <div class="watermark">DRAFT</div>
        <div class="draft-banner">
            ⚠ DRAFT PREVIEW — This invoice has not been issued. Invoice number will be assigned upon confirmation.
        </div>
    @endif

    <!-- Header -->
    <div class="header-title">Tax Invoice</div>

    <!-- Company & Client Information -->
    <table class="border-all">
        <tr>
            <td colspan="3" class="bold">
                {{ $invoice->company_name }}<br>
                <span class="small-text">
                    @if($invoice->company_gst_number)
                        GSTIN/UIN: {{ $invoice->company_gst_number }}<br>
                    @endif
                    @if($invoice->company_state)
                        State Name: {{ $invoice->company_state }}@if($invoice->company_state_code), Code: {{ $invoice->company_state_code }}@endif
                    @endif
                </span>
            </td>
            <td colspan="3">
                <strong>Invoice No.</strong><br>
                {{ $invoice->invoice_number ?? '— DRAFT —' }}
            </td>
        </tr>
        <tr>
            <td colspan="3" rowspan="2">
                <strong>Consignee (Ship to)</strong><br>
                <span class="bold">{{ $invoice->client_name }}</span><br>
                <span class="small-text">
                    @if($invoice->client_gst_number)
                        GSTIN/UIN: {{ $invoice->client_gst_number }}<br>
                    @endif
                    {{ $invoice->client_address }}<br>
                    @if($invoice->client_state)
                        State Name: {{ $invoice->client_state }}@if($invoice->client_state_code), Code: {{ $invoice->client_state_code }}@endif
                    @endif
                </span>
            </td>
            <td colspan="3">
                <strong>Dated</strong><br>
                {{ $invoice->invoice_date->format('d-M-Y') }}
            </td>
        </tr>
        <tr>
            <td colspan="3">
                <strong>Delivery Note</strong><br>
                &ndash;
            </td>
        </tr>
        <tr>
            <td colspan="3" rowspan="2">
                <strong>Buyer (Bill to)</strong><br>
                <span class="bold">{{ $invoice->client_name }}</span><br>
                <span class="small-text">
                    @if($invoice->client_gst_number)
                        GSTIN/UIN: {{ $invoice->client_gst_number }}<br>
                    @endif
                    {{ $invoice->client_address }}<br>
                    @if($invoice->client_state)
                        State Name: {{ $invoice->client_state }}@if($invoice->client_state_code), Code: {{ $invoice->client_state_code }}@endif
                    @endif
                </span>
            </td>
            <td colspan="3">
                <strong>Mode/Terms of Payment</strong><br>
                {{ $invoice->payment_terms ?? '&ndash;' }}
            </td>
        </tr>
        <tr>
            <td colspan="3">
                <strong>Delivery Note Date</strong><br>
                {{ $invoice->invoice_date->format('d-M-Y') }}
            </td>
        </tr>
        <tr>
            <td colspan="3">
                <strong>Buyer's Order No.</strong><br>&ndash;
            </td>
            <td colspan="3">
                <strong>Dated</strong><br>
                {{ $invoice->invoice_date->format('d-M-Y') }}
            </td>
        </tr>
        <tr>
            <td colspan="3"><strong>Dispatch Doc No.</strong><br>&ndash;</td>
            <td colspan="3"><strong>Destination</strong><br>&ndash;</td>
        </tr>
        <tr>
            <td colspan="3"><strong>Dispatched through</strong><br>&ndash;</td>
            <td colspan="3"><strong>Terms of Delivery</strong><br>&ndash;</td>
        </tr>
    </table>

    <!-- Items Table -->
    @php
        $slNo      = 1;
        $totalQty  = 0;
        $firstUnit = 'Nos';
    @endphp
    <table class="border-all items-table" style="margin-top: 10px;">
        <thead>
        <tr>
            <th style="width: 5%;">Sl<br>No.</th>
            <th style="width: 33%;">Description of Goods</th>
            <th style="width: 10%;">HSN/SAC</th>
            <th style="width: 10%;">Quantity</th>
            <th style="width: 10%;">Rate</th>
            <th style="width: 8%;">per</th>
            <th style="width: 8%;">Disc.&nbsp;%</th>
            <th style="width: 11%;">Amount</th>
        </tr>
        </thead>
        <tbody>

        @foreach($invoice->lineItems as $item)
            @php
                $totalQty += $item->quantity;
                if ($slNo === 1) $firstUnit = $item->unit ?? 'Nos';
            @endphp
            <tr>
                <td class="text-center">{{ $slNo++ }}</td>
                <td>{{ $item->description }}</td>
                <td class="text-center">{{ $item->hsn_code ?? '&ndash;' }}</td>
                <td class="text-right">{{ $item->quantity }} {{ $item->unit ?? 'Nos' }}</td>
                <td class="text-right">{{ number_format($item->rate, 2) }}</td>
                <td class="text-center">{{ $item->unit ?? 'Nos' }}</td>
                <td class="text-right">
                    {{ $item->discount_percent > 0 ? number_format($item->discount_percent, 2).'%' : '&ndash;' }}
                </td>
                <td class="text-right">{{ number_format($item->amount, 2) }}</td>
            </tr>
        @endforeach

        <!-- Tax Rows — dynamic based on supply type -->
        @if($isIntraState)
            <tr>
                <td colspan="7" class="text-right bold">
                    CGST
                    @php
                        // Show blended rate if all items same, otherwise leave blank
                        $cgstRate = $invoice->lineItems->avg('cgst_rate');
                    @endphp
                    @if($cgstRate > 0)({{ number_format($cgstRate, 0) }}%)@endif
                </td>
                <td class="text-right bold">{{ number_format($invoice->cgst_amount, 2) }}</td>
            </tr>
            <tr>
                <td colspan="7" class="text-right bold">
                    SGST
                    @if($cgstRate > 0)({{ number_format($cgstRate, 0) }}%)@endif
                </td>
                <td class="text-right bold">{{ number_format($invoice->sgst_amount, 2) }}</td>
            </tr>
        @else
            <tr>
                <td colspan="7" class="text-right bold">
                    IGST
                    @php $igstRate = $invoice->lineItems->avg('igst_rate'); @endphp
                    @if($igstRate > 0)({{ number_format($igstRate, 0) }}%)@endif
                </td>
                <td class="text-right bold">{{ number_format($invoice->igst_amount, 2) }}</td>
            </tr>
        @endif

        <!-- Total Row -->
        <tr class="total-row">
            <td colspan="3" class="text-left"><strong>Total</strong></td>
            <td class="text-right">{{ $totalQty }} {{ $firstUnit }}</td>
            <td colspan="3"></td>
            <td class="text-right">&#8377; {{ number_format($invoice->total_amount, 2) }}</td>
        </tr>
        </tbody>
    </table>

    <!-- Amount in Words -->
    @php
        $formatter     = new \NumberFormatter('en_IN', \NumberFormatter::SPELLOUT);
        $amountInWords = ucwords($formatter->format((int) $invoice->total_amount));
    @endphp
    <table class="border-all" style="margin-top: 10px;">
        <tr>
            <td colspan="6">
                <strong>Amount Chargeable (in words)</strong>&emsp;E. &amp; O.E<br>
                <span class="bold">INR {{ $amountInWords }} Only</span>
            </td>
        </tr>
    </table>

    <!-- Tax Breakdown Table -->
    <table class="border-all" style="margin-top: 10px;">
        <thead>
        <tr>
            <th>HSN/SAC</th>
            <th>Total Taxable<br>Value</th>
            @if($isIntraState)
                <th colspan="2">CGST</th>
                <th colspan="2">SGST/UTGST</th>
            @else
                <th colspan="2">IGST</th>
            @endif
            <th>Tax Amount</th>
        </tr>
        <tr>
            <th></th>
            <th></th>
            <th>Rate</th>
            <th>Amount</th>
            @if($isIntraState)
                <th>Rate</th>
                <th>Amount</th>
            @endif
            <th></th>
        </tr>
        </thead>
        <tbody>
        {{-- Group line items by HSN + GST rate for accurate breakdown --}}
        @php
            $grouped = $invoice->lineItems->groupBy(fn($li) => ($li->hsn_code ?: '-') . '|' . $li->gst_rate);
        @endphp
        @foreach($grouped as $key => $items)
            @php
                [$hsn, $gstRate]  = explode('|', $key);
                $taxableSum       = $items->sum('amount');
                $cgstSum          = $items->sum('cgst_amount');
                $sgstSum          = $items->sum('sgst_amount');
                $igstSum          = $items->sum('igst_amount');
                $taxSum           = $cgstSum + $sgstSum + $igstSum;
                $halfRate         = $gstRate / 2;
            @endphp
            <tr>
                <td class="text-center">{{ $hsn }}</td>
                <td class="text-right">{{ number_format($taxableSum, 2) }}</td>
                @if($isIntraState)
                    <td class="text-center">{{ number_format($halfRate, 1) }}%</td>
                    <td class="text-right">{{ number_format($cgstSum, 2) }}</td>
                    <td class="text-center">{{ number_format($halfRate, 1) }}%</td>
                    <td class="text-right">{{ number_format($sgstSum, 2) }}</td>
                @else
                    <td class="text-center">{{ number_format($gstRate, 1) }}%</td>
                    <td class="text-right">{{ number_format($igstSum, 2) }}</td>
                @endif
                <td class="text-right">{{ number_format($taxSum, 2) }}</td>
            </tr>
        @endforeach

        <tr class="total-row">
            <td class="text-center bold">Total</td>
            <td class="text-right bold">{{ number_format($invoice->subtotal, 2) }}</td>
            @if($isIntraState)
                <td></td>
                <td class="text-right bold">{{ number_format($invoice->cgst_amount, 2) }}</td>
                <td></td>
                <td class="text-right bold">{{ number_format($invoice->sgst_amount, 2) }}</td>
            @else
                <td></td>
                <td class="text-right bold">{{ number_format($invoice->igst_amount, 2) }}</td>
            @endif
            <td class="text-right bold">{{ number_format($invoice->gst_amount, 2) }}</td>
        </tr>
        </tbody>
    </table>

    @php
        $taxInWords = ucwords($formatter->format((int) $invoice->gst_amount));
    @endphp
    <div style="margin-top: 10px;">
        <strong>Tax Amount (in words):</strong> INR {{ $taxInWords }} Only
    </div>

    <!-- Bank Details -->
    @if($invoice->bank_account_number)
        <table class="border-all" style="margin-top: 10px;">
            <tr>
                <td>
                    <strong>Company's Bank Details</strong><br>
                    <span class="small-text">
                    Bank Name: {{ $invoice->bank_account_name }}<br>
                    A/c No.: {{ $invoice->bank_account_number }}<br>
                    IFS Code: {{ $invoice->bank_ifsc_code }}
                </span>
                </td>
            </tr>
        </table>
    @endif

    <!-- Notes / Terms -->
    @if($invoice->notes || $invoice->terms_and_conditions)
        <table class="border-all" style="margin-top: 10px;">
            @if($invoice->notes)
                <tr>
                    <td><strong>Notes:</strong> {{ $invoice->notes }}</td>
                </tr>
            @endif
            @if($invoice->terms_and_conditions)
                <tr>
                    <td><strong>Terms &amp; Conditions:</strong> {{ $invoice->terms_and_conditions }}</td>
                </tr>
            @endif
        </table>
    @endif

    <!-- Declaration and Signature -->
    <table style="margin-top: 30px; border: none;">
        <tr>
            <td style="width: 50%; vertical-align: top; border: none;">
                <div style="font-size: 10px;">
                    <strong>Declaration</strong><br>
                    We declare that this invoice shows the actual price of the<br>
                    goods described and that all particulars are true and correct.
                </div>
            </td>
            <td style="width: 50%; vertical-align: bottom; text-align: right; border: none;">
                <div style="margin-top: 50px;">
                    <strong>for {{ $invoice->company_name }}</strong><br><br><br>
                    <strong>Authorised Signatory</strong>
                </div>
            </td>
        </tr>
    </table>

    <div style="text-align: center; margin-top: 20px; font-size: 9px; font-style: italic;">
        This is a Computer Generated Invoice
    </div>
</div>
</body>
</html>
