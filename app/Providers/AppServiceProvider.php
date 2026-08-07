<?php

namespace App\Providers;

use App\Models\SiteSetting;
use App\Models\WishlistItem;
use App\Services\Cdek\CdekClient;
use App\Services\TBank\TBankClient;
use App\Services\YandexDelivery\YandexDeliveryClient;
use App\Services\YandexPay\YandexPayClient;
use App\Services\YandexPay\YandexPayWebhookVerifier;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CdekClient::class, fn () => new CdekClient(
            SiteSetting::get('cdek_client_id', config('services.cdek.client_id')),
            SiteSetting::get('cdek_client_secret', config('services.cdek.client_secret')),
            (int) SiteSetting::get('cdek_sender_city_code', config('services.cdek.sender_city_code')),
        ));

        $this->app->singleton(YandexDeliveryClient::class, fn () => new YandexDeliveryClient(
            SiteSetting::get('yandex_delivery_token', config('services.yandex_delivery.token')),
            SiteSetting::get('yandex_delivery_dropoff_id', config('services.yandex_delivery.dropoff_id')),
            SiteSetting::get('yandex_delivery_sender_phone', SiteSetting::get('footer_phone')),
            SiteSetting::get('yandex_delivery_sender_name', SiteSetting::get('brand_name', 'ROPA WORLD')),
        ));

        $this->app->singleton(TBankClient::class, fn () => new TBankClient(
            SiteSetting::get('tbank_terminal_key', config('services.tbank.terminal_key')),
            SiteSetting::get('tbank_secret_key', config('services.tbank.secret_key')),
        ));

        $this->app->singleton(YandexPayClient::class, fn () => new YandexPayClient(
            SiteSetting::get('yandex_pay_merchant_id', config('services.yandex_pay.merchant_id')),
            SiteSetting::get('yandex_pay_api_key', config('services.yandex_pay.api_key')),
            (string) SiteSetting::get('yandex_pay_env', config('services.yandex_pay.env')),
        ));

        $this->app->singleton(YandexPayWebhookVerifier::class, fn () => new YandexPayWebhookVerifier(
            SiteSetting::get('yandex_pay_merchant_id', config('services.yandex_pay.merchant_id')),
            (string) SiteSetting::get('yandex_pay_env', config('services.yandex_pay.env')),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin-editable mail settings (Настройки → Почта) override the .env defaults,
        // same DB-first-then-env-fallback pattern as CDEK/T-Bank above. Guarded against
        // running before the first migration, since boot() fires for every artisan command.
        if (! Schema::hasTable('site_settings')) {
            return;
        }

        $mailer = SiteSetting::get('mail_mailer', config('mail.default'));
        config(['mail.default' => $mailer]);

        if ($mailer === 'smtp') {
            config([
                'mail.mailers.smtp.host' => SiteSetting::get('mail_host', config('mail.mailers.smtp.host')),
                'mail.mailers.smtp.port' => SiteSetting::get('mail_port', config('mail.mailers.smtp.port')),
                'mail.mailers.smtp.username' => SiteSetting::get('mail_username', config('mail.mailers.smtp.username')),
                'mail.mailers.smtp.password' => SiteSetting::get('mail_password', config('mail.mailers.smtp.password')),
                'mail.mailers.smtp.scheme' => SiteSetting::get('mail_encryption') === 'ssl' ? 'smtps' : null,
            ]);
        }

        config([
            'mail.from.address' => SiteSetting::get('mail_from_address', config('mail.from.address')),
            'mail.from.name' => SiteSetting::get('mail_from_name', config('mail.from.name')),
        ]);

        // Every product card needs to know whether it's already wishlisted (to render the
        // heart filled), but doing that query per-card would be N+1 — memoize it once per request.
        View::composer(['partials.product-card', 'product'], function ($view) {
            static $ids = null;

            if ($ids === null) {
                $token = request()->cookie('wishlist_token');
                $ids = $token ? WishlistItem::where('token', $token)->pluck('product_id')->all() : [];
            }

            $view->with('wishlistedIds', $ids);
        });

        // Header cart/wishlist icons must reflect real state on every page load — not just
        // transiently after an in-page add/remove click — so both counts are read fresh here.
        View::composer('partials.header', function ($view) {
            $cart = session('cart', []);
            $token = request()->cookie('wishlist_token');

            $view->with([
                'cartHasItems' => array_sum($cart) > 0,
                'cartCount' => array_sum($cart),
                'wishlistCount' => $token ? WishlistItem::where('token', $token)->count() : 0,
            ]);
        });
    }
}
