<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\GiftCertificate;
use App\Models\Order;
use App\Models\ShippingMethod;
use App\Models\Variant;
use App\Services\Cdek\CdekClient;
use App\Services\YandexDelivery\YandexDeliveryClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        private readonly CdekClient $cdek,
        private readonly YandexDeliveryClient $yandexDelivery,
    ) {
    }

    /**
     * @param  array<int,int>  $cart  variant_id => qty
     */
    /**
     * Стоимость доставки. Способ сам знает, кто везёт (`provider`), — раньше это
     * решалось сравнением с кодами способов, и каждый новый способ требовал правки
     * условий здесь.
     *
     * @param  array<int,int>  $cart  variant_id => qty
     * @param  array{pvz_code?: ?string, address?: ?string}  $destination
     */
    public function calculateShippingCost(ShippingMethod $method, array $cart, ?string $city = null, array $destination = []): ?float
    {
        $subtotal = $this->subtotalFor($cart);

        if ($method->free_from_amount !== null && $subtotal >= (float) $method->free_from_amount) {
            return 0.0;
        }

        return match ($method->provider()) {
            'cdek' => $this->cdekCost($method, $cart, $city),
            'yandex' => $this->yandexCost($method, $cart, $city, $destination),
            default => (float) ($method->flat_cost ?? 0),
        };
    }

    private function cdekCost(ShippingMethod $method, array $cart, ?string $city): ?float
    {
        if (! $city) {
            return (float) ($method->flat_cost ?? 0);
        }

        $cityCode = $this->cdek->findCityCode($city);
        if (! $cityCode) {
            return null;
        }

        $weight = $this->totalWeightGrams($cart);
        $tariffCode = $method->config['tariff_code'] ?? null;
        $tariffs = $this->cdek->calculateTariffs(['code' => $cityCode], [$tariffCode], $weight);

        return $tariffs[0]['delivery_sum'] ?? null;
    }

    /**
     * Яндекс считает цену не по городу, а по конкретной точке доставки: пункт выдачи
     * (его идентификатор) либо адрес получателя. Пока покупатель их не указал,
     * посчитать нечего — возвращаем null, и чекаут просит уточнить данные.
     */
    private function yandexCost(ShippingMethod $method, array $cart, ?string $city, array $destination): ?float
    {
        $to = [];

        if ($method->needsPickupPoint()) {
            if (empty($destination['pvz_code'])) {
                return null;
            }
            $to['point_id'] = $destination['pvz_code'];
        } else {
            $address = trim(implode(', ', array_filter([$city, $destination['address'] ?? null])));
            if ($address === '') {
                return null;
            }
            $to['address'] = $address;
        }

        $quote = $this->yandexDelivery->quote(
            $this->yandexItems($cart),
            $to,
            'quote-'.Str::random(12),
            $this->yandexDims(),
        );

        return $quote['cost'] ?? null;
    }

    /** @return array<int, array{name: string, article: string, qty: int, price: float}> */
    private function yandexItems(array $cart): array
    {
        $variants = Variant::with('product')->whereIn('id', array_keys($cart))->get()->keyBy('id');
        $items = [];

        foreach ($cart as $variantId => $qty) {
            $variant = $variants->get($variantId);
            if (! $variant) {
                continue;
            }

            $items[] = [
                'name' => (string) ($variant->product->name ?? 'Товар'),
                'article' => (string) ($variant->sku ?: 'variant-'.$variant->id),
                'qty' => (int) $qty,
                'price' => (float) $variant->currentPrice(),
                // Свой вес товара, если он заполнен; иначе — значение из настроек.
                'weight' => $variant->product?->weight_kg
                    ? (int) round((float) $variant->product->weight_kg * 1000)
                    : null,
            ];
        }

        return $items;
    }

    /** @return array{weight: int, dx: int, dy: int, dz: int} */
    private function yandexDims(): array
    {
        return [
            'weight' => (int) (\App\Models\SiteSetting::get('yandex_delivery_weight') ?: 500),
            'dx' => (int) (\App\Models\SiteSetting::get('yandex_delivery_dx') ?: 30),
            'dy' => (int) (\App\Models\SiteSetting::get('yandex_delivery_dy') ?: 25),
            'dz' => (int) (\App\Models\SiteSetting::get('yandex_delivery_dz') ?: 10),
        ];
    }

    /**
     * @param  array<int,int>  $cart  variant_id => qty
     */
    public function subtotalFor(array $cart): float
    {
        $variants = Variant::whereIn('id', array_keys($cart))->get()->keyBy('id');
        $total = 0.0;
        foreach ($cart as $variantId => $qty) {
            $variant = $variants->get($variantId);
            if ($variant) {
                $total += $variant->currentPrice() * $qty;
            }
        }

        return $total;
    }

    private function totalWeightGrams(array $cart): float
    {
        $variants = Variant::with('product')->whereIn('id', array_keys($cart))->get()->keyBy('id');
        $grams = 0.0;
        foreach ($cart as $variantId => $qty) {
            $variant = $variants->get($variantId);
            $weightKg = $variant?->product?->weight_kg ?? 0.5; // sensible default if not set
            $grams += $weightKg * 1000 * $qty;
        }

        return max($grams, 100);
    }

    /**
     * @param  array<int,int>  $cart  variant_id => qty
     * @param  array{name:string, phone:string, email:string, city:string, address?:string, pvz_code?:string, pvz_address?:string}  $customer
     * @param  ?string  $couponCode  промокод из сессии — скидка считается здесь заново, от фактической суммы позиций
     * @param  ?string  $giftCode  подарочный сертификат — списывается с баланса в этой же транзакции
     */
    public function createFromCart(
        array $cart,
        ShippingMethod $shippingMethod,
        float $shippingCost,
        string $paymentMethod,
        array $customer,
        ?string $comment = null,
        ?string $couponCode = null,
        ?string $giftCode = null,
    ): Order {
        return DB::transaction(function () use ($cart, $shippingMethod, $shippingCost, $paymentMethod, $customer, $comment, $couponCode, $giftCode) {
            $variants = Variant::with('product', 'attributeValues')->whereIn('id', array_keys($cart))->lockForUpdate()->get()->keyBy('id');

            $subtotal = 0.0;
            $lineItems = [];
            foreach ($cart as $variantId => $qty) {
                $variant = $variants->get($variantId);
                if (! $variant || ! $variant->inStock()) {
                    continue;
                }
                $qty = min($qty, $variant->stock_qty);
                $unitPrice = $variant->currentPrice();
                $lineTotal = $unitPrice * $qty;
                $subtotal += $lineTotal;

                $lineItems[] = [
                    'variant_id' => $variant->id,
                    'product_title_snapshot' => $variant->product->name,
                    'variant_attrs_snapshot' => $variant->attributeValues->pluck('label')->implode(', '),
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ];
            }

            // Скидка и сертификат считаются здесь, а не приходят со страницы: сумма
            // позиций могла измениться прямо сейчас (товар кончился и строка выпала),
            // и порог «промокод от N ₽» должен проверяться по фактической сумме.
            $coupon = $couponCode ? Coupon::findByCode($couponCode) : null;
            if ($coupon && $coupon->rejectionReason($subtotal) !== null) {
                $coupon = null;
            }
            $discount = $coupon ? $coupon->discountFor($subtotal) : 0.0;

            $payable = max(0.0, round($subtotal - $discount + $shippingCost, 2));

            // Сертификат блокируем на чтение: два параллельных заказа с одним кодом
            // иначе списали бы один и тот же остаток дважды.
            $gift = $giftCode
                ? GiftCertificate::whereRaw('LOWER(code) = ?', [mb_strtolower(trim($giftCode))])
                    ->where('status', 'active')
                    ->where('remaining_balance', '>', 0)
                    ->lockForUpdate()
                    ->first()
                : null;
            $giftUsed = $gift ? round(min((float) $gift->remaining_balance, $payable), 2) : 0.0;

            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'status' => 'new',
                'customer_name' => $customer['name'],
                'customer_phone' => $customer['phone'],
                'customer_email' => $customer['email'],
                'shipping_method_id' => $shippingMethod->id,
                'shipping_address' => array_filter([
                    'city' => $customer['city'] ?? null,
                    'address' => $customer['address'] ?? null,
                    'pvz_code' => $customer['pvz_code'] ?? null,
                    'pvz_address' => $customer['pvz_address'] ?? null,
                ]),
                'shipping_cost' => $shippingCost,
                'payment_method' => $paymentMethod,
                'payment_status' => 'pending',
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'coupon_code' => $coupon?->code,
                'gift_certificate_code' => $giftUsed > 0 ? $gift->code : null,
                'gift_certificate_used' => $giftUsed,
                'total' => round($payable - $giftUsed, 2),
                'comment' => $comment,
            ]);

            foreach ($lineItems as $item) {
                $order->items()->create($item);
                Variant::where('id', $item['variant_id'])->decrement('stock_qty', $item['qty']);
            }

            $coupon?->increment('used_count');

            if ($giftUsed > 0) {
                $remaining = round((float) $gift->remaining_balance - $giftUsed, 2);
                $gift->update([
                    'remaining_balance' => $remaining,
                    'status' => $remaining <= 0 ? 'redeemed' : $gift->status,
                ]);
                $gift->redemptions()->create([
                    'order_id' => $order->id,
                    'amount_used' => $giftUsed,
                ]);
            }

            return $order->fresh('items');
        });
    }

    private function generateOrderNumber(): string
    {
        do {
            $number = 'RW-'.now()->format('ymd').'-'.Str::upper(Str::random(4));
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }
}
