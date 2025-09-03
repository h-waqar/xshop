<?php
// classes/ui/VoucherUI.php
namespace classes\ui;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/OrderProcessor.php';
use classes\OrderProcessor;

class VoucherUI
{
    public static function init(): void
    {
        add_action('woocommerce_thankyou', [self::class, 'show_vouchers_thankyou'], 20);
        add_action('woocommerce_order_item_meta_end', [self::class, 'show_vouchers_in_order_details'], 20, 3);
    }

    public static function show_vouchers_thankyou($order_id): void
    {
        $vouchers = get_post_meta($order_id, OrderProcessor::META_EXTERNAL_VOUCHERS, true);
        if (empty($vouchers)) {
            return;
        }

        echo '<section class="xshop-vouchers"><h2>' . __('Your Voucher Codes', 'cubixsol') . '</h2>';
        foreach ($vouchers as $item_id => $codes) {
            echo '<div class="voucher-item"><strong>' . esc_html__('Item', 'cubixsol') . " #{$item_id}</strong><ul>";
            foreach ((array) $codes as $code) {
                echo '<li><code>' . esc_html($code) . '</code></li>';
            }
            echo '</ul></div>';
        }
        echo '</section>';
    }

    public static function show_vouchers_in_order_details($item_id, $item, $order): void
    {
        $vouchers = get_post_meta($order->get_id(), OrderProcessor::META_EXTERNAL_VOUCHERS, true);
        if (empty($vouchers[$item_id])) {
            return;
        }

        echo '<p><strong>' . __('Voucher Codes:', 'cubixsol') . '</strong></p><ul>';
        foreach ((array) $vouchers[$item_id] as $code) {
            echo '<li><code>' . esc_html($code) . '</code></li>';
        }
        echo '</ul>';
    }
}
