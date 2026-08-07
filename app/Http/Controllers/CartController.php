<?php

namespace App\Http\Controllers;

use App\Models\Variant;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function add(Request $request)
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'exists:variants,id'],
            'qty' => ['nullable', 'integer', 'min:1'],
        ]);

        $variant = Variant::findOrFail($validated['variant_id']);
        if (! $variant->inStock()) {
            return $this->respond($request, false, 'Товара нет в наличии');
        }

        $cart = session('cart', []);
        $qty = $validated['qty'] ?? 1;
        $cart[$variant->id] = ($cart[$variant->id] ?? 0) + $qty;
        session(['cart' => $cart]);

        return $this->respond($request, true, 'Товар добавлен в корзину');
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'exists:variants,id'],
            'qty' => ['required', 'integer', 'min:0'],
        ]);

        $cart = session('cart', []);
        if ($validated['qty'] === 0) {
            unset($cart[$validated['variant_id']]);
        } else {
            $cart[$validated['variant_id']] = $validated['qty'];
        }
        session(['cart' => $cart]);

        return $this->respond($request, true, 'Корзина обновлена');
    }

    public function remove(Request $request)
    {
        $validated = $request->validate(['variant_id' => ['required']]);
        $cart = session('cart', []);
        unset($cart[$validated['variant_id']]);
        session(['cart' => $cart]);

        return $this->respond($request, true, 'Товар удалён из корзины');
    }

    private function respond(Request $request, bool $ok, string $message)
    {
        $cart = session('cart', []);

        if ($request->wantsJson()) {
            // Checkout re-renders totals from this in place (no page reload) so that
            // qty/remove edits there don't wipe out whatever the customer already typed
            // into the name/address/etc. fields.
            $variants = Variant::whereIn('id', array_keys($cart))->get()->keyBy('id');
            $subtotal = 0.0;
            $items = [];
            foreach ($cart as $variantId => $qty) {
                $variant = $variants->get($variantId);
                if (! $variant) {
                    continue;
                }
                $lineTotal = $variant->currentPrice() * $qty;
                $subtotal += $lineTotal;
                $items[] = ['variant_id' => $variant->id, 'qty' => $qty, 'line_total' => $lineTotal];
            }

            return response()->json([
                'ok' => $ok,
                'message' => $message,
                'count' => array_sum($cart),
                'subtotal' => $subtotal,
                'items' => $items,
            ], $ok ? 200 : 422);
        }

        return back()->with($ok ? 'status' : 'error', $message);
    }
}
