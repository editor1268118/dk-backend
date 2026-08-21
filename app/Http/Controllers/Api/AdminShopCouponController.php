<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\AdminActionLog;
use Illuminate\Http\Request;

class AdminShopCouponController extends Controller
{
    /**
     * GET /api/admin/shop/coupons
     * List all coupons with search, filters, and pagination.
     */
    public function index(Request $request)
    {
        $query = Coupon::query();

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filters
        if ($request->filled('discount_type')) {
            $query->where('discount_type', $request->discount_type);
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $allowedSorts = ['id', 'code', 'discount_type', 'discount_value', 'used_count', 'is_active', 'created_at', 'starts_at', 'ends_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDirection === 'asc' ? 'asc' : 'desc');
        }

        // Pagination
        $perPage = min((int) $request->input('per_page', 20), 100);
        $coupons = $query->paginate($perPage);

        $items = collect($coupons->items())->map(function ($coupon) {
            return [
                'id'                 => $coupon->id,
                'code'               => $coupon->code,
                'name'               => $coupon->name,
                'discount_type'      => $coupon->discount_type,
                'discount_value'     => $coupon->discount_value,
                'min_order_amount'   => $coupon->min_order_amount,
                'max_discount_amount'=> $coupon->max_discount_amount,
                'usage_limit'        => $coupon->usage_limit,
                'used_count'         => $coupon->used_count,
                'per_user_limit'     => $coupon->per_user_limit,
                'starts_at'          => $coupon->starts_at,
                'ends_at'            => $coupon->ends_at,
                'is_active'          => $coupon->is_active,
                'created_at'         => $coupon->created_at,
            ];
        });

        return response()->json([
            'coupons' => $items,
            'pagination' => [
                'current_page' => $coupons->currentPage(),
                'last_page'    => $coupons->lastPage(),
                'per_page'     => $coupons->perPage(),
                'total'        => $coupons->total(),
            ],
        ], 200);
    }

    /**
     * POST /api/admin/shop/coupons
     * Create a new coupon.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'               => 'required|string|max:50|unique:coupons,code',
            'name'               => 'nullable|string|max:255',
            'description'        => 'nullable|string',
            'discount_type'      => 'required|string|in:' . implode(',', Coupon::DISCOUNT_TYPES),
            'discount_value'     => 'required|numeric|min:0.01',
            'min_order_amount'   => 'nullable|numeric|min:0',
            'max_discount_amount'=> 'nullable|numeric|min:0',
            'usage_limit'        => 'nullable|integer|min:1',
            'per_user_limit'     => 'nullable|integer|min:1',
            'starts_at'          => 'nullable|date',
            'ends_at'            => 'nullable|date|after:starts_at',
            'is_active'          => 'nullable|boolean',
        ]);

        // Enforce discount_value constraints
        if ($validated['discount_type'] === Coupon::TYPE_PERCENTAGE && $validated['discount_value'] > 100) {
            return response()->json(['message' => 'Percentage discount cannot exceed 100.'], 422);
        }

        $coupon = Coupon::create([
            'code'               => strtoupper($validated['code']),
            'name'               => $validated['name'] ?? null,
            'description'        => $validated['description'] ?? null,
            'discount_type'      => $validated['discount_type'],
            'discount_value'     => $validated['discount_value'],
            'min_order_amount'   => $validated['min_order_amount'] ?? 0,
            'max_discount_amount'=> $validated['max_discount_amount'] ?? null,
            'usage_limit'        => $validated['usage_limit'] ?? null,
            'per_user_limit'     => $validated['per_user_limit'] ?? 1,
            'starts_at'          => $validated['starts_at'] ?? null,
            'ends_at'            => $validated['ends_at'] ?? null,
            'is_active'          => $validated['is_active'] ?? true,
            'created_by'         => $request->user()->id,
        ]);

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'coupon_created',
            'coupon',
            $coupon->id,
            "Coupon '{$coupon->code}' created.",
            null,
            null,
            $coupon->toArray()
        );

        return response()->json([
            'message' => 'Coupon created successfully.',
            'coupon'  => $coupon,
        ], 201);
    }

    /**
     * GET /api/admin/shop/coupons/{id}
     * Show coupon detail.
     */
    public function show(Request $request, $id)
    {
        $coupon = Coupon::withCount('usages')->find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        return response()->json([
            'coupon' => $coupon,
        ], 200);
    }

    /**
     * POST /api/admin/shop/coupons/{id}/update
     * Update a coupon.
     */
    public function update(Request $request, $id)
    {
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        $validated = $request->validate([
            'code'               => 'sometimes|string|max:50|unique:coupons,code,' . $coupon->id,
            'name'               => 'nullable|string|max:255',
            'description'        => 'nullable|string',
            'discount_type'      => 'sometimes|string|in:' . implode(',', Coupon::DISCOUNT_TYPES),
            'discount_value'     => 'sometimes|numeric|min:0.01',
            'min_order_amount'   => 'nullable|numeric|min:0',
            'max_discount_amount'=> 'nullable|numeric|min:0',
            'usage_limit'        => 'nullable|integer|min:1',
            'per_user_limit'     => 'nullable|integer|min:1',
            'starts_at'          => 'nullable|date',
            'ends_at'            => 'nullable|date|after:starts_at',
            'is_active'          => 'nullable|boolean',
        ]);

        $discountType = $validated['discount_type'] ?? $coupon->discount_type;
        $discountValue = $validated['discount_value'] ?? $coupon->discount_value;

        if ($discountType === Coupon::TYPE_PERCENTAGE && $discountValue > 100) {
            return response()->json(['message' => 'Percentage discount cannot exceed 100.'], 422);
        }

        $oldValues = $coupon->toArray();

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }

        $coupon->update($validated);

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'coupon_updated',
            'coupon',
            $coupon->id,
            "Coupon '{$coupon->code}' updated.",
            null,
            $oldValues,
            $coupon->fresh()->toArray()
        );

        return response()->json([
            'message' => 'Coupon updated successfully.',
            'coupon'  => $coupon->fresh(),
        ], 200);
    }

    /**
     * POST /api/admin/shop/coupons/{id}/toggle-status
     * Toggle coupon active status.
     */
    public function toggleStatus(Request $request, $id)
    {
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        $oldStatus = $coupon->is_active;
        $coupon->update(['is_active' => !$coupon->is_active]);

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'coupon_toggled',
            'coupon',
            $coupon->id,
            "Coupon '{$coupon->code}' " . ($coupon->is_active ? 'activated' : 'deactivated') . ".",
            null,
            ['is_active' => $oldStatus],
            ['is_active' => $coupon->is_active]
        );

        return response()->json([
            'message' => 'Coupon status toggled.',
            'coupon'  => [
                'id'        => $coupon->id,
                'code'      => $coupon->code,
                'is_active' => $coupon->is_active,
            ],
        ], 200);
    }

    /**
     * DELETE /api/admin/shop/coupons/{id}
     * Delete coupon. If already used, deactivate instead.
     */
    public function destroy(Request $request, $id)
    {
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        // If coupon has been used, deactivate instead of deleting
        if ($coupon->used_count > 0) {
            $coupon->update(['is_active' => false]);

            AdminActionLog::record(
                $request->user()->id,
                'coupon_deactivated_instead_of_deleted',
                'coupon',
                $coupon->id,
                "Coupon '{$coupon->code}' deactivated instead of deleted (already used {$coupon->used_count} times).",
            );

            return response()->json([
                'message' => 'Coupon has been used and cannot be deleted. It has been deactivated instead.',
                'coupon'  => [
                    'id'        => $coupon->id,
                    'code'      => $coupon->code,
                    'is_active' => false,
                ],
            ], 200);
        }

        AdminActionLog::record(
            $request->user()->id,
            'coupon_deleted',
            'coupon',
            $coupon->id,
            "Coupon '{$coupon->code}' deleted.",
        );

        $coupon->delete();

        return response()->json([
            'message' => 'Coupon deleted successfully.',
        ], 200);
    }
}
