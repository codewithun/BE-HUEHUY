<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Test Routes untuk Validation Type & Target Users
|--------------------------------------------------------------------------
| 
| Routes ini hanya untuk testing fitur baru validation_type dan 
| target users pada voucher. Hapus setelah testing selesai.
|
*/

Route::prefix('test')->group(function () {
    
    // Test create cube dengan validation manual
    Route::post('/cube-manual-validation', function () {
        $data = [
            'cube_type_id' => 1,
            'owner_user_id' => 1,
            'address' => 'Jl. Test Manual',
            'map_lat' => -6.2,
            'map_lng' => 106.8,
            'content_type' => 'promo',
            'ads' => [
                'title' => 'Test Promo Manual',
                'description' => 'Testing validation type manual',
                'validation_type' => 'manual',
                'code' => 'TEST' . time(),
                'max_grab' => 100,
                'start_validate' => '01-01-2025',
                'finish_validate' => '31-12-2025',
                'validation_time_limit' => '23:59',
                'promo_type' => 'offline'
            ],
            'cube_tags' => [[
                'address' => 'Jl. Test Validasi',
                'map_lat' => -6.2,
                'map_lng' => 106.8
            ]]
        ];

        $controller = new \App\Http\Controllers\Admin\CubeController();
        $request = new \Illuminate\Http\Request();
        $request->merge($data);
        
        return $controller->store($request);
    });

    // Test create voucher dengan target user tertentu
    Route::post('/voucher-target-users', function () {
        $data = [
            'cube_type_id' => 1,
            'owner_user_id' => 1,
            'address' => 'Jl. Test Voucher',
            'map_lat' => -6.2,
            'map_lng' => 106.8,
            'content_type' => 'voucher',
            'ads' => [
                'title' => 'Test Voucher Target Users',
                'description' => 'Testing voucher dengan target user',
                'validation_type' => 'auto',
                'target_type' => 'user',
                'target_user_ids' => [1, 2], // Adjust sesuai user yang ada
                'max_grab' => 50,
                'start_validate' => '01-01-2025',
                'finish_validate' => '31-12-2025',
                'validation_time_limit' => '23:59',
                'promo_type' => 'offline'
            ],
            'cube_tags' => [[
                'address' => 'Jl. Test Voucher Validasi',
                'map_lat' => -6.2,
                'map_lng' => 106.8
            ]]
        ];

        $controller = new \App\Http\Controllers\Admin\CubeController();
        $request = new \Illuminate\Http\Request();
        $request->merge($data);
        
        return $controller->store($request);
    });

    // Test validasi field baru di database
    Route::get('/check-database', function () {
        try {
            // Test individual fields exist
            $hasValidationType = \Illuminate\Support\Facades\Schema::hasColumn('ads', 'validation_type');
            $hasCode = \Illuminate\Support\Facades\Schema::hasColumn('ads', 'code');
            $hasTargetType = \Illuminate\Support\Facades\Schema::hasColumn('ads', 'target_type');
            $hasTargetUserId = \Illuminate\Support\Facades\Schema::hasColumn('ads', 'target_user_id');
            $hasCommunityId = \Illuminate\Support\Facades\Schema::hasColumn('ads', 'community_id');

            // Test pivot table exists
            $pivotTableExists = \Illuminate\Support\Facades\Schema::hasTable('ad_target_users');

            // Test raw query to get some data
            $adsCount = \Illuminate\Support\Facades\DB::table('ads')->count();
            $targetUsersCount = \Illuminate\Support\Facades\DB::table('ad_target_users')->count();

            return response()->json([
                'success' => true,
                'message' => 'Database fields validation passed',
                'schema_check' => [
                    'validation_type' => $hasValidationType,
                    'code' => $hasCode,
                    'target_type' => $hasTargetType,
                    'target_user_id' => $hasTargetUserId,
                    'community_id' => $hasCommunityId,
                    'pivot_table_exists' => $pivotTableExists
                ],
                'data_counts' => [
                    'total_ads' => $adsCount,
                    'total_target_users' => $targetUsersCount
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database check failed',
                'error' => $e->getMessage()
            ]);
        }
    });

    // Test validasi kode
    Route::post('/validate-code/{adId}', function ($adId) {
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'code' => request('code'),
            'validator_role' => 'admin'
        ]);

        // Mock authenticated user
        $user = \App\Models\User::first();
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new \App\Http\Controllers\Admin\CubeController();
        return $controller->validateCode($request, $adId);
    });

    // Test notifikasi yang terkirim
    Route::get('/check-notifications', function () {
        $notifications = \App\Models\Notification::where('type', 'voucher')
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $notifications,
            'total_voucher_notifications' => \App\Models\Notification::where('type', 'voucher')->count()
        ]);
    });

    // Helper: buat test data users jika belum ada
    Route::post('/create-test-users', function () {
        $users = [];
        for ($i = 1; $i <= 5; $i++) {
            $user = \App\Models\User::firstOrCreate(
                ['email' => "testuser{$i}@example.com"],
                [
                    'name' => "Test User {$i}",
                    'password' => bcrypt('password'),
                    'email_verified_at' => now()
                ]
            );
            $users[] = $user;
        }

        return response()->json([
            'success' => true,
            'message' => 'Test users created',
            'data' => $users
        ]);
    });

    // Test check recent data
    Route::get('/check-recent-data', function () {
        try {
            // Get recent ads
            $recent = \App\Models\Ad::latest()->take(2)->get(['id', 'title', 'validation_type', 'code', 'target_type', 'cube_id']);
            
            $results = [];
            foreach ($recent as $ad) {
                $cube = \App\Models\Cube::find($ad->cube_id);
                $targetUsers = \Illuminate\Support\Facades\DB::table('ad_target_users')->where('ad_id', $ad->id)->get();
                
                $results[] = [
                    'ad_id' => $ad->id,
                    'title' => $ad->title,
                    'validation_type' => $ad->validation_type,
                    'code' => $ad->code,
                    'target_type' => $ad->target_type,
                    'cube_code' => $cube ? $cube->code : null,
                    'target_users_count' => $targetUsers->count(),
                    'target_user_ids' => $targetUsers->pluck('user_id')->toArray()
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Recent data retrieved successfully',
                'data' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get recent data',
                'error' => $e->getMessage()
            ]);
        }
    });

    // Helper: buat test community
    Route::post('/create-test-community', function () {
        $community = \App\Models\Community::firstOrCreate(
            ['name' => 'Test Community'],
            [
                'description' => 'Community for testing voucher targeting',
                'admin_user_id' => 1
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Test community created',
            'data' => $community
        ]);
    });
});