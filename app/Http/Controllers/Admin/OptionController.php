<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdCategory;
use Illuminate\Http\Request;

class OptionController extends Controller
{
    public function adCategory(Request $request)
    {
        $community_id = $request->get('community_id', null);

        $query = AdCategory::query()
            ->where('is_primary_parent', 1)
            ->whereNull('parent_id')
            ->select('id', 'name', 'picture_source', 'community_id');

        // filter berdasarkan komunitas jika ada
        if ($community_id) {
            $query->where(function ($q) use ($community_id) {
                $q->whereNull('community_id')
                    ->orWhere('community_id', $community_id);
            });
        }

        $categories = $query->orderBy('name', 'asc')->get();

        $data = $categories->map(function ($cat) {
            return [
                'id' => $cat->id,
                'label' => $cat->name,
                'value' => $cat->id,
                'name' => $cat->name,
                'picture_source' => $cat->picture_source,
                'image' => $cat->picture_source
                    ? asset('storage/' . $cat->picture_source)
                    : asset('storage/ad-category/hotel.png'),
            ];
        });

        return response()->json([
            'message' => 'success',
            'data' => $data,
            'total_row' => $data->count(),
        ]);
    }

    public function adCategoryById(Request $request, $id)
    {
        $community_id = $request->get('community_id', null);

        $query = AdCategory::query()
            ->where('id', $id)
            ->select('id', 'name', 'picture_source', 'community_id');

        // filter berdasarkan komunitas jika ada
        if ($community_id) {
            $query->where(function ($q) use ($community_id) {
                $q->whereNull('community_id')
                    ->orWhere('community_id', $community_id);
            });
        }

        $category = $query->first();

        if (!$category) {
            return response()->json([
                'message' => 'Category not found',
                'data' => null,
            ], 404);
        }

        $data = [
            'id' => $category->id,
            'label' => $category->name,
            'value' => $category->id,
            'name' => $category->name,
            'picture_source' => $category->picture_source,
            'image' => $category->picture_source
                ? asset('storage/' . $category->picture_source)
                : asset('storage/ad-category/hotel.png'),
        ];

        return response()->json([
            'message' => 'success',
            'data' => $data,
        ]);
    }
}
