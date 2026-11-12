<?php

namespace App\Integrations;

use App\Models\ProductModel;
use App\Models\ProductOption;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Support\Money;

/**
 * A sales order as a manufacturer's ERP sees it - the same shape in the API
 * (/api/v1/sales-orders) and in webhooks, so an integration only learns it
 * once. Carries the ERP references (erp_ref, dealer_code) that match it to
 * the ERP's own records. Money as exact strings ("1234.50").
 *
 * Changing this shape breaks people's integrations: add fields, never rename
 * or remove them (docs/INTEGRATIONS.md promises that).
 */
class SalesOrderPayload
{
    public static function make(SalesOrder $o): array
    {
        $o->loadMissing(['lines', 'dealerLink.dealer', 'quote']);

        // The models and options the lines are for, for their names and ERP numbers.
        $models = ProductModel::whereIn('id', $o->lines->where('kind', 'model')->pluck('item_id')->filter())->get()->keyBy('id');
        $options = ProductOption::whereIn('id', $o->lines->where('kind', 'option')->pluck('item_id')->filter())->get()->keyBy('id');

        return [
            'id' => $o->id,
            'number' => $o->label(),
            'status' => $o->status,
            'erp_ref' => $o->erp_ref,
            'quote_number' => $o->quote?->label(),
            'currency' => $o->currency,
            'customer' => $o->customer,
            'dealer' => [
                'id' => $o->dealer_link_id,
                'name' => $o->dealerLink->dealer->name,
                'dealer_code' => $o->dealerLink->dealer_code,
            ],
            'po_number' => $o->po_number,
            'requested_date' => $o->requested_date?->toDateString(),
            'notes' => $o->notes,
            'subtotal' => Money::fromCents((int) $o->subtotal_cents),
            'discount' => Money::fromCents((int) $o->discount_cents),
            'total' => Money::fromCents((int) $o->total_cents),
            'lines' => $o->lines->map(function (SalesOrderLine $l) use ($models, $options) {
                $item = match ($l->kind) {
                    'model' => $models->get($l->item_id),
                    'option' => $options->get($l->item_id),
                    default => null,
                };

                return [
                    'kind' => $l->kind,
                    'description' => $l->description,
                    'quantity' => $l->quantity,
                    'unit_price' => Money::fromCents((int) $l->unit_price_cents),
                    'line_total' => Money::fromCents((int) $l->line_total_cents),
                    // What it is in the catalogue, and its ERP item number; null for a line the dealer typed in.
                    'item' => $item ? ['type' => $l->kind, 'id' => $item->id, 'name' => $item->name, 'erp_ref' => $item->erp_ref] : null,
                ];
            })->values()->all(),
            'cancel_reason' => $o->cancel_reason,
            'created_at' => $o->created_at?->toIso8601String(),
            'started_at' => $o->started_at?->toIso8601String(),
            'shipped_at' => $o->shipped_at?->toIso8601String(),
            'delivered_at' => $o->delivered_at?->toIso8601String(),
            'cancelled_at' => $o->cancelled_at?->toIso8601String(),
            'updated_at' => $o->updated_at?->toIso8601String(),
        ];
    }
}
