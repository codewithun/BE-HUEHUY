<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommunityCategory extends Model
{
    protected $fillable = ['community_id', 'title', 'description'];

    public function community()
    {
        return $this->belongsTo(Community::class);
    }

    // relasi ke promos
    public function promos()
    {
        return $this->hasMany(Promo::class, 'category_id');
    }
}
