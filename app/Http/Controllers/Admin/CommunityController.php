<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Community;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CommunityController extends Controller
{
    // List all communities

public function index(Request $request)
{
    $query = Community::query();

    // Optional: search
    if ($request->search) {
        $query->where('name', 'like', '%' . $request->search . '%');
    }

    // Optional: sorting
    if ($request->sortBy && $request->sortDirection) {
        $query->orderBy($request->sortBy, $request->sortDirection);
    }

    // Jika ada parameter paginate, gunakan pagination. Jika tidak, ambil semua.
    if ($request->has('paginate')) {
        $paginate = $request->paginate ?? 10;
        $data = $query->paginate($paginate);
        return response()->json([
            'data' => $data->items(),
            'total_row' => $data->total(),
        ]);
    } else {
        $data = $query->get();
        return response()->json([
            'data' => $data,
            'total_row' => $data->count(),
        ]);
    }
}

    // Store a new community
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            // Handle logo upload
            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')->store('communities', 'public');
                $validated['logo'] = $logoPath;
            }

            $community = Community::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Community created successfully',
                'data' => $community
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create community: ' . $e->getMessage()
            ], 500);
        }
    }

    // Show a single community
    public function show($id)
    {
        $community = Community::with('categories')->findOrFail($id);
        return response()->json($community);
    }

    // Update a community
    public function update(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $community = Community::findOrFail($id);
            $validated = $validator->validated();

            // Handle logo upload
            if ($request->hasFile('logo')) {
                // Delete old logo if exists
                if ($community->logo && Storage::disk('public')->exists($community->logo)) {
                    Storage::disk('public')->delete($community->logo);
                }
                
                $logoPath = $request->file('logo')->store('communities', 'public');
                $validated['logo'] = $logoPath;
            }

            $community->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Community updated successfully',
                'data' => $community
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update community: ' . $e->getMessage()
            ], 500);
        }
    }

    // Delete a community
    public function destroy($id)
    {
        try {
            $community = Community::findOrFail($id);
            
            // Delete logo file if exists
            if ($community->logo && Storage::disk('public')->exists($community->logo)) {
                Storage::disk('public')->delete($community->logo);
            }
            
            $community->delete();

            return response()->json([
                'success' => true,
                'message' => 'Community deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete community: ' . $e->getMessage()
            ], 500);
        }
    }
}
