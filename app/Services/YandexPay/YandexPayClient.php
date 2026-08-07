<?php

namespace App\Services\YandexPay;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Merchant API v1 Яндекс Пэй — сценарий «платёжная ссылка»: создаём заказ,
 * получаем paymentUrl, уводим покупателя туда. Сплит — не отдельная интеграция,
 * а значение SPLIT в availablePaymentMethods того же самого заказа.
 *
 * Итог платежа приходит вебхуком (см. YandexPayWebhookVerifier), возврат
 * покупателя по redirectUrls — только для показа страницы, доверять ему нельзя.
 */
class YandexPayClient
{
    private const BASE_URL_PRODUCTION = 'https://pay.yandex.ru/api/merchant/v1';

    private const BASE_URL_SANDBOX = 'https://sandbox.pay.yandex.ru/api/merchant/v1';

    /** Ставка НДС в чеке: 8 = «без НДС» (у магазина УСН, как и в чеке Т-Банка). */
    private const TAX_NONE = 8;

    public function __construct(
        private readonly ?string $merchantId,
        private readonly ?string $apiKey,
        private readonly string $environment = 'production',
    ) {}

    /**
     * Ключи задаются в админке, то есть их может не быть. Без них платёж не
     * создаётся, но страница оформления обязана продолжать работать — стёртый
     * ключ не должен ронять чекаут целиком (та же причина, что у TBankClient).
     */
    public function isConfigured(): bool
    {
        return (string) $this->merchantId !== '' && (string) $this->apiKey !== '';
    }

    public function isSandbox(): bool
    {
        return $this->environment === 'sandbox';
    }

    /**
     * Создать заказ и получить ссылку на платёжную форму.
     *
     * @param  array<int,array{name:string,price_kop:int,qty:int}>  $items  позиции чека
     * @param  array<int,string>  $paymentMethods  CARD и/или SPLIT
     * @return array{success: bool, payment_url: ?string, error: ?string}
     */
    public function createOrder(
        string $orderId,
        array $items,
        array $paymentMethods,
        string $successUrl,
        string $errorUrl,
        ?string $customerEmail,
        string $customerPhone,
    ): array {
        if (! $this->isConfigured()) {
            Log::error('Yandex Pay: ключи не настроены, платёж не создан');

            return ['success' => false, 'payment_url' => null, 'error' => 'not configured'];
        }

        if ($items === []) {
            return ['success' => false, 'payment_url' => null, 'error' => 'empty cart'];
        }

        $cartItems = [];
        $totalKop = 0;

        foreach ($items as $i => $item) {
            $lineKop = $item['price_kop'] * $item['qty'];
            $totalKop += $lineKop;

            $cartItems[] = [
                // productId обязателен и должен быть уникален в пределах корзины;
                // у позиции чека своего идентификатора нет — хватает порядкового.
                'productId' => (string) ($i + 1),
                'title' => mb_substr($item['name'], 0, 128),
                'quantity' => ['count' => (string) $item['qty']],
                'total' => $this->money($lineKop),
                'unitPrice' => $this->money($item['price_kop']),
                'receipt' => ['tax' => self::TAX_NONE],
            ];
        }

        $payload = [
            'merchantId' => $this->merchantId,
            'orderId' => $orderId,
            'currencyCode' => 'RUB',
            'availablePaymentMethods' => array_values($paymentMethods),
            'cart' => [
                'items' => $cartItems,
                'total' => ['amount' => $this->money($totalKop)],
            ],
            'redirectUrls' => [
                'onSuccess' => $successUrl,
                'onError' => $errorUrl,
                'onAbort' => $errorUrl,
            ],
            // Чек фискализируется на стороне Яндекс Пэй, контакт покупателя для него
            // обязателен. Email у нас необязательный — тогда уходит телефон.
            'fiscalContact' => $customerEmail !== null && $customerEmail !== '' ? $customerEmail : $customerPhone,
            'orderSource' => 'WEBSITE',
            'ttl' => 1800,
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Api-Key '.$this->apiKey,
            // Идемпотентность на стороне Яндекса: повтор того же запроса с тем же
            // X-Request-Id не создаст второй заказ.
            'X-Request-Id' => (string) Str::uuid(),
        ])->asJson()->timeout(15)->post($this->baseUrl().'/orders', $payload);

        $body = $response->json();
        $paymentUrl = $body['data']['paymentUrl'] ?? null;

        if (! $response->successful() || ! $paymentUrl) {
            Log::error('Yandex Pay: создание заказа не удалось', [
                'status' => $response->status(),
                'body' => $body,
                'order_id' => $orderId,
            ]);

            return [
                'success' => false,
                'payment_url' => null,
                'error' => $body['reason'] ?? $body['reasonCode'] ?? 'unknown error',
            ];
        }

        return ['success' => true, 'payment_url' => $paymentUrl, 'error' => null];
    }

    /**
     * Текущее состояние заказа у Яндекса. Нужен там, где нельзя ждать вебхук —
     * например, чтобы показать вернувшемуся покупателю честный статус.
     *
     * @return array<string,mixed>|null
     */
    public function getOrder(string $orderId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Api-Key '.$this->apiKey,
        ])->timeout(10)->get($this->baseUrl().'/orders/'.rawurlencode($orderId));

        if (! $response->successful()) {
            Log::warning('Yandex Pay: не удалось получить заказ', [
                'status' => $response->status(),
                'order_id' => $orderId,
            ]);

            return null;
        }

        return $response->json('data.order');
    }

    private function baseUrl(): string
    {
        return $this->isSandbox() ? self::BASE_URL_SANDBOX : self::BASE_URL_PRODUCTION;
    }

    /** Суммы у Яндекса — строки с двумя знаками после точки, а не копейки. */
    private function money(int $kopecks): string
    {
        return number_format($kopecks / 100, 2, '.', '');
    }
}
