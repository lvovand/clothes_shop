/**
 * Поведение, общее для всех страниц магазина. Подключается после main.js эталона,
 * чтобы можно было опираться на его обработчики и при необходимости их снимать.
 *
 * Избранное: у эталона это обычная форма с перезагрузкой страницы. Разметку
 * сохраняем как есть (форма + кнопка .wish-list-form__btn с картинкой-сердцем),
 * но отправляем без перезагрузки и меняем иконку на закрашенную. Иконку берём
 * подменой имени файла в текущем src — так путь к теме не нужно знать скрипту.
 */
jQuery(function ($) {
    const IDLE = 'icon-heart.svg';
    const ACTIVE = 'icon-col-heart.svg';

    function setIcon($img, added) {
        const src = $img.attr('src');
        $img.attr('src', added ? src.replace(IDLE, ACTIVE) : src.replace(ACTIVE, IDLE));
    }

    $(document).on('submit', '[data-wishlist-form]', function (e) {
        e.preventDefault();

        const $form = $(this);
        const productId = $form.find('input[name="product_id"]').val();

        $.post({
            url: $form.attr('action'),
            data: $form.serialize(),
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
            },
        }).done(function (response) {
            // Один товар может быть на странице несколькими формами (в карточке
            // товара — в галерее и в мобильной плашке, в сетке — если попал дважды),
            // поэтому меняем иконку у всех форм этого товара.
            $('[data-wishlist-form]').each(function () {
                if ($(this).find('input[name="product_id"]').val() === productId) {
                    setIcon($(this).find('img'), response.added);
                }
            });

            $('.header-buttons__wishlist span').toggleClass('active', response.count > 0);

            // На странице «Избранное» убранный товар должен уйти из сетки.
            if (! response.added && $form.closest('[data-wishlist-page]').length) {
                $form.closest('.product-item').remove();
            }
        });
    });
});

// Компактная шапка при прокрутке. Свой класс, а не .fixed темы: тема снимает
// .fixed при скролле вниз и оставляет его наверху страницы, нам нужно ровно
// обратное — компактный вид всегда, пока страница прокручена.
(function () {
    var header = document.querySelector('.header');
    if (!header) {
        return;
    }

    var ticking = false;

    function apply() {
        header.classList.toggle('header--compact', window.pageYOffset > 50);
        ticking = false;
    }

    window.addEventListener('scroll', function () {
        if (!ticking) {
            ticking = true;
            requestAnimationFrame(apply);
        }
    }, {passive: true});

    apply();
})();

// Фильтры каталога.
(function () {
    var form = document.getElementById('filters-form');
    if (!form) {
        return;
    }

    // Сортировка размечена чекбоксами (так требует CSS эталона), но по смыслу
    // это переключатель: отмеченным остаётся только последний выбранный.
    var sortBoxes = form.querySelectorAll('input[type="checkbox"][name="sort"]');
    Array.prototype.forEach.call(sortBoxes, function (box) {
        box.addEventListener('change', function () {
            if (!box.checked) {
                return;
            }
            Array.prototype.forEach.call(sortBoxes, function (other) {
                if (other !== box) {
                    other.checked = false;
                }
            });
        });
    });

    // «Сбросить» — уходим на тот же раздел без параметров фильтра.
    Array.prototype.forEach.call(document.querySelectorAll('[data-filters-clear]'), function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            window.location.href = window.location.pathname;
        });
    });
})();
