<?php

namespace App\Http\Controllers;

use App\Models\Product;

class ProductController extends Controller
{
    public function show(Product $product)
    {
        $product->load(['images', 'contentBlocks', 'variants.attributeValues.attribute', 'category']);

        $cover = $product->images->first();

        return view('product', [
            'product' => $product,
            'title' => $product->name,
            'metaDescription' => $product->meta_description,
            // При отправке ссылки на товар в мессенджер логичнее показать фото
            // товара, а не логотип бренда.
            'ogType' => 'product',
            'ogImage' => $cover ? asset('storage/'.$cover->path) : null,
        ]);
    }
}
