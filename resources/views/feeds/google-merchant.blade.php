<?php echo '<?xml version="1.0" encoding="UTF-8"?>'."\n"; ?>
<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">
    <channel>
        <title>{{ $shop['shop_name'] }}</title>
        <link>{{ $shop['url'] }}</link>
        <description>{{ $shop['shop_name'] }} — товарный фид</description>
@foreach($offers as $offer)
        <item>
            <g:id>{{ $offer['id'] }}</g:id>
            <g:item_group_id>{{ $offer['group_id'] }}</g:item_group_id>
            <title>{{ $offer['name'] }}{{ $offer['size'] ? ', '.$offer['size'] : '' }}{{ $offer['color'] ? ', '.$offer['color'] : '' }}</title>
@if($offer['description'] !== '')
            <description>{{ \Illuminate\Support\Str::limit($offer['description'], 4900, '') }}</description>
@else
            <description>{{ $offer['name'] }}</description>
@endif
            <link>{{ $offer['url'] }}</link>
@if(!empty($offer['pictures']))
            <g:image_link>{{ $offer['pictures'][0] }}</g:image_link>
@foreach(array_slice($offer['pictures'], 1, 10) as $picture)
            <g:additional_image_link>{{ $picture }}</g:additional_image_link>
@endforeach
@endif
            <g:availability>{{ $offer['available'] ? 'in_stock' : 'out_of_stock' }}</g:availability>
@if($offer['old_price'])
            <g:price>{{ number_format($offer['old_price'], 2, '.', '') }} RUB</g:price>
            <g:sale_price>{{ number_format($offer['price'], 2, '.', '') }} RUB</g:sale_price>
@else
            <g:price>{{ number_format($offer['price'], 2, '.', '') }} RUB</g:price>
@endif
            <g:brand>{{ $offer['vendor'] }}</g:brand>
            <g:condition>new</g:condition>
@if($offer['sku'])
            <g:mpn>{{ $offer['sku'] }}</g:mpn>
@endif
            <g:identifier_exists>no</g:identifier_exists>
@if($offer['size'])
            <g:size>{{ $offer['size'] }}</g:size>
@endif
@if($offer['color'])
            <g:color>{{ $offer['color'] }}</g:color>
@endif
            <g:google_product_category>Apparel &amp; Accessories &gt; Clothing</g:google_product_category>
@if($offer['category_name'])
            <g:product_type>{{ $offer['category_name'] }}</g:product_type>
@endif
@if($offer['weight'])
            <g:shipping_weight>{{ $offer['weight'] }} kg</g:shipping_weight>
@endif
        </item>
@endforeach
    </channel>
</rss>
