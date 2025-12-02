<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ViewTrackerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class ViewTrackerController extends Controller
{
    protected $viewTracker;

    public function __construct(ViewTrackerService $viewTracker)
    {
        $this->viewTracker = $viewTracker;
    }

    /**
     * Try to authenticate user from Bearer token (optional)
     */
    private function authenticateFromToken(Request $request)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return null;
        }

        try {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken && !$accessToken->tokenable->trashed()) {
                return $accessToken->tokenable->id;
            }
        } catch (\Exception $e) {
            // Token invalid, continue as guest
        }

        return null;
    }

    /**
     * Track view untuk cube
     * POST /api/track/cube/{cubeId}
     */
    public function trackCube(Request $request, $cubeId)
    {
        // Try to get authenticated user from token
        $userId = $this->authenticateFromToken($request);

        $sessionId = $request->header('X-Session-ID') ?? $request->input('session_id');

        $tracked = $this->viewTracker->trackCubeView($cubeId, $userId, $sessionId, $request);

        return response()->json([
            'success' => true,
            'tracked' => $tracked,
            'message' => $tracked ? 'View tracked successfully' : 'Already viewed today',
            'user_id' => $userId, // Debug: show if user detected
            'session_id' => $sessionId // Debug: show session
        ]);
    }

    /**
     * Track view untuk ad/promo/voucher
     * POST /api/track/ad/{adId}
     */
    public function trackAd(Request $request, $adId)
    {
        // Try to get authenticated user from token
        $userId = $this->authenticateFromToken($request);

        $sessionId = $request->header('X-Session-ID') ?? $request->input('session_id');

        $tracked = $this->viewTracker->trackAdView($adId, $userId, $sessionId, $request);

        return response()->json([
            'success' => true,
            'tracked' => $tracked,
            'message' => $tracked ? 'View tracked successfully' : 'Already viewed today',
            'user_id' => $userId, // Debug: show if user detected
            'session_id' => $sessionId // Debug: show session
        ]);
    }

    /**
     * Get view count untuk cube
     * GET /api/views/cube/{cubeId}
     */
    public function getCubeViews($cubeId)
    {
        $count = $this->viewTracker->getCubeViewCount($cubeId);

        return response()->json([
            'success' => true,
            'cube_id' => $cubeId,
            'view_count' => $count
        ]);
    }

    /**
     * Get view count untuk ad
     * GET /api/views/ad/{adId}
     */
    public function getAdViews($adId)
    {
        $count = $this->viewTracker->getAdViewCount($adId);

        return response()->json([
            'success' => true,
            'ad_id' => $adId,
            'view_count' => $count
        ]);
    }

    /**
     * Get multiple cube views (untuk list)
     * POST /api/views/cubes/batch
     * Body: { "cube_ids": [1, 2, 3, 4, 5] }
     */
    public function getBatchCubeViews(Request $request)
    {
        $cubeIds = $request->input('cube_ids', []);

        if (empty($cubeIds)) {
            return response()->json([
                'success' => false,
                'message' => 'No cube IDs provided'
            ], 400);
        }

        $counts = $this->viewTracker->getMultipleCubeViewCounts($cubeIds);

        return response()->json([
            'success' => true,
            'views' => $counts
        ]);
    }

    /**
     * Get multiple ad views
     * POST /api/views/ads/batch
     */
    public function getBatchAdViews(Request $request)
    {
        $adIds = $request->input('ad_ids', []);

        if (empty($adIds)) {
            return response()->json([
                'success' => false,
                'message' => 'No ad IDs provided'
            ], 400);
        }

        $counts = $this->viewTracker->getMultipleAdViewCounts($adIds);

        return response()->json([
            'success' => true,
            'views' => $counts
        ]);
    }
}
