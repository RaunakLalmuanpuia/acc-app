<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\InventoryItem;

class InvoiceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->invoiceDefinitions() as $gstNumber => $invoices) {
            $company = Company::where('gst_number', $gstNumber)->firstOrFail();

            foreach ($invoices as $data) {
                $this->seedInvoice($company, $data);
            }

            $this->command?->info("  ✓ Invoices seeded for: {$company->company_name}");
        }
    }

    private function seedInvoice(Company $company, array $data): void
    {
        $client = Client::where('company_id', $company->id)
            ->where('email', $data['client_email'])
            ->firstOrFail();

        $supplyType = $company->state_code === $client->state_code ? 'intra_state' : 'inter_state';

        $invoice = Invoice::updateOrCreate(
            ['invoice_number' => $data['invoice_number']],
            [
                'company_id'          => $company->id,
                'client_id'           => $client->id,
                'company_name'        => $company->company_name,
                'company_gst_number'  => $company->gst_number,
                'company_state'       => $company->state,
                'company_state_code'  => $company->state_code,
                'client_name'         => $client->name,
                'client_email'        => $client->email,
                'client_address'      => $client->address . ', ' . $client->city . ', ' . $client->state,
                'client_gst_number'   => $client->gst_number,
                'client_state'        => $client->state,
                'client_state_code'   => $client->state_code,
                'invoice_date'        => $data['invoice_date'],
                'due_date'            => $data['due_date'],
                'supply_type'         => $supplyType,
                'payment_terms'       => $data['payment_terms'],
                'bank_account_name'   => $company->bank_account_name,
                'bank_account_number' => $company->bank_account_number,
                'bank_ifsc_code'      => $company->bank_ifsc_code,
                'status'              => 'sent',
                'invoice_type'        => 'tax_invoice',
            ]
        );

        // Clear existing line items to avoid duplicates on re-seed
        $invoice->lineItems()->delete();

        $subtotal = $cgstTotal = $sgstTotal = $igstTotal = 0;
        $sortOrder = 1;

        foreach ($data['line_items'] as $li) {
            $item = InventoryItem::where('company_id', $company->id)
                ->where('name', 'like', '%' . substr($li['name'], 0, 15) . '%')
                ->first();

            $gstRate = $item?->gst_rate ?? 18.00;
            $amount  = round($li['quantity'] * $li['rate'], 2);

            if ($supplyType === 'intra_state') {
                $half = $gstRate / 2;
                $cgst = round($amount * $half / 100, 2);
                $sgst = $cgst;
                $igst = 0;
            } else {
                $cgst = $sgst = 0;
                $igst = round($amount * $gstRate / 100, 2);
            }

            InvoiceLineItem::create([
                'invoice_id'        => $invoice->id,
                'inventory_item_id' => $item?->id,
                'description'       => $li['name'],
                'hsn_code'          => $item?->hsn_code,
                'unit'              => $item?->unit ?? 'Unit',
                'quantity'          => $li['quantity'],
                'rate'              => $li['rate'],
                'amount'            => $amount,
                'gst_rate'          => $gstRate,
                'cgst_rate'         => $supplyType === 'intra_state' ? $gstRate / 2 : 0,
                'sgst_rate'         => $supplyType === 'intra_state' ? $gstRate / 2 : 0,
                'igst_rate'         => $supplyType === 'inter_state' ? $gstRate : 0,
                'cgst_amount'       => $cgst,
                'sgst_amount'       => $sgst,
                'igst_amount'       => $igst,
                'total_tax_amount'  => $cgst + $sgst + $igst,
                'total_amount'      => $amount + $cgst + $sgst + $igst,
                'sort_order'        => $sortOrder++,
            ]);

            $subtotal  += $amount;
            $cgstTotal += $cgst;
            $sgstTotal += $sgst;
            $igstTotal += $igst;
        }

        $gstTotal = $cgstTotal + $sgstTotal + $igstTotal;

        $invoice->update([
            'subtotal'       => $subtotal,
            'taxable_amount' => $subtotal,
            'cgst_amount'    => $cgstTotal,
            'sgst_amount'    => $sgstTotal,
            'igst_amount'    => $igstTotal,
            'gst_amount'     => $gstTotal,
            'total_amount'   => $subtotal + $gstTotal,
            'amount_due'     => $subtotal + $gstTotal,
        ]);
    }

    private function invoiceDefinitions(): array
    {
        return [
            // ── Grand Central Retail Ltd ───────────────────────────────────
            '27AAAAA7777A1Z5' => [
                [
                    'invoice_number' => 'INV-1770971585',
                    'invoice_date'   => '2026-02-13',
                    'due_date'       => '2026-03-15',
                    'client_email'   => 'admin@infosys-gh.com',
                    'payment_terms'  => 'Net 30',
                    'line_items'     => [
                        ['name' => 'Samsung 55" 4K Smart TV', 'quantity' => 3, 'rate' => 54999.00],
                    ],
                ],
                [
                    'invoice_number' => 'INV-1770973169',
                    'invoice_date'   => '2026-02-13',
                    'due_date'       => '2026-03-15',
                    'client_email'   => 'admin@infosys-gh.com',
                    'payment_terms'  => 'Net 30',
                    'line_items'     => [
                        ['name' => 'Samsung 55" 4K Smart TV', 'quantity' => 20, 'rate' => 54999.00],
                    ],
                ],
                [
                    'invoice_number' => 'INV-1770973626',
                    'invoice_date'   => '2026-02-13',
                    'due_date'       => '2026-02-28',
                    'client_email'   => 'partners@urbanclap.com',
                    'payment_terms'  => 'Net 15',
                    'line_items'     => [
                        ['name' => 'Cotton Polo T-Shirt (Bulk)', 'quantity' => 20, 'rate' => 450.00],
                    ],
                ],
                [
                    'invoice_number' => 'INV-1770973724',
                    'invoice_date'   => '2026-02-14',
                    'due_date'       => '2026-03-16',
                    'client_email'   => 'purchase@marriott.com',
                    'payment_terms'  => 'Net 30',
                    'line_items'     => [
                        ['name' => 'Philips Mixer Grinder 750W', 'quantity' => 30, 'rate' => 3400.00],
                    ],
                ],
                [
                    'invoice_number' => 'INV-1770974350',
                    'invoice_date'   => '2026-02-13',
                    'due_date'       => '2026-03-15',
                    'client_email'   => 'purchase@marriott.com',
                    'payment_terms'  => 'Net 30',
                    'line_items'     => [
                        ['name' => 'Royal Basmati Rice (5kg Bag)', 'quantity' => 300, 'rate' => 850.00],
                    ],
                ],
                [
                    'invoice_number' => 'INV-1770983669',
                    'invoice_date'   => '2026-02-13',
                    'due_date'       => '2026-03-15',
                    'client_email'   => 'partners@urbanclap.com',
                    'payment_terms'  => 'Net 30',
                    'line_items'     => [
                        ['name' => 'Samsung 55" 4K Smart TV', 'quantity' => 20, 'rate' => 54999.00],
                    ],
                ],
            ],

            // ── Horizon Tech Solutions Pvt Ltd ────────────────────────────
            '29BBBBB8888B1Z6' => [
                [
                    'invoice_number' => 'INV-HT-2026-0001',
                    'invoice_date'   => '2026-02-01',
                    'due_date'       => '2026-03-03',
                    'client_email'   => 'procurement@wipro.com',
                    'payment_terms'  => 'Net 30',
                    'line_items'     => [
                        ['name' => 'HorizonHR – Annual Subscription',    'quantity' => 5,   'rate' => 49999.00],
                        ['name' => 'HorizonPay – Payroll Module Add-on', 'quantity' => 5,   'rate' => 1999.00],
                    ],
                ],
                [
                    'invoice_number' => 'INV-HT-2026-0002',
                    'invoice_date'   => '2026-02-05',
                    'due_date'       => '2026-02-20',
                    'client_email'   => 'billing@razorpay.com',
                    'payment_terms'  => 'Net 15',
                    'line_items'     => [
                        ['name' => 'API Integration Package',  'quantity' => 2,  'rate' => 25000.00],
                        ['name' => 'Priority Support – Annual', 'quantity' => 1, 'rate' => 12000.00],
                    ],
                ],
                [
                    'invoice_number' => 'INV-HT-2026-0003',
                    'invoice_date'   => '2026-02-10',
                    'due_date'       => '2026-03-12',
                    'client_email'   => 'enterprise@swiggy.in',
                    'payment_terms'  => 'Net 30',
                    'line_items'     => [
                        ['name' => 'Cloud Migration Consulting',       'quantity' => 40,  'rate' => 3500.00],
                        ['name' => 'Custom Development (per sprint)',  'quantity' => 2,   'rate' => 75000.00],
                    ],
                ],
                [
                    'invoice_number' => 'INV-HT-2026-0004',
                    'invoice_date'   => '2026-02-14',
                    'due_date'       => '2026-03-16',
                    'client_email'   => 'procurement@wipro.com',
                    'payment_terms'  => 'Net 30',
                    'line_items'     => [
                        ['name' => 'HorizonHR – Monthly Subscription', 'quantity' => 10, 'rate' => 4999.00],
                    ],
                ],
            ],
        ];
    }
}
