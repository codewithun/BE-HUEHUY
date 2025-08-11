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
        'delivery', // tambahkan ini
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
     * * Generate Voucher Code
     */
    public function generateVoucherCode()
    {
        $zeroPadding = "0000000";
        $prefixCode = "VC-";
        $code = "$prefixCode";

        $increment = 0;
        $similiarCode = DB::table('vouchers')->select('code')
            ->orderBy('code', 'desc')
            ->first();

        if (!$similiarCode) {
            $increment = 1;
        } else {
            $increment = (int) substr($similiarCode->code, strlen($code));
            $increment = $increment + 1;
        }

        $code = $code . substr($zeroPadding, strlen("$increment")) . $increment;

        return $code;
    }
}
