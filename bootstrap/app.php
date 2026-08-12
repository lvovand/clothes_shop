<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'telegram.webapp' => \App\Http\Middleware\TelegramWebApp::class,
        ]);


        // T-Bank posts webhook notifications without a Laravel session/CSRF token.
        // Signature verification (TBankClient::verifyNotification) is what actually
        // authenticates these requests, not CSRF.
        $middleware->validateCsrfTokens(except: [
            'webhooks/tbank',
            // Telegram тоже присылает обновления без сессии; подлинность проверяется
            // заголовком с секретом внутри контроллера.
            'webhooks/telegram',
            // Яндекс Пэй присылает подписанный JWT (проверяется YandexPayWebhookVerifier).
            // Путь /v1/webhook Яндекс дописывает к указанному в кабинете Callback URL сам.
            'webhooks/yandex-pay/v1/webhook',
            // Мини-приложение Telegram работает без сессии: подлинность каждого
            // запроса подтверждает подпись initData (middleware telegram.webapp).
            'tg/api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            // Мини-приложение Telegram общается только JSON-ом: ошибку валидации
            // без этого отдавало бы редиректом на страницу, и приложение считало
            // бы неверный запрос удачным.
            fn (Request $request) => $request->is('api/*') || $request->is('tg/api/*'),
        );
    })->create();
