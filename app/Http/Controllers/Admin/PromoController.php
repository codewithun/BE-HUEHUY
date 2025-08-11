<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promo;
use Illuminate\Http\Request;

class PromoController extends Controller
{
    public function index()
    {
        $promos = Promo::all();
        return response()->json($promos);
    }

    public function show($id)
    {
        $promo = Promo::find($id);
        if (!$promo) {
            return response()->json(['message' => 'Promo not found'], 404);
        }
        return response()->json($promo);
    }

    public function store(Request $request)
    {
        $promo = Promo::create($request->all());
        return response()->json($promo, 201);
    }

    public function update(Request $request, $id)
    {
        $promo = Promo::find($id);
        if (!$promo) {
            return response()->json(['message' => 'Promo not found'], 404);
        }
        $promo->update($request->all());
        return response()->json($promo);
    }

    public function destroy($id)
    {
        $promo = Promo::find($id);
        if (!$promo) {
            return response()->json(['message' => 'Promo not found'], 404);
        }
        $promo->delete();
        return response()->json(['message' => 'Promo deleted']);
    }
}
