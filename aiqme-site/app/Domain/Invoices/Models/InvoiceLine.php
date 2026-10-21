<?php

namespace App\Domain\Invoices\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Строка счёта (§3.5): описание, количество, брутто строки заказа и выделенные из него VAT и нетто (центы). */
#[Fillable(['invoice_id', 'description', 'quantity', 'net', 'vat', 'gross'])]
class InvoiceLine extends Model
{
    public $timestamps = false;
}
