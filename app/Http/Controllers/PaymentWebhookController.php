<?php

namespace App\Http\Controllers;

use App\Mail\GiftCertificatePurchased;
use App\Models\GiftCertificate;
use App\Models\Order;
use App\Models\Payment;
use App\Services\TBank\TBankClient;
use App\Services\Telegram\TelegramNotifier;
use App\Services\Shipping\ShipmentDispatcher;
use App\Services\YandexPay\YandexPayWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaymentWebhookController extends Controller
{
    public function tbank(Request $request, TBankClient $tbank, TelegramNotifier $telegram)
    {
        $payload = $request->json()->all();

        // Never trust an unsigned/mis-signed notification — anyone could otherwise POST
        // a forged "payment succeeded" callback straight at this URL.
        if (! $tbank->verifyNotification($payload)) {
            Log::warning('T-Bank webhook: invalid signature', ['payload' => $payload]);

            return response('signature mismatch', 400);
        }

        $paymentId = (string) ($payload['PaymentId'] ?? '');
        $status = $payload['Status'] ?? null;

        $payment = Payment::where('provider', 'tbank')->where('provider_payment_id', $paymentId)->first();

        if (! $payment) {
            Log::warning('T-Bank webhook: unknown PaymentId', ['payment_id' => $paymentId]);

            // Still acknowledge with OK: an unknown PaymentId is not something retrying will fix.
            return response('OK');
        }

        $this->applyStatus(
            $payment,
            (string) $status,
            $payload,
            match (true) {
                $status === 'CONFIRMED' => 'paid',
                in_array($status, ['REJECTED', 'CANCELED', 'DEADLINE_EXPIRED'], true) => 'failed',
                default => 'pending',
            },
            $telegram,
        );

        return response('OK');
    }

    /**
     * Вебхук Яндекс Пэй. Тело запроса — не JSON, а подписанный JWT, поэтому читается
     * сырым и проверяется до всего остального.
     *
     * Отвечать нужно строго 200 с {"status":"success"}: на любой другой ответ Яндекс
     * будет повторять доставку сутки. Поэтому события, которые мы не обрабатываем, и
     * неизвестные заказы тоже подтверждаются — повтор их не исправит, они только
     * пишутся в лог.
     */
    public function yandexPay(Request $request, YandexPayWebhookVerifier $verifier, TelegramNotifier $telegram)
    {
        $payload = $verifier->verify($request->getContent());

        if ($payload === null) {
            return response()->json([
                'status' => 'fail',
                'reasonCode' => 'UNAUTHORIZED',
                'reason' => 'signature verification failed',
            ], 401);
        }

        // OPERATION_STATUS_UPDATED (возвраты, отмены) отдельной обработки пока не
        // требует: возвраты оформляются на стороне Яндекса, у нас нет своего реестра
        // операций. Заказ при полном возврате всё равно придёт как REFUNDED в событии
        // статуса заказа.
        if (($payload['event'] ?? null) !== 'ORDER_STATUS_UPDATED') {
            return response()->json(['status' => 'success']);
        }

        $orderId = (string) ($payload['order']['orderId'] ?? '');
        $status = (string) ($payload['order']['paymentStatus'] ?? '');

        $payment = Payment::whereIn('provider', ['yandex_pay', 'yandex_split'])
            ->where('provider_payment_id', $orderId)
            ->first();

        if (! $payment) {
            Log::warning('Yandex Pay webhook: неизвестный заказ', ['order_id' => $orderId]);

            return response()->json(['status' => 'success']);
        }

        $this->applyStatus(
            $payment,
            $status,
            $payload,
            match ($status) {
                'CAPTURED' => 'paid',
                'FAILED', 'VOIDED' => 'failed',
                default => 'pending',
            },
            $telegram,
        );

        return response()->json(['status' => 'success']);
    }

    /**
     * Общая часть для всех эквайеров: записать статус и, если платёж только что стал
     * оплаченным или проваленным, применить это к заказу или сертификату.
     *
     * $providerStatus хранится как есть (сырой статус эквайера) — он же служит
     * защитой от повторной доставки: тот же статус второй раз не обрабатывается.
     * $outcome — уже наша трактовка: paid | failed | pending.
     */
    private function applyStatus(Payment $payment, string $providerStatus, array $rawPayload, string $outcome, TelegramNotifier $telegram): void
    {
        if ($payment->status === $providerStatus) {
            return;
        }

        $payment->update([
            'status' => $providerStatus,
            'raw_payload' => $rawPayload,
        ]);

        if ($outcome === 'pending') {
            return;
        }

        if ($payment->order_id) {
            $order = $payment->order;

            if ($outcome === 'paid') {
                $order->update(['payment_status' => 'paid', 'status' => 'paid']);

                // Уведомление и заявка на доставку уходят после ответа эквайеру:
                // запрос к Telegram идёт через зарубежный прокси, а Яндекс Доставка —
                // ещё один внешний вызов; вебхук обязан ответить быстро.
                app()->terminating(function () use ($order, $telegram) {
                    try {
                        $telegram->orderPaid($order);
                    } catch (\Throwable $e) {
                        Log::error('Telegram notify failed', ['error' => $e->getMessage()]);
                    }

                    $this->dispatchDelivery($order, $telegram);
                });
            } else {
                $order->update(['payment_status' => 'failed']);
            }

            return;
        }

        $certificate = GiftCertificate::where('payment_id', $payment->id)->first();

        if (! $certificate) {
            return;
        }

        if ($outcome === 'paid') {
            $certificate->update(['status' => 'active']);

            Mail::to($certificate->recipient_email)->send(new GiftCertificatePurchased($certificate));
        } else {
            $certificate->update(['status' => 'failed']);
        }
    }

    /**
     * Заявка в Яндекс Доставке создаётся сама, как только заказ стал оплаченным.
     * Ошибка не должна ронять обработку вебхука — о ней сообщаем в Telegram, чтобы
     * заказ не остался незамеченным и его оформили в кабинете руками.
     */
    private function dispatchDelivery(Order $order, TelegramNotifier $telegram): void
    {
        try {
            $result = app(ShipmentDispatcher::class)->dispatch($order);
        } catch (\Throwable $e) {
            Log::error('Shipment dispatch failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            $this->notifyShipmentFailure($order, $telegram, $e->getMessage());

            return;
        }

        // Способ без перевозчика (самовывоз) и выключенное автосоздание — не ошибки, молчим.
        if (! $result['ok'] && app(ShipmentDispatcher::class)->isQuiet($result['reason'] ?? null)) {
            return;
        }

        if (! $result['ok']) {
            Log::warning('Shipment dispatch skipped', ['order' => $order->id, 'reason' => $result['reason'] ?? '']);
            $this->notifyShipmentFailure($order, $telegram, (string) ($result['reason'] ?? 'неизвестная причина'));

            return;
        }

        if (($result['reason'] ?? null) === ShipmentDispatcher::REASON_ALREADY) {
            return;
        }

        try {
            $shipment = $result['shipment'];
            $telegram->shipmentCreated(
                $order,
                (string) $shipment->tracking_number,
                $shipment->pvz_address ?: ($order->shipping_address['address'] ?? null),
            );
        } catch (\Throwable $e) {
            Log::error('Telegram notify failed', ['error' => $e->getMessage()]);
        }
    }

    private function notifyShipmentFailure(Order $order, TelegramNotifier $telegram, string $reason): void
    {
        try {
            $telegram->shipmentFailed($order, $reason);
        } catch (\Throwable $e) {
            Log::error('Telegram notify failed', ['error' => $e->getMessage()]);
        }
    }
}
