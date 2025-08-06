<?php

namespace App\Http\Controllers\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatRoom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    // ========================================>
    // ## Get Chat Rooms
    // ========================================>
    public function index(Request $request)
    {
        // ? Initial params
        $sortDirection = $request->get("sortDirection", "DESC");
        $sortby = $request->get("sortBy", "created_at");
        $paginate = $request->get("paginate", 10);
        $filter = $request->get("filter", null);

        // ? Preparation
        $columnAliases = [
            'created_at' => 'chat_rooms.created_at'
        ];

        $credential = Auth::user();
        $credentialUserWorld = $credential->worlds;

        $worldIds = $credentialUserWorld->map(function ($item) {
            return $item->world_id;
        });

        // ? Begin
        $model = new ChatRoom();
        $query = ChatRoom::with('world', 'user_hunter', 'chats');

        // ? When search
        if ($request->get("search") != "") {
            $query = $this->search($request->get("search"), $model, $query);
        } else {
            $query = $query;
        }

        // ? When Filter
        if ($filter) {
            $filters = json_decode($filter);
            foreach ($filters as $column => $value) {
                $query = $this->filter($this->remark_column($column, $columnAliases), $value, $model, $query);
            }
        }

        // ? Sort & executing with pagination
        $query = $query->select($model->selectable)
            ->where(function ($q) use ($credential, $worldIds) {
                return $q->where('chat_rooms.user_merchant_id', $credential->id)
                    ->orWhere('chat_rooms.user_hunter_id', $credential->id)
                    ->when(count($worldIds) > 0, function ($whenQuery) use ($worldIds) {
                        return $whenQuery->orWhere('chat_rooms.world_id', $worldIds);
                    });
            })
            ->groupBy('chat_rooms.id')
            ->orderBy($this->remark_column($sortby, $columnAliases), $sortDirection)
            ->paginate($paginate);

        // ? When empty
        if (empty($query->items())) {
            return response([
                "message" => "empty data",
                "data" => [],
            ], 200);
        }

        // ? When success
        return response([
            "message" => "success",
            "data" => $query->all(),
            "total_row" => $query->total(),
        ]);
    }

    // ========================================>
    // ## Get Chat by Chat Room ID
    // ========================================>
    public function show($id)
    {
        $credential = Auth::user();
        $credentialUserWorld = $credential->worlds;

        $worldIds = $credentialUserWorld->map(function ($item) {
            return $item->world_id;
        });

        // * Find Data
        $model = ChatRoom::with([
                'world', 'user_hunter', 'user_merchant', 
                'chats', 'chats.user_sender', 'chats.cube', 'chats.grab'
            ])
            ->select('chat_rooms.*')
            // ->leftJoin('cubes', 'cubes.id', 'chat_rooms.cube_id')
            // ->leftJoin('world_affiliates', 'world_affiliates.id', 'cubes.world_affiliate_id')
            // ->leftJoin('user_worlds', 'user_worlds.world_id', 'cubes.world_id')
            // ->where(function ($q) use ($credentialUserCorporate) {
            //     return $q->where('cubes.corporate_id', $credentialUserCorporate->corporate_id)
            //         ->orWhere('world_affiliates.corporate_id', $credentialUserCorporate->corporate_id)
            //         ->orWhere('user_worlds.user_id', $credentialUserCorporate->user_id);
            // })
            ->where(function ($q) use ($credential, $worldIds) {
                return $q->where('chat_rooms.user_merchant_id', $credential->id)
                    ->orWhere('chat_rooms.user_hunter_id', $credential->id)
                    ->when(count($worldIds) > 0, function ($whenQuery) use ($worldIds) {
                        return $whenQuery->orWhere('chat_rooms.world_id', $worldIds);
                    });
            })
            ->where('chat_rooms.id', $id)
            ->groupBy('chat_rooms.id')
            ->first();

        if (!$model) {
            return response([
                'message' => 'Data not found'
            ], 404);
        }

        foreach ($model->chats as $chat) {
            if ($chat->user_sender_id == $credential->id) {
                $chat->is_my_reply = true;
            } else {
                $chat->is_my_reply = false;
            }
        }

        // * Response
        return response([
            "message" => "Success",
            "data" => $model,
        ]);
    }

    // =============================================>
    // ## Send message
    // =============================================>
    public function createMessage(Request $request)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'chat_room_id' => 'required|numeric',
            'message' => 'required|string|min:1'
        ]);

        if ($validation) return $validation;

        $credential = Auth::user();
        $credentialUserWorld = $credential->worlds;

        $worldIds = $credentialUserWorld->map(function ($item) {
            return $item->world_id;
        });
        
        $chatRoom = ChatRoom::select('chat_rooms.*')
            ->where(function ($q) use ($credential, $worldIds) {
                return $q->where('chat_rooms.user_merchant_id', $credential->id)
                    ->orWhere('chat_rooms.user_hunter_id', $credential->id)
                    ->when(count($worldIds) > 0, function ($whenQuery) use ($worldIds) {
                        return $whenQuery->orWhere('chat_rooms.world_id', $worldIds);
                    });
            })
            ->where('chat_rooms.id', $request->chat_room_id)
            ->groupBy('chat_rooms.id')
            ->first();

        if (!$chatRoom) {
            return response([
                "message" => 'Validation Error: Unprocessable Entity!',
                'errors' => [
                    'chat_room_id' => ['Chat room ini tidak valid'],
                    'user' => $credential
                ]
            ], 422);
        }

        // ? Initial
        DB::beginTransaction();
        $model = new Chat();

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);
        $model->user_sender_id = $credential->id;

        // ? Executing
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: failed to create chat room",
            ], 500);
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $model
        ], 201);
    }
}
