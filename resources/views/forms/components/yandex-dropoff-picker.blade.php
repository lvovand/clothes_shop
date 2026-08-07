<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="{
            state: $wire.$entangle(@js($getStatePath())),
            points: [],
            filter: '',
            loading: false,
            error: null,
            savedLabel: @js($getSelectedLabel()),
            mapBlocked: false,
            init() {
                window.initYandexDropoffPicker(this, {
                    pointsUrl: @js(route('admin.yandex-delivery.points')),
                    mapKey: @js(\App\Models\SiteSetting::get('yandex_map_api_key', config('services.cdek.yandex_map_api_key'))),
                    cityPath: 'data.yandex_delivery_dropoff_city',
                    tokenPath: 'data.yandex_delivery_token',
                })
            },
        }"
        class="space-y-2"
    >
        <div class="flex items-center gap-3">
            <button type="button" x-on:click.prevent="load()"
                    class="fi-btn fi-btn-size-sm rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white">
                Показать точки на карте
            </button>
            <span class="text-sm text-gray-500" x-show="loading">Загружаем точки…</span>
            <span class="text-sm text-danger-600" x-show="error" x-text="error"></span>
            {{-- Выбранная точка видна всегда, а не только с открытой картой. --}}
            <span class="text-sm text-gray-600 dark:text-gray-300" x-show="label()" x-text="label()"></span>
        </div>

        {{-- Карту в браузере может блокировать расширение (adblock): тогда вместо
             пустого серого поля показываем причину и оставляем выбор списком. --}}
        <p class="text-sm text-warning-600" x-show="mapBlocked">
            Карта не загрузилась — скорее всего её блокирует расширение браузера
            (блокировщик рекламы). Выберите точку из списка ниже или отключите
            блокировку для этого сайта.
        </p>

        {{-- Раскладка и размеры — своим CSS (см. ниже): в предсобранном CSS админки
             нет произвольных Tailwind-классов (h-[420px]), а инлайновый display
             затирался бы x-show, который при показе просто чистит style.display. --}}
        <div class="yd-picker__grid" x-show="points.length">
            <div class="yd-picker__map rounded-lg border border-gray-950/10 dark:border-white/20 overflow-hidden"
                 x-ref="map"></div>

            <div class="yd-picker__side flex flex-col rounded-lg border border-gray-950/10 dark:border-white/20 overflow-hidden">
                <input type="text" x-model="filter" placeholder="Поиск по адресу"
                       class="border-b border-gray-950/10 px-3 py-2 text-sm dark:border-white/20 dark:bg-transparent">
                <ul class="flex-1 overflow-y-auto text-sm">
                    <template x-for="point in filtered().slice(0, 80)" :key="point.id">
                        <li x-on:click="choose(point)"
                            class="cursor-pointer border-b border-gray-950/5 px-3 py-2 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5"
                            :class="point.id === state ? 'bg-primary-50 dark:bg-primary-500/10' : ''">
                            <div class="font-medium" x-text="(point.type === 'warehouse' ? 'Склад: ' : 'ПВЗ: ') + point.name"></div>
                            <div class="text-gray-500" x-text="point.address"></div>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    </div>
</x-dynamic-component>

@once
    <script>
        window.initYandexDropoffPicker = function (component, config) {
            // Объекты Яндекс.Карт держим в замыкании, а НЕ в свойствах Alpine:
            // Alpine оборачивает своё состояние в реактивный Proxy, внутри которого
            // API карт ломается — карта остаётся пустой, а в консоли видно
            // «Cannot read properties of undefined (reading '0')» из full.js.
            let map = null;
            let marks = null;
            // Точки грузим только по кнопке: запрос идёт к API Яндекса и не нужен,
            // пока владелец не открыл этот раздел настроек.
            component.load = async function () {
                this.error = null;
                this.loading = true;

                try {
                    const city = this.$wire.get(config.cityPath) || 'Москва';
                    const token = this.$wire.get(config.tokenPath) || '';
                    const url = config.pointsUrl + '?city=' + encodeURIComponent(city) + '&token=' + encodeURIComponent(token);
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    const json = await response.json();

                    this.points = json.points || [];

                    if (!this.points.length) {
                        this.error = 'Точки не найдены — проверьте токен и город.';

                        return;
                    }

                    await this.drawMap();
                } catch (e) {
                    this.error = 'Не удалось получить точки: ' + e.message;
                } finally {
                    this.loading = false;
                }
            };

            component.filtered = function () {
                const needle = this.filter.trim().toLowerCase();

                return this.points.filter(point => !needle
                    || point.address.toLowerCase().includes(needle)
                    || point.name.toLowerCase().includes(needle));
            };

            component.chosen = function () {
                return this.points.find(point => point.id === this.state) || null;
            };

            component.label = function () {
                const point = this.chosen();

                if (point) {
                    return 'Выбрано: ' + (point.type === 'warehouse' ? 'Склад: ' : 'ПВЗ: ') + point.name + ' — ' + point.address;
                }

                return this.savedLabel ? 'Выбрано: ' + this.savedLabel : '';
            };

            component.choose = function (point) {
                this.state = point.id;
                this.savedLabel = (point.type === 'warehouse' ? 'Склад: ' : 'ПВЗ: ') + point.name + ' — ' + point.address;

                if (map && point.latitude && point.longitude) {
                    map.setCenter([point.latitude, point.longitude], 15);
                }
            };

            component.drawMap = async function () {
                // Ждём, пока Alpine покажет блок с картой: пока он скрыт, карта
                // не может посчитать размеры и остаётся пустым серым полем.
                await this.$nextTick();

                if (!config.mapKey) {
                    // Без ключа Яндекс.Карт остаётся список — он рабочий сам по себе.
                    return;
                }

                window.__ymapsAdminPromise ??= new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = 'https://api-maps.yandex.ru/2.1/?apikey=' + encodeURIComponent(config.mapKey) + '&lang=ru_RU';
                    script.onload = resolve;
                    script.onerror = reject;
                    document.head.appendChild(script);
                });

                await window.__ymapsAdminPromise;

                await new Promise(resolve => ymaps.ready(resolve));

                if (!map) {
                    map = new ymaps.Map(this.$refs.map, {
                        center: [55.751244, 37.618423],
                        zoom: 10,
                        controls: ['zoomControl', 'geolocationControl'],
                    });
                    marks = new ymaps.Clusterer({ preset: 'islands#blackClusterIcons', groupByCoordinates: false });
                    map.geoObjects.add(marks);
                }

                marks.removeAll();

                const placemarks = [];
                let minLat = 90, maxLat = -90, minLon = 180, maxLon = -180;

                this.points.forEach(point => {
                    if (!point.latitude || !point.longitude) {
                        return;
                    }

                    placemarks.push(new ymaps.Placemark([point.latitude, point.longitude], {
                        balloonContentHeader: (point.type === 'warehouse' ? 'Склад: ' : 'ПВЗ: ') + point.name,
                        balloonContentBody: '<p>' + point.address + '</p>'
                            + '<button type="button" class="ymap-pick" data-id="' + point.id + '">Сдавать посылки здесь</button>',
                        hintContent: point.address,
                    }, { preset: point.type === 'warehouse' ? 'islands#blackStarIcon' : 'islands#blackDotIcon' }));

                    minLat = Math.min(minLat, point.latitude);
                    maxLat = Math.max(maxLat, point.latitude);
                    minLon = Math.min(minLon, point.longitude);
                    maxLon = Math.max(maxLon, point.longitude);
                });

                // Кластеризатору метки отдаём одним массивом, иначе он пересчитывает
                // сетку на каждой добавленной метке.
                marks.add(placemarks);

                // Карта создаётся в блоке, который до нажатия кнопки скрыт, поэтому
                // своих размеров она не знает: без этого видно пустое серое поле.
                map.container.fitToViewport();

                // Границы считаем сами: Clusterer.getBounds() сразу после add() ещё
                // не готов и отдаёт null, а setBounds(null) падает внутри API Яндекса
                // ошибкой «Cannot read properties of undefined (reading '0')» — и на
                // этом отрисовка карты обрывается, оставляя пустое поле.
                try {
                    if (placemarks.length > 1) {
                        map.setBounds([[minLat, minLon], [maxLat, maxLon]], { checkZoomRange: true, zoomMargin: 30 });
                    } else if (placemarks.length === 1) {
                        map.setCenter([minLat, minLon], 14);
                    }
                } catch (e) {
                    // Не критично: карта останется в исходном положении.
                }

                setTimeout(() => map?.container.fitToViewport(), 400);

                // Если через несколько секунд карта не запросила ни одной плитки,
                // значит её режет расширение браузера (ERR_BLOCKED_BY_CLIENT).
                setTimeout(() => {
                    const tiles = performance.getEntriesByType('resource')
                        .filter(entry => /core-renderer-tiles|tiles\?/.test(entry.name));
                    this.mapBlocked = tiles.length === 0;
                }, 5000);

                // Кнопка живёт внутри балуна карты, поэтому слушаем на документе.
                if (!this.__pickBound) {
                    this.__pickBound = true;
                    document.addEventListener('click', (event) => {
                        const button = event.target.closest('.ymap-pick');
                        if (!button) {
                            return;
                        }

                        const point = this.points.find(item => item.id === button.dataset.id);
                        if (point) {
                            this.choose(point);
                            map?.balloon.close();
                        }
                    });
                }
            };
        };
    </script>

    <style>
        .yd-picker__grid {
            display: grid;
            gap: 12px;
            grid-template-columns: minmax(0, 1fr) 320px;
        }

        .yd-picker__map,
        .yd-picker__side {
            height: 420px;
        }

        @media (max-width: 1024px) {
            .yd-picker__grid {
                grid-template-columns: minmax(0, 1fr);
            }

            .yd-picker__map,
            .yd-picker__side {
                height: 320px;
            }
        }

        .ymap-pick {
            margin-top: 8px;
            background: #0c0c0c;
            color: #fff;
            border: none;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 12px;
            border-radius: 6px;
        }
    </style>
@endonce
