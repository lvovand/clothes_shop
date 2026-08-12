<?php

namespace App\Services\Telegram;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Log;

/**
 * Проверка подлинности данных мини-приложения Telegram (initData).
 *
 * Telegram отдаёт приложению строку вида «query_id=…&user=…&auth_date=…&hash=…».
 * Подлинность подтверждает hash: HMAC-SHA256 по остальным полям, отсортированным
 * по имени, с ключом HMAC-SHA256("WebAppData", токен бота). Без этой проверки
 * initData подделывается вручную, то есть чужой заказ смотрел бы кто угодно.
 *
 * Токен бота — тот же, что у уведомлений («Настройки → Уведомления в Telegram»).
 */
class WebAppAuth
{
    /** Данные старше суток считаем просроченными: столько же живёт сессия у самого Telegram. */
    private const MAX_AGE = 86400;

    /**
     * @return array{ok: bool, error: ?string, user: ?array}
     */
    public function verify(?string $initData): array
    {
        $token = trim((string) SiteSetting::get('telegram_bot_token', ''));

        if ($token === '') {
            return ['ok' => false, 'error' => 'На сайте не задан токен бота.', 'user' => null];
        }

        $initData = trim((string) $initData);
        if ($initData === '') {
            return ['ok' => false, 'error' => 'Приложение открыто не из Telegram.', 'user' => null];
        }

        // Разбираем сами, а не parse_str: тот заменяет точки в именах полей на
        // подчёркивания и раскодирует «+» как пробел, из-за чего строка для
        // подписи перестаёт совпадать с тем, что подписал Telegram.
        $fields = [];
        foreach (explode('&', $initData) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $fields[rawurldecode($key)] = rawurldecode($value);
        }

        $hash = (string) ($fields['hash'] ?? '');
        unset($fields['hash']);

        if ($hash === '' || $fields === []) {
            return ['ok' => false, 'error' => 'Данные Telegram неполные.', 'user' => null];
        }

        $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);

        // Поле signature (Ed25519-подпись для сторонней проверки) появилось в Bot
        // API 8.0, и клиенты расходятся в том, входит ли оно в HMAC. Считаем оба
        // варианта: каждый из них — подпись самого Telegram, ослабления нет.
        $withoutSignature = $fields;
        unset($withoutSignature['signature']);

        $matched = false;
        foreach ([$withoutSignature, $fields] as $variant) {
            ksort($variant);
            $checkString = collect($variant)->map(fn ($value, $key) => $key.'='.$value)->implode("\n");

            if (hash_equals(hash_hmac('sha256', $checkString, $secretKey), $hash)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            // Подпись не сошлась — почти всегда это чужой бот: initData подписан
            // токеном того бота, из которого открыто приложение.
            Log::error('Подпись мини-приложения Telegram не совпала', [
                'fields' => array_keys($fields),
                'auth_date' => $fields['auth_date'] ?? null,
            ]);

            return ['ok' => false, 'error' => 'Подпись Telegram не совпала: приложение открыто из другого бота.', 'user' => null];
        }

        $authDate = (int) ($fields['auth_date'] ?? 0);
        if ($authDate <= 0 || (time() - $authDate) > self::MAX_AGE) {
            return ['ok' => false, 'error' => 'Данные Telegram устарели, откройте приложение заново.', 'user' => null];
        }

        $user = json_decode((string) ($fields['user'] ?? ''), true);
        if (! is_array($user) || ! isset($user['id'])) {
            return ['ok' => false, 'error' => 'Telegram не передал данные пользователя.', 'user' => null];
        }

        return ['ok' => true, 'error' => null, 'user' => $user];
    }
}
