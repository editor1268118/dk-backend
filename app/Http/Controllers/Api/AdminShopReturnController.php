<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopReturnRequest;
use App\Models\ShopOrder;
use App\Models\ShopRefund;
use App\Models\Product;
use App\Models\ProductInventoryLog;
use App\Models\AdminActionLog;
use Illuminate\Http\Request;

class AdminShopReturnController extends Controller
{
    /**
     * GET /api/admin/shop/returns
     * List all return requests with filters and pagination.
     */
    public function index(Request $request)
    {
        $query = ShopReturnRequest::with([
            'user:id,name,email,phone',
            'shopOrder:id,order_number,total_amount',
        ]);

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Date filters
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // User filter
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('return_number', 'like', "%{$search}%")
                  ->orWhereHas('shopOrder', function ($oq) use ($search) {
                      $oq->where('order_number', 'like', "%{$search}%");
                  })
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%");
                  });
            });
        }

        $query->orderByDesc('created_at');

        $perPage = min((int) $request->input('per_page', 20), 100);
        $returns = $query->paginate($perPage);

        $items = collect($returns->items())->map(function ($ret) {
            return [
                'id'                      => $ret->id,
                'return_number'           => $ret->return_number,
                'order_number'            => $ret->shopOrder?->order_number,
                'customer'                => [
                    'id'    => $ret->user?->id,
                    'name'  => $ret->user?->name,
                    'email' => $ret->user?->email,
                    'phone' => $ret->user?->phone,
                ],
                'status'                  => $ret->status,
                'refund_amount_requested' => $ret->refund_amount_requested,
                'refund_amount_approved'  => $ret->refund_amount_approved,
                'requested_at'            => $ret->requested_at,
                'created_at'              => $ret->created_at,
            ];
        });

        return response()->json([
            'returns' => $items,
            'pagination' => [
                'current_page' => $returns->currentPage(),
                'last_page'    => $returns->lastPage(),
                'per_page'     => $returns->perPage(),
                'total'        => $returns->total(),
            ],
        ], 200);
    }

    /**
     * GET /api/admin/shop/returns/{id}
     * Show return request detail.
     */
    public function show(Request $request, $id)
    {
        $returnRequest = ShopReturnRequest::with([
            'user:id,name,email,phone',
            'shopOrder:id,order_number,total_amount,order_status',
            'items.orderItem',
            'items.product:id,name,main_image',
            'refunds',
            'resolver:id,name',
        ])->find($id);

        if (!$returnRequest) {
            return response()->json(['message' => 'Return request not found.'], 404);
        }

        $items = $returnRequest->items->map(function ($item) {
            return [
                'id'                  => $item->id,
                'shop_order_item_id'  => $item->shop_order_item_id,
                'product_id'          => $item->product_id,
                'product_name'        => $item->orderItem?->product_name ?? $item->product?->name,
                'quantity'            => $item->quantity,
                'reason'              => $item->reason,
                'unit_price'          => $item->unit_price,
                'line_total'          => $item->line_total,
            ];
        });

        return response()->json([
            'return_request' => [
                'id'                      => $returnRequest->id,
                'return_number'           => $returnRequest->return_number,
                'order'                   => [
                    'id'           => $returnRequest->shopOrder?->id,
                    'order_number' => $returnRequest->shopOrder?->order_number,
                    'total_amount' => $returnRequest->shopOrder?->total_amount,
                    'order_status' => $returnRequest->shopOrder?->order_status,
                ],
                'customer'                => [
                    'id'    => $returnRequest->user?->id,
                    'name'  => $returnRequest->user?->name,
                    'email' => $returnRequest->user?->email,
                    'phone' => $returnRequest->user?->phone,
                ],
                'status'                  => $returnRequest->status,
                'reason'                  => $returnRequest->reason,
                'customer_note'           => $returnRequest->customer_note,
                'admin_note'              => $returnRequest->admin_note,
                'refund_amount_requested' => $returnRequest->refund_amount_requested,
                'refund_amount_approved'  => $returnRequest->refund_amount_approved,
                'items'                   => $items,
                'refunds'                 => $returnRequest->refunds,
                'resolved_by'             => $returnRequest->resolver?->name,
                'requested_at'            => $returnRequest->requested_at,
                'approved_at'             => $returnRequest->approved_at,
                'rejected_at'             => $returnRequest->rejected_at,
                'returned_at'             => $returnRequest->returned_at,
                'created_at'              => $returnRequest->created_at,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/shop/returns/{id}/approve
     * Approve a return request and create a refund record.
     */
    public function approve(Request $request, $id)
    {
        $returnRequest = ShopReturnRequest::with('shopOrder')->find($id);

        if (!$returnRequest) {
            return response()->json(['message' => 'Return request not found.'], 404);
        }

        if ($returnRequest->status !== ShopReturnRequest::STATUS_REQUESTED) {
            return response()->json(['message' => 'Only return requests with status "requested" can be approved.'], 422);
        }

        $validated = $request->validate([
            'refund_amount_approved' => 'required|numeric|min:0',
            'admin_note'             => 'nullable|string|max:2000',
        ]);

        if ($validated['refund_amount_approved'] > (float) $returnRequest->refund_amount_requested) {
            return response()->json(['message' => 'Approved refund amount cannot exceed requested amount.'], 422);
        }

        // Update return request
        $returnRequest->update([
            'status'                => ShopReturnRequest::STATUS_APPROVED,
            'refund_amount_approved'=> $validated['refund_amount_approved'],
            'admin_note'            => $validated['admin_note'] ?? $returnRequest->admin_note,
            'approved_at'           => now(),
            'resolved_by'           => $request->user()->id,
        ]);

        // Update order return/refund status
        $returnRequest->shopOrder->update([
            'return_status' => ShopOrder::RETURN_APPROVED,
            'refund_status' => ShopOrder::REFUND_APPROVED,
        ]);

        // Create refund record
        $refund = ShopRefund::create([
            'refund_number'          => ShopRefund::generateRefundNumber(),
            'shop_order_id'          => $returnRequest->shop_order_id,
            'shop_return_request_id' => $returnRequest->id,
            'user_id'                => $returnRequest->user_id,
            'amount'                 => $validated['refund_amount_approved'],
            'status'                 => ShopRefund::STATUS_APPROVED,
            'admin_note'             => $validated['admin_note'] ?? null,
        ]);

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'return_approved',
            'shop_return_request',
            $returnRequest->id,
            "Return {$returnRequest->return_number} approved. Refund amount: {$validated['refund_amount_approved']}.",
            null,
            ['status' => ShopReturnRequest::STATUS_REQUESTED],
            ['status' => ShopReturnRequest::STATUS_APPROVED, 'refund_amount_approved' => $validated['refund_amount_approved']]
        );

        return response()->json([
            'message'        => 'Return request approved.',
            'return_request' => [
                'id'                     => $returnRequest->id,
                'return_number'          => $returnRequest->return_number,
                'status'                 => $returnRequest->status,
                'refund_amount_approved' => $returnRequest->refund_amount_approved,
                'approved_at'            => $returnRequest->approved_at,
            ],
            'refund' => [
                'id'            => $refund->id,
                'refund_number' => $refund->refund_number,
                'amount'        => $refund->amount,
                'status'        => $refund->status,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/shop/returns/{id}/reject
     * Reject a return request.
     */
    public function reject(Request $request, $id)
    {
        $returnRequest = ShopReturnRequest::with('shopOrder')->find($id);

        if (!$returnRequest) {
            return response()->json(['message' => 'Return request not found.'], 404);
        }

        if ($returnRequest->status !== ShopReturnRequest::STATUS_REQUESTED) {
            return response()->json(['message' => 'Only return requests with status "requested" can be rejected.'], 422);
        }

        $validated = $request->validate([
            'admin_note' => 'nullable|string|max:2000',
        ]);

        $returnRequest->update([
            'status'      => ShopReturnRequest::STATUS_REJECTED,
            'admin_note'  => $validated['admin_note'] ?? $returnRequest->admin_note,
            'rejected_at' => now(),
            'resolved_by' => $request->user()->id,
        ]);

        $returnRequest->shopOrder->update([
            'return_status' => ShopOrder::RETURN_REJECTED,
            'refund_status' => ShopOrder::REFUND_REJECTED,
        ]);

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'return_rejected',
            'shop_return_request',
            $returnRequest->id,
            "Return {$returnRequest->return_number} rejected.",
            null,
            ['status' => ShopReturnRequest::STATUS_REQUESTED],
            ['status' => ShopReturnRequest::STATUS_REJECTED]
        );

        return response()->json([
            'message'        => 'Return request rejected.',
            'return_request' => [
                'id'            => $returnRequest->id,
                'return_number' => $returnRequest->return_number,
                'status'        => $returnRequest->status,
                'admin_note'    => $returnRequest->admin_note,
                'rejected_at'   => $returnRequest->rejected_at,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/shop/returns/{id}/mark-returned
     * Mark a return as returned and restore stock.
     */
    public function markReturned(Request $request, $id)
    {
        $returnRequest = ShopReturnRequest::with(['shopOrder', 'items'])->find($id);

        if (!$returnRequest) {
            return response()->json(['message' => 'Return request not found.'], 404);
        }

        if ($returnRequest->status !== ShopReturnRequest::STATUS_APPROVED) {
            return response()->json(['message' => 'Only approved return requests can be marked as returned.'], 422);
        }

        // Mark as returned
        $returnRequest->update([
            'status'      => ShopReturnRequest::STATUS_RETURNED,
            'returned_at' => now(),
        ]);

        // Update order
        $returnRequest->shopOrder->update([
            'return_status' => ShopOrder::RETURN_RETURNED,
        ]);

        // Restore stock for returned items
        foreach ($returnRequest->items as $returnItem) {
            if (!$returnItem->product_id) continue;

            $product = Product::find($returnItem->product_id);
            if (!$product) continue;

            $quantityBefore = $product->stock_quantity;
            $quantityAfter = $quantityBefore + $returnItem->quantity;

            $product->update(['stock_quantity' => $quantityAfter]);

            ProductInventoryLog::create([
                'product_id'       => $product->id,
                'change_type'      => ProductInventoryLog::TYPE_RETURN_RESTORE,
                'quantity_before'  => $quantityBefore,
                'quantity_after'   => $quantityAfter,
                'quantity_changed' => $returnItem->quantity,
                'reason'           => "Stock restored — return {$returnRequest->return_number} marked as returned.",
                'admin_user_id'    => $request->user()->id,
            ]);
        }

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'return_marked_returned',
            'shop_return_request',
            $returnRequest->id,
            "Return {$returnRequest->return_number} marked as returned. Stock restored.",
            null,
            ['status' => ShopReturnRequest::STATUS_APPROVED],
            ['status' => ShopReturnRequest::STATUS_RETURNED]
        );

        return response()->json([
            'message'        => 'Return marked as returned. Stock restored.',
            'return_request' => [
                'id'            => $returnRequest->id,
                'return_number' => $returnRequest->return_number,
                'status'        => $returnRequest->status,
                'returned_at'   => $returnRequest->returned_at,
            ],
        ], 200);
    }
}
