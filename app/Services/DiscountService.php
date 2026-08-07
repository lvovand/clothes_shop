<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\GiftCertificate;

/**
 * Промокод и подарочный сертификат, применённые к текущей корзине.
 *
 * Применённые коды живут в сессии, а суммы всегда пересчитываются здесь и
 * никогда не приходят с клиента: страница оформления показывает то же, что
 * потом посчитает сервер при создании заказа.
 *
 * Промокод даёт скидку с суммы товаров (доставка не дисконтируется),
 * сертификат — это средство платежа: он гасит уже итоговую сумму со доставкой.
 */
class DiscountService
{
    public const COUPON_SESSION_KEY = 'coupon_code';

    public const GIFT_SESSION_KEY = 'gift_certificate_code';

    public function __construct(private readonly OrderService $orders)
    {
    }

    /**
     * @param  array<int,int>  $cart  variant_id => qty
     * @return array{subtotal:float, coupon:?Coupon, discount:float, shipping:float, gift:?GiftCertificate, gift_used:float, total:float}
     */
    public function totals(array $cart, float $shippingCost = 0.0): array
    {
        $subtotal = $this->orders->subtotalFor($cart);

        $coupon = $this->currentCoupon($subtotal);
        $discount = $coupon ? $coupon->discountFor($subtotal) : 0.0;

        $payable = max(0.0, round($subtotal - $discount + $shippingCost, 2));

        $gift = $this->currentGiftCertificate();
        $giftUsed = $gift ? round(min((float) $gift->remaining_balance, $payable), 2) : 0.0;

        return [
            'subtotal' => round($subtotal, 2),
            'coupon' => $coupon,
            'discount' => $discount,
            'shipping' => round($shippingCost, 2),
            'gift' => $gift,
            'gift_used' => $giftUsed,
            'total' => round($payable - $giftUsed, 2),
        ];
    }

    /**
     * Купон из сессии, если он всё ещё применим к этой корзине. Если условия
     * перестали выполняться (например, товар удалили и сумма упала ниже
     * минимальной), код молча забывается — иначе покупатель видел бы скидку,
     * которую сервер при оформлении не даст.
     */
    public function currentCoupon(float $subtotal): ?Coupon
    {
        $code = session(self::COUPON_SESSION_KEY);
        if (! $code) {
            return null;
        }

        $coupon = Coupon::findByCode($code);
        if (! $coupon || $coupon->rejectionReason($subtotal) !== null) {
            session()->forget(self::COUPON_SESSION_KEY);

            return null;
        }

        return $coupon;
    }

    public function currentGiftCertificate(): ?GiftCertificate
    {
        $code = session(self::GIFT_SESSION_KEY);
        if (! $code) {
            return null;
        }

        $gift = static::findGiftCertificate($code);
        if (! $gift) {
            session()->forget(self::GIFT_SESSION_KEY);

            return null;
        }

        return $gift;
    }

    /** Сертификат, которым реально можно заплатить: выпущен и с остатком. */
    public static function findGiftCertificate(string $code): ?GiftCertificate
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return GiftCertificate::whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
            ->where('status', 'active')
            ->where('remaining_balance', '>', 0)
            ->first();
    }
}
