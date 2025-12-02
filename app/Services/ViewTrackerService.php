<?php

namespace App\Services;

use App\Models\CubeView;
use App\Models\AdView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ViewTrackerService
{
    /**
     * Track view untuk cube
     * 
     * @param int $cubeId
     * @param int|null $userId
     * @param string|null $sessionId
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    public function trackCubeView($cubeId, $userId = null, $sessionId = null, $request = null)
    {
        // Generate session ID jika tidak ada (untuk guest)
        if (!$sessionId && !$userId) {
            $sessionId = $this->generateSessionId($request);
        }

        // Cek apakah sudah pernah view hari ini
        $today = now()->format('Y-m-d');
        $existingView = CubeView::where('cube_id', $cubeId)
            ->where(function ($query) use ($userId, $sessionId) {
                if ($userId) {
                    $query->where('user_id', $userId);
                } else {
                    $query->where('session_id', $sessionId);
                }
            })
            ->whereDate('created_at', $today)
            ->exists();

        // Jika sudah pernah view hari ini, skip
        if ($existingView) {
            return false;
        }

        // Simpan view baru
        try {
            CubeView::create([
                'cube_id' => $cubeId,
                'user_id' => $userId,
                'session_id' => $sessionId,
                'ip_address' => $request ? $request->ip() : null,
                'user_agent' => $request ? $request->userAgent() : null,
            ]);

            return true;
        } catch (\Exception $e) {
            // Log error tapi jangan throw exception (view tracking tidak boleh mengganggu user experience)
            Log::error('Failed to track cube view', [
                'cube_id' => $cubeId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Track view untuk ad/promo/voucher/iklan
     */
    public function trackAdView($adId, $userId = null, $sessionId = null, $request = null)
    {
        if (!$sessionId && !$userId) {
            $sessionId = $this->generateSessionId($request);
        }

        $today = now()->format('Y-m-d');
        $existingView = AdView::where('ad_id', $adId)
            ->where(function ($query) use ($userId, $sessionId) {
                if ($userId) {
                    $query->where('user_id', $userId);
                } else {
                    $query->where('session_id', $sessionId);
                }
            })
            ->whereDate('created_at', $today)
            ->exists();

        if ($existingView) {
            return false;
        }

        try {
            AdView::create([
                'ad_id' => $adId,
                'user_id' => $userId,
                'session_id' => $sessionId,
                'ip_address' => $request ? $request->ip() : null,
                'user_agent' => $request ? $request->userAgent() : null,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to track ad view', [
                'ad_id' => $adId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Generate unique session ID untuk guest users
     */
    private function generateSessionId($request)
    {
        if (!$request) {
            return Str::random(40);
        }

        // Kombinasi IP + User Agent untuk unique identifier
        $identifier = $request->ip() . '|' . $request->userAgent();
        return hash('sha256', $identifier);
    }

    /**
     * Get view count untuk cube
     */
    public function getCubeViewCount($cubeId)
    {
        return CubeView::uniqueViewersForCube($cubeId);
    }

    /**
     * Get view count untuk ad
     */
    public function getAdViewCount($adId)
    {
        return AdView::uniqueViewersForAd($adId);
    }

    /**
     * Get multiple cube views count (untuk list/table)
     */
    public function getMultipleCubeViewCounts(array $cubeIds)
    {
        $userViews = DB::table('cube_views')
            ->select('cube_id', DB::raw('COUNT(DISTINCT user_id) as count'))
            ->whereIn('cube_id', $cubeIds)
            ->whereNotNull('user_id')
            ->groupBy('cube_id')
            ->get()
            ->pluck('count', 'cube_id')
            ->toArray();

        $guestViews = DB::table('cube_views')
            ->select('cube_id', DB::raw('COUNT(DISTINCT session_id) as count'))
            ->whereIn('cube_id', $cubeIds)
            ->whereNull('user_id')
            ->groupBy('cube_id')
            ->get()
            ->pluck('count', 'cube_id')
            ->toArray();

        $result = [];
        foreach ($cubeIds as $id) {
            $result[$id] = ($userViews[$id] ?? 0) + ($guestViews[$id] ?? 0);
        }

        return $result;
    }

    /**
     * Get multiple ad views count
     */
    public function getMultipleAdViewCounts(array $adIds)
    {
        $userViews = DB::table('ad_views')
            ->select('ad_id', DB::raw('COUNT(DISTINCT user_id) as count'))
            ->whereIn('ad_id', $adIds)
            ->whereNotNull('user_id')
            ->groupBy('ad_id')
            ->get()
            ->pluck('count', 'ad_id')
            ->toArray();

        $guestViews = DB::table('ad_views')
            ->select('ad_id', DB::raw('COUNT(DISTINCT session_id) as count'))
            ->whereIn('ad_id', $adIds)
            ->whereNull('user_id')
            ->groupBy('ad_id')
            ->get()
            ->pluck('count', 'ad_id')
            ->toArray();

        $result = [];
        foreach ($adIds as $id) {
            $result[$id] = ($userViews[$id] ?? 0) + ($guestViews[$id] ?? 0);
        }

        return $result;
    }
}
