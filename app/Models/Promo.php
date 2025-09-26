<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
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
        'validation_type', // <-- penting: simpan tipe validasi
        'location',
        'owner_name',
        'owner_contact',
        'image',
        'image_updated_at', // Add this field
        'status', // opsional, kalau kamu pakai status (active/inactive)
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

    // ================= Accessors =================
    
    public function getImageUrlAttribute(): ?string
    {
        if (!$this->image) return null;
        
        // Handle case where image is already a full URL
        if (filter_var($this->image, FILTER_VALIDATE_URL)) {
            return $this->image;
        }
        
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        $url = $disk->url(ltrim($this->image, '/'));
        
        // Ensure the URL uses the backend host for API responses
        // Frontend should access images through backend proxy
        if (request()->is('api/*')) {
            $parsedUrl = parse_url($url);
            if ($parsedUrl && isset($parsedUrl['path'])) {
                // Return relative URL that will be served by Laravel
                return url($parsedUrl['path']);
            }
        }
        
        return $url;
    }
    
    public function getImageUrlVersionedAttribute(): ?string
    {
        $url = $this->image_url;
        if (!$url) return null;
        
        // PERBAIKAN: Prioritas versioning yang lebih robust
        $ver = null;
        
        // 1) Prioritas tertinggi: image_updated_at (ketika file diganti)
        if ($this->image_updated_at instanceof Carbon) {
            $ver = $this->image_updated_at->getTimestamp();
        } 
        // 2) Fallback: updated_at (ketika record berubah)
        else if ($this->updated_at instanceof Carbon) {
            $ver = $this->updated_at->getTimestamp();
        }
        // 3) Fallback terakhir: ID + current time
        else {
            $ver = $this->id ? ($this->id * 1000 + (time() % 1000)) : time();
        }
        
        return str_contains($url, '?') ? "{$url}&v={$ver}" : "{$url}?v={$ver}";
    }
}
