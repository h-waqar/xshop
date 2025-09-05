<?php
namespace classes\ui;

defined('ABSPATH') || exit;

class ProductButton {
    public static function init() {
        add_action('woocommerce_after_add_to_cart_button', [__CLASS__, 'decorate_single_button']);
    }

    public static function decorate_single_button() {
        global $product;

        $xshop_json = get_post_meta($product->get_id(), 'xshop_json', true);
        if (!$xshop_json) {
            return;
        }

        $decoded = json_decode($xshop_json, true);
        if (empty($decoded['product']['type']) || $decoded['product']['type'] !== 'topup') {
            return;
        }

        ?>
        <script>
            jQuery(function($){
                $('.single_add_to_cart_button').attr('data-xshop-type', 'topup');
            });
        </script>
        <?php
    }
}
