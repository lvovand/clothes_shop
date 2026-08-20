{{-- Товары: остаток по складам, цена и видимость. --}}
<section class="tab" id="tab-products" hidden>
    <div class="topbar">
        <input id="products-search" class="search" type="search" placeholder="Название товара или артикул" autocomplete="off">
        <div id="product-chips" class="chips"></div>
    </div>

    <div id="products-list"></div>
    <div id="products-note" class="note">Загружаем товары…</div>
    <button id="products-more" class="more" hidden>Показать ещё</button>
</section>

<script>
// Своя область видимости: у заказов рядом такие же по смыслу list/state/load.
(function () {
const FILTERS = [['', 'Все'], ['low', 'Мало'], ['out', 'Нет в наличии'], ['hidden', 'Скрытые']];

const list = document.getElementById('products-list');
const note = document.getElementById('products-note');
const moreBtn = document.getElementById('products-more');
const searchInput = document.getElementById('products-search');

let state = { page: 1, filter: '', search: '', canEdit: false, loading: false, warehouses: [], started: false };
let searchTimer = null;
// Нажатия ± копятся и уходят одним запросом: иначе пять нажатий — пять записей
// в журнале склада и пять кругов туда-обратно.
let pending = new Map();

/** «7 шт. · Орб 4 · Мск 3» — сразу видно, откуда отгружать. */
function stockLine(total, stocks) {
    const parts = state.warehouses
        .map((warehouse) => warehouse.short + ' ' + (stocks[warehouse.id] || 0));

    return total + ' шт.' + (parts.length > 1 ? ' · ' + parts.join(' · ') : '');
}

function priceLine(product) {
    if (product.price_min === null) {
        return 'без вариантов';
    }

    return product.price_min === product.price_max
        ? money(product.price_min)
        : money(product.price_min) + ' — ' + money(product.price_max);
}

function badges(product) {
    const items = [];
    if (!product.published) {
        items.push('<span class="badge badge--muted">Скрыт</span>');
    }
    if (product.is_new) {
        items.push('<span class="badge">Новинка</span>');
    }
    if (product.stock_total === 0) {
        items.push('<span class="badge badge--warn">Нет в наличии</span>');
    }

    return items.join(' ');
}

function cardHtml(product) {
    return '<article class="card product" data-id="' + product.id + '">'
        + '<div class="product__top">'
        + (product.image ? '<img class="product__photo" src="' + escapeHtml(product.image) + '" alt="" loading="lazy">' : '<div class="product__photo"></div>')
        + '<div class="product__info">'
        + '<div class="product__name">' + escapeHtml(product.name) + '</div>'
        + '<div class="card__meta" data-role="stock">' + escapeHtml(stockLine(product.stock_total, product.stocks)) + '</div>'
        + '<div class="card__meta">' + escapeHtml(priceLine(product)) + '</div>'
        + '<div class="product__badges">' + badges(product) + '</div>'
        + '</div></div>'
        + '<div class="card__actions"><button class="link" data-action="details">Остатки и цены</button></div>'
        + '<div class="details" hidden></div>'
        + '</article>';
}

/** Степпер остатка на одном складе: −, точное число, +. */
function stepperHtml(variant, warehouse) {
    const disabled = state.canEdit ? '' : ' disabled';
    const qty = variant.stocks[warehouse.id] || 0;

    return '<div class="stepper" data-variant="' + variant.id + '" data-warehouse="' + warehouse.id + '">'
        + '<span class="stepper__label">' + escapeHtml(warehouse.name) + (warehouse.allows_pickup ? ' (самовывоз)' : '') + '</span>'
        + '<div class="stepper__controls">'
        + '<button class="stepper__btn" data-step="-1"' + disabled + '>−</button>'
        + '<input class="stepper__input" type="number" inputmode="numeric" min="0" value="' + qty + '" data-qty="' + qty + '"' + disabled + '>'
        + '<button class="stepper__btn" data-step="1"' + disabled + '>+</button>'
        + '</div></div>';
}

function variantHtml(variant) {
    const disabled = state.canEdit ? '' : ' disabled';
    const title = variant.size || variant.label || variant.sku || 'вариант';

    return '<div class="variant" data-variant="' + variant.id + '">'
        + '<div class="variant__head"><span class="variant__title">' + escapeHtml(title) + '</span>'
        + '<span class="details__dim">' + escapeHtml(variant.sku || '') + '</span>'
        + '<span class="variant__total" data-role="variant-total">' + variant.stock_total + ' шт.</span></div>'
        + state.warehouses.map((warehouse) => stepperHtml(variant, warehouse)).join('')
        + (variant.waiting > 0 ? '<div class="variant__waiting">Ждут возврата в наличие: ' + variant.waiting + '</div>' : '')
        + '<div class="prices">'
        + '<label class="field"><span class="field__label">Цена, ₽</span>'
        + '<input class="price" type="number" inputmode="decimal" min="0" data-price="regular" value="' + variant.regular_price + '"' + disabled + '></label>'
        + '<label class="field"><span class="field__label">По скидке, ₽</span>'
        + '<input class="price" type="number" inputmode="decimal" min="0" data-price="sale" value="' + (variant.sale_price === null ? '' : variant.sale_price) + '" placeholder="без скидки"' + disabled + '></label>'
        + '</div>'
        + '</div>';
}

function detailsHtml(product) {
    const parts = [];

    if (state.canEdit) {
        parts.push('<div class="toggles">'
            + '<button class="chip" data-flag="published" aria-pressed="' + product.published + '">'
            + (product.published ? 'Опубликован' : 'Скрыт с витрины') + '</button>'
            + '<button class="chip" data-flag="is_new" aria-pressed="' + product.is_new + '">Новинка</button>'
            + '</div>');
    }

    // Варианты сгруппированы по цвету: у товара их до десятка, и без группировки
    // размеры одного цвета не отличить от размеров другого.
    const groups = new Map();
    product.variants.forEach((variant) => {
        const key = variant.color || '';
        if (!groups.has(key)) {
            groups.set(key, []);
        }
        groups.get(key).push(variant);
    });

    groups.forEach((variants, color) => {
        if (color) {
            parts.push('<div class="details__head">' + escapeHtml(color) + '</div>');
        }
        variants.forEach((variant) => parts.push(variantHtml(variant)));
    });

    if (!product.variants.length) {
        parts.push('<div class="note">У товара нет вариантов — заведите их в админке.</div>');
    }

    return parts.join('');
}

function renderChips() {
    document.getElementById('product-chips').innerHTML = FILTERS.map(([key, label]) =>
        '<button class="chip" data-filter="' + key + '" aria-pressed="' + (key === state.filter) + '">' + escapeHtml(label) + '</button>'
    ).join('');
}

async function load(reset) {
    if (state.loading) {
        return;
    }
    state.loading = true;
    state.started = true;

    if (reset) {
        state.page = 1;
        list.innerHTML = '';
        moreBtn.hidden = true;
        note.hidden = false;
        note.textContent = 'Загружаем товары…';
    }

    const params = new URLSearchParams({ page: state.page });
    if (state.filter) params.set('filter', state.filter);
    if (state.search) params.set('search', state.search);

    try {
        const data = await api('/tg/api/products?' + params.toString());
        state.canEdit = data.can_edit;
        state.warehouses = data.warehouses;

        list.insertAdjacentHTML('beforeend', data.products.map(cardHtml).join(''));
        moreBtn.hidden = !data.has_more;

        if (data.total === 0) {
            note.hidden = false;
            note.textContent = state.search || state.filter ? 'Ничего не нашлось.' : 'Товаров пока нет.';
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

async function toggleDetails(card, button) {
    const box = card.querySelector('.details');

    if (!box.hidden) {
        box.hidden = true;
        button.textContent = 'Остатки и цены';
        return;
    }

    button.textContent = 'Скрыть';
    box.hidden = false;

    if (!box.dataset.loaded) {
        box.textContent = 'Загружаем…';
        try {
            const data = await api('/tg/api/products/' + card.dataset.id);
            state.canEdit = data.can_edit;
            state.warehouses = data.warehouses;
            box.innerHTML = detailsHtml(data.product);
            box.dataset.loaded = '1';
        } catch (error) {
            box.textContent = error.message;
        }
    }
}

/** Новые числа с сервера: у варианта и в строке товара. */
function applyStockState(card, data) {
    const variantBox = card.querySelector('.variant[data-variant="' + data.variant.id + '"]');

    if (variantBox) {
        variantBox.querySelector('[data-role="variant-total"]').textContent = data.variant.stock_total + ' шт.';
        variantBox.querySelectorAll('.stepper').forEach((stepper) => {
            const input = stepper.querySelector('.stepper__input');
            const qty = data.variant.stocks[stepper.dataset.warehouse] || 0;
            input.value = qty;
            input.dataset.qty = qty;
        });
    }

    card.querySelector('[data-role="stock"]').textContent = stockLine(data.product.stock_total, data.product.stocks);
}

async function sendStock(card, stepper, body) {
    const input = stepper.querySelector('.stepper__input');
    stepper.classList.remove('stepper--saved', 'stepper--error');

    try {
        const data = await api('/tg/api/products/' + card.dataset.id + '/variants/' + stepper.dataset.variant + '/stock', {
            method: 'POST',
            body: JSON.stringify(body),
        });

        applyStockState(card, data);
        stepper.classList.add('stepper--saved');
        if (tg && tg.HapticFeedback) {
            tg.HapticFeedback.notificationOccurred('success');
        }
        setTimeout(() => stepper.classList.remove('stepper--saved'), 1200);
    } catch (error) {
        stepper.classList.add('stepper--error');
        // Возвращаем то значение, которое реально лежит на складе.
        input.value = input.dataset.qty;
        if (tg) {
            tg.showAlert(error.message);
        } else {
            alert(error.message);
        }
    }
}

/** Нажатие ±: показываем сразу, отправляем накопленную разницу через паузу. */
function step(card, stepper, delta) {
    const input = stepper.querySelector('.stepper__input');
    const shown = Math.max(0, (parseInt(input.value, 10) || 0) + delta);

    // Ниже нуля остаток не уходит — показывать «−1» и получать отказ ни к чему.
    if (shown === parseInt(input.value, 10)) {
        return;
    }

    input.value = shown;

    const key = stepper.dataset.variant + ':' + stepper.dataset.warehouse;
    const entry = pending.get(key) || { delta: 0, timer: null };
    entry.delta += delta;
    clearTimeout(entry.timer);
    entry.timer = setTimeout(() => {
        const total = entry.delta;
        pending.delete(key);
        if (total !== 0) {
            sendStock(card, stepper, { warehouse_id: Number(stepper.dataset.warehouse), delta: total });
        }
    }, 600);
    pending.set(key, entry);
}

/** Ручной ввод числа — выставляем остаток точным значением. */
function setQty(card, stepper) {
    const input = stepper.querySelector('.stepper__input');
    const qty = Math.max(0, parseInt(input.value, 10) || 0);
    input.value = qty;

    if (qty === parseInt(input.dataset.qty, 10)) {
        return;
    }

    sendStock(card, stepper, { warehouse_id: Number(stepper.dataset.warehouse), qty: qty });
}

/** Цена и скидка уходят вместе: сервер не принимает скидку выше цены. */
async function savePrice(card, variantBox, input) {
    const field = input.closest('.field');
    const regular = variantBox.querySelector('[data-price="regular"]');
    const sale = variantBox.querySelector('[data-price="sale"]');

    field.classList.remove('field--saved', 'field--error');

    try {
        const data = await api('/tg/api/products/' + card.dataset.id + '/variants/' + variantBox.dataset.variant + '/price', {
            method: 'POST',
            body: JSON.stringify({
                regular_price: Number(regular.value),
                sale_price: sale.value === '' ? null : Number(sale.value),
            }),
        });

        regular.value = data.variant.regular_price;
        sale.value = data.variant.sale_price === null ? '' : data.variant.sale_price;
        field.classList.add('field--saved');
        setTimeout(() => field.classList.remove('field--saved'), 1500);
    } catch (error) {
        field.classList.add('field--error');
        if (tg) {
            tg.showAlert(error.message);
        } else {
            alert(error.message);
        }
    }
}

/** Тумблеры «Опубликован» и «Новинка». */
async function toggleFlag(card, button) {
    const flag = button.dataset.flag;
    const next = button.getAttribute('aria-pressed') !== 'true';
    const body = flag === 'published' ? { status: next ? 'published' : 'draft' } : { is_new: next };

    button.disabled = true;

    try {
        const data = await api('/tg/api/products/' + card.dataset.id + '/flags', {
            method: 'POST',
            body: JSON.stringify(body),
        });

        const box = card.querySelector('.details');
        const published = box.querySelector('[data-flag="published"]');
        const isNew = box.querySelector('[data-flag="is_new"]');

        if (published) {
            published.setAttribute('aria-pressed', data.product.published);
            published.textContent = data.product.published ? 'Опубликован' : 'Скрыт с витрины';
        }
        if (isNew) {
            isNew.setAttribute('aria-pressed', data.product.is_new);
        }

        // Метки в строке товара считаются по тем же данным, что и в списке.
        card.querySelector('.product__badges').innerHTML = badges({
            published: data.product.published,
            is_new: data.product.is_new,
            stock_total: 1,
        });
    } catch (error) {
        if (tg) {
            tg.showAlert(error.message);
        } else {
            alert(error.message);
        }
    } finally {
        button.disabled = false;
    }
}

list.addEventListener('click', (event) => {
    const card = event.target.closest('.card');
    if (!card) {
        return;
    }

    const details = event.target.closest('[data-action="details"]');
    if (details) {
        toggleDetails(card, details);
        return;
    }

    const stepBtn = event.target.closest('[data-step]');
    if (stepBtn) {
        step(card, stepBtn.closest('.stepper'), Number(stepBtn.dataset.step));
        return;
    }

    const flag = event.target.closest('[data-flag]');
    if (flag) {
        toggleFlag(card, flag);
    }
});

list.addEventListener('change', (event) => {
    const card = event.target.closest('.card');
    if (!card) {
        return;
    }

    const stockInput = event.target.closest('.stepper__input');
    if (stockInput) {
        setQty(card, stockInput.closest('.stepper'));
        return;
    }

    const priceInput = event.target.closest('.price');
    if (priceInput) {
        savePrice(card, priceInput.closest('.variant'), priceInput);
    }
});

document.getElementById('product-chips').addEventListener('click', (event) => {
    const chip = event.target.closest('.chip');
    if (!chip) {
        return;
    }

    state.filter = chip.dataset.filter;
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

// Список грузится при первом открытии вкладки, а не на старте: приложение
// открывают ради заказов, и лишний запрос задержал бы их появление.
document.addEventListener('tab:products', () => {
    if (!state.started) {
        load(true);
    }
});
})();
</script>
