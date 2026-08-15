<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\TelegramAdmin;
use App\Models\Warehouse;
use App\Services\Shipping\ShipmentActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Заказы в мини-приложении бота: список, карточка и смена статусов.
 *
 * Доступ проверяет middleware TelegramWebApp — здесь уже известно, что запрос
 * пришёл из Telegram от допущенного человека (лежит в атрибуте telegram_admin).
 */
class OrdersController extends Controller
{
    private const PER_PAGE = 20;

    /** Список заказов: свежие сверху, поиск по номеру/имени/телефону. */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with('shippingMethod:id,title')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                // Экранируем % и _ — иначе введённый покупателем символ превратится
                // в подстановочный и поиск начнёт находить всё подряд.
                $term = '%'.addcslashes($request->string('search')->trim()->value(), '%_\\').'%';

                $q->where(function ($q) use ($term) {
                    $q->where('order_number', 'like', $term)
                        ->orWhere('customer_name', 'like', $term)
                        ->orWhere('customer_phone', 'like', $term);
                });
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, (int) $request->input('page', 1)));

        return response()->json([
            'orders' => collect($orders->items())->map(fn (Order $order) => $this->short($order))->all(),
            'page' => $orders->currentPage(),
            'has_more' => $orders->hasMorePages(),
            'total' => $orders->total(),
            'can_edit' => (bool) $this->admin($request)->can_edit,
        ]);
    }

    /**
     * Карточка заказа: всё, что нужно менеджеру для работы, чтобы не открывать
     * большую админку — покупатель, доставка с заявкой перевозчику, оплата,
     * состав и с каких складов отгружать.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $order->load(['items', 'shippingMethod', 'payments' => fn ($q) => $q->latest('id')]);

        $actions = app(ShipmentActions::class);
        $method = $order->shippingMethod;
        $address = (array) $order->shipping_address;
        $warehouses = Warehouse::pluck('name', 'id');

        return response()->json([
            'order' => $this->short($order) + [
                'email' => $order->customer_email,
                'comment' => $order->comment,
                'subtotal' => (float) $order->subtotal,
                'shipping_cost' => (float) $order->shipping_cost,
                'discount_total' => (float) $order->discount_total,
                'coupon_code' => $order->coupon_code,
                'gift_certificate_code' => $order->gift_certificate_code,
                'gift_certificate_used' => (float) $order->gift_certificate_used,
                'delivery' => [
                    'method' => $method->title ?? null,
                    'carrier' => $actions->carrierName($order) ?? 'без перевозчика (везём сами)',
                    'kind' => match ($method?->kind()) {
                        'pvz' => 'пункт выдачи',
                        'door' => 'курьером по адресу',
                        'pickup' => 'самовывоз из магазина',
                        default => null,
                    },
                    'city' => $address['city'] ?? null,
                    'address' => $address['address'] ?? null,
                    'pvz_code' => $address['pvz_code'] ?? null,
                    'pvz_address' => $address['pvz_address'] ?? null,
                    'days' => $method?->config['delivery_days'] ?? null,
                    'cost' => (float) $order->shipping_cost,
                ],
                // Заявок может быть две: заказ с товаром на двух складах едет
                // двумя отправлениями, у каждого свой номер.
                'shipments' => $actions->shipments($order)->map(fn ($shipment) => [
                    'provider' => ShipmentActions::CARRIERS[$shipment->provider] ?? $shipment->provider,
                    'number' => $shipment->tracking_number,
                    'status' => match ($shipment->status) {
                        'created' => 'создана',
                        'cancelled' => 'отменена',
                        default => (string) $shipment->status,
                    },
                    'warehouse' => $warehouses[$shipment->warehouse_id] ?? null,
                    'pvz_address' => $shipment->pvz_address,
                ])->values()->all(),
                'shipment_actions' => [
                    'can_create' => $actions->canCreate($order),
                    'can_cancel' => $actions->canCancel($order),
                    'can_refresh_number' => $actions->canRefreshNumber($order),
                ],
                'payments' => $order->payments->map(fn ($payment) => [
                    'provider' => $payment->provider,
                    'amount' => (float) $payment->amount,
                    'status' => $payment->status,
                    'payment_id' => $payment->provider_payment_id,
                    'created_at' => $payment->created_at?->format('d.m.Y H:i'),
                ])->all(),
                'items' => $order->items->map(fn ($item) => [
                    'title' => $item->product_title_snapshot,
                    'attrs' => collect((array) $item->variant_attrs_snapshot)->filter()->implode(', '),
                    'qty' => (int) $item->qty,
                    'unit_price' => (float) $item->unit_price,
                    'line_total' => (float) $item->line_total,
                    // С каких складов отгружать — иначе менеджеру пришлось бы
                    // открывать позицию заказа в большой админке.
                    'warehouses' => collect((array) $item->stock_allocation)
                        ->map(fn ($qty, $id) => ($warehouses[$id] ?? 'склад '.$id).': '.$qty.' шт.')
                        ->values()
                        ->all(),
                ])->all(),
            ],
        ]);
    }

    /**
     * Заявка на доставку: создать, отменить, подтянуть номер накладной — те же
     * действия, что в карточке заказа в админке.
     */
    public function shipmentAction(Request $request, Order $order, string $action): JsonResponse
    {
        if (! $this->admin($request)->can_edit) {
            return response()->json(['message' => 'Вам разрешён только просмотр заказов.'], 403);
        }

        $actions = app(ShipmentActions::class);

        $result = match ($action) {
            'create' => $actions->create($order),
            'cancel' => $actions->cancel($order),
            'refresh' => $actions->refreshNumber($order),
            default => ['ok' => false, 'message' => 'Неизвестное действие.'],
        };

        Log::info('Заявка на доставку из мини-приложения Telegram', [
            'order' => $order->order_number ?: $order->id,
            'by' => '@'.$this->admin($request)->username,
            'action' => $action,
            'ok' => $result['ok'],
            'message' => $result['message'],
        ]);

        return response()->json(['ok' => $result['ok'], 'message' => $result['message']], $result['ok'] ? 200 : 422);
    }

    /**
     * Смена статуса заказа и/или статуса оплаты — то, ради чего приложение и
     * нужно: из списка, без открытия карточки.
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $admin = $this->admin($request);

        if (! $admin->can_edit) {
            return response()->json(['message' => 'Вам разрешён только просмотр заказов.'], 403);
        }

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(Order::STATUS_LABELS))],
            'payment_status' => ['nullable', 'string', 'in:'.implode(',', array_keys(Order::PAYMENT_STATUS_LABELS))],
        ]);

        $changes = array_filter([
            'status' => $data['status'] ?? null,
            'payment_status' => $data['payment_status'] ?? null,
        ], fn ($value) => $value !== null);

        if ($changes === []) {
            return response()->json(['message' => 'Нечего менять.'], 422);
        }

        $before = ['status' => $order->status, 'payment_status' => $order->payment_status];

        // update(), а не тихое сохранение: на смену статуса на «Отменён» повешен
        // OrderObserver, возвращающий товар на склады.
        $order->update($changes);

        Log::info('Статус заказа изменён из мини-приложения Telegram', [
            'order' => $order->order_number ?: $order->id,
            'by' => '@'.$admin->username,
            'from' => $before,
            'to' => $changes,
        ]);

        return response()->json(['order' => $this->short($order->refresh())]);
    }

    /** Что видно в списке — одинаково и после смены статуса. */
    private function short(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->order_number ?: (string) $order->id,
            'created_at' => $order->created_at?->format('d.m.Y H:i'),
            'customer' => $order->customer_name,
            'phone' => $order->customer_phone,
            'total' => (float) $order->total,
            'status' => $order->status,
            'status_label' => $order->statusLabel(),
            'payment_status' => $order->payment_status,
            'payment_status_label' => $order->paymentStatusLabel(),
            'payment_method' => PaymentMethod::LABELS[$order->payment_method] ?? $order->payment_method,
            'shipping_method' => $order->shippingMethod->title ?? null,
        ];
    }

    private function admin(Request $request): TelegramAdmin
    {
        return $request->attributes->get('telegram_admin');
    }
}
