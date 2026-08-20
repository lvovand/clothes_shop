<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Models\BackInStockSubscription;
use App\Models\Product;
use App\Models\TelegramAdmin;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Support\ImageVariants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Товары в мини-приложении бота: остатки по складам, цена и видимость.
 *
 * Доступ уже проверен middleware TelegramWebApp — в атрибуте telegram_admin
 * лежит допущенный человек, право на правку — тот же can_edit, что и в заказах.
 */
class ProductsController extends Controller
{
    private const PER_PAGE = 20;

    /** Ниже этого остатка товар попадает в чип «мало» — пора довозить. */
    private const LOW_STOCK = 3;

    public function __construct(private readonly StockService $stock) {}

    /**
     * Список товаров: суммарный остаток и разбивка по складам сразу в строке,
     * чтобы «чего не хватает» было видно без открытия карточек.
     */
    public function index(Request $request): JsonResponse
    {
        $total = '(select coalesce(sum(stock_qty), 0) from variants where variants.product_id = products.id)';

        $products = Product::query()
            // Варианты нужны только ради вилки цен в строке — тянем поля, а не всё.
            ->with(['images', 'variants:id,product_id,regular_price,sale_price,stock_qty'])
            ->select('products.*')
            ->selectRaw("$total as stock_total")
            ->when($request->filled('search'), function ($q) use ($request) {
                // Экранируем % и _ — иначе символ из поиска станет подстановочным.
                $term = '%'.addcslashes($request->string('search')->trim()->value(), '%_\\').'%';

                $q->where(function ($q) use ($term) {
                    $q->where('products.name', 'like', $term)
                        ->orWhereExists(fn ($q) => $q->select(DB::raw(1))
                            ->from('variants')
                            ->whereColumn('variants.product_id', 'products.id')
                            ->where('variants.sku', 'like', $term));
                });
            })
            ->when($request->input('filter') === 'out', fn ($q) => $q->whereRaw("$total = 0"))
            ->when($request->input('filter') === 'low', fn ($q) => $q->whereRaw("$total > 0 and $total <= ?", [self::LOW_STOCK]))
            ->when($request->input('filter') === 'hidden', fn ($q) => $q->where('products.status', '!=', 'published'))
            ->orderBy('products.sort_order')
            ->orderBy('products.name')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, (int) $request->input('page', 1)));

        $byWarehouse = $this->warehouseTotals(collect($products->items())->pluck('id')->all());

        return response()->json([
            'products' => collect($products->items())
                ->map(fn (Product $product) => $this->short($product, $byWarehouse[$product->id] ?? []))
                ->all(),
            'page' => $products->currentPage(),
            'has_more' => $products->hasMorePages(),
            'total' => $products->total(),
            'can_edit' => (bool) $this->admin($request)->can_edit,
            'warehouses' => $this->warehouses(),
        ]);
    }

    /**
     * Карточка товара: варианты с остатком по каждому складу, ценой и числом
     * ожидающих — всё, что нужно, чтобы поправить склад с телефона.
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        $product->load(['images', 'variants.attributeValues.attribute', 'variants.stocks']);

        $waiting = BackInStockSubscription::whereIn('variant_id', $product->variants->pluck('id'))
            ->whereNull('notified_at')
            ->groupBy('variant_id')
            ->pluck(DB::raw('count(*)'), 'variant_id');

        $warehouses = $this->warehouses();

        return response()->json([
            'product' => $this->short($product, $this->warehouseTotals([$product->id])[$product->id] ?? []) + [
                'status' => $product->status,
                'variants' => $product->variants
                    ->sortBy([fn ($a, $b) => strcmp($this->attr($a, 'color'), $this->attr($b, 'color'))])
                    ->map(fn (Variant $variant) => [
                        'id' => $variant->id,
                        'sku' => $variant->sku,
                        // Цвет отдельно от размера: в карточке варианты группируются
                        // по цвету, а строки внутри группы — это размеры.
                        'color' => $this->attr($variant, 'color'),
                        'size' => $this->attr($variant, 'size'),
                        'label' => $variant->attributeValues->pluck('label')->filter()->implode(' / '),
                        'regular_price' => (float) $variant->regular_price,
                        'sale_price' => $variant->sale_price === null ? null : (float) $variant->sale_price,
                        'stock_total' => (int) $variant->stock_qty,
                        'stocks' => collect($warehouses)
                            ->mapWithKeys(fn ($warehouse) => [$warehouse['id'] => $variant->stockAt($warehouse['id'])])
                            ->all(),
                        'waiting' => (int) ($waiting[$variant->id] ?? 0),
                    ])->values()->all(),
            ],
            'warehouses' => $warehouses,
            'can_edit' => (bool) $this->admin($request)->can_edit,
        ]);
    }

    /**
     * Остаток на складе: либо точное число (qty), либо шаг ± (delta).
     * Пишем через StockService — он один ведёт журнал и суммарный кеш варианта.
     */
    public function updateStock(Request $request, Product $product, Variant $variant): JsonResponse
    {
        if ($denied = $this->denyReadOnly($request)) {
            return $denied;
        }

        if ($variant->product_id !== $product->id) {
            return response()->json(['message' => 'Этот вариант не из этого товара.'], 404);
        }

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'qty' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'delta' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
        ], [
            'qty.min' => 'Остаток не может быть отрицательным.',
            'warehouse_id.exists' => 'Такого склада нет.',
        ]);

        $admin = $this->admin($request);
        $comment = 'Мини-приложение Telegram, @'.$admin->username;
        $before = $this->stock->qtyAt($variant->id, (int) $data['warehouse_id']);

        if (array_key_exists('qty', $data) && $data['qty'] !== null) {
            $this->stock->setQty($variant->id, (int) $data['warehouse_id'], (int) $data['qty'], $comment);
        } elseif (! empty($data['delta'])) {
            $this->stock->adjust($variant->id, (int) $data['warehouse_id'], (int) $data['delta'], $comment);
        } else {
            return response()->json(['message' => 'Нечего менять.'], 422);
        }

        $after = $this->stock->qtyAt($variant->id, (int) $data['warehouse_id']);

        Log::info('Остаток изменён из мини-приложения Telegram', [
            'product' => $product->name,
            'variant' => $variant->id,
            'warehouse' => (int) $data['warehouse_id'],
            'by' => '@'.$admin->username,
            'from' => $before,
            'to' => $after,
        ]);

        return response()->json($this->stockState($product, $variant));
    }

    /** Цена и цена по скидке варианта: витрина подхватит сразу. */
    public function updatePrice(Request $request, Product $product, Variant $variant): JsonResponse
    {
        if ($denied = $this->denyReadOnly($request)) {
            return $denied;
        }

        if ($variant->product_id !== $product->id) {
            return response()->json(['message' => 'Этот вариант не из этого товара.'], 404);
        }

        $data = $request->validate([
            'regular_price' => ['required', 'numeric', 'min:0', 'max:10000000'],
            // Пустое поле — «скидки нет», а не ноль: ноль показался бы на витрине
            // как цена 0 ₽ с меткой SALE.
            'sale_price' => ['nullable', 'numeric', 'min:0', 'max:10000000', 'lt:regular_price'],
        ], [
            'regular_price.required' => 'Укажите цену.',
            'sale_price.lt' => 'Цена по скидке должна быть меньше обычной цены.',
        ]);

        $before = ['regular' => (float) $variant->regular_price, 'sale' => $variant->sale_price];

        $variant->update([
            'regular_price' => $data['regular_price'],
            'sale_price' => $data['sale_price'] ?? null,
        ]);

        Log::info('Цена варианта изменена из мини-приложения Telegram', [
            'product' => $product->name,
            'variant' => $variant->id,
            'by' => '@'.$this->admin($request)->username,
            'from' => $before,
            'to' => ['regular' => (float) $variant->regular_price, 'sale' => $variant->sale_price],
        ]);

        return response()->json([
            'variant' => [
                'id' => $variant->id,
                'regular_price' => (float) $variant->regular_price,
                'sale_price' => $variant->sale_price === null ? null : (float) $variant->sale_price,
            ],
        ]);
    }

    /** Видимость товара на витрине и бейдж «Новинка». */
    public function updateFlags(Request $request, Product $product): JsonResponse
    {
        if ($denied = $this->denyReadOnly($request)) {
            return $denied;
        }

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,published'],
            'is_new' => ['nullable', 'boolean'],
        ]);

        $changes = array_filter([
            'status' => $data['status'] ?? null,
            'is_new' => $data['is_new'] ?? null,
        ], fn ($value) => $value !== null);

        if ($changes === []) {
            return response()->json(['message' => 'Нечего менять.'], 422);
        }

        $before = ['status' => $product->status, 'is_new' => (bool) $product->is_new];
        $product->update($changes);

        Log::info('Товар изменён из мини-приложения Telegram', [
            'product' => $product->name,
            'by' => '@'.$this->admin($request)->username,
            'from' => $before,
            'to' => $changes,
        ]);

        return response()->json([
            'product' => [
                'id' => $product->id,
                'status' => $product->status,
                'is_new' => (bool) $product->is_new,
                'published' => $product->status === 'published',
            ],
        ]);
    }

    /** Что видно в строке списка — и то же самое в шапке карточки. */
    private function short(Product $product, array $byWarehouse): array
    {
        $prices = $product->relationLoaded('variants')
            ? $product->variants->map(fn (Variant $v) => $v->currentPrice())
            : collect();
        $image = $product->images->first();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'published' => $product->status === 'published',
            'is_new' => (bool) $product->is_new,
            'image' => $image ? ImageVariants::url($image->previewPath(), 160) : null,
            'stock_total' => (int) ($product->stock_total ?? $product->variants->sum('stock_qty')),
            'stocks' => $byWarehouse,
            'price_min' => $prices->isEmpty() ? null : (float) $prices->min(),
            'price_max' => $prices->isEmpty() ? null : (float) $prices->max(),
        ];
    }

    /**
     * Остатки товаров по складам одним запросом: [product_id => [warehouse_id => qty]].
     * Иначе на страницу из 20 товаров ушло бы 20 запросов.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array<int, int>>
     */
    private function warehouseTotals(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('variant_stocks')
            ->join('variants', 'variants.id', '=', 'variant_stocks.variant_id')
            ->whereIn('variants.product_id', $productIds)
            ->groupBy('variants.product_id', 'variant_stocks.warehouse_id')
            ->selectRaw('variants.product_id, variant_stocks.warehouse_id, sum(variant_stocks.qty) as qty')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [(int) $row->warehouse_id => (int) $row->qty])->all())
            ->all();
    }

    /** Склады в порядке списания — колонки таблицы остатков. */
    private function warehouses(): array
    {
        return Warehouse::active()->get()->map(fn (Warehouse $warehouse) => [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            // Короткое имя для узкой колонки на телефоне.
            'short' => mb_substr($warehouse->name, 0, 3),
            'allows_pickup' => (bool) $warehouse->allows_pickup,
        ])->all();
    }

    /** Новые числа после правки остатка: и у варианта, и в шапке товара. */
    private function stockState(Product $product, Variant $variant): array
    {
        $variant->refresh()->load('stocks');

        return [
            'variant' => [
                'id' => $variant->id,
                'stock_total' => (int) $variant->stock_qty,
                'stocks' => $this->stock->qtyByWarehouse($variant->id),
            ],
            'product' => [
                'id' => $product->id,
                'stock_total' => (int) Variant::where('product_id', $product->id)->sum('stock_qty'),
                'stocks' => $this->warehouseTotals([$product->id])[$product->id] ?? [],
            ],
        ];
    }

    /** Значение атрибута варианта по коду («color», «size»); '' — если его нет. */
    private function attr(Variant $variant, string $code): string
    {
        return (string) $variant->attributeValues
            ->first(fn ($value) => $value->attribute?->code === $code)?->label;
    }

    private function denyReadOnly(Request $request): ?JsonResponse
    {
        return $this->admin($request)->can_edit
            ? null
            : response()->json(['message' => 'Вам разрешён только просмотр.'], 403);
    }

    private function admin(Request $request): TelegramAdmin
    {
        return $request->attributes->get('telegram_admin');
    }
}
