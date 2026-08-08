<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\StockService;

/**
 * Возврат товара на склад. Отдельным наблюдателем, а не в админке, потому что
 * статус заказа меняется из нескольких мест (форма заказа, массовое действие,
 * код) — правило должно работать везде одинаково.
 */
class OrderObserver
{
    public function __construct(private readonly StockService $stock) {}

    public function updated(Order $order): void
    {
        if ($order->wasChanged('status') && $order->status === 'cancelled') {
            $this->stock->returnForOrder($order, 'Заказ отменён');
        }
    }

    public function deleting(Order $order): void
    {
        // Именно deleting, а не deleted: позиции заказа с их раскладкой по складам
        // должны быть ещё на месте, иначе возвращать будет нечего.
        $this->stock->returnForOrder($order, 'Заказ удалён');
    }
}
