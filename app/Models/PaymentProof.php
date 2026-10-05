<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProof extends Model
{
    protected $fillable = ['payment_id', 'file_path', 'source', 'extracted_amount'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
