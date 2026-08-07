{{--
    Карточка товара в разметке эталона (.product-item) — используется и в слайдере
    на главной, и в сетке каталога. Кнопка избранного сохраняет вид оригинала
    (форма .w-l-class с иконкой-сердцем), но работает без перезагрузки страницы.
--}}
@php
    /** @var \App\Models\Product $product */
    $theme = fn($p) => asset('theme/' . ltrim($p, '/'));
    $cheapest = $product->variants->sortBy(fn($v) => $v->currentPrice())->first();
    $inStock = $product->variants->contains(fn($v) => $v->inStock());
    $cover = $product->images->first();
    $isWishlisted = in_array($product->id, $wishlistedIds ?? [], true);
    $url = route('product.show', $product);
    $onSale = $cheapest?->isOnSale();
    $heart = $isWishlisted ? 'icon-col-heart.svg' : 'icon-heart.svg';
@endphp
<div class="product-item">
    <div class="product-item-first">
        <a href="{{ $url }}" class="product-item-top uri-permalink">
            @if($cover)
                <img src="{{ asset('storage/'.$cover->path) }}" alt="product">
            @endif
            <div class="product-item-settings">
                @if($product->is_new)<span class="new">new</span>@endif
                @if($onSale)<span class="sale">sale</span>@endif
            </div>
            @if(!$inStock)
                <p class="sold-out">
                    SOLD OUT
                </p>
            @endif
        </a>
        <a href="{{ $url }}">
            <button  class="btn product-item__price">
            ПОДРОБНЕЕ
        </button></a>
        <form action="{{ route('wishlist.toggle') }}" method="post" class="w-l-class" data-wishlist-form><input type="hidden" name="product_id" value="{{ $product->id }}"><button type="submit" class="wish-list-form__btn"><img src="{{ $theme('wp-content/themes/ropa-temp/assets/img/icons/'.$heart) }}" alt=""></button></form>    </div>
    <div class="product-item-bottom">
        <p class="product-item-bottom__title">
            {{ $product->name }}        </p>
        <div class="product-item-bottom-price">
            @if($cheapest)
                @include('partials.price', ['regular' => $cheapest->regular_price, 'sale' => $onSale ? $cheapest->sale_price : null])
            @endif
        </div>
    </div>
</div>
