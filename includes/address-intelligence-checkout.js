(function ($) {
    const state = {
        suggestions: [],
        pendingRequest: null,
    };

    function getAddressInput() {
        const line1 = $('#shipping_address_1').val() || $('#billing_address_1').val() || '';
        const line2 = $('#shipping_address_2').val() || $('#billing_address_2').val() || '';
        const city = $('#shipping_city').val() || $('#billing_city').val() || '';
        const postcode = $('#shipping_postcode').val() || $('#billing_postcode').val() || '';
        const stateField = $('#shipping_state').val() || $('#billing_state').val() || '';
        return [line1, line2, city, stateField, postcode].join(' ').trim();
    }

    function renderSuggestions(suggestions) {
        const $select = $('#col_ai_suggestion_select');
        $select.empty();
        $select.append($('<option>', { value: '', text: colAddressIntelligence.labels.selectPlaceholder }));

        suggestions.forEach((item, index) => {
            $select.append($('<option>', {
                value: String(index),
                text: `${item.district}, ${item.city} ${item.postal_code} (confidence ${item.confidence}%)`,
                'data-suggestion': JSON.stringify(item),
            }));
        });
    }

    function saveConfirmation(suggestion) {
        return $.post(colAddressIntelligence.ajaxUrl, {
            action: 'col_address_intelligence_confirm',
            nonce: colAddressIntelligence.nonce,
            suggestion: JSON.stringify(suggestion || {}),
        });
    }

    function analyzeAddress() {
        const address = getAddressInput();
        if (address.length < 6) {
            return;
        }

        $('#col-ai-status').text(colAddressIntelligence.labels.checking);

        if (state.pendingRequest && state.pendingRequest.readyState !== 4) {
            state.pendingRequest.abort();
        }

        state.pendingRequest = $.post(colAddressIntelligence.ajaxUrl, {
            action: 'col_address_intelligence_suggest',
            nonce: colAddressIntelligence.nonce,
            address,
        }).done((response) => {
            const data = (response && response.success && response.data) ? response.data : null;
            if (!data) {
                return;
            }

            state.suggestions = data.suggestions || [];
            renderSuggestions(state.suggestions);
            $('#col-ai-status').text(`Confidence: ${data.confidence}%`);

            if (data.ambiguous || data.confidence < colAddressIntelligence.minConfidence) {
                $('#col-ai-confirmation').show();
                $('#col-ai-warning').text(data.warning || 'Alamat ambigu, mohon konfirmasi area.');
                return;
            }

            $('#col-ai-confirmation').hide();
            $('#col-ai-warning').text('');
            $('#col-ai-status').text(colAddressIntelligence.labels.highConfidence);
        });
    }

    let timer = null;
    function debounceAnalyze() {
        if (timer) {
            clearTimeout(timer);
        }

        timer = setTimeout(analyzeAddress, 350);
    }

    $(document).on('change', '#col_ai_suggestion_select', function () {
        const index = Number($(this).val());
        const selected = Number.isNaN(index) ? null : state.suggestions[index];
        saveConfirmation(selected).always(() => {
            $(document.body).trigger('update_checkout');
        });
    });

    $(document).on('input change', '#shipping_address_1, #shipping_address_2, #shipping_city, #shipping_state, #shipping_postcode, #billing_address_1, #billing_address_2, #billing_city, #billing_state, #billing_postcode', debounceAnalyze);

    $(function () {
        if (!$('.woocommerce-checkout, .wc-block-checkout').length) {
            return;
        }

        debounceAnalyze();
    });
})(jQuery);
