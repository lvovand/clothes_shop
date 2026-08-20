{{-- Заказы: список, смена статусов и заявки перевозчику. --}}
<section class="tab" id="tab-orders">
    <div class="topbar">
        <input id="orders-search" class="search" type="search" placeholder="Номер заказа, имя или телефон" autocomplete="off">
        <div id="order-chips" class="chips"></div>
    </div>

    <div id="orders-list"></div>
    <div id="orders-note" class="note">Загружаем заказы…</div>
    <button id="orders-more" class="more" hidden>Показать ещё</button>
</section>

<script>
// Своя область видимости: у товаров рядом такие же по смыслу list/state/load.
(function () {
const STATUSES = @json($statuses);
const PAYMENT_STATUSES = @json($paymentStatuses);

const list = document.getElementById('orders-list');
const note = document.getElementById('orders-note');
const moreBtn = document.getElementById('orders-more');
const searchInput = document.getElementById('orders-search');

let state = { page: 1, status: '', search: '', canEdit: false, loading: false };
let searchTimer = null;

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
        const chips = document.getElementById('order-chips');
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

    document.getElementById('order-chips').addEventListener('click', (event) => {
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
})();
</script>
