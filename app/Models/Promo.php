<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promo extends Model
{
    use HasFactory;

    protected $fillable = [
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
    ];
}
