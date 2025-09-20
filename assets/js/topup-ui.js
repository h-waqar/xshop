
// assets/js/topup-ui.js
(function ($) {
    const TopupUI = {
        ensureModal: function () {
            if ($('#xshop-modal').length) return;

            const html = `
            <div id="xshop-modal" class="xshop-modal">
                <div class="xshop-modal-content">
                    <span class="xshop-close">&times;</span>
                    <div class="xshop-icon"><i></i></div>
                    <div class="xshop-title"></div>
                    <div id="xshop-validate-body"></div>
                    <div class="xshop-actions">
                        <button class="button xshop-cancel">Cancel</button>
                        <button class="button xshop-confirm">Continue</button>
                    </div>
                </div>
            </div>`;
            $('body').append(html);

            // Close actions
            $('#xshop-modal .xshop-close, #xshop-modal .xshop-cancel').on('click', TopupUI.hideModal);
        },

        showModal: function (mode, data = {}) {
            TopupUI.ensureModal();

            const $modal = $('#xshop-modal');
            const $icon = $modal.find('.xshop-icon');
            const $title = $modal.find('.xshop-title');
            const $body = $('#xshop-validate-body');
            const $confirm = $modal.find('.xshop-confirm');

            // Reset state
            $icon.removeClass('success error');
            $confirm.show().prop('disabled', false).text('Continue');

            if (mode === 'success') {
                $icon.html('<i class="fa-solid fa-circle-check"></i>').addClass('success');
                $title.text('Account Validated');
                $body.html(`
                    <p>User: <strong>${data.userAccount}</strong></p>
                    <p>Zone: <strong>${data.zoneId}</strong></p>
                `);
            } else {
                $icon.html('<i class="fa-solid fa-circle-xmark"></i>').addClass('error');
                $title.text('Invalid UserID');
                $body.html(`<p>${data.message || 'We could not validate this account.'}</p>`);
                // In error state → hide confirm button
                $confirm.hide();
            }

            // Rebind confirm callback safely
            $confirm.off('click').on('click', function () {
                if (typeof data.onConfirm === 'function') {
                    // Disable button while processing
                    $confirm.prop('disabled', true).text('Processing...');
                    data.onConfirm()
                        .then(() => TopupUI.hideModal())
                        .catch(err => {
                            console.error('Topup confirm failed', err);
                            $confirm.prop('disabled', false).text('Continue');
                        });
                } else {
                    TopupUI.hideModal();
                }
            });

            $modal.fadeIn(200);
        },

        hideModal: function () {
            $('#xshop-modal').fadeOut(150);
        }
    };

    // Expose globally
    window.TopupUI = TopupUI;

})(jQuery);
