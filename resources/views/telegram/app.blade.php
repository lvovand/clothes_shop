<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ROPA WORLD — заказы и товары</title>
    {{-- Скрипт Telegram отдаёт initData и тему клиента. Грузится только с telegram.org:
         внутри Telegram он доступен всегда, а локальная копия перестала бы получать
         обновления протокола. Витрины сайта это не касается — страница только для админов. --}}
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        :root {
            --bg: var(--tg-theme-bg-color, #ffffff);
            --card: var(--tg-theme-secondary-bg-color, #f3f3f6);
            --text: var(--tg-theme-text-color, #14161a);
            --hint: var(--tg-theme-hint-color, #8a8f98);
            --accent: var(--tg-theme-button-color, #2c7be5);
            --accent-text: var(--tg-theme-button-text-color, #ffffff);
            --line: color-mix(in srgb, var(--hint) 28%, transparent);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 0 12px calc(76px + env(safe-area-inset-bottom));
            background: var(--bg);
            color: var(--text);
            font: 15px/1.4 -apple-system, "Segoe UI", Roboto, system-ui, sans-serif;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 5;
            padding: 10px 0 8px;
            background: var(--bg);
        }

        .search {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--card);
            color: var(--text);
            font-size: 15px;
        }

        .search::placeholder { color: var(--hint); }

        .chips {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            padding: 8px 0 2px;
            scrollbar-width: none;
        }

        .chips::-webkit-scrollbar { display: none; }

        .chip {
            flex: 0 0 auto;
            padding: 6px 12px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: transparent;
            color: var(--hint);
            font-size: 13px;
            white-space: nowrap;
        }

        .chip[aria-pressed="true"] {
            background: var(--accent);
            border-color: var(--accent);
            color: var(--accent-text);
        }

        .card {
            margin: 0 0 10px;
            padding: 12px;
            border-radius: 14px;
            background: var(--card);
        }

        .card__head {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 8px;
        }

        .card__number { font-weight: 700; }

        .card__date { color: var(--hint); font-size: 13px; }

        .card__customer { margin-top: 6px; }

        .card__meta { margin-top: 2px; color: var(--hint); font-size: 13px; }

        .card__total { margin-top: 6px; font-weight: 700; font-size: 17px; }

        .selects {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 10px;
        }

        .field { display: flex; flex-direction: column; gap: 4px; }

        .field__label { color: var(--hint); font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }

        select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--bg);
            color: var(--text);
            font-size: 14px;
            appearance: none;
        }

        select:disabled { opacity: .6; }

        .field--saved select { border-color: #2fa84f; }

        .field--error select { border-color: #e05b4b; }

        .card__actions { display: flex; gap: 12px; margin-top: 10px; }

        .link {
            padding: 0;
            border: 0;
            background: none;
            color: var(--accent);
            font-size: 14px;
            text-decoration: none;
        }

        .details {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--line);
            font-size: 14px;
        }

        .details__row { display: flex; justify-content: space-between; gap: 12px; padding: 2px 0; }

        .details__row span:first-child { color: var(--hint); }

        .details__items { margin: 8px 0 0; padding: 0; list-style: none; }

        .details__items li { padding: 4px 0; border-bottom: 1px dashed var(--line); }

        .details__head {
            margin: 12px 0 4px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--hint);
        }

        .details__head:first-child { margin-top: 0; }

        .details__row--total { margin-top: 6px; font-weight: 700; }

        .details__row--total span:first-child { color: var(--text); }

        .details__dim { color: var(--hint); }

        .details__buttons { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 2px; }

        .btn {
            flex: 1 1 auto;
            padding: 10px 14px;
            border: 0;
            border-radius: 10px;
            background: var(--accent);
            color: var(--accent-text);
            font-size: 14px;
        }

        .btn--ghost {
            border: 1px solid var(--line);
            background: transparent;
            color: var(--text);
        }

        .btn:disabled { opacity: .6; }

        .note { padding: 24px 4px; color: var(--hint); text-align: center; }

        /* Правило display перебивает UA-стиль атрибута hidden, поэтому гасим явно. */
        .more[hidden] { display: none; }

        .more {
            display: block;
            width: 100%;
            margin: 4px 0 0;
            padding: 12px;
            border: 0;
            border-radius: 12px;
            background: var(--accent);
            color: var(--accent-text);
            font-size: 15px;
        }
    
        /* Вкладки: заказы и товары. */
        .tab[hidden] { display: none; }

        .tabs {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 10;
            display: flex;
            gap: 4px;
            padding: 6px 12px calc(6px + env(safe-area-inset-bottom));
            border-top: 1px solid var(--line);
            background: var(--bg);
        }

        .tabs__btn {
            flex: 1 1 0;
            padding: 10px 8px;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: var(--hint);
            font-size: 14px;
        }

        .tabs__btn[aria-pressed="true"] { background: var(--card); color: var(--text); font-weight: 600; }

        /* Товары. */
        .product__top { display: flex; gap: 10px; }

        .product__photo {
            flex: 0 0 auto;
            width: 56px;
            height: 74px;
            border-radius: 8px;
            object-fit: cover;
            background: color-mix(in srgb, var(--hint) 18%, transparent);
        }

        .product__info { min-width: 0; }

        .product__name { font-weight: 600; }

        .product__badges { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }

        .badge {
            padding: 2px 8px;
            border-radius: 999px;
            background: var(--accent);
            color: var(--accent-text);
            font-size: 11px;
        }

        .badge--muted { background: color-mix(in srgb, var(--hint) 40%, transparent); color: var(--text); }

        .badge--warn { background: #e05b4b; color: #fff; }

        .variant {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed var(--line);
        }

        .variant__head { display: flex; align-items: baseline; gap: 8px; }

        .variant__title { font-weight: 600; }

        .variant__total { margin-left: auto; color: var(--hint); font-size: 13px; }

        .variant__waiting { margin-top: 6px; color: var(--accent); font-size: 13px; }

        .stepper { display: flex; align-items: center; gap: 8px; margin-top: 8px; }

        .stepper__label { flex: 1 1 auto; color: var(--hint); font-size: 13px; }

        .stepper__controls { flex: 0 0 auto; display: flex; align-items: center; gap: 6px; }

        .stepper__btn {
            width: 34px;
            height: 34px;
            border: 1px solid var(--line);
            border-radius: 9px;
            background: var(--bg);
            color: var(--text);
            font-size: 18px;
            line-height: 1;
        }

        .stepper__input {
            width: 60px;
            padding: 7px 4px;
            border: 1px solid var(--line);
            border-radius: 9px;
            background: var(--bg);
            color: var(--text);
            font-size: 15px;
            text-align: center;
            appearance: textfield;
        }

        .stepper__input::-webkit-outer-spin-button,
        .stepper__input::-webkit-inner-spin-button { appearance: none; margin: 0; }

        .stepper--saved .stepper__input { border-color: #2fa84f; }

        .stepper--error .stepper__input { border-color: #e05b4b; }

        .stepper__btn:disabled, .stepper__input:disabled { opacity: .6; }

        .prices { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }

        .price {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--bg);
            color: var(--text);
            font-size: 14px;
        }

        .field--saved .price { border-color: #2fa84f; }

        .field--error .price { border-color: #e05b4b; }

        .toggles { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 4px; }
</style>
</head>
<body>
    <nav class="tabs">
        <button class="tabs__btn" data-tab="orders" aria-pressed="true">Заказы</button>
        <button class="tabs__btn" data-tab="products" aria-pressed="false">Товары</button>
    </nav>

<script>
// Общее для обеих вкладок: клиент Telegram, формат денег, обращения к API.
const tg = window.Telegram ? window.Telegram.WebApp : null;

if (tg) {
    tg.ready();
    tg.expand();
}

function money(value) {
    return new Intl.NumberFormat('ru-RU').format(Math.round(value)) + ' ₽';
}

function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

async function api(path, options) {
    const opts = Object.assign({ headers: {} }, options || {});
    opts.headers['X-Telegram-Init-Data'] = tg ? tg.initData : '';
    opts.headers['Accept'] = 'application/json';
    if (opts.body) {
        opts.headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(path, opts);
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(data.message || 'Ошибка ' + response.status);
    }

    return data;
}

function options(map, selected) {
    return Object.keys(map)
        .map((key) => '<option value="' + key + '"' + (key === selected ? ' selected' : '') + '>' + escapeHtml(map[key]) + '</option>')
        .join('');
}

// Переключение вкладок. Вкладка сообщает о своём открытии событием — так каждая
// сама решает, когда грузить данные, и лишних запросов на старте нет.
document.querySelector('.tabs').addEventListener('click', (event) => {
    const button = event.target.closest('.tabs__btn');
    if (!button) {
        return;
    }

    document.querySelectorAll('.tabs__btn').forEach((el) => {
        el.setAttribute('aria-pressed', el === button);
    });
    document.querySelectorAll('.tab').forEach((section) => {
        section.hidden = section.id !== 'tab-' + button.dataset.tab;
    });

    document.dispatchEvent(new Event('tab:' + button.dataset.tab));
    window.scrollTo(0, 0);
});
</script>

@include('telegram.partials.orders')
@include('telegram.partials.products')
</body>
</html>
