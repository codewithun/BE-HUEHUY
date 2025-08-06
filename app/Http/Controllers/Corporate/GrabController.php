<?php

namespace App\Http\Controllers\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Grab;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GrabController extends Controller
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

        $credentialUserCorporate = Auth::user()->corporate_user;

        // ? Preparation
        $columnAliases = [
            'created_at' => 'grabs.created_at'
        ];

        // ? Begin
        $model = new Grab();
        $query = Grab::with([
            'user',
            'ad', 'ad.ad_category', 'ad.cube', 'ad.cube', 'ad.cube.user', 'ad.cube.corporate', 'ad.cube.world', 'ad.cube.tags',
            'voucher_item', 'voucher_item.voucher', 'voucher_item.user'
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

        // ? Sort & executing with pagination
        $query = $query->join('ads', 'ads.id', 'grabs.ad_id')
            ->join('cubes', 'cubes.id', 'ads.cube_id')
            ->leftJoin('world_affiliates', 'world_affiliates.id', 'cubes.world_affiliate_id')
            ->where('cubes.corporate_id', $credentialUserCorporate->corporate_id)
            ->orWhere('world_affiliates.corporate_id', $credentialUserCorporate->corporate_id)
            ->orderBy($this->remark_column($sortby, $columnAliases), $sortDirection)
            ->select($model->selectable)
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
}
