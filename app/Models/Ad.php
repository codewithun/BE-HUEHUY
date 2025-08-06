<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'max_grab',
        'is_daily_grab',
        'type',
        'status',
        'promo_type',
        'viewer',
        'max_production_per_day',
        'sell_per_day',
        'level_umkm',
        'pre_order',
        'start_validate',
        'finish_validate',
        'validation_time_limit'
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
        'ads.max_grab',
        'ads.is_daily_grab',
        'ads.type',
        'ads.status',
        'ads.promo_type',
        'ads.viewer',
        'ads.max_production_per_day',
        'ads.sell_per_day',
        'ads.level_umkm',
        'ads.pre_order',
        'ads.start_validate',
        'ads.finish_validate',
        'ads.validation_time_limit',
    ];

    /**
     * * Relation to `AdCategory` model
     */
    public function ad_category() : BelongsTo
    {
        return $this->belongsTo(AdCategory::class, 'ad_category_id', 'id');
    }

    /**
     * * Relation to `Cube` model
     */
    public function cube() : BelongsTo
    {
        return $this->belongsTo(Cube::class, 'cube_id', 'id');
    }

    /**
     * * Relation to `Vocuher` model
     */
    public function voucher() : HasOne
    {
        return $this->hasOne(Voucher::class, 'ad_id', 'id');
    }

    /**
     * * Relation to `SummaryGrab` model
     */
    public function summary_grabs() : HasMany
    {
        return $this->hasMany(SummaryGrab::class, 'ad_id', 'id');
    }

    public function toArray()
    {
        $toArray = parent::toArray();

        $toArray['picture_source'] = $this->picture_source ? asset('storage/' . $this->picture_source) : null;

        return $toArray;
    }
}
