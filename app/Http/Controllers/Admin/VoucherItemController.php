<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VoucherItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VoucherItemController extends Controller
{
    /**
     * Display a listing of voucher items
     */
    public function index(Request $request)
    {
        $query = VoucherItem::with(['voucher', 'voucher.community', 'user']);

        // Filter by user if specified
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by authenticated user if no user_id specified
        if (!$request->has('user_id') && Auth::check()) {
            $query->where('user_id', Auth::id());
        }

        // Search functionality
        if ($request->has('search') && $request->search) {
            $query->whereHas('voucher', function($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%');
            });
        }

        // Sorting
        $sortBy = $request->get('sortBy', 'created_at');
        $sortDirection = $request->get('sortDirection', 'DESC');
        $query->orderBy($sortBy, $sortDirection);

        // Pagination
        $paginate = $request->get('paginate', 15);
        $result = $query->paginate($paginate);

        return response([
            'success' => true,
            'data' => $result->items(),
            'total_row' => $result->total(),
            'current_page' => $result->currentPage(),
            'last_page' => $result->lastPage()
        ]);
    }

    /**
     * Display the specified voucher item
     */
    public function show(string $id)
    {
        $voucherItem = VoucherItem::with(['voucher', 'voucher.community', 'user'])
            ->findOrFail($id);

        return response([
            'success' => true,
            'data' => $voucherItem
        ]);
    }

    /**
     * Update the specified voucher item (e.g., mark as used)
     */
    public function update(Request $request, string $id)
    {
        $voucherItem = VoucherItem::findOrFail($id);

        $validation = $this->validation($request->all(), [
            'used_at' => 'nullable|date',
        ]);
        if ($validation) return $validation;

        $voucherItem->fill($request->only(['used_at']));
        $voucherItem->save();

        return response([
            'success' => true,
            'data' => $voucherItem
        ]);
    }

    /**
     * Remove the specified voucher item
     */
    public function destroy(string $id)
    {
        $voucherItem = VoucherItem::findOrFail($id);
        $voucherItem->delete();

        return response([
            'success' => true,
            'data' => $voucherItem
        ]);
    }
}
