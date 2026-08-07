<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\GiftCardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LookbookController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\WishlistController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class.'@index')->name('home');

Route::get('/catalog', [CatalogController::class, 'all'])->name('catalog.all');
Route::get('/catalog/{category}', [CatalogController::class, 'category'])->name('catalog.category');

Route::get('/product/{product}', [ProductController::class, 'show'])->name('product.show');

// Must come before the catch-all /{slug} page route below.
Route::get('/search', [SearchController::class, 'index'])->name('search.index');
Route::get('/search/suggest', [SearchController::class, 'suggest'])->name('search.suggest');

Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
Route::post('/wishlist/toggle', [WishlistController::class, 'toggle'])->name('wishlist.toggle');

Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
Route::post('/checkout/quote', [CheckoutController::class, 'quote'])->name('checkout.quote');
Route::post('/checkout/coupon', [CheckoutController::class, 'applyCoupon'])->name('checkout.coupon');
Route::post('/checkout/gift-certificate', [CheckoutController::class, 'applyGiftCertificate'])->name('checkout.gift-certificate');
Route::post('/checkout/pickup-points', [CheckoutController::class, 'pickupPoints'])->name('checkout.pickup-points');
Route::post('/checkout/yandex-pickup-points', [CheckoutController::class, 'yandexPickupPoints'])->name('checkout.yandex-pickup-points');
Route::get('/checkout/cities', [CheckoutController::class, 'cities'])->name('checkout.cities');
Route::get('/checkout/success/{order}', [CheckoutController::class, 'success'])->name('checkout.success');
Route::get('/checkout/failed/{order}', [CheckoutController::class, 'failed'])->name('checkout.failed');

Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
Route::post('/cart/update', [CartController::class, 'update'])->name('cart.update');
Route::post('/cart/remove', [CartController::class, 'remove'])->name('cart.remove');

Route::post('/webhooks/tbank', [PaymentWebhookController::class, 'tbank'])->name('webhooks.tbank');
// Callback URL в кабинете Яндекс Пэй указывается БЕЗ хвоста /v1/webhook — его
// Яндекс добавляет сам, поэтому маршрут обязан заканчиваться именно так.
Route::post('/webhooks/yandex-pay/v1/webhook', [PaymentWebhookController::class, 'yandexPay'])->name('webhooks.yandex-pay');
Route::post('/webhooks/telegram', [TelegramWebhookController::class, 'handle'])->name('telegram.webhook');

Route::get('/lookbook', [LookbookController::class, 'index'])->name('lookbook.index');

Route::get('/gift-card', [GiftCardController::class, 'show'])->name('gift-card.show');
Route::post('/gift-card', [GiftCardController::class, 'purchase'])->name('gift-card.purchase');
Route::get('/gift-card/success/{code}', [GiftCardController::class, 'success'])->name('gift-card.success');
Route::get('/gift-card/failed/{code}', [GiftCardController::class, 'failed'])->name('gift-card.failed');

// Загрузка картинок из визуального редактора страниц в админке.
// Стоит до catch-all-маршрута страниц, иначе адрес перехватил бы PageController.
Route::get('/admin/yandex-delivery/points', \App\Http\Controllers\Admin\YandexDeliveryPointsController::class)
    ->middleware('auth')
    ->name('admin.yandex-delivery.points');

Route::post('/admin/editor/upload', \App\Http\Controllers\Admin\EditorUploadController::class)
    ->middleware('auth')
    ->name('admin.editor.upload');

Route::get('/{slug}', [PageController::class, 'show'])->name('page.show');
