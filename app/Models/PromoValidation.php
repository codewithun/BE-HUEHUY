<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromoValidation extends Model
{
    protected $fillable = ['promo_id', 'user_id', 'code', 'validated_at', 'notes'];

    protected $casts = [
        'validated_at' => 'datetime',
    ];

    public function promo()
    {
        return $this->belongsTo(Promo::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}