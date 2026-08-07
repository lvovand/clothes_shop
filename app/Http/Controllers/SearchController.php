<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /** Full results page — every match, or a "nothing found" message. */
    public function index(Request $request)
    {
        $query = trim((string) $request->input('q', ''));

        $products = Product::published()
            ->when($query !== '', fn ($q) => $q->where('name', 'like', '%'.$this->escapeLike($query).'%'), fn ($q) => $q->where('id', 0))
            ->with(['images', 'variants.attributeValues'])
            ->orderBy('sort_order')
            ->paginate(12)
            ->withQueryString();

        return view('search', [
            'title' => 'Поиск',
            'query' => $query,
            'products' => $products,
        ]);
    }

    /** AJAX: a handful of live-search previews for the header dropdown, matching the
     *  reference site's "AJAX Search for WooCommerce" widget — a few mini product
     *  previews plus a total count, rather than the full result set. */
    public function suggest(Request $request)
    {
        $query = trim((string) $request->input('q', ''));

        if ($query === '') {
            return response()->json(['ok' => true, 'total' => 0, 'products' => []]);
        }

        $matches = Product::published()
            ->where('name', 'like', '%'.$this->escapeLike($query).'%')
            ->with(['images', 'variants'])
            ->orderBy('sort_order')
            ->get();

        $preview = $matches->take(5)->map(fn (Product $product) => [
            'name' => $product->name,
            'url' => route('product.show', $product),
            'image' => $product->images->first() ? asset('storage/'.$product->images->first()->path) : null,
            'price' => $product->minPrice(),
        ]);

        return response()->json([
            'ok' => true,
            'total' => $matches->count(),
            'products' => $preview,
            'searchUrl' => route('search.index', ['q' => $query]),
        ]);
    }

    /** Escape LIKE's own wildcard characters so a literal "%" or "_" in a search term
     *  is matched as itself rather than acting as a SQL wildcard. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
