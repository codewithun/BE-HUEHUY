<?php

namespace App\Http\Controllers\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Community;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CommunityController extends Controller
{
    public function index()
    {
        $corporateId = Auth::user()->corporate_user->corporate_id ?? null;
        $data = Community::where('corporate_id', $corporateId)->get();
        return response()->json($data);
    }

    public function store(Request $request)
    {
        $corporateId = Auth::user()->corporate_user->corporate_id ?? null;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'bg_color_1' => 'nullable|string|max:10',
            'bg_color_2' => 'nullable|string|max:10',
            'world_type' => 'required|in:public,private',
            'logo' => 'nullable|image|max:2048',
        ]);

        $validated['corporate_id'] = $corporateId;
        $validated['is_active'] = $request->boolean('is_active', true);

        if ($request->hasFile('logo')) {
            $validated['logo'] = $request->file('logo')->store('community_logos', 'public');
        }

        $community = Community::create($validated);
        return response()->json($community, 201);
    }

    public function show($id)
    {
        $community = Community::findOrFail($id);
        return response()->json($community);
    }

    public function update(Request $request, $id)
    {
        $community = Community::findOrFail($id);
        $community->update($request->all());
        return response()->json($community);
    }

    public function destroy($id)
    {
        $community = Community::findOrFail($id);
        $community->delete();
        return response()->json(['message' => 'Community deleted successfully']);
    }
}
