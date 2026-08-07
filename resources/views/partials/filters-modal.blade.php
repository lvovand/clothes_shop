{{--
    Модалка фильтров. Каркас и классы — как на эталоне (.filters-modal + разметка
    wpf*), чтобы применились те же стили. Внутри — обычная GET-форма: у нас нет
    плагина эталона, фильтрация делается запросом на сервер.
    Известное упрощение: у эталона цена — ползунок, здесь два поля «от/до».
--}}
@php
    $colorValues = $colorValues ?? collect();
    $sizeValues = $sizeValues ?? collect();
    $checkedSizes = (array) request('sizes', []);
    $checkedColors = (array) request('colors', []);
    $checkedStock = (array) request('stock', []);
    $priceMin = $priceMin ?? 0;
    $priceMax = $priceMax ?? 0;
@endphp
<div class="filters-modal" id="filters-modal">
    <div class="filters-modal-header">
        <p class="filters-modal-header__title">
            Фильтры
        </p>
        <p class="filters-modal-header__clear" data-filters-clear>
            Сбросить
        </p>
    </div>

    <form method="GET" class="wpfMainWrapper" id="filters-form">

        @if($sizeValues->isNotEmpty())
        <div class="wpfFilterWrapper wpfNotActive" id="wpfBlock_1" data-filter-type="wpfAttribute" data-display-type="list" data-label="Размер">
            <div class="wpfFilterTitle" data-show-on-mobile="yes_open" data-show-on-desctop="yes_open">
                <div class="wfpTitle wfpClickable">Размер</div><i class="fa fa-minus wpfTitleToggle"></i>
            </div>
            <div class="wpfFilterContent">
                <div class="wpfCheckboxHier">
                    <ul class="wpfFilterVerScroll">
                        @foreach($sizeValues as $value)
                            <li data-term-id="{{ $value->id }}" data-parent="0" data-term-slug="{{ $value->slug ?? $value->id }}">
                                <label class="wpfLiLabel">
                                    <span class="wpfCheckbox">
                                        <input type="checkbox" id="wpfSize{{ $value->id }}" name="sizes[]" value="{{ $value->id }}" @checked(in_array($value->id, $checkedSizes))>
                                        <label aria-label="{{ $value->label }}" for="wpfSize{{ $value->id }}"></label>
                                    </span>
                                    <span class="wpfDisplay"><span class="wpfValue"><span class="wpfFilterTaxNameWrapper">{{ $value->label }}</span></span></span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
        @endif

        <div class="wpfFilterWrapper wpfNotActive" id="wpfBlock_2" data-filter-type="wpfPriceRange" data-label="Цена">
            <div class="wpfFilterTitle" data-show-on-mobile="yes_open" data-show-on-desctop="yes_open">
                <div class="wfpTitle wfpClickable">Цена</div><i class="fa fa-minus wpfTitleToggle"></i>
            </div>
            <div class="wpfFilterContent">
                <div class="wpfPriceRangeWrapper">
                    <input type="number" name="price_min" placeholder="{{ $priceMin }}" value="{{ request('price_min') }}" min="{{ $priceMin }}" max="{{ $priceMax }}" class="wpfPriceFrom">
                    <input type="number" name="price_max" placeholder="{{ $priceMax }}" value="{{ request('price_max') }}" min="{{ $priceMin }}" max="{{ $priceMax }}" class="wpfPriceTo">
                </div>
            </div>
        </div>

        @if($colorValues->isNotEmpty())
        <div class="wpfFilterWrapper wpfNotActive" id="wpfBlock_3" data-filter-type="wpfAttribute" data-display-type="list" data-label="Цвет">
            <div class="wpfFilterTitle" data-show-on-mobile="yes_open" data-show-on-desctop="yes_open">
                <div class="wfpTitle wfpClickable">Цвет</div><i class="fa fa-minus wpfTitleToggle"></i>
            </div>
            <div class="wpfFilterContent">
                <div class="wpfCheckboxHier">
                    <ul class="wpfFilterVerScroll">
                        @foreach($colorValues as $value)
                            <li data-term-id="{{ $value->id }}" data-parent="0" data-term-slug="{{ $value->slug ?? $value->id }}">
                                <label class="wpfLiLabel">
                                    <span class="wpfCheckbox">
                                        <input type="checkbox" id="wpfColor{{ $value->id }}" name="colors[]" value="{{ $value->id }}" @checked(in_array($value->id, $checkedColors))>
                                        <label aria-label="{{ $value->label }}" for="wpfColor{{ $value->id }}"></label>
                                    </span>
                                    <span class="wpfDisplay"><span class="wpfValue"><span class="wpfFilterTaxNameWrapper">{{ $value->label }}</span></span></span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
        @endif

        <div class="wpfFilterWrapper wpfNotActive" id="wpfBlock_4" data-filter-type="wpfInStock" data-label="Наличие">
            <div class="wpfFilterTitle" data-show-on-mobile="yes_open" data-show-on-desctop="yes_open">
                <div class="wfpTitle wfpClickable">Наличие</div><i class="fa fa-minus wpfTitleToggle"></i>
            </div>
            <div class="wpfFilterContent">
                <div class="wpfCheckboxHier">
                    <ul class="wpfFilterVerScroll">
                        @foreach(['in' => 'В наличии', 'out' => 'Нет в наличии'] as $key => $label)
                            <li data-term-id="{{ $key }}" data-parent="0" data-term-slug="{{ $key }}">
                                <label class="wpfLiLabel">
                                    <span class="wpfCheckbox">
                                        <input type="checkbox" id="wpfStock{{ $key }}" name="stock[]" value="{{ $key }}" @checked(in_array($key, $checkedStock))>
                                        <label aria-label="{{ $label }}" for="wpfStock{{ $key }}"></label>
                                    </span>
                                    <span class="wpfDisplay"><span class="wpfValue"><span class="wpfFilterTaxNameWrapper">{{ $label }}</span></span></span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        <div class="wpfFilterWrapper wpfNotActive" id="wpfBlock_5" data-filter-type="wpfSortBy" data-slug="Сортировать по:" data-radio="1" data-display-type="radio" data-label="Сортировка">
            <div class="wpfFilterTitle" data-show-on-mobile="yes_open" data-show-on-desctop="yes_open">
                <div class="wfpTitle wfpClickable">Сортировка</div><i class="fa fa-minus wpfTitleToggle"></i>
            </div>
            <div class="wpfFilterContent">
                <ul class="wpfFilterVerScroll">
                    @foreach(['popularity' => 'по популярности', 'date' => 'по новизне', 'price_asc' => 'цена по возрастанию', 'price_desc' => 'цена по убыванию'] as $key => $label)
                        <li data-term-id="{{ $key }}" data-parent="0" data-term-slug="{{ $key }}">
                            <label class="wpfLiLabel">
                                <span class="wpfCheckbox">
                                    <input type="radio" id="wpfSort{{ $key }}" name="sort" value="{{ $key }}" @checked(request('sort', 'popularity') === $key)>
                                    <label aria-label="{{ $label }}" for="wpfSort{{ $key }}"></label>
                                </span>
                                <span class="wpfDisplay"><span class="wpfValue"><span class="wpfFilterTaxNameWrapper">{{ $label }}</span></span></span>
                            </label>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="wpfFilterButtons">
            <button type="submit" class="wpfFilterButton wpfButton">Применить</button>
            <button type="button" class="wpfClearButton wpfButton" data-filters-clear>Сбросить</button>
        </div>

    </form>
</div>
