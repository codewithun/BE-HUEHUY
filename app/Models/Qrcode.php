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
    ];

    // Relasi ke admin/user
    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
