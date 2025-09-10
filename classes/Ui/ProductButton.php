<?php
namespace classes\ui;

defined('ABSPATH') || exit;

class ProductButton
{
    public function __construct()
    {
        add_action('woocommerce_before_add_to_cart_button', [$this, 'maybe_mark_topup'], 15);
    }

    public function maybe_mark_topup()
    {
        global $product;
        if (! $product || ! $product->get_id()) return;

        $xshop_json = get_post_meta($product->get_id(), 'xshop_json', true);
        if (empty($xshop_json)) {
            return;
        }

        // We output a tiny JS snippet that marks the button when DOM ready.
        // This avoids touching Woo templates and keeps things isolated.
        ?>
        <script>
            (function($){
                $(function(){
                    var $btn = $('form.cart').first().find('button.single_add_to_cart_button');
                    if ($btn.length && !$btn.attr('data-xshop-type')) {
                        $btn.attr('data-xshop-type', 'topup');
                    }
                });
            })(jQuery);
        </script>
        <?php
    }
}
