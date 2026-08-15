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
 *  - доставка (СДЭК/Яндекс) отгружается с того склада, где товар лежит: заказ
 *    целиком уходит с одного склада, если он его покрывает, иначе делится на два
 *    отправления, и доставка считается по каждому от его города отгрузки;
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

        return $this->covers($pickup->id, $cart);
    }

    /**
     * Лежит ли вся корзина на одном складе.
     *
     * @param  array<int, int>  $cart  [variant_id => qty]
     */
    public function covers(int $warehouseId, array $cart): bool
    {
        if (empty($cart)) {
            return true;
        }

        $stocks = VariantStock::where('warehouse_id', $warehouseId)
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
     * Разложить корзину по складам: [warehouse_id => [variant_id => qty]].
     *
     * Заказ целиком уходит с одного склада, когда такой есть — два отправления
     * дороже одного и покупателю, и магазину. Если ни один склад не покрывает
     * заказ, позиции разбираются по очереди списания, и заказ поедет двумя
     * отправлениями. Чего не хватило нигде — в раскладку не попадает.
     *
     * @param  array<int, int>  $cart  [variant_id => qty]
     * @return array<int, array<int, int>>
     */
    public function planCart(array $cart, ?ShippingMethod $method = null): array
    {
        $cart = array_filter($cart, fn ($qty) => (int) $qty > 0);

        if (empty($cart)) {
            return [];
        }

        $warehouses = $this->warehousesFor($method);

        foreach ($warehouses as $warehouse) {
            if ($this->covers($warehouse->id, $cart)) {
                return [$warehouse->id => array_map('intval', $cart)];
            }
        }

        $plan = [];

        foreach ($cart as $variantId => $qty) {
            foreach ($this->planAllocation((int) $variantId, (int) $qty, $method) as $warehouseId => $take) {
                $plan[$warehouseId][(int) $variantId] = $take;
            }
        }

        return $plan;
    }

    /**
     * Свести раскладку по складам к складам отгрузки: у склада без своей точки
     * отправления перевозчик заказ не заберёт, поэтому его позиции уезжают с
     * основного отгрузочного склада — туда товар довозится внутренним перегоном.
     * Так же ведёт себя сайт до того, как владелец заполнит второй город.
     *
     * @param  array<int, array<int, int>>  $plan  [warehouse_id => [variant_id => qty]]
     * @return array<int, array<int, int>>
     */
    public function shipmentGroups(array $plan, ?string $provider): array
    {
        $warehouses = $this->warehouses()->keyBy('id');
        $fallback = $warehouses->first(fn (Warehouse $warehouse) => $warehouse->shipsVia($provider));

        $groups = [];

        foreach ($plan as $warehouseId => $items) {
            $warehouse = $warehouses->get($warehouseId);
            $from = $warehouse && $warehouse->shipsVia($provider) ? $warehouse : $fallback;

            if (! $from) {
                continue;
            }

            foreach ($items as $variantId => $qty) {
                $groups[$from->id][$variantId] = ($groups[$from->id][$variantId] ?? 0) + (int) $qty;
            }
        }

        return $groups;
    }

    /**
     * Отгрузки заказа: [warehouse_id => [variant_id => qty]] по фактическому
     * списанию, сведённые к складам отгрузки. По одной заявке перевозчику на группу.
     *
     * @return array<int, array<int, int>>
     */
    public function orderShipmentGroups(Order $order): array
    {
        $order->loadMissing('items', 'shippingMethod');

        $plan = [];

        foreach ($order->items as $item) {
            foreach ((array) $item->stock_allocation as $warehouseId => $qty) {
                if ((int) $qty <= 0 || ! $item->variant_id) {
                    continue;
                }
                $plan[(int) $warehouseId][(int) $item->variant_id] =
                    ($plan[(int) $warehouseId][(int) $item->variant_id] ?? 0) + (int) $qty;
            }
        }

        return $this->shipmentGroups($plan, $order->shippingMethod?->provider());
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
        // Раскладка считается по всей корзине сразу, а не по каждой позиции
        // отдельно: иначе заказ, целиком лежащий на одном складе, всё равно
        // разъехался бы по двум — и доставка списалась бы не так, как посчитана.
        $cart = [];
        foreach ($order->items as $item) {
            if ($item->variant_id) {
                $cart[$item->variant_id] = ($cart[$item->variant_id] ?? 0) + (int) $item->qty;
            }
        }

        $planned = $this->planCart($cart, $method);

        foreach ($order->items as $item) {
            if (! $item->variant_id) {
                continue;
            }

            // Из общей раскладки вынимаем долю этой строки: одинаковых вариантов
            // в заказе две строки быть не должно, но если они есть — каждая
            // получит своё, а не одно и то же.
            $plan = [];
            $left = (int) $item->qty;

            foreach ($planned as $warehouseId => $items) {
                $available = (int) ($items[$item->variant_id] ?? 0);
                $take = min($left, $available);

                if ($take > 0) {
                    $plan[$warehouseId] = $take;
                    $planned[$warehouseId][$item->variant_id] = $available - $take;
                    $left -= $take;
                }

                if ($left <= 0) {
                    break;
                }
            }

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
