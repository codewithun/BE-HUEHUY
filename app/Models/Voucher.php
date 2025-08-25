<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Voucher extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'name',
        'description',
        'image',
        'type',
        'valid_until',
        'tenant_location',
        'stock',
        'code',
        'delivery',
        'community_id', // tambahkan ini
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'vouchers.name',
        'vouchers.code'
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'vouchers.id',
        'vouchers.ad_id',
        'vouchers.name',
        'vouchers.code',
    ];

    /**
     * * Relation to `Ad` model
     */
    public function ad() : BelongsTo
    {
        return $this->belongsTo(Ad::class, 'ad_id', 'id');
    }

    /**
     * * Relation to `VouhcerItem` model
     */
    public function voucher_items() : HasMany
    {
        return $this->hasMany(VoucherItem::class, 'voucher_id', 'id');
    }

    /**
     * * Relation to `Community` model
     */
    public function community() : BelongsTo
    {
        return $this->belongsTo(Community::class, 'community_id', 'id');
    }

}