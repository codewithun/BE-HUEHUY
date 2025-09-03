<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    protected $fillable = [
        'title',
        'subtitle',
        'image',
        'organizer_name',
        'organizer_logo',
        'organizer_type',
        'date',
        'time',
        'location',
        'address',
        'category',
        'participants',
        'max_participants',
        'price',
        'description',
        'requirements',
        'schedule',
        'prizes',
        'contact_phone',
        'contact_email',
        'tags',
        'community_id',
    ];

    protected $casts = [
        'date' => 'date',
        'participants' => 'integer',
        'max_participants' => 'integer',
    ];

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function isUserRegistered($userId): bool
    {
        return $this->registrations()->where('user_id', $userId)->exists();
    }

    public function canRegister(): bool
    {
        return $this->participants < $this->max_participants;
    }

    public function incrementParticipants(): void
    {
        $this->increment('participants');
    }

    public function decrementParticipants(): void
    {
        $this->decrement('participants');
    }
}
