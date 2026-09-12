<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null,
            'vendor_id' => $this->vendor_id,
            'vendor' => new VendorResource($this->whenLoaded('vendor')),
            'bill_number' => $this->bill_number,
            'bill_date' => $this->bill_date?->format('Y-m-d'),
            'subtotal' => (float) $this->subtotal,
            'grand_total' => (float) $this->grand_total,
            'status' => $this->status,
            'items' => BillItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
