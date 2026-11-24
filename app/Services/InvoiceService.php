<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Tax;
use Barryvdh\DomPDF\PDF;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Invoice rules shared by the API and the admin panel: validation, numbering,
 * line/tax totals and the status workflow.
 */
class InvoiceService
{
    public const SETTING_PREFIX = 'invoice.prefix';

    public const SETTING_TERMS = 'invoice.default_terms';

    public const SETTING_DUE_DAYS = 'invoice.due_days';

    /** Characters GST allows in an invoice number. */
    public const NUMBER_PATTERN = '/^[A-Za-z0-9\/-]+$/';

    /** Longest prefix that keeps PREFIX/26-27/0001 within GST's 16 characters. */
    public const PREFIX_MAX_LENGTH = 5;

    /** Path of the organisation logo on the private "local" disk. */
    public const SETTING_LOGO = 'company.logo';

    /** Company details printed on the invoice (Settings page). */
    public const COMPANY_SETTINGS = [
        'company.name' => 'Company name',
        'company.address' => 'Address',
        'company.tax_id' => 'GSTIN / Tax ID',
        'company.email' => 'Billing email',
        'company.phone' => 'Phone',
        'company.website' => 'Website',
    ];

    /** Allowed status changes: from => [to, ...]. */
    public const TRANSITIONS = [
        'draft' => ['sent', 'cancelled'],
        'sent' => ['paid', 'cancelled', 'draft'],
        'paid' => ['sent'],          // undo a payment recorded by mistake
        'cancelled' => ['draft'],
    ];

    /** @return array<string, mixed> */
    public function rules(?Invoice $invoice = null): array
    {
        $required = $invoice ? 'sometimes' : 'required';

        return [
            'client_id' => [$required, 'integer', Rule::exists('clients', 'id')],
            // GST (CGST Rule 46(b)): up to 16 characters; letters, digits, "-" and "/" only.
            'number' => ['nullable', 'string', 'max:16', 'regex:'.self::NUMBER_PATTERN, Rule::unique('invoices', 'number')->ignore($invoice)],
            'issue_date' => [$required, 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'payment_mode' => ['nullable', 'string', 'max:50'],
            // Flat amount off the subtotal, before tax. Can't be more than the subtotal.
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            // Flat fee added to the total after tax.
            'commission_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'items' => [$required, 'array', 'min:1', 'max:200'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            // Taxes to apply on the subtotal. Leave out on create to use the default taxes; [] = no tax.
            'tax_ids' => ['sometimes', 'array', 'max:20'],
            'tax_ids.*' => ['integer', 'distinct', Rule::exists('taxes', 'id')],
        ];
    }

    /** Create or update an invoice and its line items, recalculating totals. */
    public function save(Invoice $invoice, array $data, ?int $userId = null): Invoice
    {
        if ($invoice->exists && $invoice->isLocked()) {
            throw ValidationException::withMessages(['status' => "A {$invoice->status} invoice can't be edited."]);
        }

        return DB::transaction(function () use ($invoice, $data, $userId) {
            $items = $data['items'] ?? null;
            $taxIds = array_key_exists('tax_ids', $data) ? (array) $data['tax_ids'] : null;
            unset($data['items'], $data['tax_ids']);
            $isNew = ! $invoice->exists;

            foreach (['discount', 'commission_fee'] as $field) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = round((float) $data[$field], 2);
                }
            }
            if (! empty($data['currency'])) {
                $data['currency'] = strtoupper($data['currency']);
            }
            $invoice->fill($data);
            if (! $invoice->exists) {
                $invoice->status = 'draft';
                $invoice->created_by = $userId;
                $invoice->currency = $invoice->currency ?: 'INR';
                if (! array_key_exists('terms', $data)) {
                    $invoice->terms = $this->setting(self::SETTING_TERMS);
                }
            }
            if (blank($invoice->number)) {
                $invoice->number = $this->nextNumber(CarbonImmutable::parse($invoice->issue_date));
            }
            $invoice->save();

            if ($items !== null) {
                $invoice->items()->delete();
                foreach (array_values($items) as $i => $item) {
                    $qty = round((float) $item['quantity'], 2);
                    $price = round((float) $item['unit_price'], 2);
                    $invoice->items()->create([
                        'position' => $i,
                        'description' => $item['description'],
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'amount' => round($qty * $price, 2),
                    ]);
                }
            }

            if ($taxIds === null && $isNew) {
                $taxIds = Tax::where('is_active', true)->where('is_default', true)->pluck('id')->all();
            }
            if ($taxIds !== null) {
                $this->syncTaxes($invoice, $taxIds);
            }

            $this->recalculate($invoice);

            return $invoice->load(['client', 'items', 'taxes']);
        });
    }

    /**
     * Apply exactly these taxes. Taxes already on the invoice keep the name and
     * rate they were added with; new ones copy the current tax. A turned-off
     * tax can stay on an invoice that has it but can't be newly added.
     */
    private function syncTaxes(Invoice $invoice, array $taxIds): void
    {
        $taxIds = array_values(array_unique(array_map('intval', $taxIds)));
        $current = $invoice->taxes()->get()->keyBy('tax_id');

        $taxes = Tax::whereIn('id', $taxIds)->get()->keyBy('id');
        foreach ($taxIds as $id) {
            if (! $current->has($id) && ! ($taxes[$id]->is_active ?? false)) {
                throw ValidationException::withMessages(['tax_ids' => "The tax {$taxes[$id]?->label} is turned off and can't be added."]);
            }
        }

        // Remove taxes no longer picked (old copies without a tax_id are removed too).
        $invoice->taxes()->where(fn ($q) => $q->whereNull('tax_id')->orWhereNotIn('tax_id', $taxIds))->delete();

        foreach ($taxIds as $id) {
            if (! $current->has($id)) {
                $invoice->taxes()->create(['tax_id' => $id, 'name' => $taxes[$id]->name, 'rate' => $taxes[$id]->rate]);
            }
        }
    }

    /**
     * Subtotal = sum of lines; taxable value = subtotal - discount;
     * each tax = taxable value x rate; total = taxable value + taxes + commission fee.
     */
    public function recalculate(Invoice $invoice): void
    {
        $subtotal = round((float) $invoice->items()->sum('amount'), 2);
        $discount = round((float) $invoice->discount, 2);
        if ($discount > $subtotal) {
            throw ValidationException::withMessages(['discount' => 'The discount can\'t be more than the subtotal ('.number_format($subtotal, 2).').']);
        }
        $taxable = round($subtotal - $discount, 2);

        $taxTotal = 0.0;
        foreach ($invoice->taxes()->get() as $tax) {
            $amount = round($taxable * (float) $tax->rate / 100, 2);
            $tax->update(['amount' => $amount]);
            $taxTotal += $amount;
        }
        $invoice->forceFill([
            'subtotal' => $subtotal,
            'tax_total' => round($taxTotal, 2),
            'total' => round($taxable + $taxTotal + (float) $invoice->commission_fee, 2),
        ])->save();
    }

    public function changeStatus(Invoice $invoice, string $status, array $payment = []): Invoice
    {
        $allowed = self::TRANSITIONS[$invoice->status] ?? [];
        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "Can't change a {$invoice->status} invoice to {$status}.",
            ]);
        }
        if ($status === 'sent' && ! $invoice->items()->exists()) {
            throw ValidationException::withMessages(['status' => 'Add at least one line item before sending.']);
        }

        $invoice->status = $status;
        if ($status === 'paid') {
            $invoice->paid_at = $payment['paid_at'] ?? CarbonImmutable::today();
            $invoice->payment_reference = $payment['payment_reference'] ?? null;
        } else {
            $invoice->paid_at = null;
            $invoice->payment_reference = null;
        }
        $invoice->save();

        return $invoice;
    }

    /**
     * Next consecutive number in the issue date's Indian financial year
     * (April–March), e.g. INV/26-27/0007. The series restarts every April.
     */
    public function nextNumber(CarbonImmutable $issueDate): string
    {
        $prefix = ($this->setting(self::SETTING_PREFIX) ?: 'INV').'/'.self::financialYear($issueDate).'/';
        $last = Invoice::where('number', 'like', $prefix.'%')->lockForUpdate()->pluck('number')
            ->map(fn ($n) => (int) substr($n, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Short financial year label, e.g. 2026-11-23 => "26-27", 2027-02-10 => "26-27". */
    public static function financialYear(CarbonImmutable $date): string
    {
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return sprintf('%02d-%02d', $start % 100, ($start + 1) % 100);
    }

    public function defaultDueDate(CarbonImmutable $issueDate): CarbonImmutable
    {
        $days = (int) ($this->setting(self::SETTING_DUE_DAYS) ?: 15);

        return $issueDate->addDays($days);
    }

    /** @return array<string, string|null> */
    public function company(): array
    {
        $stored = CompanySetting::whereIn('key', array_keys(self::COMPANY_SETTINGS))->pluck('value', 'key');
        $company = [];
        foreach (array_keys(self::COMPANY_SETTINGS) as $key) {
            $company[substr($key, strlen('company.'))] = $stored[$key] ?? null;
        }
        $company['name'] = $company['name'] ?: config('app.name');
        $company['logo'] = $this->logoDataUri();

        return $company;
    }

    /**
     * The logo as a data: URI, so it works in the admin page and in the PDF
     * without a public storage link. Null when no logo is uploaded.
     */
    public function logoDataUri(): ?string
    {
        $path = $this->setting(self::SETTING_LOGO);
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        $mime = Storage::disk('local')->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($path));
    }

    /** Render the invoice as a PDF on the server. */
    public function pdf(Invoice $invoice): PDF
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice->loadMissing(['client', 'items', 'taxes']),
            'company' => $this->company(),
        ])->setPaper('a4');
    }

    /**
     * The amount in words using the Indian system (lakh, crore), e.g.
     * 160480 => "One Lakh Sixty Thousand Four Hundred and Eighty Rupees Only".
     */
    public function amountInWords(float $amount, string $currency = 'INR'): string
    {
        $cents = (int) round(abs($amount) * 100);
        $whole = intdiv($cents, 100);
        $fraction = $cents % 100;
        [$major, $minor] = strtoupper($currency) === 'INR' ? ['Rupees', 'Paise'] : [strtoupper($currency), 'Cents'];

        $words = $this->integerToWords($whole).' '.$major;
        if ($fraction > 0) {
            $words .= ' and '.$this->integerToWords($fraction).' '.$minor;
        }

        return $words.' Only';
    }

    private function integerToWords(int $number): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
            'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        if ($number === 0) {
            return 'Zero';
        }

        $parts = [];
        foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand', 100 => 'Hundred'] as $divisor => $name) {
            if ($number >= $divisor) {
                $parts[] = $this->integerToWords(intdiv($number, $divisor)).' '.$name;
                $number %= $divisor;
            }
        }
        if ($number > 0) {
            $tail = $number < 20 ? $ones[$number] : trim($tens[intdiv($number, 10)].' '.$ones[$number % 10]);
            $parts[] = $parts === [] ? $tail : 'and '.$tail;
        }

        return implode(' ', $parts);
    }

    /** Safe download name, e.g. INV-2026-0001.pdf. */
    public function pdfFilename(Invoice $invoice): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '-', $invoice->number).'.pdf';
    }

    public function setting(string $key): ?string
    {
        return CompanySetting::where('key', $key)->value('value');
    }
}
