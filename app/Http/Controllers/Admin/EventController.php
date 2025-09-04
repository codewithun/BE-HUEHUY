<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class EventController extends Controller
{
    // Display a listing of the resource
    public function index(Request $request)
    {
        $sortDirection = $request->get("sortDirection", "DESC");
        $sortby = $request->get("sortBy", "created_at");
        $paginate = $request->get("paginate", 10);
        $filter = $request->get("filter", null);

        $query = Event::with('community');

        // Filter by community_id if provided
        if ($request->get("community_id")) {
            $query = $query->where('community_id', $request->get("community_id"));
        }

        // Search
        if ($request->get("search") != "") {
            $query = $query->where('title', 'like', '%' . $request->get("search") . '%')
                          ->orWhere('organizer_name', 'like', '%' . $request->get("search") . '%')
                          ->orWhere('location', 'like', '%' . $request->get("search") . '%');
        }

        // Filter
        if ($filter) {
            $filters = json_decode($filter);
            foreach ($filters as $column => $value) {
                if ($column == 'community_id') {
                    $filterVal = explode(':', $value)[1];
                    $query = $query->where('community_id', $filterVal);
                } else {
                    $query = $query->where($column, 'like', '%' . $value . '%');
                }
            }
        }

        // Additional filters for frontend
        if ($request->get("category")) {
            $query = $query->where('category', $request->get("category"));
        }

        if ($request->get("upcoming_only") == "true") {
            $query = $query->where('date', '>=', now()->toDateString());
        }

        $query = $query->orderBy($sortby, $sortDirection);

        // Handle pagination
        if ($request->get("paginate") && $request->get("paginate") != "all") {
            $result = $query->paginate($paginate);
            
            if (empty($result->items())) {
                return response([
                    "message" => "empty data",
                    "data" => [],
                ], 200);
            }

            return response([
                "message" => "success",
                "data" => $result->items(),
                "total_row" => $result->total(),
                "current_page" => $result->currentPage(),
                "last_page" => $result->lastPage(),
            ]);
        } else {
            // Return all events
            $events = $query->get();
            
            return response([
                "message" => "success",
                "data" => $events,
                "total_row" => $events->count(),
            ]);
        }
    }

    public function show(string $id)
    {
        $model = Event::with('community', 'registrations', 'registrations.user')
            ->where('id', $id)
            ->first();

        if (!$model) {
            return response([
                'message' => 'Data not found'
            ], 404);
        }

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }

    // Store a newly created resource in storage
    public function store(Request $request)
    {
        // Tambahkan logging untuk debugging
        Log::info('Event store request:', [
            'data' => $request->except(['image', 'organizer_logo']),
            'files' => [
                'image' => $request->hasFile('image'),
                'organizer_logo' => $request->hasFile('organizer_logo')
            ],
            'content_type' => $request->header('Content-Type')
        ]);

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'organizer_name' => 'required|string|max:255',
            'organizer_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'organizer_type' => 'nullable|string|max:255',
            'date' => 'required|date',
            'time' => 'nullable|string|max:100',
            'location' => 'required|string|max:255',
            'address' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'participants' => 'nullable|integer|min:0',
            'max_participants' => 'nullable|integer|min:1',
            'price' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'requirements' => 'nullable|string',
            'schedule' => 'nullable|string',
            'prizes' => 'nullable|string',
            'contact_phone' => 'nullable|string|max:50',
            'contact_email' => 'nullable|email|max:100',
            'tags' => 'nullable|string',
            'community_id' => 'nullable|exists:communities,id',
        ]);

        if ($validator->fails()) {
            // Tambahkan detail error untuk debugging
            Log::error('Event validation failed:', [
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
                'debug_data' => config('app.debug') ? $request->all() : null
            ], 422);
        }

        try {
            DB::beginTransaction();
            
            $data = $request->except(['image', 'organizer_logo']);
            
            // Handle event image upload
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('events', 'public');
                $data['image'] = $path;
            }

            // Handle organizer logo upload
            if ($request->hasFile('organizer_logo')) {
                $path = $request->file('organizer_logo')->store('events/organizers', 'public');
                $data['organizer_logo'] = $path;
            }

            // Set default values
            $data['participants'] = $data['participants'] ?? 0;
            $data['max_participants'] = $data['max_participants'] ?? 100;

            $model = Event::create($data);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Event berhasil dibuat',
                'data' => $model
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat event: ' . $e->getMessage()
            ], 500);
        }
    }

    // Update the specified resource in storage
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'organizer_name' => 'required|string|max:255',
            'organizer_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'organizer_type' => 'nullable|string|max:255',
            'date' => 'required|date',
            'time' => 'nullable|string|max:100',
            'location' => 'required|string|max:255',
            'address' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'participants' => 'nullable|integer|min:0',
            'max_participants' => 'nullable|integer|min:1',
            'price' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'requirements' => 'nullable|string',
            'schedule' => 'nullable|string',
            'prizes' => 'nullable|string',
            'contact_phone' => 'nullable|string|max:50',
            'contact_email' => 'nullable|email|max:100',
            'tags' => 'nullable|string',
            'community_id' => 'nullable|exists:communities,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();
            
            $model = Event::findOrFail($id);
            $data = $request->except(['image', 'organizer_logo']);
            
            // Handle event image upload
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($model->image && Storage::disk('public')->exists($model->image)) {
                    Storage::disk('public')->delete($model->image);
                }
                $path = $request->file('image')->store('events', 'public');
                $data['image'] = $path;
            }

            // Handle organizer logo upload
            if ($request->hasFile('organizer_logo')) {
                // Delete old logo if exists
                if ($model->organizer_logo && Storage::disk('public')->exists($model->organizer_logo)) {
                    Storage::disk('public')->delete($model->organizer_logo);
                }
                $path = $request->file('organizer_logo')->store('events/organizers', 'public');
                $data['organizer_logo'] = $path;
            }

            $model->update($data);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Event berhasil diupdate',
                'data' => $model->fresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate event: ' . $e->getMessage()
            ], 500);
        }
    }

    // Remove the specified resource from storage
    public function destroy(string $id)
    {
        try {
            DB::beginTransaction();
            
            $model = Event::findOrFail($id);
            
            // Delete associated images
            if ($model->image && Storage::disk('public')->exists($model->image)) {
                Storage::disk('public')->delete($model->image);
            }
            if ($model->organizer_logo && Storage::disk('public')->exists($model->organizer_logo)) {
                Storage::disk('public')->delete($model->organizer_logo);
            }
            
            $model->delete();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Event berhasil dihapus'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus event: ' . $e->getMessage()
            ], 500);
        }
    }

    // Register user for event
    public function register(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();
            
            $event = Event::findOrFail($id);
            
            // Check if user is already registered
            if ($event->isUserRegistered($request->user_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'User sudah terdaftar untuk event ini'
                ], 400);
            }
            
            // Check if event is full
            if (!$event->canRegister()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Event sudah penuh'
                ], 400);
            }
            
            // Create registration
            EventRegistration::create([
                'event_id' => $id,
                'user_id' => $request->user_id,
                'registered_at' => now(),
            ]);
            
            // Increment participants count
            $event->incrementParticipants();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Berhasil mendaftar event'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal mendaftar event: ' . $e->getMessage()
            ], 500);
        }
    }

    // Get event registrations
    public function registrations(string $id)
    {
        $event = Event::with(['registrations.user'])->findOrFail($id);
        
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $event->registrations
        ]);
    }

    // Get events by community ID
    public function indexByCommunity(Request $request, string $communityId)
    {
        $sortDirection = $request->get("sortDirection", "DESC");
        $sortby = $request->get("sortBy", "created_at");
        $paginate = $request->get("paginate", 10);

        $query = Event::with('community')
            ->where('community_id', $communityId);

        // Search within community events
        if ($request->get("search") != "") {
            $query = $query->where(function($q) use ($request) {
                $q->where('title', 'like', '%' . $request->get("search") . '%')
                  ->orWhere('organizer_name', 'like', '%' . $request->get("search") . '%')
                  ->orWhere('location', 'like', '%' . $request->get("search") . '%');
            });
        }

        // Additional filters
        if ($request->get("category")) {
            $query = $query->where('category', $request->get("category"));
        }

        if ($request->get("upcoming_only") == "true") {
            $query = $query->where('date', '>=', now()->toDateString());
        }

        $query = $query->orderBy($sortby, $sortDirection);

        // If pagination is requested
        if ($request->get("paginate") && $request->get("paginate") != "all") {
            $result = $query->paginate($paginate);
            
            return response([
                "message" => "success",
                "data" => $result->items(),
                "total_row" => $result->total(),
                "current_page" => $result->currentPage(),
                "last_page" => $result->lastPage(),
            ]);
        } else {
            // Return all events for the community
            $events = $query->get();
            
            return response([
                "message" => "success",
                "data" => $events,
                "total_row" => $events->count(),
            ]);
        }
    }
}
