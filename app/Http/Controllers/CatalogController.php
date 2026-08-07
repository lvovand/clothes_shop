<?php

namespace App\Http\Controllers;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function all(Request $request)
    {
        return $this->render($request, null);
    }

    public function category(Request $request, Category $category)
    {
        // Выключенный в админке раздел недоступен и по прямой ссылке.
        abort_unless($category->is_active, 404);

        // ALL — виртуальная категория: товары к ней не привязаны, показываем весь каталог.
        return $this->render($request, $category->is_virtual ? null : $category);
    }

    private function render(Request $request, ?Category $category)
    {
        $query = Product::published();

        if ($category) {
            $query->whereHas('categories', fn ($q) => $q->where('categories.id', $category->id));
        }

        $query->with(['images', 'variants.attributeValues']);
        $this->applyFilters($query, $request);
        $this->applySort($query, $request);

        $products = $query->paginate(12)->withQueryString();

        // Отдельного ajax-ответа нет специально: догрузку страниц делает плагин
        // эталона, который забирает обычную страницу целиком и сам вырезает из неё
        // div.catalog-block. Раньше здесь возвращался фрагмент со своей разметкой
        // (div.product-grid), и подгрузка молча не работала — плагин такого
        // контейнера в ответе не находил.

        // Границы для фильтра цены — по всем опубликованным товарам раздела,
        // без учёта уже выбранной цены, иначе диапазон схлопывался бы после первого
        // же применения фильтра.
        $priceScope = Variant::query()
            ->whereHas('product', function ($q) use ($category) {
                $q->published();
                if ($category) {
                    $q->whereHas('categories', fn ($c) => $c->where('categories.id', $category->id));
                }
            });

        return view('catalog', [
            'products' => $products,
            'title' => $category?->name ?? 'ALL',
            'category' => $category,
            'colorValues' => Attribute::where('code', 'color')->first()?->values()->orderBy('sort_order')->get() ?? collect(),
            'sizeValues' => Attribute::where('code', 'size')->first()?->values()->orderBy('sort_order')->get() ?? collect(),
            'priceMin' => (int) floor((float) (clone $priceScope)->min(\DB::raw('COALESCE(sale_price, regular_price)'))),
            'priceMax' => (int) ceil((float) (clone $priceScope)->max(\DB::raw('COALESCE(sale_price, regular_price)'))),
        ]);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('colors')) {
            $query->whereHas('variants.attributeValues', fn ($q) => $q->whereIn('attribute_values.id', (array) $request->input('colors')));
        }

        if ($request->filled('sizes')) {
            $query->whereHas('variants.attributeValues', fn ($q) => $q->whereIn('attribute_values.id', (array) $request->input('sizes')));
        }

        if ($request->filled('price_min') || $request->filled('price_max')) {
            // Границу приводим к числу прямо в запросе: PDO передаёт её строкой,
            // а sqlite сравнение числа со строкой считает заведомо ложным — фильтр
            // молча возвращал пустую выдачу.
            $query->whereHas('variants', function ($q) use ($request) {
                if ($request->filled('price_min')) {
                    $q->whereRaw('COALESCE(sale_price, regular_price) >= CAST(? AS DECIMAL(10,2))', [$request->input('price_min')]);
                }
                if ($request->filled('price_max')) {
                    $q->whereRaw('COALESCE(sale_price, regular_price) <= CAST(? AS DECIMAL(10,2))', [$request->input('price_max')]);
                }
            });
        }

        // Наличие: на эталоне это два отдельных пункта — «В наличии» и «Нет в наличии».
        // Отмечены оба (или ни одного) — показываем всё.
        $stock = (array) $request->input('stock', []);
        if ($request->boolean('in_stock')) {
            $stock[] = 'in';
        }
        $stock = array_unique($stock);

        if (count($stock) === 1) {
            if (in_array('in', $stock, true)) {
                $query->whereHas('variants', fn ($q) => $q->where('stock_qty', '>', 0));
            } elseif (in_array('out', $stock, true)) {
                $query->whereDoesntHave('variants', fn ($q) => $q->where('stock_qty', '>', 0));
            }
        }
    }

    private function applySort(Builder $query, Request $request): void
    {
        $query->addSelect('products.*')->addSelect([
            'min_price' => Variant::selectRaw('COALESCE(MIN(sale_price), MIN(regular_price))')
                ->whereColumn('product_id', 'products.id'),
        ]);

        match ($request->input('sort')) {
            'price_asc' => $query->orderBy('min_price', 'asc'),
            'price_desc' => $query->orderBy('min_price', 'desc'),
            'date' => $query->orderBy('products.created_at', 'desc'),
            // Сортировка по умолчанию задаётся в админке («Настройки → Сайт»),
            // чтобы порядок в каталоге не приходилось менять правкой кода.
            default => match (\App\Models\SiteSetting::get('catalog_default_sort', 'manual')) {
                'price_asc' => $query->orderBy('min_price', 'asc'),
                'price_desc' => $query->orderBy('min_price', 'desc'),
                'date' => $query->orderBy('products.created_at', 'desc'),
                default => $query->orderBy('products.sort_order', 'asc'),
            },
        };
    }
}
