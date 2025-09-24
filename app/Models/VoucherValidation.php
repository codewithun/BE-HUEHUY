<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherValidation extends Model
{
    protected $fillable = ['voucher_id', 'user_id', 'code', 'validated_at', 'notes'];

    public $timestamps = false;

    protected $casts = [
        'validated_at' => 'datetime',
    ];

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
