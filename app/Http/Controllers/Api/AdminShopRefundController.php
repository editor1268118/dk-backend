<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopRefund;
use App\Models\ShopOrder;
use App\Models\AdminActionLog;
use Illuminate\Http\Request;

class AdminShopRefundController extends Controller
{
    /**
     * GET /api/admin/shop/refunds
     * List all refunds with filters and pagination.
     */
    public function index(Request $request)
    {
        $query = ShopRefund::with([
            'user:id,name,email,phone',
            'shopOrder:id,order_number',
            'returnRequest:id,return_number',
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
                $q->where('refund_number', 'like', "%{$search}%")
                  ->orWhere('refund_reference', 'like', "%{$search}%")
                  ->orWhereHas('shopOrder', function ($oq) use ($search) {
                      $oq->where('order_number', 'like', "%{$search}%");
                  })
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $query->orderByDesc('created_at');

        $perPage = min((int) $request->input('per_page', 20), 100);
        $refunds = $query->paginate($perPage);

        $items = collect($refunds->items())->map(function ($refund) {
            return [
                'id'               => $refund->id,
                'refund_number'    => $refund->refund_number,
                'order_number'     => $refund->shopOrder?->order_number,
                'return_number'    => $refund->returnRequest?->return_number,
                'customer'         => [
                    'id'    => $refund->user?->id,
                    'name'  => $refund->user?->name,
                    'email' => $refund->user?->email,
                    'phone' => $refund->user?->phone,
                ],
                'amount'           => $refund->amount,
                'status'           => $refund->status,
                'refund_method'    => $refund->refund_method,
                'refund_reference' => $refund->refund_reference,
                'created_at'       => $refund->created_at,
                'processed_at'     => $refund->processed_at,
            ];
        });

        return response()->json([
            'refunds' => $items,
            'pagination' => [
                'current_page' => $refunds->currentPage(),
                'last_page'    => $refunds->lastPage(),
                'per_page'     => $refunds->perPage(),
                'total'        => $refunds->total(),
            ],
        ], 200);
    }

    /**
     * GET /api/admin/shop/refunds/{id}
     * Show refund detail.
     */
    public function show(Request $request, $id)
    {
        $refund = ShopRefund::with([
            'user:id,name,email,phone',
            'shopOrder:id,order_number,total_amount,order_status',
            'returnRequest:id,return_number,status',
            'processor:id,name',
        ])->find($id);

        if (!$refund) {
            return response()->json(['message' => 'Refund not found.'], 404);
        }

        return response()->json([
            'refund' => [
                'id'               => $refund->id,
                'refund_number'    => $refund->refund_number,
                'order'            => [
                    'id'           => $refund->shopOrder?->id,
                    'order_number' => $refund->shopOrder?->order_number,
                    'total_amount' => $refund->shopOrder?->total_amount,
                    'order_status' => $refund->shopOrder?->order_status,
                ],
                'return'           => $refund->returnRequest ? [
                    'id'            => $refund->returnRequest->id,
                    'return_number' => $refund->returnRequest->return_number,
                    'status'        => $refund->returnRequest->status,
                ] : null,
                'customer'         => [
                    'id'    => $refund->user?->id,
                    'name'  => $refund->user?->name,
                    'email' => $refund->user?->email,
                    'phone' => $refund->user?->phone,
                ],
                'amount'           => $refund->amount,
                'status'           => $refund->status,
                'refund_method'    => $refund->refund_method,
                'refund_reference' => $refund->refund_reference,
                'admin_note'       => $refund->admin_note,
                'processed_at'     => $refund->processed_at,
                'processed_by'     => $refund->processor?->name,
                'created_at'       => $refund->created_at,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/shop/refunds/{id}/update-status
     * Update refund status (mock — no real payment processing).
     */
    public function updateStatus(Request $request, $id)
    {
        $refund = ShopRefund::with('shopOrder')->find($id);

        if (!$refund) {
            return response()->json(['message' => 'Refund not found.'], 404);
        }

        $validated = $request->validate([
            'status'           => 'required|string|in:' . implode(',', ShopRefund::STATUSES),
            'refund_method'    => 'nullable|string|max:100',
            'refund_reference' => 'nullable|string|max:255',
            'admin_note'       => 'nullable|string|max:2000',
        ]);

        $oldStatus = $refund->status;
        $updateData = [
            'status'           => $validated['status'],
            'refund_method'    => $validated['refund_method'] ?? $refund->refund_method,
            'refund_reference' => $validated['refund_reference'] ?? $refund->refund_reference,
            'admin_note'       => $validated['admin_note'] ?? $refund->admin_note,
        ];

        // If status is processed, set processed timestamp and processor
        if ($validated['status'] === ShopRefund::STATUS_PROCESSED) {
            $updateData['processed_at'] = now();
            $updateData['processed_by'] = $request->user()->id;
        }

        $refund->update($updateData);

        // Update related order refund_status
        if ($refund->shopOrder) {
            $refund->shopOrder->update([
                'refund_status' => $validated['status'],
            ]);
        }

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'refund_status_updated',
            'shop_refund',
            $refund->id,
            "Refund {$refund->refund_number} status changed from '{$oldStatus}' to '{$validated['status']}'.",
            null,
            ['status' => $oldStatus],
            ['status' => $validated['status']]
        );

        return response()->json([
            'message' => "Refund status updated to '{$validated['status']}'.",
            'refund'  => [
                'id'               => $refund->id,
                'refund_number'    => $refund->refund_number,
                'status'           => $refund->status,
                'refund_method'    => $refund->refund_method,
                'refund_reference' => $refund->refund_reference,
                'processed_at'     => $refund->processed_at,
            ],
        ], 200);
    }
}
