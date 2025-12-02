<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CubeView extends Model
{
    use HasFactory;

    protected $fillable = [
        'cube_id',
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
     * Relasi ke Cube
     */
    public function cube()
    {
        return $this->belongsTo(Cube::class);
    }

    /**
     * Relasi ke User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Hitung total views untuk cube tertentu
     */
    public static function countForCube($cubeId)
    {
        return self::where('cube_id', $cubeId)
            ->distinct('user_id', 'session_id')
            ->count();
    }

    /**
     * Hitung total unique viewers (user + guest unik)
     */
    public static function uniqueViewersForCube($cubeId)
    {
        $userViews = self::where('cube_id', $cubeId)
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        $guestViews = self::where('cube_id', $cubeId)
            ->whereNull('user_id')
            ->distinct('session_id')
            ->count('session_id');

        return $userViews + $guestViews;
    }
}
