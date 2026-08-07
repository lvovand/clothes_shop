<?php

namespace App\Http\Controllers;

use App\Models\GiftCertificate;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SiteSetting;
use App\Services\TBank\TBankClient;
use App\Services\YandexPay\YandexPayClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GiftCardController extends Controller
{
    public function show()
    {
        return view('gift-card', [
            'title' => 'Gift Card',
            'paymentMethods' => PaymentMethod::active()->orderBy('sort_order')->get(),
            'yandexPayMerchantId' => SiteSetting::get('yandex_pay_merchant_id', config('services.yandex_pay.merchant_id')),
        ]);
    }

    public function purchase(Request $request, TBankClient $tbank, YandexPayClient $yandexPay)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:3000', 'max:150000'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_email' => ['required', 'email'],
            'message' => ['nullable', 'string', 'max:1000'],
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_email' => ['required', 'email'],
            'buyer_phone' => ['required', 'string', 'max:32'],
            'payment_method' => ['required', Rule::exists('payment_methods', 'key')->where('is_active', true)],
        ]);

        $certificate = GiftCertificate::create([
            'code' => $this->generateCode(),
            'initial_amount' => $data['amount'],
            'remaining_balance' => $data['amount'],
            'recipient_name' => $data['recipient_name'],
            'recipient_email' => $data['recipient_email'],
            'buyer_name' => $data['buyer_name'],
            'buyer_email' => $data['buyer_email'],
            'buyer_phone' => $data['buyer_phone'],
            'message' => $data['message'] ?? null,
            'status' => 'pending',
        ]);

        // Способы оплаты, у которых есть готовая интеграция в коде. Запись в
        // payment_methods управляет только тем, что видит покупатель, поэтому
        // неизвестный ключ здесь — не ошибка настройки, а отсутствие интеграции.
        $method = $data['payment_method'];

        if (! in_array($method, ['tbank', 'yandex_pay', 'yandex_split'], true)) {
            $certificate->update(['status' => 'failed']);

            return back()->withInput()->with('error', 'Выбранный способ оплаты временно недоступен');
        }

        $payment = Payment::create([
            'order_id' => null,
            'provider' => $method,
            'amount' => $data['amount'],
            'status' => 'pending',
        ]);
        $certificate->update(['payment_id' => $payment->id]);

        $orderNumber = 'GC-'.$certificate->id.'-'.Str::upper(Str::random(4));
        $amountKop = (int) round($data['amount'] * 100);
        $title = 'Подарочный сертификат ROPA WORLD';

        if ($method === 'tbank') {
            $result = $tbank->init(
                $orderNumber,
                (float) $data['amount'],
                $title,
                [[
                    'Name' => $title,
                    'Price' => $amountKop,
                    'Quantity' => 1,
                    'Amount' => $amountKop,
                    'Tax' => 'none',
                ]],
                $data['buyer_email'],
                $data['buyer_phone'],
            );

            $providerPaymentId = $result['payment_id'] ?? null;
        } else {
            $result = $yandexPay->createOrder(
                $orderNumber,
                [['name' => $title, 'price_kop' => $amountKop, 'qty' => 1]],
                $method === 'yandex_split' ? ['SPLIT'] : ['CARD'],
                route('gift-card.success', ['code' => $certificate->code]),
                route('gift-card.failed', ['code' => $certificate->code]),
                $data['buyer_email'],
                $data['buyer_phone'],
            );

            // У Яндекс Пэй своего идентификатора платежа нет — в вебхуке приходит
            // тот номер заказа, который отправили мы.
            $providerPaymentId = $orderNumber;
        }

        if (! $result['success']) {
            $payment->update(['status' => 'failed']);
            $certificate->update(['status' => 'failed']);

            return redirect()->route('gift-card.failed', ['code' => $certificate->code]);
        }

        $payment->update(['provider_payment_id' => $providerPaymentId]);

        return redirect()->away($result['payment_url']);
    }

    public function success(string $code)
    {
        $certificate = GiftCertificate::where('code', $code)->firstOrFail();

        return view('gift-card-success', ['certificate' => $certificate, 'title' => 'Сертификат оформлен']);
    }

    public function failed(string $code)
    {
        $certificate = GiftCertificate::where('code', $code)->firstOrFail();

        return view('gift-card-failed', ['certificate' => $certificate, 'title' => 'Ошибка оплаты']);
    }

    private function generateCode(): string
    {
        do {
            $code = 'RW-GIFT-'.Str::upper(Str::random(8));
        } while (GiftCertificate::where('code', $code)->exists());

        return $code;
    }
}
