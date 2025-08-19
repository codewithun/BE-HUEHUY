<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Community extends Model
{
    protected $fillable = [
        'name',
        'description',
        'logo', // opsional
    ];

    public function categories()
    {
        return $this->hasMany(CommunityCategory::class);
    }
}
