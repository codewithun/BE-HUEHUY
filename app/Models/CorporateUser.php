<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporateUser extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'user_id',
        'corporate_id',
        'role_id',
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'corporate_users.id',
        'corporate_users.user_id',
        'corporate_users.corporate_id',
        'corporate_users.role_id',
    ];

    /**
     * * Relation to `User` model
     */
    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * * Relation to `Corporate` model
     */
    public function corporate() : BelongsTo
    {
        return $this->belongsTo(Corporate::class, 'corporate_id', 'id');
    }

    /**
     * * Relation to `Role` model
     */
    public function role() : BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }
}
