(function ($) {
    const state = {
        points: [],
    };

    function selectedShippingMethod() {
        return $('input[name^="shipping_method"]:checked').val() || '';
    }

    function isPickupMode() {
        return selectedShippingMethod().indexOf(colPickupPoint.shippingMethodId) === 0;
    }

    function getDestination() {
        const city = $('#shipping_city').val() || $('#billing_city').val() || '';
        const postcode = $('#shipping_postcode').val() || $('#billing_postcode').val() || '';
        return { city, postcode };
    }

    function renderSelect(points) {
        const $select = $('#col_pickup_point_select');
        $select.empty();
        $select.append($('<option>', { value: '', text: colPickupPoint.labels.placeholder }));

        points.forEach((point) => {
            $select.append(
                $('<option>', {
                    value: point.id,
                    text: `${point.name} - ${point.address}`,
                    'data-point': JSON.stringify(point),
                })
            );
        });
    }

    function toggleWrapper() {
        if (isPickupMode()) {
            $('#col-pickup-point-wrapper').show();
            loadPoints();
            return;
        }

        $('#col-pickup-point-wrapper').hide();
    }

    function loadPoints() {
        const destination = getDestination();
        $('#col-pickup-point-meta').text(colPickupPoint.labels.loading);

        $.post(colPickupPoint.ajaxUrl, {
            action: 'col_get_pickup_points',
            nonce: colPickupPoint.nonce,
            city: destination.city,
            postcode: destination.postcode,
        }).done((response) => {
            const points = (response && response.success && response.data && response.data.points) ? response.data.points : [];
            state.points = points;
            renderSelect(points);

            if (!points.length) {
                $('#col-pickup-point-meta').text(colPickupPoint.labels.empty);
            } else {
                $('#col-pickup-point-meta').text('');
            }
        }).fail(() => {
            $('#col-pickup-point-meta').text(colPickupPoint.labels.error);
        });
    }

    function saveSelection(point) {
        $.post(colPickupPoint.ajaxUrl, {
            action: 'col_set_pickup_point',
            nonce: colPickupPoint.nonce,
            point: JSON.stringify(point || {}),
        });
    }

    $(document).on('change', '#col_pickup_point_select', function () {
        const selectedId = $(this).val();
        const selected = state.points.find((point) => point.id === selectedId);
        saveSelection(selected);

        if (selected) {
            $('#col-pickup-point-meta').html(`<strong>${selected.name}</strong><br/>${selected.address}<br/>${selected.operating_hours}`);
        }

        $(document.body).trigger('update_checkout');
    });

    $(document).on('change', 'input[name^="shipping_method"], #shipping_city, #shipping_postcode, #billing_city, #billing_postcode', toggleWrapper);

    $(document.body).on('updated_checkout', toggleWrapper);

    $(function () {
        if (!$('.woocommerce-checkout, .wc-block-checkout').length) {
            return;
        }

        toggleWrapper();
    });
})(jQuery);
