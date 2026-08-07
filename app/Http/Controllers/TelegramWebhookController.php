<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Http\Request;

/**
 * Обработчик команд бота. Нужен ровно для одного: показать ID чата, который
 * потом вписывается в «Настройки → Уведомления в Telegram». Сам ID бот сюда
 * не сохраняет специально — иначе подписаться на уведомления о заказах мог бы
 * любой, кто нашёл бота.
 */
class TelegramWebhookController extends Controller
{
    public function handle(Request $request, TelegramNotifier $telegram)
    {
        $secret = (string) SiteSetting::get('telegram_webhook_secret', '');

        // Адрес вебхука знает только Telegram, но заголовок с секретом — единственная
        // настоящая проверка того, что запрос действительно от него.
        if ($secret === '' || $request->header('X-Telegram-Bot-Api-Secret-Token') !== $secret) {
            return response('forbidden', 403);
        }

        $message = $request->input('message') ?? $request->input('edited_message') ?? [];
        $chatId = $message['chat']['id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if (! $chatId) {
            return response('OK');
        }

        $command = strtolower(explode('@', explode(' ', $text)[0])[0]);

        if (in_array($command, ['/id', '/start', '/chatid'], true)) {
            $type = $message['chat']['type'] ?? 'private';

            $telegram->sendTo((string) $chatId, implode("\n", [
                'ID этого чата: <code>'.$chatId.'</code>',
                '',
                'Скопируйте его в админке сайта: Настройки → Уведомления в Telegram → «ID чатов получателей».',
                $type === 'private'
                    ? 'Сюда будут приходить уведомления о новых заказах.'
                    : 'Уведомления будут приходить в эту группу.',
            ]));
        }

        return response('OK');
    }
}
