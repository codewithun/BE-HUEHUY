<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OpeningHour extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'cube_id',
        'day',
        'open',
        'close',
        'is_24hour',
        'is_closed',
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'opening_hours.day',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'opening_hours.id',
        'opening_hours.cube_id',
        'opening_hours.day',
        'opening_hours.open',
        'opening_hours.close',
        'opening_hours.is_24hour',
        'opening_hours.is_closed',
    ];
}
