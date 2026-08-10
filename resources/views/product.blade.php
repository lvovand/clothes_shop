@extends('layouts.app')

{{--
    Карточка товара в разметке эталона. Порядок и классы блоков — как в его выводе
    (breadcrumbs → .content-width.nav-product → .product-card из четырёх частей:
    кнопка «назад», слайдеры, мобильная плашка с ценой, правая колонка).
    Осознанные отступления от эталонного HTML (визуально ни на что не влияют):
      * не выводятся служебные классы и id WordPress (post-N, type-product, instock…)
        и обёртки .entry-content/.woocommerce — по ним нет ни одного правила в CSS темы;
      * не выводится скрытый <select> плагина свотчей (.variation-selector скрыт
        через display:none !important) — выбор вариации у нас делает свой скрипт;
      * .single_variation_wrap оставлен, хотя эталон его скрывает целиком: в нём
        живёт поле количества, которое отправляется вместе с формой.
--}}

@php
    $icon = fn ($f) => asset('theme/wp-content/themes/ropa-temp/assets/img/icons/'.$f);

    $isWishlisted = in_array($product->id, $wishlistedIds ?? [], true);

    // Свотчи сгруппированы по атрибуту и идут в порядке атрибутов (Цвет, Размер),
    // как у эталона; значения внутри группы — в порядке сортировки атрибута.
    $attributeGroups = $product->variants
        ->flatMap->attributeValues
        ->unique('id')
        ->sortBy('sort_order')
        ->groupBy('attribute_id')
        ->sortKeys();

    // Вариация, выбранная при открытии страницы: эталон подставляет значения по
    // умолчанию из товара. Такого поля у нас нет, поэтому берём первую вариацию,
    // которая есть в наличии и покрывает все группы свотчей — иначе страница
    // открывалась бы на комбинации, которой не существует.
    $coversAllGroups = fn ($v) => $attributeGroups->keys()
        ->every(fn ($attributeId) => $v->attributeValues->contains('attribute_id', $attributeId));

    $selectedVariant = $product->variants->first(fn ($v) => $v->inStock() && $coversAllGroups($v))
        ?? $product->variants->first($coversAllGroups)
        ?? $product->variants->first();

    $selectedValueIds = $selectedVariant ? $selectedVariant->attributeValues->pluck('id')->all() : [];

    // Значение свотча гасится (класс disabled), если вместе с выбранным в других
    // группах оно не даёт ни одной вариации в наличии — так ведёт себя эталон.
    $isAvailable = function ($value) use ($product, $selectedValueIds, $attributeGroups) {
        $sameGroup = $attributeGroups->get($value->attribute_id)->pluck('id')->all();
        $fixed = array_diff($selectedValueIds, $sameGroup);

        return $product->variants->contains(fn ($v) => $v->inStock()
            && $v->attributeValues->contains('id', $value->id)
            && collect($fixed)->every(fn ($id) => $v->attributeValues->contains('id', $id)));
    };

    $variantsJson = $product->variants->map(fn ($v) => [
        'id' => $v->id,
        'in_stock' => $v->inStock(),
        'value_ids' => $v->attributeValues->pluck('id')->all(),
        'price_html' => trim(view('partials.price-single', [
            'regular' => (float) $v->regular_price,
            'sale' => $v->isOnSale() ? (float) $v->sale_price : null,
        ])->render()),
    ])->values();
@endphp

@section('content')

<div class="breadcrumbs">
    <div class="container">
        <nav class="breadcrumbs__wrapper" itemprop="breadcrumb"><a href="{{ url('/') }}">Главная</a><span class="arrow-bread">/</span> @if($product->category)<a href="{{ route('catalog.category', $product->category) }}">{{ $product->category->name }}</a><span class="arrow-bread">/</span> @endif{{ $product->name }}</nav>    </div>
</div>


<div class="content-width nav-product">

    {{-- Класс .product у эталона стоит и на внешней обёртке, и на самой карточке:
         в теме на него навешаны отступы (padding-bottom 60px, а на планшетах ещё
         и боковые 16px), поэтому оба вложения нужны — иначе страница на 120px короче. --}}
    <div class="product">

    <div class="container" style="min-height: calc(94vh - 95px);">

        <div class="product-card product" data-variants='@json($variantsJson)'>

    <a href="javascript:void(0);" onclick="history.back();" class="product-card__back sticky">
        <img src="{{ $icon('arrow-left.png') }}" alt="">
    </a>

    <div class="product-card-sliders">

<div class="product-page-slider__main">
    <div class="swiper-container gallery-main">
        <form action="{{ route('wishlist.toggle') }}" method="post" class="wish-list-form" data-wishlist-form><input type="hidden" name="product_id" value="{{ $product->id }}"><button type="submit" class="wish-list-form__btn"><img src="{{ $icon($isWishlisted ? 'icon-col-heart.svg' : 'icon-heart.svg') }}" alt=""></button></form>        <div class="swiper-wrapper">
            @foreach($product->images as $image)
                    <div class="swiper-slide">
                        <a href="{{ asset('storage/'.$image->path) }}" data-fancybox="product" class="gallery-top-container">
                            <img src="{{ asset('storage/'.$image->path) }}" alt="{{ $product->name }}"@if(! $loop->first) loading="lazy"@endif decoding="async">
                        </a>
                    </div>
            @endforeach
                    </div>
    </div>
</div>



<div class="sticky__max">
    <div class="sticky__container">
        <div class="product-page-slider__thumbs">
            <div class="swiper-container gallery-thumbs">
                <div class="swiper-wrapper">
                    @foreach($product->images as $image)
            <div class="swiper-slide">
                <div class="gallery-thumbs-container__image" style="background: url('{{ asset('storage/'.$image->path) }}') no-repeat center / cover;" data-alt="{{ $product->name }} - 1_{{ $loop->index }}"></div>
            </div>
                    @endforeach
                                </div>
            </div>
        </div>
    </div>
</div>
    </div>

        <div class="product-mobile-description">
            <div class="btn-wh-list-mobile">
                <form action="{{ route('wishlist.toggle') }}" method="post" data-wishlist-form><input type="hidden" name="product_id" value="{{ $product->id }}"><button type="submit" class="wish-list-form__btn"><img src="{{ $icon($isWishlisted ? 'icon-col-heart.svg' : 'icon-heart.svg') }}" alt=""></button></form>            </div>
            <h1>{{ $product->name }}</h1>
            <p class="product-card-description__price">
                @if($selectedVariant)@include('partials.price-single', ['regular' => (float) $selectedVariant->regular_price, 'sale' => $selectedVariant->isOnSale() ? (float) $selectedVariant->sale_price : null])@endif            </p>
                            <div class="add-to-cart-block">
                    <p class="btn add-cart-link btn-mobile-product">
                        В корзину
                    </p>
                    {{-- На мобильных кнопка покупки живёт здесь (в форме она скрыта темой),
                         поэтому бейдж Сплита нужен рядом именно с этой кнопкой. --}}
                    @if($selectedVariant)
                        @include('partials.yandex-pay-badge', [
                            'amount' => $selectedVariant->currentPrice(),
                            'type' => 'bnpl',
                            'size' => 'm',
                        ])
                    @endif
                </div>

        </div>

        <div class="product-card-description">
            <h1>{{ $product->name }}</h1>

            <p class="product-card-description__price">
                @if($selectedVariant)@include('partials.price-single', ['regular' => (float) $selectedVariant->regular_price, 'sale' => $selectedVariant->isOnSale() ? (float) $selectedVariant->sale_price : null])@endif
            </p>

	<div class="summary entry-summary">

<form class="variations_form cart" action="{{ route('cart.add') }}" method="post" data-product_id="{{ $product->id }}">
    @csrf

			<table class="variations" cellspacing="0" role="presentation">
			<tbody>
            @foreach($attributeGroups as $attributeId => $values)
                    <tr class="product-card-colors-panel">
                        <td>
                            <p>
                                {{ $values->first()->attribute->name }}:
                            </p>
                        </td>
                        <td class="value">
                            <div class="tawcvs-swatches" data-attribute_name="attribute_pa_{{ $values->first()->attribute->code }}">@foreach($values as $value)<div class="swatch-item-wrapper"><div class="swatch swatch-shape-circle swatch-type-label swatch-label swatch-{{ $value->value }}@if(in_array($value->id, $selectedValueIds, true)) selected @elseif(! $isAvailable($value)) disabled @endif" data-value="{{ $value->value }}" data-value-id="{{ $value->id }}"><span class="text">{{ $value->label }}</span></div></div>@endforeach</div>@if($loop->last)<a class="reset_variations" href="#">Очистить</a>@endif                        </td>
                    </tr>
            @endforeach
							</tbody>
		</table>

        <button type="submit" class="btn add-cart-link add-to-cart-single button alt">В корзину</button>

        {{-- Сумма берётся у выбранного варианта: у товара размеры стоят одинаково,
             поэтому бейдж не пересчитывается при смене размера. --}}
        @if($selectedVariant)
            @include('partials.yandex-pay-badge', [
                'amount' => $selectedVariant->currentPrice(),
                'type' => 'bnpl',
                'size' => 'm',
            ])
        @endif


		<div class="single_variation_wrap">
			<div class="woocommerce-variation single_variation"></div><div class="woocommerce-variation-add-to-cart variations_button">

		<div class="quantity">
		<label class="screen-reader-text" for="smntcswcb">Quantity</label>

									<input class="minus button wp-element-button" type="button" value="">

		<input type="number"
			id="smntcswcb" step="1"
			min="1"
						name="qty"
			value="1"
			title="Qty"
			class="input-text qty text"
			inputmode="numeric" />

									<input class="plus button wp-element-button" type="button" value="">

	</div>


	<input type="hidden" name="product_id" value="{{ $product->id }}" />
	<input type="hidden" name="variant_id" class="variation_id" value="{{ $selectedVariant?->id }}" />
</div>
		</div>

	</form>


    <!--                descriptions-->
    <div class="drop-down-item">

        @foreach($product->contentBlocks as $block)
            <div class="spoiler-wrap">
                <div class="spoiler-head">{{ $block->title }}                    <div class="drop-arrow">
                        <img src="{{ $icon('triangle-down.svg') }}" alt="">
                    </div>
                </div>
                <div class="spoiler-body">
                    {!! $block->body !!}
                </div>
            </div>
        @endforeach

    </div>
    <!--                descriptions END-->

   	</div>

	</div>

        </div>

    </div>

    </div>

</div>

@endsection

@push('scripts')
{{--
    Поведение свотчей у эталона рисует плагин, поэтому здесь оно своё, но повторяет
    его наблюдаемую логику: класс selected на выбранном, disabled на значениях, не
    дающих вариации в наличии, подмена .price-container (в обеих плашках — на
    мобильной и в правой колонке) и всплывашка .custom-message после добавления —
    ровно та же разметка и тайминги, что в main.js эталона.
--}}
<script>
jQuery(function ($) {
    const $card = $('.product-card');
    if (! $card.length) return;

    const variants = JSON.parse($card.attr('data-variants'));
    const $form = $card.find('form.variations_form');
    const $groups = $card.find('.tawcvs-swatches');
    const $variantId = $form.find('input[name="variant_id"]');

    // main.js эталона сам вешает submit на form.variations_form и отправляет заказ
    // в ajax WooCommerce (wc_add_to_cart_params) — у нас его нет, обработчик падает
    // и до нашего дело не доходит. Класс формы нужен ради стилей, поэтому снимаем
    // именно обработчик.
    $form.off('submit');

    const selection = () => $groups.map(function () {
        const id = $(this).find('.swatch.selected').data('value-id');
        return id === undefined ? null : id;
    }).get().filter(id => id !== null);

    const matches = (variant, ids) => ids.every(id => variant.value_ids.includes(id))
        && variant.value_ids.length === ids.length;

    // Кольцо вокруг кружка эталон рисует классом selected на .swatch-item-wrapper и
    // только в группе цвета (main.js сам двигает его по клику, в том числе по
    // погашенному значению) — держим его в соответствии с реальным выбором.
    // В группе размера этот класс ставить нельзя: рамка +2px раздувает свотч.
    function syncWrappers() {
        $card.find('[data-attribute_name="attribute_pa_color"] .swatch-item-wrapper').each(function () {
            $(this).toggleClass('selected', $(this).find('.swatch').hasClass('selected'));
        });
    }

    function refresh() {
        const ids = selection();

        // Гасим значения, которые вместе с выбранным в других группах не дают
        // ни одной вариации в наличии. Выбранное не гасим никогда.
        $groups.each(function () {
            const $group = $(this);
            const groupIds = $group.find('.swatch').map(function () { return $(this).data('value-id'); }).get();
            const fixed = ids.filter(id => ! groupIds.includes(id));

            $group.find('.swatch').each(function () {
                const $swatch = $(this);
                if ($swatch.hasClass('selected')) return;
                const id = $swatch.data('value-id');
                const available = variants.some(v => v.in_stock
                    && v.value_ids.includes(id)
                    && fixed.every(other => v.value_ids.includes(other)));
                $swatch.toggleClass('disabled', ! available);
            });
        });

        syncWrappers();

        const variant = variants.find(v => matches(v, ids));
        $variantId.val(variant ? variant.id : '');
        if (variant) {
            $card.find('.price-container').replaceWith(variant.price_html);
        }
    }

    $card.on('click', '.swatch', function () {
        const $swatch = $(this);
        if ($swatch.hasClass('disabled')) {
            syncWrappers();

            return;
        }
        $swatch.closest('.tawcvs-swatches').find('.swatch').removeClass('selected');
        $swatch.addClass('selected');
        refresh();
    });

    $card.on('click', '.reset_variations', function (e) {
        e.preventDefault();
        $groups.find('.swatch').removeClass('selected');
        refresh();
    });

    // Кнопки количества эталону рисует плагин, здесь тот же DOM обслуживается сам.
    $card.on('click', '.quantity .minus, .quantity .plus', function () {
        const $input = $(this).siblings('input.qty');
        const step = $(this).hasClass('plus') ? 1 : -1;
        $input.val(Math.max(1, (parseInt($input.val(), 10) || 1) + step));
    });

    function message(text) {
        $('.container-close-message').remove();
        $('body').append('<div class="container container-close-message"><div class="custom-message"><p>' + text + '</p></div></div>');
        setTimeout(function () {
            $('.container-close-message').fadeOut('slow', function () { $(this).remove(); });
        }, 1000);
    }

    $form.on('submit', function (e) {
        e.preventDefault();

        if (! $variantId.val()) {
            message('выберите вариант товара');
            return;
        }

        $.post({
            url: $form.attr('action'),
            data: $form.serialize(),
            headers: { 'Accept': 'application/json' },
        }).done(function (response) {
            // Точку на иконке корзины в шапке рисует main.js эталона — по клику
            // на .add-to-cart-single, поэтому здесь её трогать не нужно.
            message(response.message || 'товар добавлен в корзину');
        }).fail(function (xhr) {
            message((xhr.responseJSON && xhr.responseJSON.message) || 'не удалось добавить товар');
        });
    });

    // Сердечко обслуживает общий public/js/storefront.js — формы здесь помечены
    // data-wishlist-form так же, как в карточках каталога.

    refresh();
});
</script>
@endpush
