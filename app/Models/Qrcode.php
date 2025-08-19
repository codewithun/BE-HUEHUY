<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Qrcode extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_id',
        'qr_code',
        'voucher_id',
        'promo_id',
        'tenant_name',
    ];

    // Relasi ke admin/user
    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    // Relasi ke voucher
    public function voucher()
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }

    // Relasi ke promo
    public function promo()
    {
        return $this->belongsTo(Promo::class, 'promo_id');
    }
}
