<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Grab extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'user_id',
        'validation_by',
        'ad_id',
        'voucher_item_id',
        'code',
        'validation_at',
        'expired_at',
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'grabs.user_id',
        'grabs.validation_by',
        'grabs.ad_id',
        'grabs.voucher_item_id',
        'grabs.code',
        'grabs.validation_at',
        'grabs.expired_at',
    ];

    /**
     * * Generate Grab Code
     */
    public function generateCode() {

        $zeroPadding = "000000";
        $prefixCode = date('md');
        $code = "$prefixCode";

        $increment = 0;
        $similiarCode = DB::table('grabs')->select('code')
            ->where('code', 'LIKE', "$prefixCode%")
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$similiarCode) {
            $increment = 1;
        } else {
            $increment = (int) substr($similiarCode->code, strlen(($code)));
            $increment = $increment + 1;
        }

        $code = $code . substr($zeroPadding, strlen("$increment")) . $increment;

        return $code;
    }

    /**
     * * Relation to `Ad` model
     */
    public function ad() : BelongsTo
    {
        return $this->belongsTo(Ad::class, 'ad_id', 'id');
    }

    /**
     * * Relation to `User` model
     */
    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * * Relation to `VoucherItem` model
     */
    public function voucher_item() : BelongsTo
    {
        return $this->belongsTo(VoucherItem::class, 'voucher_item_id', 'id');
    }
}
