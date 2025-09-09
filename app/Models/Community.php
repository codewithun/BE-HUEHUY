<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Community extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'logo',
        'category',
        'privacy',
        'is_verified'
    ];

    protected $casts = [
        'is_verified' => 'boolean',
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
     * Relationship dengan community memberships
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CommunityMembership::class);
    }

    /**
     * Relationship dengan users (members)
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'community_memberships')
                    ->wherePivot('status', 'active')
                    ->withPivot('status', 'joined_at')
                    ->withTimestamps();
    }

    /**
     * Get active memberships for this community.
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->active();
    }

    /**
     * Check if user is already a member
     */
    public function hasMember(User $user): bool
    {
        return $this->memberships()
                    ->where('user_id', $user->id)
                    ->where('status', 'active')
                    ->exists();
    }

    /**
     * Add user as member
     */
    public function addMember(User $user): CommunityMembership
    {
        // Check if membership already exists
        $existingMembership = $this->memberships()
                                   ->where('user_id', $user->id)
                                   ->first();

        if ($existingMembership) {
            // If exists but inactive, reactivate
            if ($existingMembership->status !== 'active') {
                $existingMembership->update([
                    'status' => 'active',
                    'joined_at' => now()
                ]);
                return $existingMembership;
            }
            return $existingMembership;
        }

        // Create new membership
        return $this->memberships()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now()
        ]);
    }

    /**
     * Remove user from community
     */
    public function removeMember(User $user): bool
    {
        return $this->memberships()
                    ->where('user_id', $user->id)
                    ->where('status', 'active')
                    ->update(['status' => 'inactive']);
    }

    /**
     * Get the count of active members.
     */
    public function getMembersCountAttribute(): int
    {
        return $this->memberships()->where('status', 'active')->count();
    }
}
