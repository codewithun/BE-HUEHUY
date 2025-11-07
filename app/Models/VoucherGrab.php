<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherGrab extends Model
{
    use HasFactory;

    protected $fillable = [
        'voucher_id',
        'user_id',
        'date',
        'total_grab',
    ];

    protected $casts = [
        'date' => 'date',
        'total_grab' => 'integer',
    ];

    // =========================>
    // ## Relations
    // =========================>

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
