<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    public function events()
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Get all memberships for this community.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CommunityMembership::class);
    }

    /**
     * Get active memberships for this community.
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->active();
    }

    /**
     * Get users who are members of this community.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'community_memberships')
                    ->withPivot(['status', 'joined_at'])
                    ->withTimestamps()
                    ->wherePivot('status', 'active');
    }

    /**
     * Get the count of active members.
     */
    public function getMembersCountAttribute(): int
    {
        return $this->activeMemberships()->count();
    }

    /**
     * Check if a user is a member of this community.
     */
    public function hasMember(User $user): bool
    {
        return $this->activeMemberships()
                    ->where('user_id', $user->id)
                    ->exists();
    }

    /**
     * Add a user as a member of this community.
     */
    public function addMember(User $user): CommunityMembership
    {
        return $this->memberships()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'status' => 'active',
                'joined_at' => now(),
            ]
        );
    }

    /**
     * Remove a user from this community.
     */
    public function removeMember(User $user): bool
    {
        return $this->memberships()
                    ->where('user_id', $user->id)
                    ->delete() > 0;
    }
}
