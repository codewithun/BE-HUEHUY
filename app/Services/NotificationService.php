<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class NotificationService {

    /**
     * Handle Create Notification Service
     * 
     * @param $assigneeType
     * @param $message
     * @param ?$additionalData
     * @param ?$notificationType
     * $additionalData = [ 'cube_id', 'ad_id', 'grab_id', 'corporate', 'world', 'user'[] ]
     */
    public static function create($assigneeType, $message, $additionalData, $notificationType = 'hunter') {

        $assigneeType = strtoupper($assigneeType);

        switch ($assigneeType) {
            case 'ADMIN': {
                
                $adminAccounts = DB::table('users')
                    ->select([
                        'roles.id AS role_id', 'roles.name AS role_name',
                        'users.id AS user_id', 'users.name AS user_name', 'users.email AS user_email',
                    ])
                    ->join('roles', 'roles.id', 'users.role_id')
                    ->where('roles.id', 1)
                    ->whereNotNull('users.verified_at')
                    ->get();

                $notificationsData = [];
                if (count($adminAccounts) > 0) {

                    foreach ($adminAccounts as $admin) {

                        array_push($notificationsData, [
                            'user_id' => $admin->user_id,
                            'cube_id' => $additionalData['cube_id'] ?? null,
                            'ad_id' => $additionalData['ad_id'] ?? null,
                            'grab_id' => $additionalData['grab_id'] ?? null,
                            'type' => $notificationType,
                            'message' => $message,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                    }

                    try {
                        Notification::insert($notificationsData);
                    } catch (\Throwable $th) {
                        return [
                            'error' => true,
                            'message' => "Error: failed to create new notification",
                            'errorMessage' => $th,
                            'errorCode' => 500
                        ];
                    }
                }

                return [
                    'error' => false,
                    'message' => 'Success',
                    'data' => $notificationsData
                ];
            } break;
            case 'CORPORATE': {

                // * Validate parameter passed
                if (!isset($additionalData['corporate']) || $additionalData['corporate'] == '') {
                    throw new \BadMethodCallException('Failed because parameter $additionalData["corporate"] not passed');
                }

                $corporateMembers = DB::table('corporates')
                    ->select([
                        'corporates.id AS corporate_id', 'corporates.name AS corporate_name',
                        'users.id AS user_id', 'users.name AS user_name', 'users.email AS user_email',
                    ])
                    ->join('corporate_users', function ($q) use ($additionalData) {
                        return $q->on('corporate_users.corporate_id', '=', 'corporates.id')
                            ->where('corporates.id', $additionalData['corporate']);
                    })
                    ->join('users', 'users.id', 'corporate_users.user_id')
                    ->whereNotNull('users.verified_at')
                    ->get();

                $notificationsData = [];
                if (count($corporateMembers) > 0) {

                    foreach ($corporateMembers as $member) {

                        array_push($notificationsData, [
                            'user_id' => $member->user_id,
                            'cube_id' => $additionalData['cube_id'] ?? null,
                            'ad_id' => $additionalData['ad_id'] ?? null,
                            'grab_id' => $additionalData['grab_id'] ?? null,
                            'type' => $notificationType,
                            'message' => $message,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                    }

                    try {
                        Notification::insert($notificationsData);
                    } catch (\Throwable $th) {
                        return [
                            'error' => true,
                            'message' => "Error: failed to create new notification",
                            'errorMessage' => $th,
                            'errorCode' => 500
                        ];
                    }
                }

                return [
                    'error' => false,
                    'message' => 'Success',
                    'data' => $notificationsData
                ];
            } break;
            case 'USER': {

                // * Validate parameter passed
                if (!isset($additionalData['user']) || $additionalData['user'] == '') {
                    throw new \BadMethodCallException('Failed because parameter $additionalData["user"] not passed');
                }

                $users = DB::table('users')
                    ->select(['users.id AS user_id', 'users.name AS user_name', 'users.email AS user_email'])
                    ->whereNotNull('users.verified_at')
                    ->whereIn('id', $additionalData['user'])
                    ->get();

                $notificationsData = [];
                if (count($users) > 0) {

                    foreach ($users as $user) {

                        array_push($notificationsData, [
                            'user_id' => $user->user_id,
                            'cube_id' => $additionalData['cube_id'] ?? null,
                            'ad_id' => $additionalData['ad_id'] ?? null,
                            'grab_id' => $additionalData['grab_id'] ?? null,
                            'type' => $notificationType,
                            'message' => $message,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                    }

                    try {
                        Notification::insert($notificationsData);
                    } catch (\Throwable $th) {
                        return [
                            'error' => true,
                            'message' => "Error: failed to create new notification",
                            'errorMessage' => $th,
                            'errorCode' => 500
                        ];
                    }
                }

                return [
                    'error' => false,
                    'message' => 'Success',
                    'data' => $notificationsData
                ];
            } break;
            case 'WORLD_OWNER': {

                // * Validate parameter passed
                if (!isset($additionalData['world']) || $additionalData['world'] == '') {
                    throw new \BadMethodCallException('Failed because parameter $additionalData["world"] not passed');
                }

                $corporateMembers = DB::table('worlds')
                    ->select([
                        'worlds.id AS world_id', 'worlds.name AS world_name',
                        'corporates.id AS corporate_id', 'corporates.name AS corporate_name',
                        'users.id AS user_id', 'users.name AS user_name', 'users.email AS user_email',
                    ])
                    ->join('corporates', function ($q) use ($additionalData) {
                        return $q->on('corporates.id', '=', 'worlds.corporate_id')
                            ->where('worlds.id', $additionalData['world']);
                    })
                    ->join('corporate_users', 'corporate_users.corporate_id', 'corporates.id')
                    ->join('users', 'users.id', 'corporate_users.user_id')
                    ->whereNotNull('users.verified_at')
                    ->get();

                $notificationsData = [];
                if (count($corporateMembers) > 0) {

                    foreach ($corporateMembers as $member) {

                        array_push($notificationsData, [
                            'user_id' => $member->user_id,
                            'cube_id' => $additionalData['cube_id'] ?? null,
                            'ad_id' => $additionalData['ad_id'] ?? null,
                            'grab_id' => $additionalData['grab_id'] ?? null,
                            'type' => $notificationType,
                            'message' => $message,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                    }

                    try {
                        Notification::insert($notificationsData);
                    } catch (\Throwable $th) {
                        return [
                            'error' => true,
                            'message' => "Error: failed to create new notification",
                            'errorMessage' => $th,
                            'errorCode' => 500
                        ];
                    }
                }

                return [
                    'error' => false,
                    'message' => 'Success',
                    'data' => $notificationsData
                ];
            } break;
        }
    }
}