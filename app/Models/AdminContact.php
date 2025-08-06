<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminContact extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'name',
        'phone'
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'admin_contacts.name',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'admin_contacts.id',
        'admin_contacts.name',
        'admin_contacts.phone',
    ];
}
