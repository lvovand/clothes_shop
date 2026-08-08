<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ShippingMethod;
use App\Models\StockMovement;
use App\Models\Variant;
use App\Models\VariantStock;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Единственное место, где меняется складской остаток.
 *
 * Правила, заданные владельцем:
 *  - самовывоз отгружается только со склада, где есть выдача (Москва);
 *  - доставка (СДЭК/Яндекс) отгружается из Оренбурга, а чего там не хватает —
 *    доезжает из Москвы;
 *  - товара нет ни на одном складе — SOLD OUT;
 *  - отмена или удаление заказа возвращает единицы на те склады, откуда списаны.
 *
 * `variants.stock_qty` больше не источник правды, а сумма по складам: держим её
 * в актуальном состоянии здесь, потому что на неё завязаны витрина и фильтры.
 */
class StockService
{
    /** Склады в порядке списания при доставке (Оренбург раньше Москвы). */
    public function warehouses(): Collection
    {
        return Warehouse::active()->get();
    }

    /** Склад самовывоза; null — если такого склада нет вовсе. */
    public function pickupWarehouse(): ?Warehouse
    {
        return $this->warehouses()->firstWhere('allows_pickup', true);
    }

    /**
     * Склады, с которых можно отгрузить этим способом доставки, в порядке списания.
     * Самовывоз — только свой склад, доставка — все.
     */
    public function warehousesFor(?ShippingMethod $method): Collection
    {
        if ($method && $method->kind() === 'pickup') {
            $pickup = $this->pickupWarehouse();

            return $pickup ? collect([$pickup]) : collect();
        }

        return $this->warehouses();
    }

    /** Остаток варианта на конкретном складе. */
    public function qtyAt(int $variantId, int $warehouseId): int
    {
        return (int) VariantStock::where('variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->value('qty');
    }

    /** Остатки варианта по всем складам: [warehouse_id => qty]. */
    public function qtyByWarehouse(int $variantId): array
    {
        return VariantStock::where('variant_id', $variantId)
            ->pluck('qty', 'warehouse_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }

    /**
     * Сколько единиц варианта можно продать этим способом доставки.
     * Без способа — всё, что есть на складах (этим живёт витрина).
     */
    public function available(int $variantId, ?ShippingMethod $method = null): int
    {
        $ids = $this->warehousesFor($method)->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return max(0, (int) VariantStock::where('variant_id', $variantId)
            ->whereIn('warehouse_id', $ids)
            ->sum('qty'));
    }

    /**
     * Хватает ли складов самовывоза на всю корзину. Гасит самовывоз на оформлении,
     * когда хоть одна позиция не покрыта московским остатком целиком.
     *
     * @param  array<int, int>  $cart  [variant_id => qty]
     */
    public function pickupCoversCart(array $cart): bool
    {
        $pickup = $this->pickupWarehouse();
        if (! $pickup || empty($cart)) {
            return (bool) $pickup;
        }

        $stocks = VariantStock::where('warehouse_id', $pickup->id)
            ->whereIn('variant_id', array_keys($cart))
            ->pluck('qty', 'variant_id');

        foreach ($cart as $variantId => $qty) {
            if ((int) ($stocks[$variantId] ?? 0) < (int) $qty) {
                return false;
            }
        }

        return true;
    }

    /**
     * Разложить нужное количество по складам в порядке списания.
     * Возвращает [warehouse_id => qty]; если товара не хватает, разложится меньше.
     *
     * @return array<int, int>
     */
    public function planAllocation(int $variantId, int $qty, ?ShippingMethod $method = null): array
    {
        $plan = [];
        $left = $qty;

        foreach ($this->warehousesFor($method) as $warehouse) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $this->qtyAt($variantId, $warehouse->id));
            if ($take > 0) {
                $plan[$warehouse->id] = $take;
                $left -= $take;
            }
        }

        return $plan;
    }

    /**
     * Списать позиции заказа со складов. Вызывается внутри транзакции оформления,
     * когда строки заказа уже созданы: у каждой проставляется её раскладка.
     */
    public function allocateForOrder(Order $order, ?ShippingMethod $method = null): void
    {
        foreach ($order->items as $item) {
            if (! $item->variant_id) {
                continue;
            }

            $plan = $this->planAllocation($item->variant_id, (int) $item->qty, $method);

            foreach ($plan as $warehouseId => $qty) {
                $this->apply($item->variant_id, (int) $warehouseId, -$qty, 'order', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);
            }

            $item->update(['stock_allocation' => $plan]);
        }
    }

    /**
     * Вернуть единицы заказа на склады, откуда они были списаны.
     * Идемпотентна: повторный вызов (отмена → удаление) ничего не прибавит.
     */
    public function returnForOrder(Order $order, string $reasonComment = 'Заказ отменён'): void
    {
        DB::transaction(function () use ($order, $reasonComment) {
            $fresh = Order::whereKey($order->getKey())->lockForUpdate()->first();
            if (! $fresh || $fresh->stock_returned_at !== null) {
                return;
            }

            foreach ($fresh->items as $item) {
                foreach ((array) $item->stock_allocation as $warehouseId => $qty) {
                    if ((int) $qty <= 0) {
                        continue;
                    }
                    $this->apply($item->variant_id, (int) $warehouseId, (int) $qty, 'return', [
                        'order_id' => $fresh->id,
                        'order_number' => $fresh->order_number,
                        'comment' => $reasonComment,
                    ]);
                }
            }

            // Пишем без модельных событий: иначе наблюдатель заказа сработает
            // на собственное обновление.
            Order::whereKey($fresh->getKey())->update(['stock_returned_at' => now()]);
            $order->stock_returned_at = now();
        });
    }

    /** Ручная корректировка остатка из админки (приход, списание, инвентаризация). */
    public function adjust(int $variantId, int $warehouseId, int $delta, ?string $comment = null): void
    {
        if ($delta === 0) {
            return;
        }

        $this->apply($variantId, $warehouseId, $delta, 'adjustment', [
            'user_id' => auth()->id(),
            'comment' => $comment,
        ]);
    }

    /** Выставить остаток на складе конкретным числом (движение пишется на разницу). */
    public function setQty(int $variantId, int $warehouseId, int $qty, ?string $comment = null): void
    {
        $current = $this->qtyAt($variantId, $warehouseId);
        $this->adjust($variantId, $warehouseId, $qty - $current, $comment);
    }

    /**
     * Единственная точка записи: меняет остаток, пишет движение и обновляет
     * суммарный кеш варианта.
     */
    protected function apply(int $variantId, int $warehouseId, int $delta, string $reason, array $extra = []): void
    {
        DB::transaction(function () use ($variantId, $warehouseId, $delta, $reason, $extra) {
            $stock = VariantStock::firstOrCreate(
                ['variant_id' => $variantId, 'warehouse_id' => $warehouseId],
                ['qty' => 0]
            );

            // Остаток не уводим в минус: продать больше, чем лежит, нельзя,
            // а «минус» в отчётах только запутает владельца.
            $stock->qty = max(0, $stock->qty + $delta);
            $stock->save();

            StockMovement::create(array_merge([
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'delta' => $delta,
                'reason' => $reason,
            ], $extra));

            $this->syncVariantTotal($variantId);
        });
    }

    /** Пересчитать суммарный остаток варианта — им живут витрина и фильтры каталога. */
    public function syncVariantTotal(int $variantId): void
    {
        $total = (int) VariantStock::where('variant_id', $variantId)->sum('qty');

        Variant::whereKey($variantId)->update(['stock_qty' => $total]);
    }
}
