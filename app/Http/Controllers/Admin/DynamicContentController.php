<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DynamicContent;
use App\Models\DynamicContentCube;
use App\Models\AdCategory;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class DynamicContentController extends Controller
{
    // ========================================>
    // ## Display a listing of the resource.
    // ========================================>
    public function index(Request $request)
    {
        // ? Initial params
        $sortDirection = $request->get("sortDirection", "DESC");
        $sortby = $request->get("sortBy", "created_at");
        $paginate = $request->get("paginate", 10);
        $filter = $request->get("filter", null);
        $community_id = $request->get("community_id", null);

        // ? Preparation
        $columnAliases = [];

        // ? Begin
        $model = new DynamicContent();
        $query = DynamicContent::with([
            'dynamic_content_cubes', 
            'dynamic_content_cubes.cube', 
            'dynamic_content_cubes.cube.cube_type', 
            'dynamic_content_cubes.cube.ads' => function($query) {
                $query->where('status', 'active');
            },
            'dynamic_content_cubes.cube.ads.ad_category',
            'dynamic_content_cubes.cube.tags',
            'dynamic_content_cubes.cube.user',
            'dynamic_content_cubes.cube.corporate',
            'dynamic_content_cubes.cube.opening_hours',
            'ad_category'
        ]);

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

        // Optional query param filter: type=home|hunting
        if ($request->filled('type')) {
            $query->where('type', $request->get('type'));
        }

        // PERBAIKAN: Filter komunitas yang lebih fleksibel
        if ($community_id && $community_id !== '' && $community_id !== 'null') {
            // Jika ada filter komunitas yang dipilih, tampilkan hanya widget komunitas tersebut
            $query->where('community_id', $community_id);
        }
        // HAPUS bagian else - sehingga kalau tidak ada filter, tampilkan SEMUA widget (global + komunitas)

        // ? Sort & executing with pagination
        if ($paginate === 'all') {
            $widgets = $query->orderBy('level', 'asc')->get();
            $totalRow = $widgets->count();

            return response([
                "message" => $widgets->isEmpty() ? "empty data" : "success",
                "data" => $widgets,
                "total_row" => $totalRow,
            ]);
        } else {
            $paginated = $query->orderBy('level', 'asc')->paginate($paginate);

            return response([
                "message" => $paginated->isEmpty() ? "empty data" : "success",
                "data" => $paginated->items(),
                "total_row" => $paginated->total(),
            ]);
        }

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
            "data" => $widgets,
            "total_row" => $query->total(),
        ]);
    }

    // =============================================>
    // ## Store a newly created resource in storage.
    // =============================================>
    public function store(Request $request)
    {
        // Debug logging untuk request
        Log::info('=== DYNAMIC CONTENT STORE START ===');
        Log::info('Request data:', $request->all());
        Log::info('Source type: ' . $request->source_type);
        // Avoid array to string conversion when multiple cubes are selected
        Log::info('Dynamic content cubes:', ['dynamic_content_cubes' => $request->dynamic_content_cubes]);
        Log::info('Community ID: ' . $request->community_id);

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:255',
            'type' => ['required', Rule::in(['home', 'hunting', 'information'])],
            'content_type' => ['required', Rule::in(['nearby', 'horizontal', 'vertical', 'category', 'ad_category', 'recommendation', 'promo'])],
            'source_type' => ['nullable', Rule::in(['cube', 'ad', 'shuffle_cube', 'promo_selected', 'ad_category'])],
            'size' => ['nullable', Rule::in(['S', 'M', 'L', 'XL', 'XL-Ads'])],
            'community_id' => 'nullable|numeric|exists:communities,id',
            'dynamic_content_cubes' => [Rule::requiredIf(function () use ($request) {
                return $request->source_type === 'cube' && $request->content_type === 'promo';
            })],
        ]);

        if ($validation) return $validation;

        // ? Initial
        DB::beginTransaction();
        $model = new DynamicContent();

        // Handle community_id filtering properly
        $query = DynamicContent::where('type', $request->type);

        if ($request->community_id) {
            $query->where('community_id', $request->community_id);
        } else {
            $query->whereNull('community_id');
        }

        $last_level = $query->orderBy('level', 'DESC')->first();

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);
        $model->level = ($last_level && $last_level->level) ? $last_level->level + 1 : 1;
        $model->is_active = 1;

        if ($request->content_type === 'category') {
            $model->source_type = 'ad_category';
        }

        // ? Executing
        try {
            $model->save();
            Log::info('Dynamic content saved successfully:', ['id' => $model->id]);
        } catch (\Throwable $th) {
            Log::error('Failed to save dynamic content:', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'data' => $model->toArray()
            ]);
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem! - " . $th->getMessage(),
            ], 500);
        }

        // * Handle Dynamic Content Cubes
        if ($request->source_type == 'cube' && $request->dynamic_content_cubes) {
            Log::info('Processing cubes...');
            // Avoid array to string conversion; log raw payload safely
            Log::info('Raw cubes data', ['raw' => $request->dynamic_content_cubes]);
            Log::info('Cubes data type: ' . gettype($request->dynamic_content_cubes));

            // Handle both array and string formats
            $cubes = [];
            if (is_array($request->dynamic_content_cubes)) {
                $cubes = $request->dynamic_content_cubes;
            } elseif (is_string($request->dynamic_content_cubes)) {
                // Handle comma-separated string or single value
                $cubes = explode(',', $request->dynamic_content_cubes);
            } else {
                // Single numeric value
                $cubes = [$request->dynamic_content_cubes];
            }

            // Clean up the array - remove empty values and ensure numeric
            $cubes = array_filter(array_map('intval', $cubes), function ($cube) {
                return $cube > 0;
            });

            Log::info('Processed cubes:', $cubes);

            if (!empty($cubes)) {
                $prepareDynamicContentCubes = [];
                foreach ($cubes as $item) {
                    $prepareDynamicContentCubes[] = [
                        'dynamic_content_id' => $model->id,
                        'cube_id' => intval($item),
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now()
                    ];
                }

                try {
                    DynamicContentCube::insert($prepareDynamicContentCubes);
                    Log::info('Successfully inserted cubes:', $prepareDynamicContentCubes);
                } catch (\Throwable $th) {
                    Log::error('Failed to insert cubes:', [
                        'error' => $th->getMessage(),
                        'data' => $prepareDynamicContentCubes
                    ]);
                    DB::rollBack();
                    return response([
                        "message" => "Error: failed to insert new dynamic content cubes - " . $th->getMessage(),
                    ], 500);
                }
            }
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $model
        ], 201);
    }

    // ============================================>
    // ## Update the specified resource in storage.
    // ============================================>
    public function update(Request $request, string $id)
    {
        // ? Initial
        DB::beginTransaction();
        $model = DynamicContent::findOrFail($id);

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            'type' => ['nullable', Rule::in(['home', 'hunting', 'information'])],
            'content_type' => ['nullable', Rule::in(['nearby', 'horizontal', 'vertical', 'category', 'ad_category', 'recommendation', 'promo'])],
            'source_type' => ['nullable', Rule::in(['cube', 'ad', 'shuffle_cube', 'promo_selected', 'ad_category'])],
            'size' => ['nullable', Rule::in(['S', 'M', 'L', 'XL', 'XL-Ads'])],
            'level' => 'nullable|numeric',
            'is_active' => 'nullable|boolean',
            'community_id' => 'nullable|numeric|exists:communities,id',
            'dynamic_content_cubes' => [Rule::requiredIf($request->source_type == 'cube')],
        ]);

        if ($validation) return $validation;

        if ($request->level) {
            if ($request->level < $model->level) {
                $lowest_stages = DynamicContent::where('type', $model->type)->where('community_id', $model->community_id)->where('level', '<', $model->level)->where('level', '>=', $request->level)->get();

                foreach ($lowest_stages as $lowest_stage) {
                    $lowest_stage->update(['level' => $lowest_stage->level + 1]);
                }
            }

            if ($request->level > $model->level) {
                $lowest_stages = DynamicContent::where('type', $model->type)->where('community_id', $model->community_id)->where('level', '>', $model->level)->where('level', '<=', $request->level)->get();

                foreach ($lowest_stages as $lowest_stage) {
                    $lowest_stage->update(['level' => $lowest_stage->level - 1]);
                }
            }
        }

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);

        if ($request->content_type === 'category') {
            $model->source_type = 'ad_category';
        }

        // ? Executing
        try {

            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
        }

        // * Handle Dynamic Content Cubes
        if ($request->source_type == 'cube' && $request->dynamic_content_cubes) {
            // Handle both array and string formats
            $cubes = [];
            if (is_array($request->dynamic_content_cubes)) {
                $cubes = $request->dynamic_content_cubes;
            } elseif (is_string($request->dynamic_content_cubes)) {
                $cubes = explode(',', $request->dynamic_content_cubes);
            } else {
                $cubes = [$request->dynamic_content_cubes];
            }

            // Clean up the array
            $cubes = array_filter(array_map('intval', $cubes), function ($cube) {
                return $cube > 0;
            });

            if (!empty($cubes)) {
                $prepareDynamicContentCubes = [];

                // Delete existing cubes first
                DynamicContentCube::where('dynamic_content_id', $model->id)->delete();

                foreach ($cubes as $item) {
                    $prepareDynamicContentCubes[] = [
                        'dynamic_content_id' => $model->id,
                        'cube_id' => intval($item),
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now()
                    ];
                }

                try {
                    DynamicContentCube::insert($prepareDynamicContentCubes);
                } catch (\Throwable $th) {
                    DB::rollBack();
                    return response([
                        "message" => "Error: failed to insert new dynamic content cubes - " . $th->getMessage(),
                    ], 500);
                }
            }
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $model
        ]);
    }

    // ===============================================>
    // ## Remove the specified resource from storage.
    // ===============================================>
    public function destroy(string $id)
    {
        // ? Initial
        $model = DynamicContent::findOrFail($id);

        // ? Executing
        try {
            $model->delete();
        } catch (\Throwable $th) {
            return response([
                "message" => "Error: server side having problem!"
            ], 500);
        }

        return response([
            "message" => "Success",
            "data" => $model
        ]);
    }
}
