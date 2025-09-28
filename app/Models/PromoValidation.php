<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromoValidation extends Model
{
    protected $fillable = ['promo_id', 'promo_item_id', 'user_id', 'code', 'validated_at', 'notes'];
    public $timestamps = false;

    protected $casts = ['validated_at' => 'datetime'];

    // === TAMBAHKAN BARIS INI ===
    protected $with = ['promo', 'user'];

    public function promo()
    {
        return $this->belongsTo(Promo::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function promoItem()
    {
        return $this->belongsTo(PromoItem::class, 'promo_item_id');
    }
}
