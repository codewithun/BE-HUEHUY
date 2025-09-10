<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory;

    // Jika nama tabel standar "notifications", ini opsional:
    // protected $table = 'notifications';

    /**
     * Mass-assignable.
     */
    protected $fillable = [
        'user_id',
        'cube_id',
        'ad_id',
        'grab_id',
        'type',     // enum: merchant|hunter (nullable)
        'message',  // isi notifikasi yang ditampilkan di FE
    ];

    /**
     * Casting sederhana (membantu saat filter / compare).
     */
    protected $casts = [
        'user_id' => 'integer',
        'cube_id' => 'integer',
        'ad_id' => 'integer',
        'grab_id' => 'integer',
        'type' => 'string',
    ];

    /**
     * Relasi.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cube(): BelongsTo
    {
        return $this->belongsTo(Cube::class);
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    public function grab(): BelongsTo
    {
        return $this->belongsTo(Grab::class);
    }

    /**
     * Scopes untuk memudahkan query di controller:
     * Notification::forUser($id)->type('hunter')->latest()->get();
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeType($query, ?string $type)
    {
        if ($type) {
            $query->where('type', $type); // 'hunter' | 'merchant'
        }
        return $query;
    }

    public function scopeHunter($query)
    {
        return $query->where('type', 'hunter');
    }

    public function scopeMerchant($query)
    {
        return $query->where('type', 'merchant');
    }
}
