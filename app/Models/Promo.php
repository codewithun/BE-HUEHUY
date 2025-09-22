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
        'code',
        'title',
        'description',
        'detail',
        'promo_distance',
        'start_date',
        'end_date',
        'always_available',
        'stock',
        'promo_type',
        'validation_type', // <-- penting: simpan tipe validasi
        'location',
        'owner_name',
        'owner_contact',
        'image',
        'status', // opsional, kalau kamu pakai status (active/inactive)
    ];

    protected $casts = [
        'start_date'       => 'datetime',
        'end_date'         => 'datetime',
        'always_available' => 'boolean',
        'stock'            => 'integer',
        'promo_distance'   => 'float',
    ];

    // default value saat new Model() / mass-assignment tanpa kolom ini
    protected $attributes = [
        'validation_type' => 'auto',
    ];

    // ====== Normalisasi getter/setter ======

    /**
     * Getter: selalu kembalikan 'auto' kalau null/kosong.
     */
    public function getValidationTypeAttribute($value)
    {
        $val = strtolower((string) $value);
        return in_array($val, ['auto', 'manual'], true) ? $val : 'auto';
    }

    /**
     * Setter: pastikan hanya 'auto' | 'manual' yang tersimpan.
     */
    public function setValidationTypeAttribute($value): void
    {
        $val = strtolower((string) $value);
        $this->attributes['validation_type'] = in_array($val, ['auto', 'manual'], true) ? $val : 'auto';
    }

    // ====== Relasi ======

    public function community()
    {
        return $this->belongsTo(Community::class);
    }

    public function category()
    {
        return $this->belongsTo(CommunityCategory::class, 'category_id');
    }

    public function validations()
    {
        return $this->hasMany(PromoValidation::class);
    }

    // (opsional) scope kalau butuh filter aktif
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'active')
              ->orWhereNull('status');
        });
    }
}
