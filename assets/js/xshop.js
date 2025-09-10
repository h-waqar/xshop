// assets/js/xshop.js
// Intercept WooCommerce single-product "Pay Now" button for xshop topup products.
// Sends serialized form (so WCPA fields like user account are included) and shows a validation modal.
// Expects `xshopValidateConfig` localized object from PHP with keys:
//   - ajax_url (admin-ajax.php), nonce, checkout_url

jQuery(function ($) {
    'use strict';
    console.log('xshop.js initialised');

    class XshopTopup {
        constructor() {
            // We accept several possible modal IDs/classes to be resilient against markup changes
            this.modalSelectors = ['#xshopValidateModal',       // camelCase id (used in earlier examples)
                '#xshop-validate-modal',     // hyphen-case id (older TopupUI)
                '.xshop-validate-modal',     // class fallback
                '.xshop-modal'               // generic fallback
            ].join(',');

            // ensure modal exists (TopupUI should print it; if not, we'll create a minimal one)
            this.ensureModalExists();

            // cache jQuery modal ref (first found)
            this.$modal = $(this.modalSelectors).first();

            this.bindEvents();
        }

        /**
         * Ensure a modal element exists in the DOM. If your PHP prints the modal (TopupUI),
         * nothing changes. If not, we append a minimal accessible modal markup.
         */
        ensureModalExists() {
            if ($(this.modalSelectors).length) {
                return;
            }

            // Minimal modal markup — safe fallback so JS doesn't crash.
            const fallback = `
            <div id="xshopValidateModal" class="xshop-modal" style="display:none;">
                <div class="xshop-modal-content" role="dialog" aria-modal="true" aria-labelledby="xshop-validate-title">
                    <button type="button" class="xshop-close" aria-label="Close">&times;</button>
                    <h2 id="xshop-validate-title">Confirm account & role</h2>
                    <div id="xshop-validate-body"><p>Validating... please wait</p></div>
                    <div class="xshop-actions" style="margin-top:12px;text-align:right;">
                        <button type="button" class="button button-secondary xshop-cancel">Cancel</button>
                        <button type="button" class="button button-primary xshop-confirm">Continue</button>
                    </div>
                </div>
            </div>
            `;
            $('body').append(fallback);
        }

        bindEvents() {
            // Intercept single product add-to-cart / Pay Now button
            $(document).on('click', '.single_add_to_cart_button', (e) => {
                const $btn = $(e.currentTarget);

                // Check data attribute in both forms (attr or jQuery data)
                const isTopup = ($btn.attr('data-xshop-type') === 'topup') || ($btn.data('xshopType') === 'topup');

                if (isTopup) {
                    // block default submit and do our flow
                    e.preventDefault();
                    e.stopImmediatePropagation && e.stopImmediatePropagation();
                    console.log('xshop: intercepting topup Pay Now button');
                    this.handleTopupClick($btn);
                }
            });

            // modal close (x or cancel)
            $(document).on('click', `${this.modalSelectors} .xshop-close, ${this.modalSelectors} .xshop-cancel`, () => {
                $(this.modalSelectors).hide();
            });

            // modal confirm button
            // $(document).on('click', `${this.modalSelectors} .xshop-confirm`, () => {
            //     this.addToCart();
            // });

            // optional: close modal on overlay click (if markup uses full-screen overlay)
            $(document).on('click', `${this.modalSelectors}`, (ev) => {
                if (ev.target === ev.currentTarget) {
                    $(this.modalSelectors).hide();
                }
            });
        }

        /**
         * Called when user clicks Pay Now on a topup product.
         * We serialize the whole form and send to PHP so WCPA fields (user id) are available server-side.
         */
        handleTopupClick($btn) {
            const $form = $btn.closest('form.cart');

            if ($form.length === 0) {
                console.warn('xshop: form.cart not found, aborting topup flow');
                return;
            }

            // product / variation / quantity — read from the hidden inputs Woo generates
            const productId = $form.find('input[name="product_id"]').val() || $form.find('input[name="add-to-cart"]').val() || '';
            const variationId = $form.find('input[name="variation_id"]').val() || 0;
            // quantity input may be named "quantity" or have custom class - use both fallbacks
            const quantity = $form.find('input[name="quantity"]').val() || $form.find('input.qty, input.qty-input, input.qty-input, .qty-input').val() || 1;

            // Serialize entire form (WCPA fields, hidden inputs etc.)
            const formData = $form.serialize();

            console.log('Form Data: ', formData);

            // UI: disable button while validating and preserve original text
            const originalText = $btn.text();
            $btn.prop('disabled', true).text('Validating...');

            // POST to validate endpoint (send serialized form under form_data)
            // in handleTopupClick -> $.post success callback
            $.post(xshopValidateConfig.ajax_url, {
                action: 'xshop_validate',
                nonce: xshopValidateConfig.nonce,
                product_id: productId,
                variation_id: variationId,
                quantity: quantity,
                form_data: formData
            }, (res) => {
                if (res && res.success) {
                    this.showModal('success', res.data.result, {
                        product_id: productId, variation_id: variationId, form_data: formData
                    });
                } else {
                    const msg = res?.data?.message || 'Validation failed';
                    this.showModal('error', {message: msg, raw: res}, {
                        product_id: productId, variation_id: variationId, form_data: formData
                    });
                }
            }, 'json')
                .fail((xhr) => {
                    this.showModal('error', {message: 'Server/network error', raw: xhr.responseText}, {
                        product_id: productId, variation_id: variationId, form_data: formData
                    });
                })
                .always(() => {
                    $btn.prop('disabled', false).text(originalText);
                });

        }

        /**
         * Render and show validation modal.
         * `result` is the object returned by PHP (decoded XShop result)
         * `meta` is an object with product_id, variation_id, form_data (we attach to confirm button)
         */
        /**
         * @param {string} mode "success" or "error"
         * @param {object} result Response payload
         * @param {object} meta Extra info
         */
        showModal(mode, result, meta) {
            const $modal = $(this.modalSelectors).first();
            const $body = $modal.find('#xshop-validate-body');

            let html = '';

            if (mode === 'success') {
                html += `<p><strong>User:</strong> ${this.escapeHtml(result.message?.username || 'N/A')}</p>`;

                if (result.message?.roles?.length) {
                    html += '<p><label for="xshopRole">Select Role:</label><br>';
                    html += '<select id="xshopRole">';
                    result.message.roles.forEach((r) => {
                        html += `<option value="${this.escapeHtml(String(r.id))}">${this.escapeHtml(r.name)}</option>`;
                    });
                    html += '</select></p>';
                }

            } else if (mode === 'error') {
                html += `<p class="error"><strong>Error:</strong> ${this.escapeHtml(result.message || 'Unknown error')}</p>`;
            }

            // Always include debug <details>
            html += `<details><summary>Debug info</summary><pre style="white-space:pre-wrap;">${this.escapeHtml(JSON.stringify({
                result,
                meta
            }, null, 2))}</pre></details>`;

            $body.html(html);

            // toggle buttons depending on mode
            const $confirm = $modal.find('.xshop-confirm');
            if (mode === 'success') {
                $confirm.show()
                    .data('product-id', meta.product_id || '')
                    .data('variation-id', meta.variation_id || 0)
                    .data('form-data', meta.form_data || '')
                    .data('validate', result || {});
            } else {
                $confirm.hide(); // nothing to confirm on error
            }

            $modal.show();
        }


        /**
         * Add to cart using the validated payload (calls backend xshop_add_to_cart).
         * Sends:
         *   - product_id
         *   - variation_id
         *   - role_id (if selected)
         *   - validate_result (JSON-string)
         *   - form_data (optional -- included for debugging / completeness)
         */
        addToCart() {
            const $modal = $(this.modalSelectors).first();
            const $confirm = $modal.find('.xshop-confirm');

            const productId = $confirm.data('product-id');
            const variationId = $confirm.data('variation-id') || 0;
            const formData = $confirm.data('form-data') || '';
            const validateResult = $confirm.data('validate') || {};
            const roleId = $('select#xshopRole').length ? $('select#xshopRole').val() : null;

            // derive sku if present in validate result (message.sku might exist)
            let sku = '';
            if (validateResult && validateResult.message && validateResult.message.sku) {
                // safe attempt to get sku string (structure may vary)
                sku = validateResult.message.sku.sku || validateResult.message.sku.code || '';
            }

            // POST to add-to-cart handler — stringify validate_result to preserve structure
            $.post(xshopValidateConfig.ajax_url, {
                action: 'xshop_add_to_cart',
                nonce: xshopValidateConfig.nonce,
                product_id: productId,
                variation_id: variationId,
                sku: sku,
                role_id: roleId,
                validate_result: JSON.stringify(validateResult),
                form_data: formData
            }, (res) => {
                if (res && res.success) {
                    // redirect to check out if backend returned redirect
                    const redirect = (res.data && res.data.redirect) ? res.data.redirect : xshopValidateConfig.checkout_url;
                    window.location.href = redirect;
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : 'Add to cart failed';
                    // alert(msg);
                    this.showModal('error', { message: msg, raw: res }, { product_id: productId, variation_id: variationId, form_data: formData });
                    console.warn('xshop add_to_cart response', res);
                }
            }, 'json')
                .fail((xhr) => {
                    console.error('xshop add_to_cart AJAX failure', xhr);
                    // alert('Add to cart request failed (network/server).');
                    this.showModal('error', { message: 'Add to cart request failed (network/server)', raw: xhr.responseText }, { product_id: productId, variation_id: variationId, form_data: formData });
                });
        }

        // small helper: escape HTML to avoid injection in modal insertions
        escapeHtml(str) {
            if (!str && str !== 0) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    }

    // instantiate
    new XshopTopup();
});
