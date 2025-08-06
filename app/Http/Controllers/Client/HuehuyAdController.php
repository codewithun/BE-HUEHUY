<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\HuehuyAd;
use Illuminate\Http\Request;

class HuehuyAdController extends Controller
{
    public function index(Request $request) {
        $sortBy = $request->get("sortBy", "created_at");
        $sortDirection = $request->get("sortDirection", "DESC");

        $model = new HuehuyAd();
        $query = HuehuyAd::query();
        
        if ($request->get("search") != "") {
            $query = $this->search($request->get("search"), $model, $query);
        } else {
            $query = $query;
        }

        $query =  $query->select($model->selectable)
            ->where('type', 'screen')
            ->limit(10)
            ->inRandomOrder()
            ->get();


        return response([
            'message' => 'Success',
            'data' => $query
        ]);
    }

    public function cube_ad(Request $request) {
        $model = new HuehuyAd();
        $query = HuehuyAd::query();
        
        if ($request->get("search") != "") {
            $query = $this->search($request->get("search"), $model, $query);
        } else {
            $query = $query;
        }

        $query =  $query->select($model->selectable)
            ->where('type', 'cube')
            ->where('limit', '>', 0)
            ->inRandomOrder()
            ->first();

        if($query->limit) {
            $query->limit -= 1; 
            $query->save();
        }


        return response([
            'message' => 'Success',
            'data' => $query
        ]);
    }

    public function show($id) {
        $query = HuehuyAd::findOrFail($id);


        return response([
            'message' => 'Success',
            'data' => $query
        ]);
    }
}
