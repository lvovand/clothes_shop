<?php

namespace App\Http\Controllers;

use App\Models\WishlistItem;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WishlistController extends Controller
{
    public function index()
    {
        $token = request()->cookie('wishlist_token');
        $items = $token
            ? WishlistItem::where('token', $token)->with('product.images', 'product.variants')->get()
            : collect();

        return view('wishlist', ['items' => $items, 'title' => 'Избранное']);
    }

    public function toggle(Request $request)
    {
        $data = $request->validate(['product_id' => ['required', 'exists:products,id']]);

        $token = $request->cookie('wishlist_token') ?: (string) Str::uuid();

        $existing = WishlistItem::where('token', $token)->where('product_id', $data['product_id'])->first();

        if ($existing) {
            $existing->delete();
            $added = false;
        } else {
            WishlistItem::create(['token' => $token, 'product_id' => $data['product_id']]);
            $added = true;
        }

        $count = WishlistItem::where('token', $token)->count();

        // Сердечко — обычная форма (как у эталона), а без перезагрузки её отправляет
        // storefront.js. Если скрипт ещё не успел подключиться и форма ушла обычным
        // POST-ом, возвращаем на страницу, а не JSON — поведение как у эталона.
        if (! $request->wantsJson()) {
            return back()->cookie('wishlist_token', $token, 60 * 24 * 365);
        }

        return response()->json(['ok' => true, 'added' => $added, 'count' => $count])
            ->cookie('wishlist_token', $token, 60 * 24 * 365);
    }
}
