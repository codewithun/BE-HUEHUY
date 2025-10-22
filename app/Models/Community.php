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
        // Tambahan agar sesuai FE
        'corporate_id',
        'bg_color_1',
        'bg_color_2',
        'world_type',
        'is_active',

        // opsional lama
        'category',
        'privacy',
        'is_verified',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'is_active'   => 'boolean',
    ];

    /**
     * ===== Relasi dasar =====
     */
    public function categories(): HasMany
    {
        return $this->hasMany(CommunityCategory::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Relasi ke tabel memberships (1..n)
     * Tabel: community_memberships (kolom minimal: id, community_id, user_id, status, joined_at, timestamps)
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CommunityMembership::class, 'community_id', 'id');
    }

    /**
     * Relasi many-to-many ke users via pivot community_memberships
     * Hanya mengambil member dengan status 'active'
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'community_memberships', 'community_id', 'user_id')
            ->wherePivot('status', 'active')
            ->withPivot(['status', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Active memberships (tanpa pakai scope yang belum pasti ada)
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()
            ->where('status', 'active');
    }

    /**
     * Cek apakah user sudah menjadi member aktif
     */
    public function hasMember(User $user): bool
    {
        return $this->memberships()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Tambahkan user sebagai member (aktif).
     * - Jika membership sudah ada tapi non-aktif → re-activate
     * - Jika belum ada → create baru
     */
    public function addMember(User $user): CommunityMembership
    {
        $existing = $this->memberships()
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            if ($existing->status !== 'active') {
                $existing->update([
                    'status'    => 'active',
                    'joined_at' => now(),
                ]);
            }
            return $existing->refresh();
        }

        return $this->memberships()->create([
            'user_id'   => $user->id,
            'status'    => 'active',
            'joined_at' => now(),
        ]);
    }

    /**
     * Keluarkan user dari komunitas (set non-aktif).
     * Return: bool
     */
    public function removeMember(User $user): bool
    {
        $membership = $this->memberships()
            ->where('user_id', $user->id)
            ->first();

        if ($membership) {
            // ubah jadi left agar ter-track di history
            $membership->update([
                'status'     => 'left',
                'updated_at' => now(),
            ]);
            return true;
        }

        return false;
    }

    /**
     * Accessor: members_count (jumlah active members)
     * Bisa diakses sebagai $community->members_count
     */
    public function getMembersCountAttribute(): int
    {
        return $this->memberships()
            ->where('status', 'active')
            ->count();
    }

    public function adminContacts()
    {
        // relasi ke user yang jadi kontak admin/manager tenant
        return $this->belongsToMany(User::class, 'community_admin_contacts')
            ->withTimestamps();
    }

    // Relasi ke mitra
    public function corporate()
    {
        return $this->belongsTo(Corporate::class);
    }
}
