<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Catalog\ProductViews;
use App\Services\Catalog\RelatedProducts;
use App\Support\PrivateCatalog;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function show(Product $product, Request $request, RelatedProducts $related, ProductViews $views)
    {
        // Товар из закрытой категории по прямой ссылке не открывается — только
        // после ввода промокода на странице самой категории.
        abort_unless(PrivateCatalog::allowsProduct($product), 404);

        $product->load(['images', 'contentBlocks', 'variants.attributeValues.attribute', 'category']);
        $isPrivate = $product->isPrivate();

        $views->record($product, $request);

        $cover = $product->images->first();

        return view('product', [
            'product' => $product,
            'title' => $product->name,
            // Товар закрытой категории — только для просмотра: кнопки покупки нет.
            'purchasable' => ! $isPrivate,
            'relatedProducts' => $related->for($product),
            'metaRobots' => $isPrivate ? 'noindex, nofollow' : null,
            'metaDescription' => $product->meta_description,
            // При отправке ссылки на товар в мессенджер логичнее показать фото
            // товара, а не логотип бренда.
            'ogType' => 'product',
            'ogImage' => $cover ? asset('storage/'.$cover->path) : null,
        ]);
    }
}
