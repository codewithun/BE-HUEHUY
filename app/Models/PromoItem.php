<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromoItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'promo_id',
        'user_id',
        'code',
        'status',
        'reserved_at',
        'redeemed_at',
        'expires_at',
    ];

    protected $casts = [
        'reserved_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Accessors that should be appended to JSON form.
     */
    protected $appends = ['resolved_ad_id'];

    public function promo()
    {
        return $this->belongsTo(Promo::class);
    }

    /**
     * (Legacy) relasi lama yang salah-asumsi (promo_id -> ads.id).
     * Biarkan ada jika dipakai tempat lain, TAPI jangan dipakai untuk saku/detail.
     */
    public function adLegacy()
    {
        return $this->belongsTo(\App\Models\Ad::class, 'promo_id', 'id');
    }

    /**
     * Helper: ambil Ad yang BENAR via promo.code
     */
    public function adResolved()
    {
        // tidak bisa pure-relasi karena butuh promo->code dulu; pakai query helper
        $code = optional($this->promo)->code;
        return $code ? \App\Models\Ad::where('code', $code)->first() : null;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Accessor yang konsisten dipakai FE
     */
    public function getResolvedAdIdAttribute()
    {
        $code = optional($this->promo)->code;
        if (!$code) return null;
        return \App\Models\Ad::where('code', $code)->value('id');
    }
}
