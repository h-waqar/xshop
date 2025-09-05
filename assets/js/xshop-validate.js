// assets/js/xshop-validate.js
jQuery(function($){
    // Helper to open modal and populate content
    function openModal(html) {
        $('#xshop-validate-body').html(html);
        $('#xshop-validate-modal').show();
    }
    function closeModal() {
        $('#xshop-validate-modal').hide();
    }

    // simple: intercept form.cart submissions for products that have xshop inputs
    $(document).on('submit', 'form.cart', function(e){
        var $form = $(this);

        // detect an xshop product by presence of input[name="xshop_user_account"] OR select[name="attribute_pa_xshop"]
        var userAccountField = $form.find('input[name="xshop_user_account"]');
        var skuField = $form.find('select[name="attribute_pa_xshop"], input[name="attribute_pa_xshop"]');

        if (userAccountField.length === 0 || skuField.length === 0) {
            // not an xshop product — allow normal submit
            return true;
        }

        e.preventDefault();

        var productId = $form.find('input[name="add-to-cart"]').val();
        var skuVal = skuField.val();
        var userAccount = userAccountField.val();

        // optional price/currency — plugin may render it or you can compute server-side
        var price = $form.find('input[name="xshop_price"]').val() || $form.find('input[name="price"]').val() || '';
        var currency = $form.find('input[name="xshop_currency"]').val() || '';

        openModal('<p>Validating account...</p>');

        $.post(xshopValidateConfig.ajax_url, {
            action: 'xshop_validate',
            nonce: xshopValidateConfig.nonce,
            product_id: productId,
            sku: skuVal,
            price: price,
            currency: currency,
            userAccount: userAccount
        }).done(function(resp){
            if (!resp.success) {
                openModal('<p style="color:red;">Validation failed: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown') + '</p>');
                return;
            }
            var result = resp.data.result || resp.data;
            var userName = (result.message && result.message.username) || (typeof result.message === 'string' ? result.message : '');
            var roles = (result.message && result.message.roles) || [];

            var html = '<p><strong>Account:</strong> ' + $('<div/>').text(userName).html() + '</p>';
            if (roles.length) {
                html += '<p><label for="xshop-role-select">Select role:</label><br/>';
                html += '<select id="xshop-role-select">';
                roles.forEach(function(r){
                    html += '<option value="'+ r.id +'">' + $('<div/>').text(r.name).html() + '</option>';
                });
                html += '</select></p>';
            }

            html += '<p><small>Order will be added to cart and you will be redirected to checkout.</small></p>';
            openModal(html);

            // on confirm we will post add_to_cart
            $('#xshop-validate-confirm').off('click').on('click', function(){
                var selectedRole = $('#xshop-role-select').length ? $('#xshop-role-select').val() : null;
                var validateResult = JSON.stringify(result);

                openModal('<p>Adding to cart…</p>');

                $.post(xshopValidateConfig.ajax_url, {
                    action: 'xshop_add_to_cart',
                    nonce: xshopValidateConfig.nonce,
                    product_id: productId,
                    sku: skuVal,
                    variation_id: $form.find('input[name="variation_id"]').val() || 0,
                    validate_result: validateResult,
                    role_id: selectedRole
                }).done(function(resp2){
                    if (!resp2.success) {
                        openModal('<p style="color:red;">Add to cart failed: ' + (resp2.data && resp2.data.message ? resp2.data.message : 'Unknown') + '</p>');
                        return;
                    }
                    // redirect
                    var redirect = (resp2.data && resp2.data.redirect) ? resp2.data.redirect : xshopValidateConfig.checkout_url;
                    window.location.href = redirect;
                }).fail(function(){
                    openModal('<p style="color:red;">AJAX error while adding to cart.</p>');
                });
            });

            $('#xshop-validate-cancel').off('click').on('click', function(){ closeModal(); });

        }).fail(function(){
            openModal('<p style="color:red;">Validation request failed (network/server).</p>');
        });

    });

    // close modal on background click
    $(document).on('click', '#xshop-validate-modal', function(e){
        if (e.target === this) closeModal();
    });
});
