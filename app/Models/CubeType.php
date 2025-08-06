<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CubeType extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'name', 'code', 'color', 'description'
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'cube_types.name',
        'cube_types.code',
        'cube_types.description',
        'cube_types.color',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'cube_types.id',
        'cube_types.name',
        'cube_types.code',
        'cube_types.description',
        'cube_types.color',
    ];
}
