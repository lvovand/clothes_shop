{{--
    Фотография из хранилища с уменьшенными копиями (см. App\Support\ImageVariants).

    Отдаём WebP через <source> и JPEG через сам <img>: браузер берёт формат,
    который понимает, и ширину, которая нужна его экрану. Обёртка <picture>
    появляется только когда копии реально есть — если их ещё не сделали
    (app:generate-thumbs не гоняли), выводится обычный <img> с оригиналом, то
    есть разметка остаётся точно такой же, как была до srcset.

    $path   — путь внутри storage/app/public
    $sizes  — sizes-атрибут: какой шириной картинка показывается на экране
    $width  — какую копию положить в src как запасную (для браузеров без srcset)
--}}
@props(['path', 'sizes' => null, 'width' => 1280])
@php
    $jpg = \App\Support\ImageVariants::srcset($path, 'jpg');
    $webp = \App\Support\ImageVariants::srcset($path, 'webp');
    $src = $jpg ? \App\Support\ImageVariants::url($path, $width) : asset('storage/' . $path);
@endphp
@if($jpg)
    <picture>
        @if($webp)<source type="image/webp" srcset="{{ $webp }}"@if($sizes) sizes="{{ $sizes }}"@endif>@endif
        <img src="{{ $src }}" srcset="{{ $jpg }}"@if($sizes) sizes="{{ $sizes }}"@endif {{ $attributes }}>
    </picture>
@else
    <img src="{{ $src }}" {{ $attributes }}>
@endif
