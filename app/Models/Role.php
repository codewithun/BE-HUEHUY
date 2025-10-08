<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'name',
        'slug',
        'is_corporate'
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'roles.name',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'roles.id',
        'roles.name',
        'roles.slug',
        'roles.is_corporate',
    ];
}
