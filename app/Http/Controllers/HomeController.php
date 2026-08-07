<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\HomepageSlide;
use App\Models\Product;
use App\Models\SiteSetting;

class HomeController extends Controller
{
    public function index()
    {
        $desktopSlides = HomepageSlide::desktop()->where('is_active', true)->orderBy('sort_order')->get();
        $mobileSlides = HomepageSlide::mobile()->where('is_active', true)->orderBy('sort_order')->get();
        $newProducts = Product::published()->where('is_new', true)
            ->with(['images', 'variants'])
            ->orderBy('sort_order')
            ->limit(8)
            ->get();

        // Плитка SHOP: категории из админки — порядок задаётся перетаскиванием в
        // «Каталог → Категории», картинка ячейки — полем «Изображение» у самой
        // категории, количество ячеек — настройкой «Настройки → Сайт».
        // Раньше первая ячейка («весь каталог») с картинкой была захардкожена здесь —
        // из-за этого после появления виртуальной категории ALL плитка ALL выводилась
        // дважды, а её картинку нельзя было поменять из админки.
        $shopTiles = Category::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit((int) SiteSetting::get('home_shop_tiles_count', 4))
            ->get()
            ->map(fn (Category $category) => [
                'name' => $category->name,
                'url' => $category->url(),
                'image' => $category->image ? asset('storage/'.$category->image) : '',
            ])
            ->all();

        return view('home', compact('desktopSlides', 'mobileSlides', 'newProducts', 'shopTiles'));
    }
}
