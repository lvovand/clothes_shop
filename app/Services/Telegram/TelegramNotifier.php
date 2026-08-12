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
    public function send(string $text, bool $withAppButton = false): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Не заданы токен бота или ID чатов.'];
        }

        $error = null;

        foreach ($this->chatIds() as $chatId) {
            $result = $this->sendTo($chatId, $text, $withAppButton ? $this->appButton($chatId) : null);
            if (! $result['ok']) {
                $error = $result['error'];
            }
        }

        return ['ok' => $error === null, 'error' => $error];
    }

    /**
     * Кнопка «Заказы» под сообщением.
     *
     * В личной переписке она открывает мини-приложение сразу (тип web_app), а в
     * группах Telegram такие кнопки запрещает — там ведём в чат с ботом, где
     * приложение открывается кнопкой меню.
     */
    private function appButton(string $chatId): ?array
    {
        $url = route('telegram.app');

        // Telegram принимает адрес мини-приложения только по HTTPS.
        if (! str_starts_with($url, 'https://')) {
            return null;
        }

        if (str_starts_with($chatId, '-')) {
            $bot = trim((string) SiteSetting::get('telegram_bot_username', ''));

            return $bot === '' ? null : [
                'inline_keyboard' => [[['text' => '📋 Заказы', 'url' => 'https://t.me/'.$bot]]],
            ];
        }

        return [
            'inline_keyboard' => [[['text' => '📋 Заказы', 'web_app' => ['url' => $url]]]],
        ];
    }

    /**
     * Отправка в конкретный чат — используется обработчиком команды /id, где
     * получатель ещё не вписан в настройки.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function sendTo(string $chatId, string $text, ?array $replyMarkup = null): array
    {
        $result = $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $replyMarkup,
        ], fn ($value) => $value !== null));

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
                ['command' => 'orders', 'description' => 'Открыть заказы'],
            ],
        ]);

        // Кнопка рядом с полем ввода в личной переписке с ботом — основной вход в
        // мини-приложение. Ставится один раз для всех приватных чатов сразу.
        $menu = $this->setAppMenuButton();

        return [
            'ok' => $commands['ok'] && $menu['ok'],
            'error' => $commands['error'] ?? $menu['error'],
        ];
    }

    /**
     * Ответ на команду /orders: сообщение с кнопкой, открывающей приложение.
     * Кому оно доступно, решает уже само приложение по списку никнеймов.
     */
    public function sendAppLink(string $chatId): void
    {
        $button = $this->appButton($chatId);

        $this->sendTo($chatId, $button === null
            ? 'Мини-приложение с заказами пока не подключено.'
            : 'Заказы магазина — по кнопке ниже.', $button);
    }

    /**
     * Кнопка меню бота, открывающая мини-приложение с заказами.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function setAppMenuButton(): array
    {
        $url = route('telegram.app');

        if (! str_starts_with($url, 'https://')) {
            return ['ok' => false, 'error' => 'Мини-приложение открывается только по HTTPS, а адрес сайта — '.$url];
        }

        $result = $this->call('setChatMenuButton', [
            'menu_button' => [
                'type' => 'web_app',
                'text' => 'Заказы',
                'web_app' => ['url' => $url],
            ],
        ]);

        return ['ok' => $result['ok'], 'error' => $result['error']];
    }

    public function orderCreated(Order $order): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->sendAbout($order, $this->orderText($order, '🛍 Новый заказ'));
    }

    public function orderPaid(Order $order): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->sendAbout($order, $this->orderText($order, '✅ Оплата получена'));
    }

    /**
     * Уведомление о заказе: неушедшее сообщение — это пропущенный заказ, поэтому
     * пишется уровнем error. Внутренние предупреждения `call()` при боевом
     * LOG_LEVEL=error в лог не попадают, и сбой оставался бы незаметным.
     */
    private function sendAbout(Order $order, string $text): void
    {
        $result = $this->send($text, withAppButton: true);

        if (! $result['ok']) {
            Log::error('Уведомление о заказе не отправлено', [
                'order' => $order->order_number ?: $order->id,
                'error' => $result['error'],
            ]);
        }
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

        if ($address = $order->shippingAddressText()) {
            $lines[] = 'Адрес: '.e($address);
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
