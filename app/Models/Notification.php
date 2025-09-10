<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Log;

class Notification extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'image_url',
        'target_type',
        'target_id',
        'action_url',
        'meta',
        'read_at',
        
        // Legacy fields (untuk backward compatibility)
        'cube_id',
        'ad_id',
        'grab_id',
    ];

    /**
     * Casting untuk type conversion.
     */
    protected $casts = [
        'user_id' => 'integer',
        'target_id' => 'integer',
        'meta' => 'array',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        
        // Legacy casts
        'cube_id' => 'integer',
        'ad_id' => 'integer',
        'grab_id' => 'integer',
    ];

    /**
     * Relationship dengan User (penerima notifikasi)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Polymorphic relationship untuk target entity
     */
    public function target(): MorphTo
    {
        return $this->morphTo('target', 'target_type', 'target_id');
    }

    /**
     * Legacy relationships (untuk backward compatibility)
     */
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

    // ================= Scopes =================

    /**
     * Scope untuk filter berdasarkan user
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope untuk filter berdasarkan type
     */
    public function scopeType($query, ?string $type)
    {
        if ($type && $type !== 'all') {
            return $query->where('type', $type);
        }
        return $query;
    }

    /**
     * Scope untuk notifikasi yang belum dibaca
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope untuk notifikasi yang sudah dibaca
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope untuk notifikasi hunter (legacy)
     */
    public function scopeHunter($query)
    {
        return $query->where('type', 'hunter');
    }

    /**
     * Scope untuk notifikasi merchant (legacy)
     */
    public function scopeMerchant($query)
    {
        return $query->where('type', 'merchant');
    }

    /**
     * Scope untuk notifikasi voucher
     */
    public function scopeVoucher($query)
    {
        return $query->where('type', 'voucher');
    }

    /**
     * Scope untuk notifikasi promo
     */
    public function scopePromo($query)
    {
        return $query->where('type', 'promo');
    }

    /**
     * Scope untuk notifikasi system
     */
    public function scopeSystem($query)
    {
        return $query->where('type', 'system');
    }

    /**
     * Scope untuk filter berdasarkan target type
     */
    public function scopeTargetType($query, string $targetType)
    {
        return $query->where('target_type', $targetType);
    }

    // ================= Accessors & Mutators =================

    /**
     * Check apakah notifikasi sudah dibaca
     */
    public function getIsReadAttribute(): bool
    {
        return !is_null($this->read_at);
    }

    /**
     * Get formatted time ago
     */
    public function getTimeAgoAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }

    /**
     * Get notification icon berdasarkan type
     */
    public function getIconAttribute(): string
    {
        $icons = [
            'voucher' => '🎫',
            'promo' => '🏷️',
            'community' => '👥',
            'system' => '🔔',
            'merchant' => '🏪',
            'hunter' => '🎯',
            'grab' => '🎁',
            'default' => '📢'
        ];

        return $icons[$this->type] ?? $icons['default'];
    }

    /**
     * Get notification color berdasarkan type
     */
    public function getColorAttribute(): string
    {
        $colors = [
            'voucher' => 'bg-purple-100 text-purple-800',
            'promo' => 'bg-orange-100 text-orange-800',
            'community' => 'bg-blue-100 text-blue-800',
            'system' => 'bg-gray-100 text-gray-800',
            'merchant' => 'bg-green-100 text-green-800',
            'hunter' => 'bg-red-100 text-red-800',
            'grab' => 'bg-yellow-100 text-yellow-800',
        ];

        return $colors[$this->type] ?? $colors['system'];
    }

    // ================= Helper Methods =================

    /**
     * Mark notifikasi sebagai sudah dibaca
     */
    public function markAsRead(): bool
    {
        if ($this->is_read) {
            return true;
        }

        $this->read_at = now();
        return $this->save();
    }

    /**
     * Mark notifikasi sebagai belum dibaca
     */
    public function markAsUnread(): bool
    {
        $this->read_at = null;
        return $this->save();
    }

    /**
     * Get notification content for display
     */
    public function getDisplayContent(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'image_url' => $this->image_url,
            'action_url' => $this->action_url,
            'icon' => $this->icon,
            'color' => $this->color,
            'is_read' => $this->is_read,
            'time_ago' => $this->time_ago,
            'created_at' => $this->created_at,
            'meta' => $this->meta,
        ];
    }

    /**
     * Create voucher notification (helper method)
     */
    public static function createVoucherNotification(
        int $userId,
        Voucher $voucher,
        string $title = null,
        string $message = null
    ): self {
        return self::create([
            'user_id' => $userId,
            'type' => 'voucher',
            'title' => $title ?? 'Voucher Baru Tersedia!',
            'message' => $message ?? "Voucher '{$voucher->name}' tersedia untuk Anda. Gunakan kode: {$voucher->code}",
            'image_url' => $voucher->image_url,
            'target_type' => 'voucher',
            'target_id' => $voucher->id,
            'action_url' => "/vouchers/{$voucher->id}",
            'meta' => [
                'voucher_code' => $voucher->code,
                'voucher_name' => $voucher->name,
                'valid_until' => $voucher->valid_until?->format('Y-m-d'),
                'community_id' => $voucher->community_id,
                'target_type' => $voucher->target_type,
            ]
        ]);
    }

    /**
     * Create promo notification (helper method)
     */
    public static function createPromoNotification(
        int $userId,
        $promo,
        string $title = null,
        string $message = null
    ): self {
        return self::create([
            'user_id' => $userId,
            'type' => 'promo',
            'title' => $title ?? 'Promo Baru!',
            'message' => $message ?? "Promo '{$promo->name}' tersedia untuk Anda",
            'image_url' => $promo->image_url ?? null,
            'target_type' => 'promo',
            'target_id' => $promo->id,
            'action_url' => "/promos/{$promo->id}",
            'meta' => [
                'promo_name' => $promo->name,
                'community_id' => $promo->community_id ?? null,
            ]
        ]);
    }

    /**
     * Bulk mark as read untuk multiple notifications
     */
    public static function bulkMarkAsRead(array $notificationIds, int $userId): int
    {
        return self::whereIn('id', $notificationIds)
                   ->where('user_id', $userId)
                   ->whereNull('read_at')
                   ->update(['read_at' => now()]);
    }

    /**
     * Get unread count untuk user
     */
    public static function getUnreadCount(int $userId): int
    {
        return self::where('user_id', $userId)
                   ->whereNull('read_at')
                   ->count();
    }

    // ================= Model Events =================

    /**
     * Boot method untuk model events
     */
    protected static function boot()
    {
        parent::boot();

        // Log saat notifikasi dibuat
        static::created(function ($notification) {
            Log::info("Notification created", [
                'id' => $notification->id,
                'user_id' => $notification->user_id,
                'type' => $notification->type,
                'target_type' => $notification->target_type,
                'target_id' => $notification->target_id,
            ]);
        });
    }
}
