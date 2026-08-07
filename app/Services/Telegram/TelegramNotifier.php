<?php

namespace App\Services\Telegram;

use App\Models\Order;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Уведомления в Telegram о заказах.
 *
 * api.telegram.org из России недоступен, поэтому все запросы идут через прокси —
 * его адрес задаётся в админке («Настройки → Уведомления в Telegram»). Без
 * прокси отправка не пробуется вообще, чтобы не ждать таймаут на каждом заказе.
 */
class TelegramNotifier
{
    private const API = 'https://api.telegram.org';

    public function isConfigured(): bool
    {
        return $this->token() !== '' && $this->chatIds() !== [];
    }

    public function enabled(): bool
    {
        return $this->isConfigured() && (bool) SiteSetting::get('telegram_notify_orders', '1');
    }

    private function token(): string
    {
        return trim((string) SiteSetting::get('telegram_bot_token', ''));
    }

    private function proxy(): ?string
    {
        $proxy = trim((string) SiteSetting::get('telegram_proxy', ''));

        return $proxy === '' ? null : $proxy;
    }

    /** @return array<int, string> */
    private function chatIds(): array
    {
        $raw = (string) SiteSetting::get('telegram_chat_ids', '');

        return collect(preg_split('/[\s,;]+/', $raw))
            ->map(fn ($id) => trim($id))
            ->filter(fn ($id) => $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Отправка сообщения во все указанные чаты. Никогда не бросает исключений:
     * упавшее уведомление не должно ломать оформление заказа.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function send(string $text): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Не заданы токен бота или ID чатов.'];
        }

        $error = null;

        foreach ($this->chatIds() as $chatId) {
            $result = $this->sendTo($chatId, $text);
            if (! $result['ok']) {
                $error = $result['error'];
            }
        }

        return ['ok' => $error === null, 'error' => $error];
    }

    /**
     * Отправка в конкретный чат — используется обработчиком команды /id, где
     * получатель ещё не вписан в настройки.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function sendTo(string $chatId, string $text): array
    {
        $result = $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        return ['ok' => $result['ok'], 'error' => $result['error']];
    }

    /**
     * Любой метод Bot API через прокси. Не бросает исключений: сбой Telegram не
     * должен отражаться ни на заказе, ни на админке.
     *
     * @return array{ok: bool, error: ?string, result: mixed}
     */
    public function call(string $method, array $payload = []): array
    {
        if ($this->token() === '') {
            return ['ok' => false, 'error' => 'Не задан токен бота.', 'result' => null];
        }

        try {
            $request = Http::timeout(15)->asJson();
            if ($this->proxy() !== null) {
                $request = $request->withOptions(['proxy' => $this->proxy()]);
            }

            $response = $request->post(self::API.'/bot'.$this->token().'/'.$method, $payload);

            if (! $response->successful()) {
                Log::warning('Telegram API failed', ['method' => $method, 'status' => $response->status(), 'body' => $response->body()]);

                return [
                    'ok' => false,
                    'error' => 'Telegram ответил '.$response->status().': '.$response->json('description', $response->body()),
                    'result' => null,
                ];
            }

            return ['ok' => true, 'error' => null, 'result' => $response->json('result')];
        } catch (\Throwable $e) {
            Log::warning('Telegram API error', ['method' => $method, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage(), 'result' => null];
        }
    }

    /**
     * Подключает бота к сайту: регистрирует адрес вебхука (с секретом) и меню
     * команд, чтобы в боте появилась команда /id.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function registerBot(): array
    {
        $secret = trim((string) SiteSetting::get('telegram_webhook_secret', ''));
        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
            SiteSetting::set('telegram_webhook_secret', $secret);
        }

        // Ник бота нигде в API не нужен (бот определяется токеном) — сохраняем его
        // только для показа в админке, чтобы было видно, какой бот подключён.
        $me = $this->call('getMe');
        if ($me['ok']) {
            SiteSetting::set('telegram_bot_username', (string) ($me['result']['username'] ?? ''));
        }

        $webhook = $this->call('setWebhook', [
            'url' => route('telegram.webhook'),
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'edited_message'],
        ]);

        if (! $webhook['ok']) {
            return ['ok' => false, 'error' => $webhook['error']];
        }

        $commands = $this->call('setMyCommands', [
            'commands' => [
                ['command' => 'id', 'description' => 'Показать ID этого чата'],
            ],
        ]);

        return ['ok' => $commands['ok'], 'error' => $commands['error']];
    }

    public function orderCreated(Order $order): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->send($this->orderText($order, '🛍 Новый заказ'));
    }

    public function orderPaid(Order $order): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->send($this->orderText($order, '✅ Оплата получена'));
    }

    /** Заявка в Яндекс Доставке создана автоматически после оплаты. */
    public function shipmentCreated(Order $order, string $requestId, ?string $where = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        $lines = [
            '<b>📦 Заявка в Яндекс Доставку создана</b>',
            '',
            'Заказ №'.e((string) ($order->order_number ?: $order->id)),
            'Номер заявки: <code>'.e($requestId).'</code>',
        ];

        if ($where) {
            $lines[] = 'Куда: '.e($where);
        }

        $lines[] = '';
        $lines[] = 'Посылку нужно привезти в точку сдачи Яндекса.';

        $this->send(implode(PHP_EOL, $lines));
    }

    /** Заявку создать не удалось — заказ придётся оформить в кабинете руками. */
    public function shipmentFailed(Order $order, string $reason): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->send(implode(PHP_EOL, [
            '<b>⚠️ Не удалось создать заявку в Яндекс Доставку</b>',
            '',
            'Заказ №'.e((string) ($order->order_number ?: $order->id)),
            'Причина: '.e($reason),
            '',
            'Оформите доставку в кабинете Яндекса вручную.',
        ]));
    }

    private function orderText(Order $order, string $title): string
    {
        $order->loadMissing('items', 'shippingMethod');

        $lines = [
            '<b>'.$title.' №'.e((string) ($order->order_number ?: $order->id)).'</b>',
            '',
            'Покупатель: '.e((string) $order->customer_name),
            'Телефон: '.e((string) $order->customer_phone),
        ];

        if ($order->customer_email) {
            $lines[] = 'Email: '.e($order->customer_email);
        }

        $lines[] = 'Доставка: '.e((string) ($order->shippingMethod->title ?? '—'));

        if ($order->shipping_address) {
            $lines[] = 'Адрес: '.e($order->shipping_address);
        }

        $lines[] = 'Оплата: '.e(\App\Models\PaymentMethod::LABELS[$order->payment_method] ?? (string) $order->payment_method);
        $lines[] = '';

        foreach ($order->items as $item) {
            $attrs = collect((array) $item->variant_attrs_snapshot)->filter()->implode(', ');
            $lines[] = '• '.e((string) $item->product_title_snapshot)
                .($attrs !== '' ? ' ('.e($attrs).')' : '')
                .' × '.$item->qty.' — '.number_format((float) $item->line_total, 0, ',', ' ').' ₽';
        }

        $lines[] = '';
        $lines[] = '<b>Итого: '.number_format((float) $order->total, 0, ',', ' ').' ₽</b>';

        if ($order->comment) {
            $lines[] = '';
            $lines[] = 'Комментарий: '.e($order->comment);
        }

        return implode("\n", $lines);
    }
}
