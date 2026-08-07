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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
