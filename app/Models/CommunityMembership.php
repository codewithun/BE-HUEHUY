<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class CommunityMembership extends Model
{
    use HasFactory;

    // Jika tabel kamu mengikuti konvensi "community_memberships", tak perlu deklarasi.
    // protected $table = 'community_memberships';

    protected $fillable = [
        'user_id',
        'community_id',
        'status',
        'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    // Default attributes
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Boot: set default joined_at kalau belum ada.
     */
    protected static function booted(): void
    {
        static::creating(function (self $membership) {
            if (empty($membership->status)) {
                $membership->status = 'active';
            }
            if (empty($membership->joined_at)) {
                $membership->joined_at = now();
            }
        });
    }

    /**
     * Relasi ke User.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Relasi ke Community.
     */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class, 'community_id', 'id');
    }

    /**
     * Scope: hanya membership aktif.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Helper: apakah membership aktif?
     */
    public function isActive(): bool
    {
        return strtolower((string)$this->status) === 'active';
    }
}
