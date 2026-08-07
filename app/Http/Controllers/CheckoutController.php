<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\ShippingMethod;
use App\Models\SiteSetting;
use App\Models\Variant;
use App\Services\Cdek\CdekClient;
use App\Services\YandexDelivery\YandexDeliveryClient;
use App\Services\DiscountService;
use App\Services\OrderService;
use App\Services\ReceiptBuilder;
use App\Services\TBank\TBankClient;
use App\Services\Telegram\TelegramNotifier;
use App\Services\YandexPay\YandexPayClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly CdekClient $cdek,
        private readonly YandexDeliveryClient $yandexDelivery,
        private readonly DiscountService $discounts,
        private readonly ReceiptBuilder $receipts,
    ) {}

    public function index()
    {
        $cart = session('cart', []);
        $variants = Variant::with(['product.images', 'attributeValues'])
            ->whereIn('id', array_keys($cart))
            ->get()
            ->map(function (Variant $variant) use ($cart) {
                $variant->qty = $cart[$variant->id];
                $variant->lineTotal = $variant->currentPrice() * $variant->qty;

                return $variant;
            });

        $shippingMethods = ShippingMethod::where('is_enabled', true)->orderBy('sort_order')->get();

        // Суммы на первой отрисовке считаются для метода, который выбран по
        // умолчанию (первый в списке) — иначе страница показывала бы «доставка: —»
        // до первого клика, чего у эталона нет.
        $selectedMethod = $shippingMethods->first();
        $shippingCost = $selectedMethod
            ? ($this->orders->calculateShippingCost($selectedMethod, $cart, old('city')) ?? 0.0)
            : 0.0;

        return view('checkout', [
            'title' => 'Оформление заказа',
            'items' => $variants,
            'shippingMethods' => $shippingMethods,
            'selectedMethod' => $selectedMethod,
            'totals' => $this->discounts->totals($cart, $shippingCost),
            'yandexMapApiKey' => SiteSetting::get('yandex_map_api_key', config('services.cdek.yandex_map_api_key')),
            // Онлайн-эквайеры берутся из админки («Настройки → Способы оплаты»),
            // оплата при получении живёт отдельно: она не платёжный шлюз, а признак
            // способа доставки (cod_allowed).
            'onlinePaymentMethods' => PaymentMethod::active()->orderBy('sort_order')->get(),
            // Нужен бейджам Яндекса рядом со способами оплаты: они сами считают
            // размер платежа в Сплит и кешбэк по сумме заказа.
            'yandexPayMerchantId' => SiteSetting::get('yandex_pay_merchant_id', config('services.yandex_pay.merchant_id')),
        ]);
    }

    /**
     * AJAX: все суммы заказа для выбранного способа доставки и города —
     * стоимость доставки, скидка промокода, списание с сертификата, итог.
     * Страница оформления никогда не считает суммы сама: и она, и создание
     * заказа берут их отсюда, чтобы показанное и посчитанное не разошлись.
     */
    public function quote(Request $request)
    {
        $data = $request->validate([
            'shipping_method' => ['nullable', 'exists:shipping_methods,code'],
            'city' => ['nullable', 'string'],
            // Яндекс считает цену по конкретной точке доставки, а не по городу.
            'pvz_code' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $cart = session('cart', []);
        $method = isset($data['shipping_method'])
            ? ShippingMethod::where('code', $data['shipping_method'])->first()
            : null;

        $cost = $method
            ? $this->orders->calculateShippingCost($method, $cart, $data['city'] ?? null, [
                'pvz_code' => $data['pvz_code'] ?? null,
                'address' => $data['address'] ?? null,
            ])
            : 0.0;
        if ($cost === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Не удалось рассчитать стоимость доставки для этого города',
            ], 422);
        }

        return response()->json(['ok' => true] + $this->quotePayload($cart, $cost));
    }

    /** Применить или снять промокод (пустой код = снять). */
    public function applyCoupon(Request $request)
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:64'],
            'shipping_method' => ['nullable', 'exists:shipping_methods,code'],
            'city' => ['nullable', 'string'],
        ]);

        $cart = session('cart', []);
        $code = trim((string) ($data['code'] ?? ''));

        if ($code === '') {
            session()->forget(DiscountService::COUPON_SESSION_KEY);

            return response()->json(['ok' => true, 'valid' => false, 'message' => null] + $this->quoteFor($data, $cart));
        }

        $coupon = Coupon::findByCode($code);
        $subtotal = $this->orders->subtotalFor($cart);
        $reason = $coupon ? $coupon->rejectionReason($subtotal) : 'Промокод не найден';

        if ($reason !== null) {
            session()->forget(DiscountService::COUPON_SESSION_KEY);

            return response()->json(['ok' => true, 'valid' => false, 'message' => $reason] + $this->quoteFor($data, $cart));
        }

        session([DiscountService::COUPON_SESSION_KEY => $coupon->code]);

        return response()->json(['ok' => true, 'valid' => true, 'message' => 'Готово!'] + $this->quoteFor($data, $cart));
    }

    /** Применить или снять подарочный сертификат (пустой номер = снять). */
    public function applyGiftCertificate(Request $request)
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:64'],
            'shipping_method' => ['nullable', 'exists:shipping_methods,code'],
            'city' => ['nullable', 'string'],
        ]);

        $cart = session('cart', []);
        $code = trim((string) ($data['code'] ?? ''));

        if ($code === '') {
            session()->forget(DiscountService::GIFT_SESSION_KEY);

            return response()->json(['ok' => true, 'valid' => false, 'message' => null] + $this->quoteFor($data, $cart));
        }

        $gift = DiscountService::findGiftCertificate($code);
        if (! $gift) {
            session()->forget(DiscountService::GIFT_SESSION_KEY);

            return response()->json([
                'ok' => true, 'valid' => false, 'message' => 'Сертификат не найден',
            ] + $this->quoteFor($data, $cart));
        }

        session([DiscountService::GIFT_SESSION_KEY => $gift->code]);

        return response()->json(['ok' => true, 'valid' => true, 'message' => 'Готово!'] + $this->quoteFor($data, $cart));
    }

    /**
     * Суммы для запроса, в котором способ доставки и город — вспомогательные
     * поля (применение промокода/сертификата). Если стоимость доставки
     * посчитать не удалось, считаем её нулевой: ошибка расчёта доставки не
     * должна выглядеть как отказ применить промокод.
     */
    private function quoteFor(array $data, array $cart): array
    {
        $method = isset($data['shipping_method'])
            ? ShippingMethod::where('code', $data['shipping_method'])->first()
            : null;
        $cost = $method
            ? $this->orders->calculateShippingCost($method, $cart, $data['city'] ?? null, [
                'pvz_code' => $data['pvz_code'] ?? null,
                'address' => $data['address'] ?? null,
            ])
            : 0.0;

        return $this->quotePayload($cart, $cost ?? 0.0);
    }

    private function quotePayload(array $cart, float $shippingCost): array
    {
        $totals = $this->discounts->totals($cart, $shippingCost);

        return [
            'subtotal' => $totals['subtotal'],
            'discount' => $totals['discount'],
            'shipping_cost' => $totals['shipping'],
            'gift_used' => $totals['gift_used'],
            'total' => $totals['total'],
            'coupon_code' => $totals['coupon']?->code,
            'gift_code' => $totals['gift']?->code,
        ];
    }

    /**
     * Уведомление отправляется после того, как ответ ушёл покупателю: запрос к
     * Telegram идёт через зарубежный прокси и может занять секунды. Ошибка
     * уведомления никогда не должна отражаться на оформлении заказа.
     */
    private function notifyTelegram(callable $callback): void
    {
        app()->terminating(function () use ($callback) {
            try {
                $callback(app(TelegramNotifier::class));
            } catch (\Throwable $e) {
                Log::warning('Telegram notify failed', ['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Заявка в Яндекс Доставке — после отдачи ответа покупателю: это внешний вызов,
     * который не должен задерживать оформление. Ошибки только логируются и уходят
     * в Telegram, чтобы заказ не остался без доставки незамеченным.
     */
    private function dispatchDelivery(\App\Models\Order $order): void
    {
        app()->terminating(function () use ($order) {
            try {
                $result = app(\App\Services\YandexDelivery\YandexDeliveryDispatcher::class)->dispatch($order);
            } catch (\Throwable $e) {
                Log::error('Yandex Delivery dispatch failed', ['order' => $order->id, 'error' => $e->getMessage()]);

                return;
            }

            $quiet = [
                \App\Services\YandexDelivery\YandexDeliveryDispatcher::REASON_NOT_YANDEX,
                \App\Services\YandexDelivery\YandexDeliveryDispatcher::REASON_AUTO_OFF,
                \App\Services\YandexDelivery\YandexDeliveryDispatcher::REASON_ALREADY,
            ];

            if (in_array($result['reason'] ?? '', $quiet, true)) {
                return;
            }

            $telegram = app(TelegramNotifier::class);

            try {
                if ($result['ok']) {
                    $telegram->shipmentCreated($order, (string) $result['shipment']->tracking_number, $result['shipment']->pvz_address);
                } else {
                    $telegram->shipmentFailed($order, (string) ($result['reason'] ?? 'неизвестная причина'));
                }
            } catch (\Throwable $e) {
                Log::warning('Telegram notify failed', ['error' => $e->getMessage()]);
            }
        });
    }

    /** AJAX: подсказки городов для поля «Выбрать город» (данные СДЭК). */
    public function cities(Request $request)
    {
        $data = $request->validate(['q' => ['required', 'string', 'max:100']]);

        $query = trim($data['q']);

        // Подсказки городов берём у СДЭК всегда, когда его ключи заданы — даже если
        // сам способ доставки СДЭК выключен: это просто справочник названий.
        // У Яндекс Доставки автодополнения нет вовсе (её location/detect на «Мос»
        // отвечает «Владимир»), а геокодер Яндекс.Карт наш ключ не принимает —
        // он только для JS API. Выбранный город всё равно разрешает Яндекс: если
        // он его не знает, расчёт вернёт «уточните город».
        if ($this->cdek->isConfigured()) {
            return response()->json(['cities' => $this->cdek->suggestCities($query)]);
        }

        // Ключей СДЭК нет — остаётся справочник Яндекса. Он годится только для
        // почти полностью введённого названия, поэтому это именно запасной путь.
        $cities = collect($this->yandexDelivery->detectLocation($query))
            ->map(fn ($variant) => explode(',', $variant['address'])[0])
            ->unique()
            ->values()
            ->all();

        return response()->json(['cities' => $cities]);
    }

    /** AJAX: пункты выдачи СДЭК в выбранном городе (нужны его виджету карты). */
    public function pickupPoints(Request $request)
    {
        $data = $request->validate(['city' => ['required', 'string']]);

        $cityCode = $this->cdek->findCityCode($data['city']);
        if (! $cityCode) {
            return response()->json(['ok' => false, 'points' => []]);
        }

        $points = $this->cdek->getPickupPoints($cityCode) ?? [];

        return response()->json(['ok' => true, 'points' => $points]);
    }

    /** AJAX: пункты выдачи Яндекс Доставки в выбранном городе. */
    public function yandexPickupPoints(Request $request)
    {
        $data = $request->validate(['city' => ['required', 'string', 'max:255']]);

        $geoId = $this->yandexDelivery->findGeoId(trim($data['city']));

        if (! $geoId) {
            return response()->json(['ok' => false, 'points' => []]);
        }

        $points = collect($this->yandexDelivery->pickupPoints($geoId));

        // Точек в крупном городе больше тысячи, поэтому в списке первыми должны идти
        // ближние к центру города, а не первые по алфавиту адреса (иначе Москва
        // открывалась списком пунктов в Зеленограде). Центр считаем как медиану
        // координат: своих координат города API не отдаёт.
        $centerLat = $this->median($points->pluck('latitude')->all());
        $centerLon = $this->median($points->pluck('longitude')->all());

        $points = $points
            ->sortBy(fn ($point) => ($point['latitude'] - $centerLat) ** 2 + ($point['longitude'] - $centerLon) ** 2)
            ->values()
            ->all();

        return response()->json(['ok' => true, 'points' => $points]);
    }

    /** @param  array<int, float>  $values */
    private function median(array $values): float
    {
        $values = array_values(array_filter($values));

        if (! $values) {
            return 0.0;
        }

        sort($values);

        return (float) $values[intdiv(count($values), 2)];
    }

    /** Включён ли хоть один способ доставки Яндекса. */
    private function yandexEnabled(): bool
    {
        return ShippingMethod::where('is_enabled', true)->get()
            ->contains(fn (ShippingMethod $method) => $method->provider() === 'yandex');
    }

    public function store(Request $request, TBankClient $tbank, YandexPayClient $yandexPay)
    {
        $cart = session('cart', []);
        if (empty($cart)) {
            return redirect()->route('checkout.index')->with('error', 'Корзина пуста');
        }

        // Поля разбиты так же, как в форме эталона (имя/фамилия отдельно, адрес
        // по частям улица/дом/квартира) — в заказе они складываются в те же две
        // строки, что и раньше, схема заказа от этого не меняется.
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'shipping_method' => ['required', Rule::exists('shipping_methods', 'code')->where('is_enabled', true)],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'house' => ['nullable', 'string', 'max:64'],
            'room' => ['nullable', 'string', 'max:64'],
            // Раньше здесь стоял required_if по коду 'cdek_pvz' — с появлением ПВЗ
            // Яндекса требование привязано к самому способу.
            'pvz_code' => [
                'nullable', 'string', 'max:64',
                function (string $attribute, $value, $fail) use ($request) {
                    $method = ShippingMethod::where('code', $request->input('shipping_method'))->first();
                    if ($method?->needsPickupPoint() && ! $value) {
                        $fail('Выберите пункт выдачи');
                    }
                },
            ],
            'pvz_address' => ['nullable', 'string', 'max:500'],
            // 'cod' — оплата при получении, остальное — ключи активных эквайеров
            // из админки (tbank, yandex_pay, yandex_split).
            'payment_method' => ['required', Rule::in(
                PaymentMethod::active()->pluck('key')->push('cod')->all()
            )],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['name'] = trim($data['first_name'].' '.($data['last_name'] ?? ''));
        $data['address'] = implode(', ', array_filter([
            $data['region'] ?? null,
            $data['street'] ?? null,
            $data['house'] ?? null,
            $data['room'] ?? null,
        ])) ?: null;

        $shippingMethod = ShippingMethod::where('code', $data['shipping_method'])->firstOrFail();

        // Server-side enforcement of the one hard business rule carried over from the
        // old site: cash-on-delivery is only ever valid for methods that allow it.
        if ($data['payment_method'] === 'cod' && ! $shippingMethod->cod_allowed) {
            return back()->withInput()->with('error', 'Оплата при получении недоступна для выбранного способа доставки');
        }

        $shippingCost = $this->orders->calculateShippingCost($shippingMethod, $cart, $data['city'] ?? null, [
            'pvz_code' => $data['pvz_code'] ?? null,
            'address' => $data['address'] ?? null,
        ]);
        if ($shippingCost === null) {
            return back()->withInput()->with('error', 'Не удалось рассчитать стоимость доставки, проверьте город');
        }

        $order = $this->orders->createFromCart(
            $cart,
            $shippingMethod,
            $shippingCost,
            $data['payment_method'],
            [
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'city' => $data['city'] ?? null,
                'address' => $data['address'] ?? null,
                'pvz_code' => $data['pvz_code'] ?? null,
                'pvz_address' => $data['pvz_address'] ?? null,
            ],
            $data['comment'] ?? null,
            session(DiscountService::COUPON_SESSION_KEY),
            session(DiscountService::GIFT_SESSION_KEY),
        );

        session()->forget(['cart', DiscountService::COUPON_SESSION_KEY, DiscountService::GIFT_SESSION_KEY]);

        // Уведомление в Telegram отправляется после отдачи ответа покупателю:
        // запрос идёт через зарубежный прокси и не должен задерживать оформление.
        $this->notifyTelegram(fn (TelegramNotifier $telegram) => $telegram->orderCreated($order));

        // Сертификат покрыл заказ целиком — платить нечего, к эквайеру не идём,
        // но заказ уже оплачен, значит заявку на доставку надо создать здесь же
        // (в остальных случаях это делает вебхук эквайера).
        if ((float) $order->total <= 0) {
            $order->update(['payment_status' => 'paid']);
            $this->dispatchDelivery($order);

            return redirect()->route('checkout.success', ['order' => $order->order_number]);
        }

        if ($data['payment_method'] === 'cod') {
            return redirect()->route('checkout.success', ['order' => $order->order_number]);
        }

        return $this->startPayment($order, $data['payment_method'], $tbank, $yandexPay);
    }

    /**
     * Увести покупателя на оплату выбранным эквайером.
     *
     * У Т-Банка идентификатор платежа выдаёт сам эквайер, у Яндекс Пэй такого нет —
     * там ключом служит наш же номер заказа, по нему потом и находится платёж при
     * получении вебхука.
     */
    private function startPayment(Order $order, string $method, TBankClient $tbank, YandexPayClient $yandexPay)
    {
        $failedUrl = route('checkout.failed', ['order' => $order->order_number]);

        if ($method === 'tbank') {
            $result = $tbank->init(
                $order->order_number,
                (float) $order->total,
                'Заказ '.$order->order_number,
                $this->receipts->build($order),
                $order->customer_email,
                $order->customer_phone,
            );

            if (! $result['success']) {
                $order->update(['payment_status' => 'failed']);

                return redirect()->away($failedUrl);
            }

            $order->payments()->create([
                'provider' => 'tbank',
                'provider_payment_id' => $result['payment_id'],
                'amount' => $order->total,
                'status' => 'pending',
            ]);

            return redirect()->away($result['payment_url']);
        }

        // Яндекс Пэй и Сплит — один шлюз, разница только в наборе способов,
        // предложенных покупателю на платёжной форме.
        $result = $yandexPay->createOrder(
            $order->order_number,
            $this->receipts->rows($order),
            $method === 'yandex_split' ? ['SPLIT'] : ['CARD'],
            route('checkout.success', ['order' => $order->order_number]),
            $failedUrl,
            $order->customer_email,
            $order->customer_phone,
        );

        if (! $result['success']) {
            $order->update(['payment_status' => 'failed']);

            return redirect()->away($failedUrl);
        }

        $order->payments()->create([
            'provider' => $method,
            'provider_payment_id' => $order->order_number,
            'amount' => $order->total,
            'status' => 'pending',
        ]);

        return redirect()->away($result['payment_url']);
    }

    public function success(string $order)
    {
        $order = Order::where('order_number', $order)->firstOrFail();

        return view('checkout-success', ['order' => $order, 'title' => 'Заказ оформлен']);
    }

    public function failed(string $order)
    {
        $order = Order::where('order_number', $order)->firstOrFail();

        return view('checkout-failed', ['order' => $order, 'title' => 'Ошибка оплаты']);
    }
}
