<?php
// classes/ui/TopupUI.php

namespace classes\Ui;

defined('ABSPATH') || exit;

class TopupUI
{
    public static function init(): void
    {
        // Output modal container in footer
        add_action('wp_footer', [__CLASS__, 'render_modal']);

        // Decorate add-to-cart button only for topup products
        add_filter('woocommerce_product_single_add_to_cart_button', [__CLASS__, 'decorate_button'], 10, 2);
    }

    public static function render_modal(): void
    {
        ?>
        <div id="xshopValidateModal" class="xshop-modal" style="display:none;">
            <div class="xshop-modal-content" role="dialog" aria-modal="true" aria-labelledby="xshop-validate-title">
                <button type="button" class="xshop-close" aria-label="Close">&times;</button>
                <h2 id="xshop-validate-title" class="xshop-title">Validate Account</h2>
                <div id="xshop-validate-body"><p>Waiting for validation...</p></div>
                <div class="xshop-actions">
                    <button type="button" class="button button-secondary xshop-cancel">Cancel</button>
                    <button type="button" class="button button-primary xshop-confirm">Confirm</button>
                </div>
            </div>
        </div>
        <?php
    }


    /**
     * Add data attributes to the Add to Cart button if the product is a topup.
     */
    public static function decorate_button(string $button, \WC_Product $product): string
    {
        $xshop_json = get_post_meta($product->get_id(), 'xshop_json', true);
        if (!$xshop_json) {
            return $button;
        }

        $decoded = json_decode($xshop_json, true);
        if (($decoded['product']['type'] ?? '') !== 'topup') {
            return $button;
        }

        $sku      = $product->get_sku();
        $price    = $product->get_price();
        $currency = get_woocommerce_currency();

        // Inject our attributes into the button HTML
        $attrs = sprintf(
                ' name="add-to-cart" data-xshop-type="topup" data-product_id="%d" data-product_sku="%s" data-price="%s" data-currency="%s"',
                $product->get_id(),
                esc_attr($sku),
                esc_attr($price),
                esc_attr($currency)
        );

        // Replace the default name="add-to-cart" with our enriched attributes
        $button = preg_replace('/name="add-to-cart"/', $attrs, $button, 1);

        return $button;
    }
}
