<?php

namespace App\Services\TBank;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TBankClient
{
    private const BASE_URL = 'https://securepay.tinkoff.ru/v2';

    public function __construct(
        private readonly ?string $terminalKey,
        private readonly ?string $secretKey,
    ) {
    }

    /**
     * Ключи эквайринга берутся из настроек сайта, то есть их может не быть.
     * Без них платёж не создаётся, но приложение не должно падать при сборке
     * контейнера — иначе стёртый в админке ключ ломает всю страницу оформления.
     */
    public function isConfigured(): bool
    {
        return (string) $this->terminalKey !== '' && (string) $this->secretKey !== '';
    }

    /**
     * Create a payment session. $amountRub is in whole rubles (converted to kopecks here).
     * $receiptItems: WooCommerce/54-FZ style line items, e.g.
     *   [['Name' => 'Товар', 'Price' => 150000, 'Quantity' => 1, 'Amount' => 150000, 'Tax' => 'none']]
     * (Price/Amount here are in kopecks too, matching T-Bank's Receipt schema.)
     *
     * @return array{success: bool, payment_id: ?string, payment_url: ?string, error: ?string}
     */
    public function init(
        string $orderNumber,
        float $amountRub,
        string $description,
        array $receiptItems,
        ?string $customerEmail,
        string $customerPhone,
    ): array {
        if (! $this->isConfigured()) {
            Log::error('T-Bank keys are not configured, payment cannot be created');

            return ['success' => false, 'payment_id' => null, 'payment_url' => null, 'error' => 'not configured'];
        }

        $amountKopecks = (int) round($amountRub * 100);

        $params = [
            'TerminalKey' => $this->terminalKey,
            'Amount' => $amountKopecks,
            'OrderId' => $orderNumber,
            'Description' => mb_substr($description, 0, 250),
            'NotificationURL' => route('webhooks.tbank'),
            'SuccessURL' => route('checkout.success', ['order' => $orderNumber]),
            'FailURL' => route('checkout.failed', ['order' => $orderNumber]),
        ];

        $params['Token'] = $this->generateToken($params);

        // T-Bank's receipt just needs one contact method — email is optional on our checkout
        // form, so only include it when present rather than sending an empty string.
        $params['Receipt'] = array_filter([
            'Email' => $customerEmail,
            'Phone' => $customerPhone,
            'Taxation' => 'usn_income',
            'Items' => $receiptItems,
        ], fn ($value) => $value !== null);

        $response = Http::asJson()->post(self::BASE_URL.'/Init', $params);
        $body = $response->json();

        if (! $response->successful() || ! ($body['Success'] ?? false)) {
            Log::error('T-Bank Init failed', ['status' => $response->status(), 'body' => $body]);

            return ['success' => false, 'payment_id' => null, 'payment_url' => null, 'error' => $body['Message'] ?? 'unknown error'];
        }

        return [
            'success' => true,
            'payment_id' => (string) $body['PaymentId'],
            'payment_url' => $body['PaymentURL'],
            'error' => null,
        ];
    }

    /**
     * Verify a webhook's Token signature. $payload is the raw decoded JSON body T-Bank posted.
     * Never trust a notification without this check — anyone could otherwise POST a fake
     * "payment succeeded" callback.
     */
    public function verifyNotification(array $payload): bool
    {
        $receivedToken = $payload['Token'] ?? null;
        if (! $receivedToken) {
            return false;
        }

        $params = $payload;
        unset($params['Token'], $params['Receipt'], $params['DATA']);

        $expectedToken = $this->generateToken($params);

        return hash_equals($expectedToken, $receivedToken);
    }

    /**
     * T-Bank's signature algorithm: take all root-level scalar params (never nested
     * objects), add Password=secretKey, sort by key, concatenate values, SHA-256 hex.
     */
    private function generateToken(array $params): string
    {
        $params['Password'] = $this->secretKey;

        ksort($params);

        $concatenated = '';
        foreach ($params as $value) {
            if (is_scalar($value)) {
                $concatenated .= $value;
            }
        }

        return hash('sha256', $concatenated);
    }
}
