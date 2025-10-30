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
            'message'       => 'required|string|max:1000',
            'receiver_id'   => 'nullable|integer|exists:users,id',
            'chat_id'       => 'nullable|integer|exists:chats,id',
            'community_id'  => 'nullable|integer',
            'corporate_id'  => 'nullable|integer',
            'receiver_type' => 'nullable|in:user,admin',
        ]);

        if (!$request->receiver_id && !$request->chat_id) {
            return response()->json(['success' => false, 'error' => 'receiver_id atau chat_id wajib diisi'], 422);
        }

        $sender = Auth::user();

        if ($request->receiver_id && $request->receiver_id == $sender->id) {
            return response()->json([
                'success' => false,
                'error' => 'Tidak bisa mengirim pesan ke diri sendiri.',
            ], 422);
        }


        DB::beginTransaction();
        try {
            if ($request->chat_id) {
                $chat = Chat::findOrFail($request->chat_id);
                if ($chat->sender_id !== $sender->id && $chat->receiver_id !== $sender->id) {
                    return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
                }
            } else {
                $partnerId   = (int) $request->receiver_id;

                // cari chat dua arah dalam scope community/corporate
                $chat = Chat::where(function ($q) use ($sender, $partnerId) {
                    $q->where('sender_id', $sender->id)->where('receiver_id', $partnerId);
                })
                    ->orWhere(function ($q) use ($sender, $partnerId) {
                        $q->where('sender_id', $partnerId)->where('receiver_id', $sender->id);
                    })
                    ->where('community_id', $request->community_id)
                    ->where('corporate_id', $request->corporate_id)
                    ->first();

                if (!$chat) {
                    $chat = Chat::create([
                        'sender_id'     => $sender->id,
                        'receiver_id'   => $partnerId,
                        'receiver_type' => $request->receiver_type ? strtolower($request->receiver_type) : null,
                        'community_id'  => $request->community_id,
                        'corporate_id'  => $request->corporate_id,
                    ]);
                }
            }

            $msg = $chat->messages()->create([
                'sender_id'   => $sender->id,
                'sender_type' => strtolower(optional($sender->role)->name ?? 'user'),
                'message'     => $request->message,
                'is_read'     => false,
            ]);

            $chat->update([
                'last_message' => $request->message,
                'updated_at'   => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'chat'    => $chat,
                'message' => $msg,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
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
            'receiver_id'   => 'required|integer|exists:users,id',
            'receiver_type' => 'nullable|in:user,admin',
            'community_id'  => 'nullable|integer',
            'corporate_id'  => 'nullable|integer',
        ]);

        $me = $request->user();
        $partnerId = (int) $request->receiver_id;
        $communityId = $request->community_id;
        $corporateId = $request->corporate_id;

        // 🚫 Cegah self-chat
        if ($partnerId === $me->id) {
            return response()->json([
                'success' => false,
                'error' => 'Tidak bisa mengirim pesan ke diri sendiri.',
            ], 422);
        }

        $chat = Chat::where(function ($q) use ($me, $partnerId) {
            $q->where('sender_id', $me->id)->where('receiver_id', $partnerId);
        })
            ->orWhere(function ($q) use ($me, $partnerId) {
                $q->where('sender_id', $partnerId)->where('receiver_id', $me->id);
            })
            ->where('community_id', $communityId)
            ->where('corporate_id', $corporateId)
            ->first();

        if (!$chat) {
            $chat = Chat::create([
                'sender_id'     => $me->id,
                'receiver_id'   => $partnerId,
                'receiver_type' => $request->receiver_type ? strtolower($request->receiver_type) : null,
                'community_id'  => $communityId,
                'corporate_id'  => $corporateId,
            ]);
        }

        return response()->json(['success' => true, 'chat' => $chat]);
    }


    public function chatRooms(Request $request)
    {
        $user = $request->user();

        $partnerId   = $request->query('partner_id');     // id user lawan bicara yang ingin difilter
        $communityId = $request->query('community_id');   // optional
        $corporateId = $request->query('corporate_id');   // optional
        // Optional behavior: only list chats that have replies from the partner (default true to preserve current behavior)
        $repliedOnly = filter_var($request->query('replied_only', '1'), FILTER_VALIDATE_BOOLEAN);

        $query = Chat::with([
            'sender:id,name,picture_source',
            'receiver:id,name,picture_source',
            // Use latest-of-many relation for robust per-parent latest message retrieval
            'lastMessage'
        ])
            // Compute unread_count efficiently without N+1
            ->withCount([
                'messages as unread_count' => function ($q) use ($user) {
                    $q->where('sender_id', '!=', $user->id)->where('is_read', false);
                }
            ]);

        // hanya chat yang melibatkan user login
        $query->where(function ($q) use ($user) {
            $q->where('sender_id', $user->id)
                ->orWhere('receiver_id', $user->id);
        });

        // 🔥 Optional filter: only chats with at least one reply from partner
        if ($repliedOnly) {
            $query->whereHas('messages', function ($q) use ($user) {
                $q->where('sender_id', '!=', $user->id);
            });
        }

        // filter partner tertentu (hanya chat dengan A)
        if (!empty($partnerId)) {
            $query->where(function ($q) use ($user, $partnerId) {
                $q->where(function ($q) use ($user, $partnerId) {
                    $q->where('sender_id', $user->id)->where('receiver_id', $partnerId);
                })->orWhere(function ($q) use ($user, $partnerId) {
                    $q->where('sender_id', $partnerId)->where('receiver_id', $user->id);
                });
            });
        }

        // filter konteks (jika dipakai)
        if ($communityId !== null && $communityId !== '') {
            $query->where('community_id', $communityId);
        }
        if ($corporateId !== null && $corporateId !== '') {
            $query->where('corporate_id', $corporateId);
        }

        $chats = $query->orderByDesc('updated_at')
            ->get()
            ->map(function ($chat) use ($user) {
                $last = $chat->lastMessage;

                $partner = $chat->sender_id === $user->id ? $chat->receiver : $chat->sender;
                if (!$partner || $partner->id === $user->id) {
                    return null;
                }

                return [
                    'id' => $chat->id,
                    'partner' => [
                        'id' => $partner->id,
                        'name' => $partner->name,
                        'picture' => $partner->picture_source,
                    ],
                    'last_message' => $last?->message ?? '(belum ada pesan)',
                    'created_at' => $last?->created_at,
                    'unread_count' => (int) ($chat->unread_count ?? 0),
                ];
            })
            ->filter();

        return response()->json([
            'success' => true,
            'data' => $chats->values(),
        ]);
    }
}
