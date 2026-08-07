{{--
    Цена в разметке эталона. $regular обязателен, $sale — если есть скидка.
    Формат чисел тоже как на эталоне: без разделителя тысяч, без копеек,
    неразрывный пробел перед знаком рубля.
--}}
@php
    $money = fn($v) => (int) round($v);
@endphp
@if(!empty($sale) && $sale < $regular)
<del aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi>{{ $money($regular) }}&nbsp;<span class="woocommerce-Price-currencySymbol">&#8381;</span></bdi></span></del> <span class="screen-reader-text">Первоначальная цена составляла {{ $money($regular) }}&nbsp;&#8381;.</span><ins aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi>{{ $money($sale) }}&nbsp;<span class="woocommerce-Price-currencySymbol">&#8381;</span></bdi></span></ins><span class="screen-reader-text">Текущая цена: {{ $money($sale) }}&nbsp;&#8381;.</span>
@else
<span class="woocommerce-Price-amount amount"><bdi>{{ $money($regular) }}&nbsp;<span class="woocommerce-Price-currencySymbol">&#8381;</span></bdi></span>
@endif
