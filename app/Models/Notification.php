<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// Import model yg dipakai relasi & helper
use App\Models\User;
use App\Models\Voucher;
use App\Models\Promo;
use App\Models\Ad;
use App\Models\Grab;
use App\Models\Cube;

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
        'user_id'   => 'integer',
        'target_id' => 'integer',
        'meta'      => 'array',
        'read_at'   => 'datetime',
        'created_at'=> 'datetime',
        'updated_at'=> 'datetime',

        // Legacy casts
        'cube_id'   => 'integer',
        'ad_id'     => 'integer',
        'grab_id'   => 'integer',
    ];

    // ================= Relationships =================

    /**
     * Penerima notifikasi
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Polymorphic relationship untuk target entity (voucher/promo/…)
     * Wajib ada Morph Map di AppServiceProvider:
     * Relation::enforceMorphMap(['voucher' => Voucher::class, 'promo' => Promo::class, ...]);
     */
    public function target(): MorphTo
    {
        // kolom: target_type, target_id
        return $this->morphTo('target', 'target_type', 'target_id');
    }

    /**
     * Legacy relationships (opsional bila dipakai FE)
     */
    public function cube(): BelongsTo   { return $this->belongsTo(Cube::class); }
    public function ad(): BelongsTo     { return $this->belongsTo(Ad::class); }
    public function grab(): BelongsTo   { return $this->belongsTo(Grab::class); }

    // ================= Scopes =================

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeType($query, ?string $type)
    {
        if ($type && $type !== 'all') {
            return $query->where('type', $type);
        }
        return $query;
    }

    public function scopeUnread($query) { return $query->whereNull('read_at'); }
    public function scopeRead($query)   { return $query->whereNotNull('read_at'); }

    public function scopeHunter($query)   { return $query->where('type', 'hunter'); }
    public function scopeMerchant($query) { return $query->where('type', 'merchant'); }
    public function scopeVoucher($query)  { return $query->where('type', 'voucher'); }
    public function scopePromo($query)    { return $query->where('type', 'promo'); }
    public function scopeSystem($query)   { return $query->where('type', 'system'); }

    public function scopeTargetType($query, string $targetType)
    {
        return $query->where('target_type', $targetType);
    }

    // ================= Accessors & Mutators =================

    public function getIsReadAttribute(): bool
    {
        return !is_null($this->read_at);
    }

    public function getTimeAgoAttribute(): string
    {
        return $this->created_at?->diffForHumans() ?? '';
    }

    public function getIconAttribute(): string
    {
        $icons = [
            'voucher'  => '🎫',
            'promo'    => '🏷️',
            'community'=> '👥',
            'system'   => '🔔',
            'merchant' => '🏪',
            'hunter'   => '🎯',
            'grab'     => '🎁',
            'default'  => '📢',
        ];
        return $icons[$this->type] ?? $icons['default'];
    }

    public function getColorAttribute(): string
    {
        $colors = [
            'voucher'  => 'bg-purple-100 text-purple-800',
            'promo'    => 'bg-orange-100 text-orange-800',
            'community'=> 'bg-blue-100 text-blue-800',
            'system'   => 'bg-gray-100 text-gray-800',
            'merchant' => 'bg-green-100 text-green-800',
            'hunter'   => 'bg-red-100 text-red-800',
            'grab'     => 'bg-yellow-100 text-yellow-800',
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
     * Konten siap tampil (opsional)
     */
    public function getDisplayContent(): array
    {
        return [
            'id'         => $this->id,
            'type'       => $this->type,
            'title'      => $this->title,
            'message'    => $this->message,
            'image_url'  => $this->image_url,
            'action_url' => $this->action_url,
            'icon'       => $this->icon,
            'color'      => $this->color,
            'is_read'    => $this->is_read,
            'time_ago'   => $this->time_ago,
            'created_at' => $this->created_at,
            'meta'       => $this->meta,
        ];
    }

    /**
     * Create voucher notification (helper method)
     * NOTE: pakai ?string untuk PHP 8.4 agar tidak warning
     */
    public static function createVoucherNotification(
        int $userId,
        Voucher $voucher,
        ?string $title = null,
        ?string $message = null
    ): self {
        // Bangun URL gambar dari path storage kalau perlu
        $imgUrl = null;
        if (!empty($voucher->image_url)) {
            $imgUrl = $voucher->image_url;
        } elseif (!empty($voucher->image)) {
            // asumsikan disimpan di disk 'public'
            $imgUrl = Storage::disk('public')->exists($voucher->image)
                ? Storage::url($voucher->image)
                : null;
        }

        return self::create([
            'user_id'     => $userId,
            'type'        => 'voucher',
            'title'       => $title ?? 'Voucher Baru Tersedia!',
            'message'     => $message ?? "Voucher '{$voucher->name}' tersedia untuk Anda. Gunakan kode: {$voucher->code}",
            'image_url'   => $imgUrl,
            'target_type' => 'voucher',
            'target_id'   => $voucher->id,
            'action_url'  => "/vouchers/{$voucher->id}",
            'meta'        => [
                'voucher_code'  => $voucher->code,
                'voucher_name'  => $voucher->name,
                'valid_until'   => optional($voucher->valid_until)->format('Y-m-d'),
                'community_id'  => $voucher->community_id,
                'target_type'   => $voucher->target_type,
            ],
        ]);
    }

    /**
     * Create promo notification (helper method)
     */
    public static function createPromoNotification(
        int $userId,
        Promo $promo,
        ?string $title = null,
        ?string $message = null
    ): self {
        // Build image URL aman
        $imgUrl = null;
        if (!empty($promo->image_url)) {
            $imgUrl = $promo->image_url;
        } elseif (!empty($promo->image)) {
            $imgUrl = Storage::disk('public')->exists($promo->image)
                ? Storage::url($promo->image)
                : null;
        }

        return self::create([
            'user_id'     => $userId,
            'type'        => 'promo',
            'title'       => $title ?? 'Promo Baru!',
            'message'     => $message ?? "Promo '{$promo->name}' tersedia untuk Anda",
            'image_url'   => $imgUrl,
            'target_type' => 'promo',
            'target_id'   => $promo->id,
            'action_url'  => "/promos/{$promo->id}",
            'meta'        => [
                'promo_name'   => $promo->name,
                'community_id' => $promo->community_id ?? null,
            ],
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

    protected static function boot()
    {
        parent::boot();

        static::created(function ($notification) {
            Log::info("Notification created", [
                'id'          => $notification->id,
                'user_id'     => $notification->user_id,
                'type'        => $notification->type,
                'target_type' => $notification->target_type,
                'target_id'   => $notification->target_id,
            ]);
        });
    }
}
