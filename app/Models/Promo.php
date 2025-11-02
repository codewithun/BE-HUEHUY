<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Carbon;

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
        'online_store_link',  // link untuk promo online
        'validation_type',   // simpan tipe validasi: auto|manual
        'location',
        'owner_name',
        'owner_contact',
        'image',
        'image_updated_at',  // buat cache-busting image
        'status',            // optional: active/inactive
    ];

    protected $casts = [
        'start_date'       => 'datetime',
        'end_date'         => 'datetime',
        'always_available' => 'boolean',
        'stock'            => 'integer',
        'promo_distance'   => 'float',
        'image_updated_at' => 'datetime',
    ];

    protected $attributes = [
        'validation_type' => 'auto',
    ];

    protected $appends = ['image_url', 'image_url_versioned'];

    // ================= Normalisasi getter/setter =================

    public function getValidationTypeAttribute($value)
    {
        $val = strtolower((string) $value);
        return in_array($val, ['auto', 'manual'], true) ? $val : 'auto';
    }

    public function setValidationTypeAttribute($value): void
    {
        $val = strtolower((string) $value);
        $this->attributes['validation_type'] = in_array($val, ['auto', 'manual'], true) ? $val : 'auto';
    }

    // ================= Relasi =================

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

    // (opsional) scope aktif
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'active')->orWhereNull('status');
        });
    }

    // ================= Accessors =================

    public function getImageUrlAttribute(): ?string
    {
        // pakai raw original/attributes supaya nggak melempar MissingAttributeException
        $image = $this->getRawOriginal('image') ?? ($this->getAttributes()['image'] ?? null);
        if (!$image) return null;

        // kalau sudah full URL, balikin langsung
        if (filter_var($image, FILTER_VALIDATE_URL)) {
            return $image;
        }

        // generate URL dari disk publik
        $url = Storage::url(ltrim($image, '/'));

        // untuk response API, pakai host backend (hindari direct ke storage host)
        if (request()->is('api/*')) {
            $path = parse_url($url, PHP_URL_PATH);
            if ($path) {
                // pakai Facade biar Intelephense tidak protes
                return URL::to($path);
            }
        }

        return $url;
    }

    public function getImageUrlVersionedAttribute(): ?string
    {
        $url = $this->image_url;
        if (!$url) return null;

        // prioritas versi: image_updated_at -> updated_at -> now
        $verSource = $this->image_updated_at instanceof Carbon
            ? $this->image_updated_at
            : ($this->updated_at instanceof Carbon ? $this->updated_at : Carbon::now());

        $ver = $verSource->getTimestamp();

        return str_contains($url, '?') ? "{$url}&v={$ver}" : "{$url}?v={$ver}";
    }
}
