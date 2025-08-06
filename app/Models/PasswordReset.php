<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PasswordReset extends Model
{
    use HasFactory;

    protected $table = 'password_reset_tokens';

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'email', 'token', 'used_at'
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'password_resets.email',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'password_resets.id',
        'password_resets.email',
        'password_resets.token',
        'password_resets.used_at',
    ];
}
