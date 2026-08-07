{{--
    Цена на карточке товара. У эталона тут СВОЯ разметка, не та, что в каталоге
    (partials/price): скидка выводится как <del>старая</del> -N % новая, без <ins>,
    и всё завёрнуто в .price-container — по нему же цену подменяет скрипт при
    выборе вариации. $regular обязателен, $sale — если есть скидка.
--}}
@php
    $money = fn ($v) => (int) round($v);
    $amount = fn ($v) => '<span class="woocommerce-Price-amount amount"><bdi>'.$money($v).'&nbsp;<span class="woocommerce-Price-currencySymbol">&#8381;</span></bdi></span>';
    $hasSale = ! empty($sale) && $sale < $regular;
@endphp
<span class="price-container">@if($hasSale)<del>{!! $amount($regular) !!}</del> <span class="percent-discount">-{{ round((($regular - $sale) / $regular) * 100) }} %</span>{!! $amount($sale) !!}@else{!! $amount($regular) !!}@endif</span>
