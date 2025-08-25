<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promo extends Model
{
    use HasFactory;

    protected $fillable = [
        'community_id',
        'category_id',
        'code', // tambahkan code agar bisa diisi saat create/update
        'title',
        'description',
        'detail',
        'promo_distance',
        'start_date',
        'end_date',
        'always_available',
        'stock',
        'promo_type',
        'location',
        'owner_name',
        'owner_contact',
        'image',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'always_available' => 'boolean',
        'stock' => 'integer',
        'promo_distance' => 'float',
    ];

    // relasi ke Community
    public function community()
    {
        return $this->belongsTo(Community::class);
    }

    // relasi ke CommunityCategory (kategori)
    public function category()
    {
        return $this->belongsTo(CommunityCategory::class, 'category_id');
    }

      public function validations()
    {
        return $this->hasMany(PromoValidation::class);
    }
}
