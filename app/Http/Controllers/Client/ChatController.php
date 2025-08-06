<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatRoom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        $type = $request->get("type", null);

        // ? Preparation
        $columnAliases = [
            'created_at' => 'chat_rooms.created_at'
        ];

        $credential = Auth::user();
        $worldIds = $credential->worlds->map(function ($item) {
            return $item->world_id;
        });

        // ? Begin
        $model = new ChatRoom();
        $query = ChatRoom::with('world', 'user_hunter', 'user_merchant');

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

        if ($type == 'hunter') {
            $query->where('user_hunter_id', $credential->id);
        } else if ($type == 'merchant') {
            $query->where('user_merchant_id', $credential->id);
        } else if ($type == 'world') {
            $query->whereIn('world_id', $worldIds);
        }

        // ? Sort & executing with pagination
        $query = $query->select($model->selectable)
            // ->leftJoin('worlds', 'worlds.id', 'chat_rooms.world_id')
            // ->where(function ($q) use ($credential, $worldIds) {
            //     $q->where('user_hunter_id', $credential->id)
            //         ->orWhere('user_merchant_id', $credential->id)
            //         ->orWhereIn('chat_rooms.world_id', $worldIds);
            // })
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
        $worldMemberId = $credential->worlds->map(function ($item) {
            return $item->world_id;
        });

        // * Find Data
        $instanceModel = new ChatRoom();
        $model = ChatRoom::with([
                'world', 'user_hunter', 'user_merchant', 
                'chats', 'chats.user_sender', 'chats.cube', 'chats.grab'
            ])
            ->select($instanceModel->selectable)
            ->where(function ($q) use ($credential, $worldMemberId) {
                $q->where('user_hunter_id', $credential->id)
                    ->orWhere('user_merchant_id', $credential->id)
                    ->orWhereIn('world_id', $worldMemberId);
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
    // ## Create Chat Rooms or with first message
    // =============================================>
    public function store(Request $request)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'user_merchant_id' => 'required|numeric|exists:users,id',

            'chat.message' => 'nullable|string|min:1',
            'chat.cube_id' => 'nullable|numeric|exists:cubes,id',
            'chat.grab_id' => 'nullable|numeric|exists:grabs,id',
        ]);

        if ($validation) return $validation;

        $credential = Auth::user();

        // ? Initial
        DB::beginTransaction();
        $model = ChatRoom::where('user_merchant_id', $request->user_merchant_id)->where('user_hunter_id', $credential->id)->first();
        
        if(!$model) {
            $model = new ChatRoom();
    
            // ? Dump data
            $model = $this->dump_field($request->all(), $model);
            $model->user_hunter_id = $credential->id;
    
            // ? Executing
            try {
                $model->save();
            } catch (\Throwable $th) {
                DB::rollBack();
                return response([
                    "message" => "Error: failed to create chat room",
                ], 500);
            }
        }

        // * Check a message
        // if ($request->chat) {

            $chat = new Chat();
            $chat->chat_room_id = $model->id;
            $chat->user_sender_id = $credential->id;
            $chat->message = $request->chat['message'] ?? null;
            $chat->cube_id = $request->chat['cube_id'] ?? null;
            $chat->grab_id = $request->chat['grab_id'] ?? null;

            try {
                $chat->save();
            } catch (\Throwable $th) {
                DB::rollBack();
                return response([
                    "message" => "Error: failed to create first chat",
                ], 500);
            }
        // }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $model
        ], 201);
    }

    // =============================================>
    // ## Send message
    // =============================================>
    public function createMessage(Request $request)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'chat_room_id' => 'required|numeric',
            'message' => 'required|string|min:1',
            'cube_id' => 'nullable|numeric|exists:cubes,id',
            'grab_id' => 'nullable|numeric|exists:grabs,id',
        ]);

        if ($validation) return $validation;

        $credential = Auth::user();
        
        $chatRoom = ChatRoom::select('chat_rooms.*')
            // ->leftJoin('cubes', 'cubes.id', 'chat_rooms.cube_id')
            // ->where(function ($q) use ($credential) {
            //     $q->where('user_hunter_id', $credential->id)
            //         ->orWhere('cubes.user_id', $credential->id);
            // })
            // ->where('user_hunter_id', $credential->id)
            ->where('id', $request->chat_room_id)
            ->groupBy('id')
            ->first();

        if (!$chatRoom) {
            return response([
                "message" => 'Validation Error: Unprocessable Entity!',
                'errors' => [
                    'chat_room_id' => ['Chat room ini tidak valid']
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
        