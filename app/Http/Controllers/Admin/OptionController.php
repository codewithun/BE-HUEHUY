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
        $paginate = $request->get('paginate', null);
        $isAll = is_string($paginate) && strtolower($paginate) === 'all';
        $includeChildren = false;
        if ($request->filled('include_children')) {
            $val = strtolower((string) $request->get('include_children'));
            if (in_array($val, ['1', 'true', 'yes'], true)) $includeChildren = true;
        }
        $isFull = $request->boolean('full');

        // Mulai query tanpa memaksa hanya parent/primary
        $query = AdCategory::query();

        // Optional: jika caller hanya mau parent-only, gunakan param parent_only
        if ($request->filled('parent_only')) {
            $query->whereNull('parent_id');
        }

        // filter berdasarkan komunitas (global + spesifik)
        if ($community_id) {
            $query->where(function ($q) use ($community_id) {
                $q->whereNull('community_id')
                    ->orWhere('community_id', $community_id);
            });
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->get('search') . '%');
        }

        $rows = $isAll ? $query->orderBy('name', 'asc')->get() : $query->orderBy('name', 'asc')->get();
        // (Jika Anda ingin paginate numerik, ubah bagian ini menjadi ->paginate($perPage) sesuai kebutuhan)

        if ($rows->isEmpty()) {
            return response()->json(['message' => 'empty data', 'data' => [], 'total_row' => 0], 200);
        }

        // Jika client minta struktur tree
        if ($includeChildren) {
            $flat = $rows->map(function ($cat) use ($isFull) {
                $node = [
                    'id' => $cat->id,
                    'value' => $cat->id,
                    'label' => $cat->name,
                    'parent_id' => $cat->parent_id,
                ];
                if ($isFull) {
                    $node = array_merge($node, [
                        'name' => $cat->name,
                        'picture_source' => $cat->picture_source,
                        'image' => $cat->picture_source ? asset('storage/' . $cat->picture_source) : null,
                        'is_primary_parent' => (int) $cat->is_primary_parent,
                        'is_home_display' => (int) $cat->is_home_display,
                        'community_id' => $cat->community_id,
                    ]);
                }
                return $node;
            })->toArray();

            // build tree
            $itemsById = [];
            foreach ($flat as $item) {
                $item['children'] = [];
                $itemsById[$item['id']] = $item;
            }

            $tree = [];
            foreach ($itemsById as $id => $item) {
                $parentId = $item['parent_id'];
                if ($parentId && isset($itemsById[$parentId])) {
                    $itemsById[$parentId]['children'][] = &$itemsById[$id];
                } else {
                    $tree[] = &$itemsById[$id];
                }
            }

            // optional: hapus internal id,parent_id dari response (frontend tidak butuh)
            $cleanTree = $this->strip_keys_recursive($tree, ['id', 'parent_id']);

            return response()->json(['message' => 'success', 'data' => $cleanTree, 'total_row' => count($flat)], 200);
        }

        // Default: flat list. Jika full flag, return fields lengkap; kalau tidak, kembalikan {value,label}
        if ($isFull) {
            $data = $rows->map(function ($cat) {
                return [
                    'id' => $cat->id,
                    'value' => $cat->id,
                    'label' => $cat->name,
                    'name' => $cat->name,
                    'picture_source' => $cat->picture_source,
                    'image' => $cat->picture_source ? asset('storage/' . $cat->picture_source) : null,
                    'parent_id' => $cat->parent_id,
                    'is_primary_parent' => (int) $cat->is_primary_parent,
                    'is_home_display' => (int) $cat->is_home_display,
                    'community_id' => $cat->community_id,
                ];
            })->toArray();

            return response()->json(['message' => 'success', 'data' => $data, 'total_row' => count($data)], 200);
        }

        $simple = $rows->map(fn($r) => ['value' => $r->id, 'label' => $r->name])->toArray();
        return response()->json(['message' => 'success', 'data' => $simple, 'total_row' => count($simple)], 200);
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
