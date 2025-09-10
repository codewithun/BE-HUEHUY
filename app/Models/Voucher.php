<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Log;

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
        'stock' => 'integer',
        'community_id' => 'integer',
        'target_user_id' => 'integer',
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

    /**
     * TAMBAHAN: Relationship ke notifications menggunakan polymorphic
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'target', 'target_type', 'target_id');
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

    /**
     * TAMBAHAN: Get formatted image URL
     */
    public function getImageUrlAttribute(): ?string
    {
        if (!$this->image) {
            return null;
        }

        // Jika sudah absolute URL
        if (filter_var($this->image, FILTER_VALIDATE_URL)) {
            return $this->image;
        }

        // Jika relative path
        return asset('storage/' . $this->image);
    }

    /**
     * TAMBAHAN: Get target description for display
     */
    public function getTargetDescriptionAttribute(): string
    {
        switch ($this->target_type) {
            case 'user':
                $user = $this->targetUser;
                return $user ? "User: {$user->name}" : "User #" . $this->target_user_id;
                
            case 'community':
                $community = $this->community;
                return $community ? "Community: {$community->name}" : "Community #" . $this->community_id;
                
            case 'all':
            default:
                return 'Semua Pengguna';
        }
    }

    /**
     * TAMBAHAN: Check if voucher is still valid
     */
    public function getIsValidAttribute(): bool
    {
        return $this->stock > 0 && 
               (!$this->valid_until || now()->isBefore($this->valid_until));
    }

    /**
     * TAMBAHAN: Get validation count
     */
    public function getValidationCountAttribute(): int
    {
        return $this->validations()->count();
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
              })
              ->orWhere(function ($qq) use ($userId) {
                  // TAMBAHAN: Include voucher untuk community yang user join
                  $qq->where('target_type', 'community')
                     ->whereHas('community.memberships', function($qqq) use ($userId) {
                         $qqq->where('user_id', $userId)
                             ->where('status', 'active');
                     });
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

    /**
     * TAMBAHAN: Scope untuk voucher yang masih aktif
     */
    public function scopeActive($query)
    {
        return $query->where('stock', '>', 0)
                     ->where(function ($q) {
                         $q->whereNull('valid_until')
                           ->orWhere('valid_until', '>=', now());
                     });
    }

    /**
     * TAMBAHAN: Scope untuk voucher yang expired
     */
    public function scopeExpired($query)
    {
        return $query->where(function ($q) {
            $q->where('stock', '<=', 0)
              ->orWhere(function ($qq) {
                  $qq->whereNotNull('valid_until')
                     ->where('valid_until', '<', now());
              });
        });
    }

    /**
     * TAMBAHAN: Scope berdasarkan target type
     */
    public function scopeTargetType($query, string $targetType)
    {
        return $query->where('target_type', $targetType);
    }

    // ================= Helper Methods =================

    /**
     * TAMBAHAN: Check apakah user sudah dapat notifikasi untuk voucher ini
     */
    public function hasNotifiedUser(int $userId): bool
    {
        return $this->notifications()
                    ->where('user_id', $userId)
                    ->exists();
    }

    /**
     * TAMBAHAN: Check apakah user eligible untuk voucher ini
     */
    public function isEligibleForUser(int $userId): bool
    {
        switch ($this->target_type) {
            case 'all':
                return true;
                
            case 'user':
                return $this->target_user_id == $userId;
                
            case 'community':
                if (!$this->community_id) return false;
                
                // Check if user is member of this community
                return User::where('id', $userId)
                          ->whereHas('communityMemberships', function($q) {
                              $q->where('community_id', $this->community_id)
                                ->where('status', 'active');
                          })
                          ->exists();
                          
            default:
                return false;
        }
    }

    /**
     * TAMBAHAN: Get eligible users untuk voucher ini
     */
    public function getEligibleUsers()
    {
        switch ($this->target_type) {
            case 'all':
                return User::whereNotNull('email_verified_at');
                
            case 'user':
                return User::where('id', $this->target_user_id);
                
            case 'community':
                if (!$this->community_id) {
                    return User::whereRaw('1 = 0'); // empty query
                }
                
                return User::whereHas('communityMemberships', function($q) {
                    $q->where('community_id', $this->community_id)
                      ->where('status', 'active');
                });
                
            default:
                return User::whereRaw('1 = 0'); // empty query
        }
    }

    /**
     * TAMBAHAN: Decrement stock dengan safe checking
     */
    public function decrementStock(int $amount = 1): bool
    {
        if ($this->stock < $amount) {
            return false;
        }

        $this->decrement('stock', $amount);
        return true;
    }

    /**
     * TAMBAHAN: Get community-based vouchers for indexByCommunity endpoint
     */
    public function scopeIndexByCommunity($query, int $communityId)
    {
        return $query->where(function ($q) use ($communityId) {
            // Voucher yang dibuat untuk community ini
            $q->where('community_id', $communityId)
              // Atau voucher global yang bisa dipakai semua community
              ->orWhere('target_type', 'all');
        });
    }

    // ================= Events =================

    /**
     * TAMBAHAN: Boot method untuk model events
     */
    protected static function boot()
    {
        parent::boot();

        // Log saat voucher dibuat
        static::created(function ($voucher) {
            Log::info("Voucher created", [
                'id' => $voucher->id,
                'name' => $voucher->name,
                'code' => $voucher->code,
                'target_type' => $voucher->target_type,
                'stock' => $voucher->stock
            ]);
        });

        // Log saat stock berubah
        static::updated(function ($voucher) {
            if ($voucher->isDirty('stock')) {
                Log::info("Voucher stock updated", [
                    'id' => $voucher->id,
                    'old_stock' => $voucher->getOriginal('stock'),
                    'new_stock' => $voucher->stock
                ]);
            }
        });
    }
}
