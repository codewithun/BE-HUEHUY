<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'role_id',
        'name',
        'email',
        'password',
        'picture_source',
        'phone',
        'verified_at',
    ];

    // =========================>
    // ## Hidden
    // =========================>
    protected $hidden = [
        'password',
        'remember_token',
        'last_active_at',
    ];

    // =========================>
    // ## Casts
    // =========================>
    protected $casts = [
        'verified_at' => 'datetime',
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'users.name',
        'users.email',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'users.id',
        'users.role_id',
        'users.name',
        'users.email',
        'users.phone',
        'users.verified_at',
        'users.last_active_at',
        'users.point',
        'users.picture_source',
    ];

    /**
     * * Relation to `Role` model
     */
    public function role() : BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * * Relation to `CorporateUser` model
     */
    public function corporate_user() : HasOne
    {
        return $this->hasOne(CorporateUser::class, 'user_id', 'id');
    }

    /**
     * * Relation to `Cube` model
     */
    public function cubes() : HasMany
    {
        return $this->hasMany(Cube::class, 'user_id', 'id');
    }

    /**
     * * Relation to `Cube` model
     */
    public function worlds() : HasMany
    {
        return $this->hasMany(UserWorld::class, 'user_id', 'id');
    }

    /**
     * Relationship dengan community memberships
     */
    public function communityMemberships()
    {
        return $this->hasMany(CommunityMembership::class);
    }

    /**
     * Relationship dengan communities yang diikuti
     */
    public function communities()
    {
        return $this->belongsToMany(Community::class, 'community_memberships')
                    ->wherePivot('status', 'active')
                    ->withPivot('status', 'joined_at')
                    ->withTimestamps();
    }

    public function toArray()
    {
        $toArray = parent::toArray();

        $toArray['picture_source'] = $this->picture_source ? asset('storage/' . $this->picture_source) : null;

        return $toArray;
    }

    /**
     * Check if user's email is verified
     */
    public function hasVerifiedEmail(): bool
    {
        return !is_null($this->verified_at);
    }

    /**
     * Mark the user's email as verified
     */
    public function markEmailAsVerified(): bool
    {
        return $this->update(['verified_at' => now()]);
    }

    /**
     * Get verification codes for this user's email
     */
    public function verificationCodes()
    {
        return EmailVerificationCode::where('email', $this->email);
    }

    /**
     * * Hook Event Model
     */
    protected static function booted()
    {
        static::updated(function (User $user) {

            $originalData = $user->getOriginal();

            $requestData = request()->all();

            if ($user->wasChanged('point')) {

                // ? Create Point Log
                $pointLog = new PointLog();
                $pointLog->user_id = $user->id;
                $pointLog->initial_point = $originalData['point'];
                $pointLog->final_point = $user->point;
                $pointLog->description = isset($requestData['log_description'])
                    ? $requestData['log_description']
                    : "Diubah oleh " . Auth::user()->email . " dari " . $originalData['point'] . " ke " . $user->point;
                $pointLog->save();
            }
        });
    }
}
