<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillResource;
use App\Models\Bill;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class BillController extends Controller
{
    /**
     * Display a listing of bills for authenticated user (with advanced 5-way filters).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->bills()->with(['user', 'vendor', 'items.item']);

        // 1. Status Filter
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // 2. Vendor Filter
        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        // 3. Purchaser (User) Filter
        if ($request->filled('purchaser_id')) {
            $query->where('user_id', $request->purchaser_id);
        }

        // 4. Text Search Filter (Bill #, Vendor Name, Purchaser Name)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('bill_number', 'like', "%{$search}%")
                    ->orWhereHas('vendor', function ($vq) use ($search) {
                        $vq->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // 5. Date Range Filters (Daily, Weekly, Monthly, Custom)
        if ($request->filled('from_date')) {
            $query->whereDate('bill_date', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('bill_date', '<=', $request->to_date);
        }

        $bills = $query->latest('bill_date')->paginate($request->integer('per_page', 20));

        return BillResource::collection($bills);
    }

    /**
     * Store a newly created bill along with bill items.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => ['required', 'exists:vendors,id'],
            'bill_number' => ['required', 'string', 'max:255'],
            'bill_date' => ['required', 'date'],
            'status' => ['required', 'string', 'in:paid,unpaid,pending'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'exists:items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $bill = DB::transaction(function () use ($request, $validated) {
            $bill = $request->user()->bills()->create([
                'vendor_id' => $validated['vendor_id'],
                'bill_number' => $validated['bill_number'],
                'bill_date' => $validated['bill_date'],
                'status' => $validated['status'],
                'subtotal' => 0,
                'grand_total' => 0,
            ]);

            $subtotal = 0;

            foreach ($validated['items'] as $itemData) {
                $itemModel = Item::findOrFail($itemData['item_id']);
                $unitPrice = isset($itemData['unit_price']) ? (float) $itemData['unit_price'] : (float) $itemModel->current_price;
                $qty = (float) $itemData['quantity'];
                $totalPrice = round($qty * $unitPrice, 2);
                $subtotal += $totalPrice;

                // 1. Bill Line Item Save Karein
                $bill->items()->create([
                    'item_id' => $itemModel->id,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                ]);

                // 2. Catalog Item Price Auto-Update Karein (Naye Rate Par)
                $itemModel->update([
                    'current_price' => $unitPrice,
                ]);
            }

            $bill->update([
                'subtotal' => $subtotal,
                'grand_total' => $subtotal,
            ]);

            return $bill;
        });

        return (new BillResource($bill->load(['vendor', 'items.item'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified bill details.
     */
    public function show(Request $request, Bill $bill): BillResource
    {
        if ($bill->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized action.');
        }

        return new BillResource($bill->load(['vendor', 'items.item']));
    }

    /**
     * Update the specified bill.
     */
    public function update(Request $request, Bill $bill): BillResource
    {
        if ($bill->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'vendor_id' => ['required', 'exists:vendors,id'],
            'bill_number' => ['required', 'string', 'max:255'],
            'bill_date' => ['required', 'date'],
            'status' => ['required', 'string', 'in:paid,unpaid,pending'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.item_id' => ['required_with:items', 'exists:items,id'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($bill, $validated) {
            $bill->update([
                'vendor_id' => $validated['vendor_id'],
                'bill_number' => $validated['bill_number'],
                'bill_date' => $validated['bill_date'],
                'status' => $validated['status'],
            ]);

            if (isset($validated['items'])) {
                // Delete existing line items and recreate
                $bill->items()->delete();
                $subtotal = 0;

                foreach ($validated['items'] as $itemData) {
                    $itemModel = Item::findOrFail($itemData['item_id']);
                    $unitPrice = isset($itemData['unit_price']) ? (float) $itemData['unit_price'] : (float) $itemModel->current_price;
                    $qty = (float) $itemData['quantity'];
                    $totalPrice = round($qty * $unitPrice, 2);
                    $subtotal += $totalPrice;

                    // 1. Re-create Bill Line Item
                    $bill->items()->create([
                        'item_id' => $itemModel->id,
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice,
                    ]);

                    // 2. Catalog Item Price Auto-Update Karein
                    $itemModel->update([
                        'current_price' => $unitPrice,
                    ]);
                }

                $bill->update([
                    'subtotal' => $subtotal,
                    'grand_total' => $subtotal,
                ]);
            }
        });

        return new BillResource($bill->fresh(['vendor', 'items.item']));
    }

    /**
     * Remove the specified bill.
     */
    public function destroy(Request $request, Bill $bill): JsonResponse
    {
        if ($bill->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized action.');
        }

        $bill->delete();

        return response()->json([
            'message' => 'Bill deleted successfully',
        ]);
    }
}
