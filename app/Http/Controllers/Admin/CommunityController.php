<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Community;
use Illuminate\Http\Request;

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

    // Pagination
    $paginate = $request->paginate ?? 10;
    $data = $query->paginate($paginate);

    return response()->json([
        'data' => $data->items(),
        'total_row' => $data->total(),
    ]);
}

    // Store a new community
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'logo' => 'nullable|string', // atau file jika upload file
        ]);

        $community = Community::create($validated);

        return response()->json($community, 201);
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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'logo' => 'nullable|string',
        ]);

        $community = Community::findOrFail($id);
        $community->update($validated);

        return response()->json($community);
    }

    // Delete a community
    public function destroy($id)
    {
        $community = Community::findOrFail($id);
        $community->delete();

        return response()->json(['message' => 'Community deleted']);
    }
}
