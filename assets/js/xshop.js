// assets/js/xshop.js
console.log('Testing xshop.js .....');

jQuery(function ($) {
    console.log('Testing xshop.js file jQuery .....');

    class XshopTopup {
        constructor() {
            this.modalSelector = '#xshopValidateModal';
            this.bindEvents();
        }

        /**
         * Bind all DOM events: intercept Woo button, modal close, confirm.
         */
        bindEvents() {
            // Intercept WooCommerce's "Pay Now" / Add to Cart button on single product page
            $(document).on('click', '.single_add_to_cart_button', (e) => {
                const $btn = $(e.currentTarget);

                // Only intercept if marked as xshop topup
                if ($btn.data('xshop-type') === 'topup') {
                    e.preventDefault();
                    this.handleTopupClick($btn);
                }
            });

            // Modal close (x icon or cancel button)
            $(document).on('click', `${this.modalSelector} .xshop-close, ${this.modalSelector} .xshop-cancel`, () => {
                $(this.modalSelector).hide();
            });

            // Modal confirm button → continue checkout
            $(document).on('click', `${this.modalSelector} .xshop-confirm`, () => {
                this.addToCart();
            });
        }

        /**
         * Handle Topup Pay Now click → trigger validate API via AJAX.
         */
        handleTopupClick($btn) {
            const $form = $btn.closest('form.cart');

            // Core product identifiers from Woo hidden inputs
            const productId   = $form.find('input[name="product_id"]').val();
            const variationId = $form.find('input[name="variation_id"]').val();
            const quantity    = $form.find('input.qty').val() || 1;

            // Use variation ID as SKU if present, else fallback to productId
            const sku = variationId && variationId !== "0" ? variationId : productId;

            // TODO: Proper price + currency should come from localized PHP or hidden inputs
            // For now: fallbacks until we wire product pricing properly
            const price    = $form.find('input[name="xshop_price"]').val() || 0;
            const currency = $form.find('input[name="xshop_currency"]').val() || 'USD';

            // Disable button to prevent double clicks
            $btn.prop('disabled', true).text('Validating...');

            // AJAX call to our validate endpoint
            $.post(xshopValidateConfig.ajax_url, {
                action: 'xshop_validate',
                nonce: xshopValidateConfig.nonce,
                product_id: productId,
                variation_id: variationId,
                sku,
                price,
                currency,
                quantity,
                userAccount: $('#user_account').val() || ''
            })
                .done((res) => {
                    if (res.success) {
                        this.showModal(res.data.result, productId, sku);
                    } else {
                        alert('Validation failed: ' + (res.data?.message || 'Unknown error'));
                    }
                })
                .fail((xhr) => {
                    console.error('AJAX error', xhr);
                    alert('Validation request failed.');
                })
                .always(() => {
                    $btn.prop('disabled', false).text('Pay Now');
                });
        }

        /**
         * Show validation modal with results (user info, role select).
         */
        showModal(result, productId, sku) {
            const $modal = $(this.modalSelector);
            const $body = $modal.find('#xshop-validate-body');

            // Build modal HTML
            let html = `
                <h3>Validation Successful</h3>
                <p>User: ${result.message?.username || 'N/A'}</p>
                ${result.message?.roles ? this.renderRoles(result.message.roles) : ''}
            `;

            $body.html(html);

            // Attach required data for checkout continuation
            $modal.find('.xshop-confirm')
                .data('product-id', productId)
                .data('sku', sku)
                .data('validate', result);

            $modal.show();
        }

        /**
         * Render <select> dropdown with role options (if any).
         */
        renderRoles(roles) {
            let options = roles.map(r => `<option value="${r.id}">${r.name}</option>`).join('');
            return `<label>Select Role:</label><select id="xshopRole">${options}</select>`;
        }

        /**
         * Add item to Woo cart with validated data → redirect to check out.
         */
        addToCart() {
            const $modal = $(this.modalSelector);
            const btn = $modal.find('.xshop-confirm');

            const productId      = btn.data('product-id');
            const sku            = btn.data('sku');
            const validateResult = btn.data('validate');
            const roleId         = $('#xshopRole').val() || null;

            // AJAX call to add product to cart with validation data
            $.post(xshopValidateConfig.ajax_url, {
                action: 'xshop_add_to_cart',
                nonce: xshopValidateConfig.nonce,
                product_id: productId,
                sku,
                role_id: roleId,
                validate_result: validateResult,
            })
                .done((res) => {
                    if (res.success && res.data.redirect) {
                        window.location.href = res.data.redirect;
                    } else {
                        alert('Add to cart failed.');
                    }
                })
                .fail(() => alert('Add to cart request failed.'));
        }
    }

    // Initialize on DOM ready
    new XshopTopup();
});
