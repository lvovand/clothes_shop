<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\ShippingMethod;
use App\Models\SiteSetting;
use App\Models\Variant;
use App\Services\Address\AddressSuggest;
use App\Services\Cdek\CdekClient;
use App\Services\DiscountService;
use App\Services\OrderService;
use App\Services\ReceiptBuilder;
use App\Services\StockService;
use App\Services\TBank\TBankClient;
use App\Services\Telegram\TelegramNotifier;
use App\Services\YandexDelivery\YandexDeliveryClient;
use App\Services\Shipping\ShipmentDispatcher;
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
        private readonly AddressSuggest $addressSuggest,
        private readonly StockService $stock,
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

        // Самовывоз возможен, только если весь заказ лежит на складе с выдачей:
        // товар из Оренбурга покупателю в Москве через прилавок не выдать.
        $pickupAvailable = $this->stock->pickupCoversCart($cart);
        $isDisabled = fn (ShippingMethod $method) => $method->kind() === 'pickup' && ! $pickupAvailable;

        // Суммы на первой отрисовке считаются для метода, который выбран по
        // умолчанию (первый доступный) — иначе страница показывала бы «доставка: —»
        // до первого клика, чего у эталона нет.
        $selectedMethod = $shippingMethods->reject($isDisabled)->first();
        $shipping = $selectedMethod
            ? $this->orders->calculateShipping($selectedMethod, $cart, old('city'))
            : ['cost' => 0.0, 'days' => null];
        $shippingCost = $shipping['cost'];

        return view('checkout', [
            'title' => 'Оформление заказа',
            'items' => $variants,
            'shippingMethods' => $shippingMethods,
            'selectedMethod' => $selectedMethod,
            'pickupAvailable' => $pickupAvailable,
            'totals' => $this->discounts->totals($cart, $shippingCost ?? 0.0),
            // Способ по умолчанию может требовать адрес/пункт выдачи, которых ещё нет —
            // тогда в строке доставки прочерк, а не ноль (ноль читается как «бесплатно»).
            'shippingUnknown' => $shippingCost === null,
            'shippingDays' => $shipping['days'],
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

        $shipping = $method
            ? $this->orders->calculateShipping($method, $cart, $data['city'] ?? null, [
                'pvz_code' => $data['pvz_code'] ?? null,
                'address' => $data['address'] ?? null,
            ])
            : ['cost' => 0.0, 'days' => null];
        $cost = $shipping['cost'];

        // Нерассчитанная доставка отдаётся как null, а не как 0 и не 422-й ошибкой:
        // при 422 страница молча оставляла в итогах прежний ноль, и покупатель видел
        // «доставка 0 ₽» там, где цену ещё нельзя узнать (адрес/пункт не указаны).
        return response()->json(['ok' => true] + $this->quotePayload($cart, $cost, $shipping['days']));
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
        $shipping = $method
            ? $this->orders->calculateShipping($method, $cart, $data['city'] ?? null, [
                'pvz_code' => $data['pvz_code'] ?? null,
                'address' => $data['address'] ?? null,
            ])
            : ['cost' => 0.0, 'days' => null];

        return $this->quotePayload($cart, $shipping['cost'], $shipping['days']);
    }

    /**
     * @param  ?float  $shippingCost  null = стоимость доставки пока неизвестна
     *                                (не указан адрес/пункт выдачи или перевозчик не ответил)
     */
    private function quotePayload(array $cart, ?float $shippingCost, ?string $shippingDays = null): array
    {
        $totals = $this->discounts->totals($cart, $shippingCost ?? 0.0);

        return [
            'subtotal' => $totals['subtotal'],
            'discount' => $totals['discount'],
            'shipping_cost' => $shippingCost === null ? null : $totals['shipping'],
            'shipping_unknown' => $shippingCost === null,
            // Примерный срок от перевозчика («1–2 дня»); null — показывать нечего.
            'shipping_days' => $shippingDays,
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
                Log::error('Telegram notify failed', ['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Заявка в Яндекс Доставке — после отдачи ответа покупателю: это внешний вызов,
     * который не должен задерживать оформление. Ошибки только логируются и уходят
     * в Telegram, чтобы заказ не остался без доставки незамеченным.
     */
    private function dispatchDelivery(Order $order): void
    {
        app()->terminating(function () use ($order) {
            try {
                $result = app(ShipmentDispatcher::class)->dispatch($order);
            } catch (\Throwable $e) {
                Log::error('Shipment dispatch failed', ['order' => $order->id, 'error' => $e->getMessage()]);

                return;
            }

            $reason = $result['reason'] ?? null;

            if (app(ShipmentDispatcher::class)->isQuiet($reason) || $reason === ShipmentDispatcher::REASON_ALREADY) {
                return;
            }

            $telegram = app(TelegramNotifier::class);

            try {
                if ($result['ok']) {
                    // Заказ с двух складов уезжает двумя отправлениями — сообщаем
                    // о каждом, номера у них разные.
                    foreach ($result['shipments'] ?? array_filter([$result['shipment'] ?? null]) as $shipment) {
                        $telegram->shipmentCreated($order, (string) $shipment->tracking_number, $shipment->pvz_address);
                    }

                    if ($reason) {
                        $telegram->shipmentFailed($order, $reason);
                    }
                } else {
                    $telegram->shipmentFailed($order, (string) ($result['reason'] ?? 'неизвестная причина'));
                }
            } catch (\Throwable $e) {
                Log::error('Telegram notify failed', ['error' => $e->getMessage()]);
            }
        });
    }

    /** AJAX: подсказки городов для поля «Выбрать город» (данные СДЭК). */
    public function cities(Request $request)
    {
        $data = $request->validate(['q' => ['required', 'string', 'max:100']]);

        $query = trim($data['q']);

        // Адресный справочник (DaData) точнее справочника перевозчика и знает
        // посёлки, а названия у него официальные (ФИАС) — их перевозчики
        // находят у себя. Поэтому, когда он настроен, спрашиваем сначала его.
        if ($this->addressSuggest->hasPreciseSource()) {
            $cities = $this->addressSuggest->suggestCities($query);

            if ($cities !== []) {
                return response()->json(['cities' => $cities]);
            }
        }

        // СДЭК дёргаем, только если он реально возит: раньше его справочник
        // использовался всегда «просто как список названий», и выключенный
        // перевозчик всё равно получал запрос на каждое нажатие клавиши.
        $cdekIsUsed = ShippingMethod::where('is_enabled', true)
            ->get()
            ->contains(fn (ShippingMethod $method) => $method->provider() === 'cdek');

        if ($cdekIsUsed && $this->cdek->isConfigured()) {
            $cities = $this->cdek->suggestCities($query);

            // Пустой ответ здесь — это, как правило, не «нет такого города», а
            // отвалившийся ключ или недоступный API: молча оставлять человека без
            // подсказок из-за этого не стоит, ниже есть другие источники.
            if ($cities !== []) {
                return response()->json(['cities' => $cities]);
            }
        }

        // Иначе — тот же источник, что и у подсказок улиц (OSM). Город всё равно
        // разрешает перевозчик: если он его не знает, расчёт вернёт «уточните город».
        $cities = $this->addressSuggest->suggestCities($query);

        if ($cities !== []) {
            return response()->json(['cities' => $cities]);
        }

        // Последний рубеж — справочник Яндекса. Он годится только для почти
        // полностью введённого названия, поэтому это именно запасной путь.
        $cities = collect($this->yandexDelivery->detectLocation($query))
            ->map(fn ($variant) => explode(',', $variant['address'])[0])
            ->unique()
            ->values()
            ->map(fn ($city) => ['city' => $city, 'label' => $city])
            ->all();

        return response()->json(['cities' => $cities]);
    }

    /**
     * AJAX: подсказки улицы и дома для доставки курьером.
     *
     * Нужны прежде всего Яндекс Доставке до двери — она считает цену по конкретному
     * адресу, и опечатка в улице оборачивается «не удалось рассчитать доставку».
     */
    public function streets(Request $request)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            // Заполнено, когда подсказка нужна полю «Дом»: улица уже выбрана и
            // подсказывать надо только номера на ней.
            'street' => ['nullable', 'string', 'max:100'],
        ]);

        $city = $data['city'] ?? null;

        $addresses = ($data['street'] ?? '') !== ''
            ? $this->addressSuggest->suggestHouses($data['street'], $data['q'], $city, numbersOnly: true)
            : $this->addressSuggest->suggest($data['q'], $city);

        return response()->json(['addresses' => $addresses]);
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

        // То же правило, что гасит радиокнопку на странице, но проверенное на сервере:
        // выбор способа приходит из формы, ей верить нельзя.
        if ($shippingMethod->kind() === 'pickup' && ! $this->stock->pickupCoversCart($cart)) {
            return back()->withInput()->with('error', 'Самовывоз недоступен: товара нет на складе самовывоза. Выберите доставку.');
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
