$(document).ready(function () {

    // Цвет в карточке товара

    let swatchItemWrappers = document.querySelectorAll('div[data-attribute_name="attribute_pa_color"] .swatch-item-wrapper');

    swatchItemWrappers.forEach(function(swatchItemWrapper) {

        if (swatchItemWrapper.querySelector('.selected')) {
            swatchItemWrapper.classList.add('selected');
        }

        swatchItemWrapper.addEventListener('click', function(event) {

            swatchItemWrappers.forEach(function(item) {
                item.classList.remove('selected');
            });

            if (event.target.classList.contains('selected')) {

                event.target.classList.add('selected');
            } else {

                event.currentTarget.classList.add('selected');
            }
        });
    });


    // дизайн для мини-корзины при клике
    let spanAdded = false;

    $('.add-to-cart, .add-to-cart-single').on('click', function() {
        if (!spanAdded) {
            let spanElement = $('<span></span>');
            $('.header-buttons__use_cart').prepend(spanElement);
            spanAdded = true;
        }
    });

    // плавающая шапка up/down

    let prevScrollPos = window.pageYOffset;
    const header = document.querySelector(".header");
    let scrolling = false;

    function handleScroll() {
        if (!scrolling) {
            scrolling = true;
            requestAnimationFrame(function () {
                const currentScrollPos = window.pageYOffset;

                if (prevScrollPos > currentScrollPos) {
                    if (header.classList.contains("hidden")) {
                        setTimeout(function () {
                            header.classList.remove("hidden");
                        }, 20);
                    }
                    setTimeout(function () {
                        header.classList.add("fixed");
                    }, 20);
                } else if (currentScrollPos > 0) {
                    setTimeout(function () {
                        header.classList.remove("fixed");
                        header.classList.add("hidden");
                    }, 20);
                } else {

                }

                prevScrollPos = currentScrollPos;
                scrolling = false;
            });
        }
    }

    window.addEventListener("scroll", handleScroll);


    // filters

    $('.filters-modal-header__clear').on('click', function () {
        $('.wpfClearButton').trigger('click');
    });

    // SWIPER

    const swiper = new Swiper('.banner-block-images', {
        direction: 'horizontal',
        loop: true,
        infinite: true,
        spaceBetween: 0,
        speed: 600,
        autoplay: {
            delay: 8000,
        }
    });

    const swiperCollections = new Swiper('.new-collect-slider', {
        direction: 'horizontal',
        loop: true,
        slidesPerView: 3.2,
        spaceBetween: 20,
        infinite: true,
        speed: 600,
        navigation: {
            nextEl: '.slider__nextCol',
            prevEl: '.slider__prevCol'
        },
        freeMode: true,
        breakpoints: {
            0: {
                slidesPerView: 1.2,
            },
            768: { // при 768px и выше
                slidesPerView: 2.8,
            },
            1200: { // при 1200px и выше
                slidesPerView: 3.2,
            }
        }
    });

    let galleryThumbs = new Swiper(".gallery-thumbs", {
        slidesPerView: 'auto',
        direction: 'vertical',
        spaceBetween: 20,
        loop: false,
        slideToClickedSlide: true,
    });

    let galleryMain = new Swiper(".gallery-main", {
        slidesPerView: 'auto',
        direction: 'vertical',
        spaceBetween: 20,
        freeMode: true,
        touchRatio: 0.2,
        loop: false,
        thumbs: {
            swiper: galleryThumbs
        },
        breakpoints: {
            0: {
                spaceBetween: 8,
            },
            768: {
                spaceBetween: 20,
            }
        }
    });

    galleryThumbs.on('transitionStart', function () {
        galleryMain.slideTo(galleryThumbs.activeIndex);
    });

    // СКРОЛ и КЛИК фоток в карточке товара

    if ($('.product-card-sliders').length > 0) {
        let isScrolling = false;

        function updateThumbsClasses(index) {
            galleryThumbs.el.querySelectorAll('.swiper-slide').forEach(function (slide) {
                slide.classList.remove('swiper-slide-active');
            });

            galleryThumbs.el.querySelectorAll('.swiper-slide')[index].classList.add('swiper-slide-active');
        }

        window.addEventListener('scroll', function () {
            if (!isScrolling) {
                let scrollY = window.scrollY;
                let pixelsToSwitch = 700;
                let indexToSwitch = Math.floor(scrollY / pixelsToSwitch);
                updateThumbsClasses(indexToSwitch);
            }
        });

        galleryThumbs.on('click', function () {
            isScrolling = true;
            let clickedIndex = galleryThumbs.clickedIndex;
            galleryMain.slideTo(clickedIndex);
            galleryMain.slides[clickedIndex].scrollIntoView({behavior: 'smooth'});
            updateThumbsClasses(clickedIndex);
            setTimeout(function () {
                isScrolling = false;
            }, 1000);
        });
    } else {

    }

    //    slider lookbook

    if ($('.inner-block_tabs').length) {
        let bigSwipers = [];
        let smallSwipers = [];

// Добавляем класс "active" первому элементу при загрузке страницы
        document.querySelector('.tab-content:first-child').classList.add('active');

// Функция для инициализации слайдеров
        function initSliders() {
            let activeTabContent = document.querySelector('.tab-content.active');
            if (activeTabContent) {
                let bigSlider = activeTabContent.querySelector('.tab-big-slider');
                let smallSlider = activeTabContent.querySelector('.tab-small-slider');

                if (bigSlider && smallSlider) {
                    let bigSwiper = new Swiper(bigSlider, {
                        thumbs: {
                            swiper: smallSlider,
                        },
                        autoHeight: true,

                        speed: 600,
                    });
                    bigSwipers.push(bigSwiper);

                    let smallSwiper = new Swiper(smallSlider, {
                        slidesPerView: 4
                    });
                    smallSwipers.push(smallSwiper);
                }
            }
        }

        window.addEventListener('load', function () {
            initSliders();
        });

        let tabsLinks = document.querySelectorAll('.tabs-link-element');

        tabsLinks.forEach(function (tabsLink, index) {
            tabsLink.addEventListener('click', function () {
                // Убираем класс "active" у всех элементов
                document.querySelectorAll('.tab-content').forEach(function (tabContent) {
                    tabContent.classList.remove('active');
                });

                // Добавляем класс "active" только к текущему элементу
                let tabContent = document.querySelector('.tab-content:nth-child(' + (index + 1) + ')');
                if (tabContent) {
                    tabContent.classList.add('active');
                }
                initSliders();
            });
        });
    }

//    slider lookbook END

// Лук бук мобилка - модалка

    $('body').on('click', '.tab-btn-mobile', function (e) {
        e.stopPropagation();
        let tabsList = $('.max-content.tabs-list');
        tabsList.toggleClass('active');
    });



// SWIPER END


// Search
    $('body').on('click', '.header-buttons__open-search', function () {
        $('.header__search').slideDown(300);
    });

    $('body').on('click', '.search-close', function () {
        $('.header__search').slideUp(300);
    });
// Search END

// Options product
    $(".cp__color").on('click', function () {
        $(".cp__color").each(function () {
            $(this).removeClass('active');
        });
        $(this).addClass('active');
    });

    $(".product-card-size span").on('click', function () {
        $(".product-card-size span").each(function () {
            $(this).removeClass('active');
        });
        $(this).addClass('active');
    });
// Options product END

// Fixed header
    $(window).scroll(function () {
        if ($(this).scrollTop() > 50) {
            $('.header').addClass('fixed');
        } else {
            $('.header').removeClass('fixed');
        }
    });
// Fixed header END

//    spoiler
    $('.spoiler-head').on('click', function (e) {
        e.preventDefault();
        $(this).toggleClass("rtoate180-arrow");
    });

    $("body").on('click', '.spoiler-head', function (e) {
        e.preventDefault();
        $(this).parents('.spoiler-head').toggleClass('drop-arrow-toggle').find('.rop-arrow').toggleClass('drop-arrow-toggle');
    });

    $('.spoiler-body').hide(300);
    $(document).on('click', '.spoiler-head', function (e) {
        e.preventDefault();
        $(this).parents('.spoiler-wrap').toggleClass("active").find('.spoiler-body').slideToggle();
    });
//    spoiler END

//    mask
    $('input[name="sol-tel"], input[name="phone"], input[name="billing_phone"]').each(function () {
        $(this).inputmask({"mask": "+7 (999) 999-99-99"});
    });
//    mask END

});


$(document).ready(function () {
    // sold out
    $('.product-item').each(function () {
        if ($(this).find('.sold-out').length > 0) {
            $(this).addClass('product-sold-out');
        }
    });
    // sold out END
});

// ajax добавление товара

$(document).ready(function ($) {
    const $body = $('body');
    const $customMessage = $('.custom-message');

    $body.on('click', '.add-to-cart', function (e) {
        e.preventDefault();
        const $button = $(this);
        const productId = $button.data('product_id');
        const variation = {};

        $('select').each(function () {
            const attribute_name = $(this).attr('name');
            const attribute_value = $(this).val();
            variation[attribute_name] = attribute_value;
        });

        $.ajax({
            type: 'POST',
            url: wc_add_to_cart_params.ajax_url,
            data: {
                action: 'woocommerce_ajax_add_to_cart',
                product_id: productId,
                variation: variation,
            },
            success: function (response) {
                $button.addClass('added');

                if ($customMessage.length > 0) {
                    $customMessage.show();
                } else {
                    const customMessage = '<div class="container container-close-message"><div class="custom-message"><p>товар добавлен в корзину</p></div></div>';
                    $body.append(customMessage);
                    setTimeout(function () {
                        $('.container-close-message').fadeOut('slow', function () {
                            $(this).remove();
                        });
                    }, 1000);
                }
            }
        });
    });
});



// обновление цены вариаций

$(document).ready(function ($) {
    let product = $('form.variations_form');
    let priceContainer = $('.price-container');

    product.on('show_variation', function (event, variation) {
        if (variation.price_html) {
            priceContainer.html(variation.price_html);
        }
    });
});

// Перестановка на странице checkout
$(document).ready(function($) {

    $('body').contents().filter(function() {
        return this.nodeType === 3 && /Купон:/.test(this.nodeValue);
    }).remove();
});

$(document).ready(function () {
    let checkInnerLeftContent = $('.cart-main').html();
    $('.ordering-checkout-left').prepend(checkInnerLeftContent);

    let payInnerRightContent = $('.wc_payment_methods').html();
    $('.col-03').prepend(payInnerRightContent);


    $('.showcoupon-trigger').on('click', function () {
        let reviewPayPromoShow = $(this).siblings('.review-pay-promo-show');
        $('.review-pay-promo-show').not(reviewPayPromoShow).slideUp(10).removeClass('active');
        if (reviewPayPromoShow.length) {
            reviewPayPromoShow.slideToggle(300).toggleClass('active');
        }
    });

    $(".input-coupon").on("input", function() {
        $("input#coupon_code").val($(this).val());
    });

    $('.coupon-trigger-btn').on('click', function () {
        $('.checkout_coupon button').trigger('click');
    });


    // Изменение кол-ва товаров ajax

    $('body').on('click', '.button.wp-element-button', function () {
        setTimeout(function () {
            $('[name="update_cart"]').trigger('click');
        }, 100);
    });

    $('body').on('click', '.ordering-checkout-left .minus', function () {
        $('.cart-main .minus').trigger('click');
    });
    $('body').on('click', '.ordering-checkout-left .plus', function () {
        $('.cart-main .plus').trigger('click');
    });

    $('body').on('click', '.btn-mobile-product', function () {
        $('.add-to-cart-single').trigger('click');
    });
    $('body').on('click', '.btn-mobile-product-out', function () {
        $('.cwgstock_button').trigger('click');
    });

});

// toggle menu

$(document).ready(function ($) {

    $('.sub-menu').hide();

    $('body').on('click', '.menu-item-has-children > a', function (e) {
        e.preventDefault();
        var subMenu = $(this).siblings('.sub-menu');
        subMenu.slideToggle();

        $(this).parent().toggleClass('active');
    });

    $(document).click(function (event) {
        if (!$(event.target).closest('.menu-item-has-children').length) {
            $('.sub-menu').slideUp();
            $('.menu-item-has-children').removeClass('active');
        }
    });

});

// menu modal close - click to search

$('.header-buttons__open-search').on('click', function(e) {
    e.preventDefault();

    $.fancybox.close();
});


// tabs

$('.tabs .tabs-link-element').click(function (e) {
    e.preventDefault();
    let tabsList = $('.max-content.tabs-list');
    let tab_id = $(this).attr('href');
    $('.tabs a').removeClass('active');
    $('.tab-content').removeClass('active');
    $(this).addClass('active');
    $(tab_id).addClass('active');
    tabsList.removeClass('active');
});

$('body').on( 'click', '.tabs-list.active', function (e) {
    e.preventDefault();
    $('.max-content.tabs-list').removeClass('active');
});

// оповещение добавления в корзину

$(document).ready(function ($) {
    $(function($){
        $('button.single_add_to_cart_button').click(function(){

            let customMessage = '<div class="container container-close-message"><div class="custom-message"><p>товар добавлен в корзину</p></div></div>';
            $('body').append(customMessage);
            setTimeout(function () {
                $('.container-close-message').fadeOut('slow', function () {
                    $(this).remove();
                });
            }, 1000);
        });
    });
});

// отложеная загрузка страниц

document.addEventListener("DOMContentLoaded", function () {

    const siteMain = document.querySelector(".site-main");

    if (siteMain) {
        siteMain.style.opacity = 1;

        setTimeout(function () {
            siteMain.classList.add("fade");
        }, 100);
    }

});

// скрывать сообщение по клику
$('body').on('click', '.custom-message img', function () {
    $('.custom-message').fadeOut(300);
});

$('.plus.button.wp-element-button').val('');
$('.minus.button.wp-element-button').val('');

// добавление ajax

$('form.variations_form').on('submit', function (e) {
    e.preventDefault();
    let $form = $(this);
    let product_id = $form.data('product_id');
    let variation_id = $form.find('.variation_id').val();
    let quantity = $form.find('input[name="quantity"]').val();
    let variation = {};

    $form.find('select').each(function () {
        let attribute_name = $(this).data('attribute_name');
        let attribute_value = $(this).val();
        variation[attribute_name] = attribute_value;
    });

    $.ajax({
        type: 'POST',
        url: wc_add_to_cart_params.ajax_url,
        data: {
            action: 'woocommerce_ajax_add_to_cart',
            product_id: product_id,
            variation_id: variation_id,
            quantity: quantity,
            variation: variation,
        },
        success: function (response) {
            $form.find('.single_variation_wrap').html(response);
            $form.find('.add-to-cart-single').addClass('added');

            let customMessage = '<div class="container container-close-message"><div class="custom-message"><p>товар добавлен в корзину</p></div></div>';
            $('body').append(customMessage);

            setTimeout(function () {
                $('.container-close-message').fadeOut('slow', function () {
                    $(this).remove();
                });
            }, 1000);

        },
        error: function (xhr, status, error) {
            console.error(error);
        }
    });
});

// модалка добавление в корзину - кастом для мобилки
$(document).ready(function() {
    $(window).on('scroll', function() {
        if ($(window).scrollTop() >= 100) {
            $('.custom-message').addClass('mobile-active');
        } else {
            $('.custom-message').removeClass('mobile-active');
        }
    });
});

document.addEventListener('DOMContentLoaded', function () {
    let inputSave = document.getElementsByClassName('form-control');

    function saveInputValues() {
        for (let i = 0; i < inputSave.length; i++) {
            let inputSaved = inputSave[i];
            localStorage.setItem(inputSaved.id, inputSaved.value);
        }
    }

    function restoreInputValues() {
        for (let i = 0; i < inputSave.length; i++) {
            let inputSaved = inputSave[i];
            let savedValue = localStorage.getItem(inputSaved.id);

            if (savedValue) {
                inputSaved.value = savedValue;
            }
        }
    }

    restoreInputValues();

    for (let i = 0; i < inputSave.length; i++) {
        let inputSaved = inputSave[i];

        inputSaved.addEventListener('input', function () {
            saveInputValues();
        });
    }

    window.addEventListener('beforeunload', function () {
        saveInputValues();
    });
});



