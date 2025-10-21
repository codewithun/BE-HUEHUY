<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    /**
     * 1️⃣ Ambil daftar chat milik admin login
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = Chat::query()
            ->with(['user:id,name,email', 'admin:id,name,email', 'community:id,name', 'corporate:id,name'])
            ->orderByDesc('updated_at');

        // Kalau bukan admin super → filter berdasarkan user login
        if (strtolower(optional($user->role)->name) !== 'admin') {
            $query->where('admin_id', $user->id);
        }

        $chats = $query->get();

        return response()->json([
            'success' => true,
            'data' => $chats
        ]);
    }

    /**
     * 2️⃣ Ambil semua pesan dalam satu chat
     */
    public function messages($chatId)
    {
        $chat = Chat::with(['messages.sender:id,name'])
            ->findOrFail($chatId);

        $messages = $chat->messages()->orderBy('created_at', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $messages
        ]);
    }

    /**
     * 3️⃣ Kirim pesan baru (buat chat kalau belum ada)
     */
    public function send(Request $request)
    {
        $request->validate([
            'chat_id' => 'nullable|integer',
            'receiver_id' => 'required|integer',
            'receiver_type' => 'required|in:user,admin',
            'message' => 'required|string|max:1000',
            'community_id' => 'nullable|integer',
            'corporate_id' => 'nullable|integer',
        ]);

        $sender = Auth::user();
        $isAdmin = strtolower(optional($sender->role)->name) === 'admin';

        DB::beginTransaction();
        try {
            // 🔹 Gunakan chat_id kalau sudah ada
            $chat = null;
            if ($request->chat_id) {
                $chat = Chat::find($request->chat_id);
            }

            // 🔹 Kalau belum ada, buat baru
            if (!$chat) {
                $chat = Chat::firstOrCreate([
                    'user_id' => $isAdmin ? $request->receiver_id : $sender->id,
                    'admin_id' => $isAdmin ? $sender->id : $request->receiver_id,
                    'community_id' => $request->community_id,
                    'corporate_id' => $request->corporate_id,
                ]);
            }

            // 🔹 Simpan pesan baru
            $msg = $chat->messages()->create([
                'sender_id' => $sender->id,
                'sender_type' => $isAdmin ? 'admin' : 'user',
                'message' => $request->message,
                'is_read' => false,
            ]);

            // 🔹 Update last message
            $chat->update([
                'last_message' => $request->message,
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'chat' => $chat,
                'message' => $msg,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 4️⃣ Tandai pesan sudah dibaca
     */
    public function markAsRead($chatId)
    {
        $user = Auth::user();

        ChatMessage::where('chat_id', $chatId)
            ->where('sender_id', '!=', $user->id)
            ->update(['is_read' => true]);

        return response()->json(['success' => true]);
    }

    /**
     * 0️⃣ Resolve/buat chat room dan kembalikan chatId (tanpa kirim pesan)
     */
    public function resolve(Request $request)
    {
        $request->validate([
            'receiver_id'   => 'required|integer',
            'receiver_type' => 'required|in:user,admin',
            'community_id'  => 'nullable|integer',
            'corporate_id'  => 'nullable|integer',
        ]);

        $sender  = Auth::user();
        $isAdmin = strtolower(optional($sender->role)->name) === 'admin';

        // tentukan pasangan user_id/admin_id berdasarkan siapa pengirimnya
        $userId  = $isAdmin ? $request->receiver_id : $sender->id;
        $adminId = $isAdmin ? $sender->id : $request->receiver_id;

        $chat = Chat::firstOrCreate([
            'user_id'      => $userId,
            'admin_id'     => $adminId,
            'community_id' => $request->community_id,
            'corporate_id' => $request->corporate_id,
        ]);

        return response()->json([
            'success' => true,
            'chat'    => $chat,
        ]);
    }
}
