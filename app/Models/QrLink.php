<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Remembers which person a QR text belongs to, once someone has confirmed it. */
class QrLink extends Model
{
    protected $primaryKey = 'qr_text';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }
}
