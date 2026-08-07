<?php

namespace App\Services\YandexPay;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Яндекс Пэй присылает вебхук не JSON-ом, а JWT, подписанным ES256; тело запроса —
 * сам токен. Без проверки подписи этот адрес принимал бы любое «заказ оплачен»
 * от кого угодно, поэтому непроверенный вебхук не обрабатывается никогда.
 *
 * Открытые ключи лежат на стороне Яндекса и ротируются, нужный выбирается по kid
 * из заголовка токена. Набор ключей кешируется, но при неудачной проверке
 * перечитывается один раз — иначе ротация ключа роняла бы приём платежей на
 * всё время жизни кеша.
 */
class YandexPayWebhookVerifier
{
    private const JWKS_URL_PRODUCTION = 'https://pay.yandex.ru/api/jwks';

    private const JWKS_URL_SANDBOX = 'https://sandbox.pay.yandex.ru/api/jwks';

    private const CACHE_TTL_SECONDS = 600;

    public function __construct(
        private readonly ?string $merchantId,
        private readonly string $environment = 'production',
    ) {}

    /**
     * Разобрать и проверить подпись токена.
     *
     * @return array<string,mixed>|null payload вебхука или null, если токен невалиден
     */
    public function verify(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $payload = $this->decode($token, false);

        // Не расшифровался — возможно, ключи сменились: перечитываем набор и пробуем ещё раз.
        if ($payload === null) {
            $payload = $this->decode($token, true);
        }

        if ($payload === null) {
            return null;
        }

        // Токен подписан настоящим ключом Яндекса, но адресован ли он нам.
        $merchantId = $payload['merchantId'] ?? null;
        if ($this->merchantId && $merchantId && ! hash_equals((string) $this->merchantId, (string) $merchantId)) {
            Log::warning('Yandex Pay: вебхук с чужим merchantId', ['merchant_id' => $merchantId]);

            return null;
        }

        return $payload;
    }

    /** @return array<string,mixed>|null */
    private function decode(string $token, bool $forceFreshKeys): ?array
    {
        $jwks = $this->jwks($forceFreshKeys);
        if ($jwks === null) {
            return null;
        }

        try {
            $decoded = JWT::decode($token, JWK::parseKeySet($jwks));
        } catch (\Throwable $e) {
            if ($forceFreshKeys) {
                Log::warning('Yandex Pay: подпись вебхука не прошла проверку', ['error' => $e->getMessage()]);
            }

            return null;
        }

        return json_decode(json_encode($decoded), true);
    }

    /** @return array<string,mixed>|null */
    private function jwks(bool $forceFresh): ?array
    {
        $cacheKey = 'yandex_pay_jwks_'.$this->environment;

        if ($forceFresh) {
            Cache::forget($cacheKey);
        }

        $jwks = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () {
            $response = Http::timeout(10)->get($this->jwksUrl());

            if (! $response->successful() || ! is_array($response->json('keys'))) {
                Log::error('Yandex Pay: не удалось получить набор ключей', ['status' => $response->status()]);

                // null не кешируем: иначе одна сетевая ошибка выключила бы приём
                // вебхуков на всё время жизни кеша.
                return null;
            }

            return $response->json();
        });

        if ($jwks === null) {
            Cache::forget($cacheKey);
        }

        return $jwks;
    }

    private function jwksUrl(): string
    {
        return $this->environment === 'sandbox' ? self::JWKS_URL_SANDBOX : self::JWKS_URL_PRODUCTION;
    }
}
