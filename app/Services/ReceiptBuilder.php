<?php

namespace App\Services;

use App\Models\Order;

/**
 * Позиции чека (объект Receipt в T-Bank Init) для заказа.
 *
 * Единственное жёсткое требование эквайера: сумма Amount всех позиций должна
 * точно равняться сумме платежа. Поэтому скидка промокода и списание с
 * подарочного сертификата не идут отдельными строками (отрицательных строк в
 * чеке не бывает) — они уменьшают цены самих позиций.
 *
 * Скидка распределяется по отдельным ЕДИНИЦАМ товара, а не по строкам: тогда
 * цена за единицу всегда целое число копеек, а строка при необходимости
 * распадается на две (например «2 × 4500» → «1 × 4500» + «1 × 4499»), и
 * копейки не теряются на округлении.
 */
class ReceiptBuilder
{
    /**
     * Позиции в формате Т-Банка (суммы в копейках).
     *
     * @return array<int,array<string,mixed>>
     */
    public function build(Order $order): array
    {
        return array_map(fn (array $row) => [
            'Name' => $row['name'],
            'Price' => $row['price_kop'],
            'Quantity' => $row['qty'],
            'Amount' => $row['price_kop'] * $row['qty'],
            'Tax' => 'none',
        ], $this->rows($order));
    }

    /**
     * Те же позиции в нейтральном виде — их формат под конкретного эквайера
     * приводит его собственный клиент (у Яндекс Пэй суммы строками в рублях).
     *
     * @return array<int,array{name:string,price_kop:int,qty:int}>
     */
    public function rows(Order $order): array
    {
        $subtotalKop = $this->kop($order->subtotal);
        $discountKop = $this->kop($order->discount_total);
        $shippingKop = $this->kop($order->shipping_cost);
        $totalKop = $this->kop($order->total);

        $payableKop = $subtotalKop - $discountKop + $shippingKop;
        if ($totalKop <= 0 || $payableKop <= 0) {
            return [];
        }

        // Сертификат гасит и товары, и доставку — делим его пропорционально,
        // чтобы доставка не оказалась «бесплатной» в чеке при частичной оплате.
        $goodsAfterCouponKop = $subtotalKop - $discountKop;
        $goodsTargetKop = (int) round($goodsAfterCouponKop * $totalKop / $payableKop);
        $shippingTargetKop = $totalKop - $goodsTargetKop;
        if ($shippingTargetKop < 0) {
            $goodsTargetKop = $totalKop;
            $shippingTargetKop = 0;
        }
        if ($shippingKop === 0) {
            $goodsTargetKop = $totalKop;
            $shippingTargetKop = 0;
        }

        $items = $this->goodsItems($order, $subtotalKop, $goodsTargetKop);

        if ($shippingTargetKop > 0) {
            $items[] = [
                'name' => 'Доставка',
                'price_kop' => $shippingTargetKop,
                'qty' => 1,
            ];
        }

        return $items;
    }

    /** @return array<int,array{name:string,price_kop:int,qty:int}> */
    private function goodsItems(Order $order, int $subtotalKop, int $goodsTargetKop): array
    {
        // Сначала цена каждой отдельной единицы товара, пропорционально её доле;
        // остаток от округления достаётся последней единице, поэтому сумма всех
        // единиц равна цели ровно, без «плюс-минус копейка».
        $units = [];
        foreach ($order->items as $item) {
            for ($i = 0; $i < $item->qty; $i++) {
                $units[] = [
                    'name' => mb_substr($item->product_title_snapshot, 0, 128),
                    'base' => $this->kop($item->unit_price),
                ];
            }
        }
        if ($units === []) {
            return [];
        }

        $assigned = 0;
        $last = count($units) - 1;
        foreach ($units as $i => &$unit) {
            $unit['price'] = $i === $last
                ? $goodsTargetKop - $assigned
                : (int) round($unit['base'] * $goodsTargetKop / max($subtotalKop, 1));
            $assigned += $unit['price'];
        }
        unset($unit);

        // Единицы с одинаковым названием и ценой снова собираются в одну строку
        // с Quantity — чек не должен без нужды распухать построчно.
        $rows = [];
        foreach ($units as $unit) {
            $key = $unit['name'].'|'.$unit['price'];
            $rows[$key] ??= ['name' => $unit['name'], 'price_kop' => $unit['price'], 'qty' => 0];
            $rows[$key]['qty']++;
        }

        return array_values($rows);
    }

    private function kop(float|string|null $rubles): int
    {
        return (int) round((float) $rubles * 100);
    }
}
