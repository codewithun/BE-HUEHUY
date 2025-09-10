<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'image',
        'type',
        'valid_until',
        'tenant_location',
        'stock',
        'code',
        'community_id',

        // NEW: targeting fields
        'target_type',      // all | user | community
        'target_user_id',   // nullable (wajib saat target_type = user)
    ];

    protected $casts = [
        'valid_until' => 'date',
    ];

    // ================= Relations =================

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class, 'ad_id', 'id');
    }

    public function voucher_items(): HasMany
    {
        return $this->hasMany(VoucherItem::class, 'voucher_id', 'id');
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class, 'community_id', 'id');
    }

    public function validations(): HasMany
    {
        return $this->hasMany(VoucherValidation::class, 'voucher_id', 'id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    // ================= Accessors =================

    public function getStatusAttribute(): string
    {
        if ((int) $this->stock <= 0) {
            return 'inactive';
        }

        if ($this->valid_until && now()->isAfter($this->valid_until)) {
            return 'expired';
        }

        return 'active';
    }

    // ================= Scopes / Helpers =================

    /**
     * Filter voucher yang applicable untuk user tertentu (berdasarkan target_type).
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('target_type', 'all')
              ->orWhere(function ($qq) use ($userId) {
                  $qq->where('target_type', 'user')
                     ->where('target_user_id', $userId);
              });
        });
    }

    /**
     * Filter voucher untuk suatu community (target_type = all/community).
     */
    public function scopeForCommunity($query, int $communityId)
    {
        return $query->where(function ($q) use ($communityId) {
            $q->where('target_type', 'all')
              ->orWhere(function ($qq) use ($communityId) {
                  $qq->where('target_type', 'community')
                     ->where('community_id', $communityId);
              });
        });
    }
}
