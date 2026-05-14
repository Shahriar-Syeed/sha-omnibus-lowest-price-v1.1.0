(function ($) {
    'use strict';

    if (typeof BSSOmnibusLowestPrice === 'undefined') {
        return;
    }

    var pendingTimer = null;
    var activeRequest = false;

    function getProductId($card) {
        var classes = $card.attr('class') || '';
        var match = classes.match(/(?:^|\s)post-(\d+)(?:\s|$)/);

        if (match && match[1]) {
            return parseInt(match[1], 10);
        }

        var dataId = $card.data('product_id') || $card.data('product-id') || $card.find('[data-product_id]').first().data('product_id');
        dataId = parseInt(dataId, 10);

        return isNaN(dataId) ? 0 : dataId;
    }

    function cardLooksOnSale($card) {
        return $card.find('.price del, del .woocommerce-Price-amount, .onsale, .badge.sale, .badge-container .sale').length > 0;
    }

    function findPriceTarget($card) {
        var $price = $card.find('.price').first();

        if (!$price.length) {
            $price = $card.find('.price-wrapper').first();
        }

        return $price;
    }

    function removeDuplicateLabels(context) {
        $('.price, .price-wrapper', context).each(function () {
            var seen = {};
            $(this).find('.bss-omnibus-lowest-price').each(function () {
                var productId = $(this).data('product-id') || '';
                var text = $.trim($(this).text());
                var key = productId + '|' + text;

                if (seen[key]) {
                    $(this).remove();
                } else {
                    seen[key] = true;
                }
            });
        });
    }

    function collectMissingCards(context) {
        var cardsById = {};
        var ids = [];

        $('.product, li.product, .product-small, .box-product', context).each(function () {
            var $card = $(this);
            var id = getProductId($card);

            if (!id || $card.find('.bss-omnibus-lowest-price').length || !cardLooksOnSale($card)) {
                return;
            }

            var $target = findPriceTarget($card);
            if (!$target.length) {
                return;
            }

            if (!cardsById[id]) {
                cardsById[id] = [];
                ids.push(id);
            }

            cardsById[id].push($card);
        });

        return {
            ids: ids,
            cardsById: cardsById
        };
    }

    function requestMissingLabels(context) {
        removeDuplicateLabels(context || document);

        if (activeRequest) {
            return;
        }

        var collected = collectMissingCards(context || document);

        if (!collected.ids.length) {
            return;
        }

        activeRequest = true;

        $.ajax({
            url: BSSOmnibusLowestPrice.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'bss_omnibus_get_labels',
                nonce: BSSOmnibusLowestPrice.nonce,
                product_ids: collected.ids
            }
        }).done(function (response) {
            if (!response || !response.success || !response.data) {
                return;
            }

            $.each(response.data, function (productId, html) {
                var cards = collected.cardsById[productId] || [];

                $.each(cards, function (index, card) {
                    var $card = $(card);
                    var $target = findPriceTarget($card);

                    if ($target.length && !$card.find('.bss-omnibus-lowest-price').length) {
                        $target.append(html);
                    }
                });
            });
        }).always(function () {
            activeRequest = false;
            removeDuplicateLabels(context || document);
        });
    }

    function scheduleRefresh(context, delay) {
        window.clearTimeout(pendingTimer);
        pendingTimer = window.setTimeout(function () {
            requestMissingLabels(context || document);
        }, typeof delay === 'number' ? delay : 120);
    }

    $(function () {
        scheduleRefresh(document, 100);
    });

    $(document).ajaxComplete(function () {
        scheduleRefresh(document, 180);
    });

    $(document).on('click', '.ux-relay button, .ux-relay a, .ux-relay__button, .load-more, .load-more-button, .woocommerce-pagination a', function () {
        scheduleRefresh(document, 500);
    });

    if ('MutationObserver' in window) {
        var observer = new MutationObserver(function (mutations) {
            var shouldRefresh = false;

            mutations.forEach(function (mutation) {
                if (mutation.addedNodes && mutation.addedNodes.length) {
                    shouldRefresh = true;
                }
            });

            if (shouldRefresh) {
                scheduleRefresh(document, 250);
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
})(jQuery);
