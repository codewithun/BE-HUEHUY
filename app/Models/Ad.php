<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Promo;

class Ad extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'cube_id',
        'ad_category_id',
        'title',
        'slug',
        'description',
        'picture_source',
        'image_1',
        'image_2',
        'image_3',
        'image_updated_at',
        'max_grab',
        'unlimited_grab',
        'is_daily_grab',
        'type',
        'status',
        'promo_type',
        'online_store_link',
        'validation_type',
        'code',
        'target_type',
        'target_user_id',
        'community_id',
        'viewer',
        'max_production_per_day',
        'sell_per_day',
        'level_umkm',
        'pre_order',
        'start_validate',
        'finish_validate',
        'validation_time_limit',
        'jam_mulai',
        'jam_berakhir',
        'day_type',
        'custom_days'
    ];

    protected $appends = ['remaining_stock', 'stock_source'];

    // =========================>
    // ## Casts
    // =========================>
    protected $casts = [
        'custom_days' => 'array',
        'start_validate' => 'datetime',
        'finish_validate' => 'datetime',
        'image_updated_at' => 'datetime',
        'is_daily_grab' => 'boolean',
        'unlimited_grab' => 'boolean',
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'ads.title',
        'ads.description',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'ads.id',
        'ads.cube_id',
        'ads.ad_category_id',
        'ads.title',
        'ads.slug',
        'ads.description',
        'ads.picture_source',
        'ads.image_1',
        'ads.image_2',
        'ads.image_3',
        'ads.image_updated_at',
        'ads.max_grab',
        'ads.unlimited_grab',
        'ads.is_daily_grab',
        'ads.type',
        'ads.status',
        'ads.promo_type',
        'ads.online_store_link',
        'ads.validation_type',
        'ads.code',
        'ads.target_type',
        'ads.target_user_id',
        'ads.community_id',
        'ads.viewer',
        'ads.max_production_per_day',
        'ads.sell_per_day',
        'ads.level_umkm',
        'ads.pre_order',
        'ads.start_validate',
        'ads.finish_validate',
        'ads.validation_time_limit',
        'ads.jam_mulai',
        'ads.jam_berakhir',
        'ads.day_type',
        'ads.custom_days',
    ];

    /**
     * * Relation to `AdCategory` model
     */
    public function ad_category(): BelongsTo
    {
        return $this->belongsTo(AdCategory::class, 'ad_category_id', 'id');
    }

    /**
     * * Relation to `Cube` model
     */
    public function cube(): BelongsTo
    {
        return $this->belongsTo(Cube::class, 'cube_id', 'id');
    }

    /**
     * * Relation to `Vocuher` model
     */
    public function voucher(): HasOne
    {
        return $this->hasOne(Voucher::class, 'ad_id', 'id');
    }

    /**
     * * Relation to `Community` model
     */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class, 'community_id', 'id');
    }

    /**
     * * Relation to `User` model (target user)
     */
    public function target_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id', 'id');
    }

    /**
     * * Relation to `User` model (many-to-many for voucher targets)
     */
    public function target_users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ad_target_users', 'ad_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * * Relation to `SummaryGrab` model
     */
    public function summary_grabs(): HasMany
    {
        return $this->hasMany(SummaryGrab::class, 'ad_id', 'id');
    }

    public function toArray()
    {
        $toArray = parent::toArray();

        $toArray['picture_source'] = $this->picture_source ? asset('storage/' . $this->picture_source) : null;
        $toArray['image_1'] = $this->image_1 ? asset('storage/' . $this->image_1) : null;
        $toArray['image_2'] = $this->image_2 ? asset('storage/' . $this->image_2) : null;
        $toArray['image_3'] = $this->image_3 ? asset('storage/' . $this->image_3) : null;

        return $toArray;
    }

    public function getRemainingStockAttribute()
    {
        // Cari promo mirror berdasarkan code iklan
        $promo = $this->code
            ? Promo::where('code', $this->code)->first()
            : null;

        // Jika Promo punya stok (tidak null), pakai itu
        if ($promo !== null && $promo->stock !== null) {
            // pastikan integer non-negatif
            return max(0, (int) $promo->stock);
        }

        // Fallback ke Ad: unlimited = ∞ (kita representasikan null), else max_grab
        if ($this->unlimited_grab) {
            return null; // artinya tak terbatas
        }

        return max(0, (int) ($this->max_grab ?? 0));
    }

    /**
     * Untuk debug/label UI: tunjukkan dari mana stok dihitung
     * - 'promo' jika pakai promos.stock
     * - 'ad' kalau fallback ke Ad
     */
    public function getStockSourceAttribute()
    {
        $promo = $this->code
            ? Promo::where('code', $this->code)->first()
            : null;

        if ($promo !== null && $promo->stock !== null) {
            return 'promo';
        }
        return 'ad';
    }
}
