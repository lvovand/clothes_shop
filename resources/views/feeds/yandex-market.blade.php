<?php echo '<?xml version="1.0" encoding="UTF-8"?>'."\n"; ?>
<yml_catalog date="{{ now()->format('Y-m-d\TH:i') }}">
    <shop>
        <name>{{ $shop['shop_name'] }}</name>
        <company>{{ $shop['company'] }}</company>
        <url>{{ $shop['url'] }}</url>
        <currencies>
            <currency id="RUB" rate="1"/>
        </currencies>
        <categories>
@foreach($categories as $id => $name)
            <category id="{{ $id }}">{{ $name }}</category>
@endforeach
        </categories>
        <offers>
@foreach($offers as $offer)
            <offer id="{{ $offer['id'] }}" available="{{ $offer['available'] ? 'true' : 'false' }}">
                <url>{{ $offer['url'] }}</url>
                <price>{{ number_format($offer['price'], 2, '.', '') }}</price>
@if($offer['old_price'])
                <oldprice>{{ number_format($offer['old_price'], 2, '.', '') }}</oldprice>
@endif
                <currencyId>RUB</currencyId>
@if($offer['category_id'])
                <categoryId>{{ $offer['category_id'] }}</categoryId>
@endif
@foreach($offer['pictures'] as $picture)
                <picture>{{ $picture }}</picture>
@endforeach
                <vendor>{{ $offer['vendor'] }}</vendor>
                <name>{{ $offer['name'] }}</name>
@if($offer['description'] !== '')
                <description>{{ \Illuminate\Support\Str::limit($offer['description'], 2900, '') }}</description>
@endif
@if($offer['sku'])
                <vendorCode>{{ $offer['sku'] }}</vendorCode>
@endif
@if($offer['size'])
                <param name="Размер">{{ $offer['size'] }}</param>
@endif
@if($offer['color'])
                <param name="Цвет">{{ $offer['color'] }}</param>
@endif
@if($offer['weight'])
                <weight>{{ $offer['weight'] }}</weight>
@endif
@if($offer['dimensions'])
                <dimensions>{{ $offer['dimensions'] }}</dimensions>
@endif
            </offer>
@endforeach
        </offers>
    </shop>
</yml_catalog>
