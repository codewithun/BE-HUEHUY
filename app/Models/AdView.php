<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdView extends Model
{
    use HasFactory;

    protected $fillable = [
        'ad_id',
        'user_id',
        'ip_address',
        'user_agent',
        'session_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relasi ke Ad
     */
    public function ad()
    {
        return $this->belongsTo(Ad::class);
    }

    /**
     * Relasi ke User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Hitung total unique viewers untuk ad tertentu
     */
    public static function uniqueViewersForAd($adId)
    {
        $userViews = self::where('ad_id', $adId)
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        $guestViews = self::where('ad_id', $adId)
            ->whereNull('user_id')
            ->distinct('session_id')
            ->count('session_id');

        return $userViews + $guestViews;
    }
}
