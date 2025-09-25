<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;

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

        // Targeting
        'target_type',      // all | user | community
        'target_user_id',   // nullable (wajib saat target_type = user)

        // Tipe validasi
        'validation_type',  // auto | manual

        // Versi gambar
        'image_updated_at',
    ];

    protected $casts = [
        'valid_until'      => 'datetime',
        'stock'            => 'integer',
        'community_id'     => 'integer',
        'target_user_id'   => 'integer',
        'validation_type'  => 'string',
        'image_updated_at' => 'datetime',
    ];

    // agar ikut di JSON
    protected $appends = ['image_url', 'image_url_versioned'];

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

    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'target', 'target_type', 'target_id');
    }

    // ================= Accessors =================

    public function getStatusAttribute(): string
    {
        if ((int) $this->stock <= 0) return 'inactive';
        if ($this->valid_until && now()->isAfter($this->valid_until)) return 'expired';
        return 'active';
    }

    public function getImageUrlAttribute(): ?string
{
    if (!$this->image) return null;
    if (filter_var($this->image, FILTER_VALIDATE_URL)) {
        return $this->image;
    }

    /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
    $disk = \Illuminate\Support\Facades\Storage::disk('public');
    return $disk->url(ltrim($this->image, '/'));
}

    public function getImageUrlVersionedAttribute(): ?string
    {
        $url = $this->image_url;
        if (!$url) return null;

        // PERBAIKAN: Prioritas versioning yang lebih robust
        $ver = null;

        // 1) Prioritas tertinggi: image_updated_at (ketika file diganti)
        if ($this->image_updated_at instanceof Carbon) {
            $ver = $this->image_updated_at->getTimestamp();
        } 
        // 2) Fallback: updated_at (ketika record berubah)
        else if ($this->updated_at instanceof Carbon) {
            $ver = $this->updated_at->getTimestamp();
        }
        // 3) Fallback terakhir: ID + current time
        else {
            $ver = $this->id ? ($this->id * 1000 + (time() % 1000)) : time();
        }

        return str_contains($url, '?') ? "{$url}&v={$ver}" : "{$url}?v={$ver}";
    }

    public function getTargetDescriptionAttribute(): string
    {
        switch ($this->target_type) {
            case 'user':
                $user = $this->targetUser;
                return $user ? "User: {$user->name}" : "User #{$this->target_user_id}";
            case 'community':
                $community = $this->community;
                return $community ? "Community: {$community->name}" : "Community #{$this->community_id}";
            default:
                return 'Semua Pengguna';
        }
    }

    public function getIsValidAttribute(): bool
    {
        return $this->stock > 0 &&
               (!$this->valid_until || now()->isBefore($this->valid_until));
    }

    public function getValidationCountAttribute(): int
    {
        return $this->validations()->count();
    }

    public function getValidationTypeAttribute($value): string
    {
        return $value ?: 'auto';
    }

    // ================= Scopes =================

    public function scopeForUser($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('target_type', 'all')
              ->orWhere(function ($qq) use ($userId) {
                  $qq->where('target_type', 'user')
                     ->where('target_user_id', $userId);
              })
              ->orWhere(function ($qq) use ($userId) {
                  $qq->where('target_type', 'community')
                     ->whereHas('community.memberships', function($qqq) use ($userId) {
                         $qqq->where('user_id', $userId)->where('status', 'active');
                     });
              });
        });
    }

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

    public function scopeActive($query)
    {
        return $query->where('stock', '>', 0)
                     ->where(function ($q) {
                         $q->whereNull('valid_until')
                           ->orWhere('valid_until', '>=', now());
                     });
    }

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

    public function scopeTargetType($query, string $targetType)
    {
        return $query->where('target_type', $targetType);
    }

    public function scopeIndexByCommunity($query, int $communityId)
    {
        return $query->where(function ($q) use ($communityId) {
            $q->where('community_id', $communityId)
              ->orWhere('target_type', 'all');
        });
    }

    // ================= Helpers =================

    public function hasNotifiedUser(int $userId): bool
    {
        return $this->notifications()->where('user_id', $userId)->exists();
    }

    public function isEligibleForUser(int $userId): bool
    {
        switch ($this->target_type) {
            case 'all': return true;
            case 'user': return $this->target_user_id == $userId;
            case 'community':
                if (!$this->community_id) return false;
                return User::where('id', $userId)
                    ->whereHas('communityMemberships', function($q) {
                        $q->where('community_id', $this->community_id)->where('status', 'active');
                    })
                    ->exists();
            default: return false;
        }
    }

    public function getEligibleUsers()
    {
        switch ($this->target_type) {
            case 'all': return User::whereNotNull('email_verified_at');
            case 'user': return User::where('id', $this->target_user_id);
            case 'community':
                if (!$this->community_id) return User::whereRaw('1 = 0');
                return User::whereHas('communityMemberships', function($q) {
                    $q->where('community_id', $this->community_id)->where('status', 'active');
                });
            default: return User::whereRaw('1 = 0');
        }
    }

    public function decrementStock(int $amount = 1): bool
    {
        if ($this->stock < $amount) return false;
        $this->decrement('stock', $amount);
        return true;
    }

    // ================= Mutator =================
    public function setImageAttribute($value): void
    {
        $original = $this->attributes['image'] ?? null;
        
        // Hanya set jika benar-benar ada nilai dan berbeda
        if ($value !== null && $value !== '') {
            $this->attributes['image'] = $value;
            
            // Update timestamp hanya jika nilai benar-benar berubah
            if ($value !== $original) {
                $this->attributes['image_updated_at'] = now();
            }
        }
    }

    // ================= Events =================
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($voucher) {
            if (empty($voucher->validation_type)) {
                $voucher->validation_type = 'auto';
            }
        });

        static::created(function ($voucher) {
            Log::info("Voucher created", [
                'id'              => $voucher->id,
                'name'            => $voucher->name,
                'code'            => $voucher->code,
                'target_type'     => $voucher->target_type,
                'stock'           => $voucher->stock,
                'validation_type' => $voucher->validation_type,
            ]);
        });

        static::updated(function ($voucher) {
            if ($voucher->isDirty('stock')) {
                Log::info("Voucher stock updated", [
                    'id'        => $voucher->id,
                    'old_stock' => $voucher->getOriginal('stock'),
                    'new_stock' => $voucher->stock
                ]);
            }
        });
    }
}
