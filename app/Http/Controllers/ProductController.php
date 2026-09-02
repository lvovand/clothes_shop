<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\PrivateCatalog;

class ProductController extends Controller
{
    public function show(Product $product)
    {
        // Товар из закрытой категории по прямой ссылке не открывается — только
        // после ввода промокода на странице самой категории.
        abort_unless(PrivateCatalog::allowsProduct($product), 404);

        $product->load(['images', 'contentBlocks', 'variants.attributeValues.attribute', 'category']);
        $isPrivate = $product->isPrivate();

        $cover = $product->images->first();

        return view('product', [
            'product' => $product,
            'title' => $product->name,
            // Товар закрытой категории — только для просмотра: кнопки покупки нет.
            'purchasable' => ! $isPrivate,
            'metaRobots' => $isPrivate ? 'noindex, nofollow' : null,
            'metaDescription' => $product->meta_description,
            // При отправке ссылки на товар в мессенджер логичнее показать фото
            // товара, а не логотип бренда.
            'ogType' => 'product',
            'ogImage' => $cover ? asset('storage/'.$cover->path) : null,
        ]);
    }
}
