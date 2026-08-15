<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Заказы — ROPA WORLD</title>
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
            padding: 0 12px calc(24px + env(safe-area-inset-bottom));
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
    </style>
</head>
<body>
    <div class="topbar">
        <input id="search" class="search" type="search" placeholder="Номер заказа, имя или телефон" autocomplete="off">
        <div id="status-chips" class="chips"></div>
    </div>

    <div id="list"></div>
    <div id="note" class="note">Загружаем заказы…</div>
    <button id="more" class="more" hidden>Показать ещё</button>

<script>
const STATUSES = @json($statuses);
const PAYMENT_STATUSES = @json($paymentStatuses);

const tg = window.Telegram ? window.Telegram.WebApp : null;
const list = document.getElementById('list');
const note = document.getElementById('note');
const moreBtn = document.getElementById('more');
const searchInput = document.getElementById('search');

let state = { page: 1, status: '', search: '', canEdit: false, loading: false };
let searchTimer = null;

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

function cardHtml(order) {
    const disabled = state.canEdit ? '' : ' disabled';

    return '<article class="card" data-id="' + order.id + '">'
        + '<div class="card__head"><span class="card__number">№ ' + escapeHtml(order.number) + '</span>'
        + '<span class="card__date">' + escapeHtml(order.created_at) + '</span></div>'
        + '<div class="card__customer">' + escapeHtml(order.customer || 'без имени') + '</div>'
        + '<div class="card__meta">' + (order.phone ? '<a class="link" href="tel:' + escapeHtml(order.phone) + '">' + escapeHtml(order.phone) + '</a> · ' : '')
        + escapeHtml(order.shipping_method || 'доставка не выбрана') + ' · ' + escapeHtml(order.payment_method || '') + '</div>'
        + '<div class="card__total">' + money(order.total) + '</div>'
        + '<div class="selects">'
        + '<label class="field" data-field="status"><span class="field__label">Статус</span>'
        + '<select data-kind="status"' + disabled + '>' + options(STATUSES, order.status) + '</select></label>'
        + '<label class="field" data-field="payment_status"><span class="field__label">Оплата</span>'
        + '<select data-kind="payment_status"' + disabled + '>' + options(PAYMENT_STATUSES, order.payment_status) + '</select></label>'
        + '</div>'
        + '<div class="card__actions"><button class="link" data-action="details">Подробнее</button></div>'
        + '<div class="details" hidden></div>'
        + '</article>';
}

function detailsHtml(order) {
    const row = (label, value) => value ? '<div class="details__row"><span>' + label + '</span><span>' + escapeHtml(value) + '</span></div>' : '';
    const head = (text) => '<div class="details__head">' + text + '</div>';
    const parts = [];

    parts.push(head('Покупатель'));
    parts.push(row('Телефон', order.phone));
    parts.push(row('Email', order.email));
    parts.push(row('Комментарий', order.comment));

    const d = order.delivery;
    parts.push(head('Доставка'));
    parts.push(row('Способ', d.method));
    parts.push(row('Перевозчик', d.carrier));
    parts.push(row('Как получает', d.kind));
    parts.push(row('Город', d.city));
    parts.push(row('Адрес', d.address));
    parts.push(row('Пункт выдачи', d.pvz_address || d.pvz_code));
    parts.push(row('Код пункта', d.pvz_address ? d.pvz_code : null));
    parts.push(row('Срок', d.days));
    parts.push(row('Стоимость', money(d.cost)));

    // Заявок бывает две: заказ с двух складов едет двумя отправлениями.
    if (order.shipments && order.shipments.length) {
        order.shipments.forEach(shipment => parts.push(row(
            shipment.warehouse ? 'Заявка (' + shipment.warehouse + ')' : 'Заявка',
            shipment.provider + ': ' + shipment.number + ' — ' + shipment.status,
        )));
    } else {
        parts.push(row('Заявка', 'не создана'));
    }

    if (state.canEdit) {
        const buttons = [];
        if (order.shipment_actions.can_create) {
            buttons.push('<button class="btn" data-shipment="create">Оформить доставку</button>');
        }
        if (order.shipment_actions.can_refresh_number) {
            buttons.push('<button class="btn btn--ghost" data-shipment="refresh">Обновить номер накладной</button>');
        }
        if (order.shipment_actions.can_cancel) {
            buttons.push('<button class="btn btn--ghost" data-shipment="cancel">Отменить заявку</button>');
        }
        if (buttons.length) {
            parts.push('<div class="details__buttons">' + buttons.join('') + '</div>');
        }
    }

    parts.push(head('Оплата'));
    parts.push(row('Способ', order.payment_method));
    parts.push(row('Статус', order.payment_status_label));
    order.payments.forEach((payment) => {
        parts.push(row(payment.created_at, payment.provider + ': ' + money(payment.amount) + ' — ' + payment.status
            + (payment.payment_id ? ' (' + payment.payment_id + ')' : '')));
    });

    parts.push(head('Состав заказа'));
    parts.push('<ul class="details__items">' + order.items.map((item) => '<li>' + escapeHtml(item.title)
        + (item.attrs ? ' <span class="details__dim">' + escapeHtml(item.attrs) + '</span>' : '')
        + '<br>' + item.qty + ' × ' + money(item.unit_price) + ' = ' + money(item.line_total)
        + (item.warehouses.length ? '<br><span class="details__dim">Отгрузка — ' + escapeHtml(item.warehouses.join('; ')) + '</span>' : '')
        + '</li>').join('') + '</ul>');

    parts.push(row('Товары', money(order.subtotal)));
    parts.push(row('Доставка', money(order.shipping_cost)));

    if (order.discount_total > 0) {
        parts.push(row('Скидка' + (order.coupon_code ? ' (' + order.coupon_code + ')' : ''), '−' + money(order.discount_total)));
    }

    if (order.gift_certificate_used > 0) {
        parts.push(row('Сертификат' + (order.gift_certificate_code ? ' (' + order.gift_certificate_code + ')' : ''), '−' + money(order.gift_certificate_used)));
    }

    parts.push('<div class="details__row details__row--total"><span>Итого</span><span>' + money(order.total) + '</span></div>');

    return parts.join('');
}

/** Создание, отмена заявки и дозапрос номера накладной — как кнопки в админке. */
async function shipmentAction(card, button) {
    const action = button.dataset.shipment;
    const confirmText = {
        create: 'Оформить доставку у перевозчика по данным этого заказа?',
        cancel: 'Отменить заявку у перевозчика?',
    }[action];

    if (confirmText && tg && tg.showConfirm) {
        const agreed = await new Promise((resolve) => tg.showConfirm(confirmText, resolve));
        if (!agreed) {
            return;
        }
    }

    const buttons = card.querySelectorAll('[data-shipment]');
    buttons.forEach((el) => { el.disabled = true; });
    const label = button.textContent;
    button.textContent = 'Отправляем…';

    try {
        const data = await api('/tg/api/orders/' + card.dataset.id + '/shipment/' + action, { method: 'POST' });
        if (tg) {
            tg.showAlert(data.message);
        }
    } catch (error) {
        if (tg) {
            tg.showAlert(error.message);
        } else {
            alert(error.message);
        }
    } finally {
        button.textContent = label;
        buttons.forEach((el) => { el.disabled = false; });
        // Перечитываем карточку: после действия меняются и номер заявки, и кнопки.
        const box = card.querySelector('.details');
        delete box.dataset.loaded;
        box.hidden = true;
        toggleDetails(card, card.querySelector('[data-action="details"]'));
    }
}

function renderChips() {
    const chips = document.getElementById('status-chips');
    const all = [['', 'Все']].concat(Object.keys(STATUSES).map((key) => [key, STATUSES[key]]));

    chips.innerHTML = all.map(([key, label]) =>
        '<button class="chip" data-status="' + key + '" aria-pressed="' + (key === state.status) + '">' + escapeHtml(label) + '</button>'
    ).join('');
}

async function load(reset) {
    if (state.loading) {
        return;
    }
    state.loading = true;

    if (reset) {
        state.page = 1;
        list.innerHTML = '';
        moreBtn.hidden = true;
        note.hidden = false;
        note.textContent = 'Загружаем заказы…';
    }

    const params = new URLSearchParams({ page: state.page });
    if (state.status) params.set('status', state.status);
    if (state.search) params.set('search', state.search);

    try {
        const data = await api('/tg/api/orders?' + params.toString());
        state.canEdit = data.can_edit;

        list.insertAdjacentHTML('beforeend', data.orders.map(cardHtml).join(''));
        moreBtn.hidden = !data.has_more;

        if (data.total === 0) {
            note.hidden = false;
            note.textContent = state.search || state.status ? 'Ничего не нашлось.' : 'Заказов пока нет.';
        } else {
            note.hidden = true;
        }
    } catch (error) {
        note.hidden = false;
        note.textContent = error.message;
        moreBtn.hidden = true;
    } finally {
        state.loading = false;
    }
}

async function saveStatus(card, select) {
    const field = select.closest('.field');
    const kind = select.dataset.kind;
    const body = {};
    body[kind] = select.value;

    field.classList.remove('field--saved', 'field--error');
    select.disabled = true;

    try {
        const data = await api('/tg/api/orders/' + card.dataset.id + '/status', {
            method: 'POST',
            body: JSON.stringify(body),
        });

        // Второй селект тоже обновляем: статусы связаны (оплата может поменять
        // статус заказа в коде), и показывать устаревшее значение нельзя.
        card.querySelectorAll('select').forEach((el) => {
            el.value = data.order[el.dataset.kind];
        });

        field.classList.add('field--saved');
        if (tg && tg.HapticFeedback) {
            tg.HapticFeedback.notificationOccurred('success');
        }
        setTimeout(() => field.classList.remove('field--saved'), 1500);
    } catch (error) {
        field.classList.add('field--error');
        if (tg) {
            tg.showAlert(error.message);
        } else {
            alert(error.message);
        }
        await load(true);
    } finally {
        select.disabled = !state.canEdit;
    }
}

async function toggleDetails(card, button) {
    const box = card.querySelector('.details');

    if (!box.hidden) {
        box.hidden = true;
        button.textContent = 'Подробнее';
        return;
    }

    button.textContent = 'Скрыть';
    box.hidden = false;

    if (!box.dataset.loaded) {
        box.textContent = 'Загружаем…';
        try {
            const data = await api('/tg/api/orders/' + card.dataset.id);
            box.innerHTML = detailsHtml(data.order);
            box.dataset.loaded = '1';
        } catch (error) {
            box.textContent = error.message;
        }
    }
}

list.addEventListener('change', (event) => {
    const select = event.target.closest('select[data-kind]');
    if (!select) {
        return;
    }

    const card = select.closest('.card');

    // Отмена возвращает товар на склад — необратимое действие, поэтому спрашиваем.
    if (select.dataset.kind === 'status' && select.value === 'cancelled' && tg && tg.showConfirm) {
        tg.showConfirm('Отменить заказ? Товар вернётся на склад.', (ok) => {
            if (ok) {
                saveStatus(card, select);
            } else {
                load(true);
            }
        });
        return;
    }

    saveStatus(card, select);
});

list.addEventListener('click', (event) => {
    const details = event.target.closest('[data-action="details"]');
    if (details) {
        toggleDetails(details.closest('.card'), details);
        return;
    }

    const shipment = event.target.closest('[data-shipment]');
    if (shipment) {
        shipmentAction(shipment.closest('.card'), shipment);
    }
});

document.getElementById('status-chips').addEventListener('click', (event) => {
    const chip = event.target.closest('.chip');
    if (!chip) {
        return;
    }

    state.status = chip.dataset.status;
    renderChips();
    load(true);
});

searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        state.search = searchInput.value.trim();
        load(true);
    }, 350);
});

moreBtn.addEventListener('click', () => {
    state.page += 1;
    load(false);
});

renderChips();
load(true);
</script>
</body>
</html>
