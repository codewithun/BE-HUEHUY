<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class VoucherItem extends Model
{
    use HasFactory;
    
    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'user_id',
        'voucher_id',
        'code',
        'used_at'
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'voucher_items.code',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'voucher_items.id',
        'voucher_items.user_id',
        'voucher_items.voucher_id',
        'voucher_items.code',
        'voucher_items.used_at',
    ];

    /**
     * * Generate Voucher Item Code
     */
    public function generateCode() {

        $zeroPadding = "000000";
        $prefixCode = date('md');
        $code = "$prefixCode";

        $increment = 0;
        $similiarCode = DB::table('voucher_items')->select('code')
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
     * * Relation to `Voucher` model
     */
    public function voucher() : BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id', 'id');
    }

    /**
     * * Relation to `User` model
     */
    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
