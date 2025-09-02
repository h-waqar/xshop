<?php

//  classes/Cubixsol_Woo_Order.php:3

namespace classes;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/OrderProcessor.php';

use classes\OrderProcessor;

class Cubixsol_Woo_Order
{
    private static ?Cubixsol_Woo_Order $instance;

    public function __construct()
    {
        add_action('woocommerce_thankyou', [$this, 'cubixsol_place_woo_order']);
        add_action('woocommerce_payment_complete', [$this, 'cubixsol_place_woo_order']);
        add_action('woocommerce_order_status_completed', [$this, 'cubixsol_place_woo_order']);
        add_action('woocommerce_order_status_processing', [$this, 'cubixsol_place_woo_order']);

        add_filter('woocommerce_add_cart_item_data', [$this, 'add_data_to_cart'], 10, 3);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'append_item_meta'], 10, 4);
    }

    public static  function instance(): Cubixsol_Woo_Order
    {
        return self::$instance ?? self::$instance = new self();
    }

    public function cubixsol_place_woo_order($order_id)
    {
        (new OrderProcessor())->process($order_id);
    }

    public function add_data_to_cart($cart_item_data, $product_id, $variation_id)
    {
        if (!empty($_POST['server'])) {
            $cart_item_data['xshop_server'] = sanitize_text_field($_POST['server']);
        }
        if (!empty($_POST['attribute_pa_xshop'])) {
            $cart_item_data['xshop_variation_name'] = sanitize_text_field($_POST['attribute_pa_xshop']);
        }
        if (!empty($_POST['variation_id'])) {
            $cart_item_data['xshop_variation_id'] = sanitize_text_field($_POST['variation_id']);
        }
        if (!empty($_POST['server_id'])) {
            $cart_item_data['xshop_server_id'] = intval($_POST['server_id']);
        }
        $xshop_json = get_post_meta($product_id, 'xshop_json', true);
        if ($xshop_json) {
            $cart_item_data['xshop_json'] = $xshop_json;
        }
        $cart_item_data['_POST'] = $_POST;

        return $cart_item_data;
    }

    public function append_item_meta($item, $cart_item_key, $values, $order)
    {
        if (isset($values['xshop_server'])) $item->add_meta_data('xshop_server', sanitize_text_field($values['xshop_server']), true);
        if (isset($values['xshop_variation_name'])) $item->add_meta_data('xshop_variation_name', sanitize_text_field($values['xshop_variation_name']), true);
        if (isset($values['xshop_variation_id'])) $item->add_meta_data('xshop_variation_id', sanitize_text_field($values['xshop_variation_id']), true);
        if (isset($values['xshop_server_id'])) $item->add_meta_data('xshop_server_id', intval($values['xshop_server_id']), true);
        if (isset($values['xshop_json'])) $item->add_meta_data('xshop_json', $values['xshop_json'], true);
    }
}
