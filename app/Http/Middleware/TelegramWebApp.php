<?php

namespace App\Http\Middleware;

use App\Models\TelegramAdmin;
use App\Services\Telegram\WebAppAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Доступ к API мини-приложения: подпись Telegram + никнейм в списке допущенных
 * («Настройки → Доступ в Telegram-приложение»).
 *
 * Сессии здесь нет намеренно: initData присылается с каждым запросом заголовком
 * X-Telegram-Init-Data, поэтому проверка одинакова и для GET, и для POST, а
 * украденная cookie ничего не даёт.
 */
class TelegramWebApp
{
    public function __construct(private readonly WebAppAuth $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $result = $this->auth->verify($request->header('X-Telegram-Init-Data'));

        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 401);
        }

        $user = $result['user'];
        $admin = TelegramAdmin::findFor((int) $user['id'], $user['username'] ?? null);

        if (! $admin) {
            return response()->json([
                'message' => 'Доступ закрыт. Попросите добавить ваш никнейм'
                    .(isset($user['username']) ? ' @'.$user['username'] : '')
                    .' в админке сайта.',
            ], 403);
        }

        // Никнейм человек может сменить в любой момент — привязываем запись к его
        // числовому id при первом входе, дальше доступ держится на нём.
        $changes = ['last_seen_at' => now()];
        if (! $admin->telegram_id) {
            $changes['telegram_id'] = (int) $user['id'];
        }
        $username = TelegramAdmin::normalizeUsername($user['username'] ?? null);
        if ($username !== '' && $username !== $admin->username
            && ! TelegramAdmin::where('username', $username)->whereKeyNot($admin->getKey())->exists()) {
            $changes['username'] = $username;
        }
        $admin->forceFill($changes)->saveQuietly();

        $request->attributes->set('telegram_admin', $admin);

        return $next($request);
    }
}
