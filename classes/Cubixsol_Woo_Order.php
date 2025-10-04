<?php
// classes/Cubixsol_Woo_Order.php

namespace classes;

defined('ABSPATH') || exit;

//include_once PLUGIN_DIR_PATH . 'classes/OrderProcessor.php';
//include_once PLUGIN_DIR_PATH . 'classes/CLogger.php';
//include_once PLUGIN_DIR_PATH . 'classes/CartDataHandler.php';

use classes\CLogger;
use classes\OrderProcessor;
use classes\CartDataHandler;

class Cubixsol_Woo_Order
{
    private static ?Cubixsol_Woo_Order $instance = null;

    public function __construct()
    {
        // --- Order hooks ---
        add_action('woocommerce_payment_complete', [$this, 'cubixsol_place_woo_order']);
        add_action('woocommerce_order_status_completed', [$this, 'cubixsol_place_woo_order']);
        add_action('woocommerce_order_status_processing', [$this, 'cubixsol_place_woo_order']);


        // --- Cart/checkout hooks ---
        add_filter('woocommerce_add_cart_item_data', [CartDataHandler::class, 'add_data_to_cart'], 10, 3);
        add_action('woocommerce_checkout_create_order_line_item', [CartDataHandler::class, 'append_item_meta'], 10, 4);
    }

    public static function instance(): Cubixsol_Woo_Order
    {
        return self::$instance ??= new self();
    }

    public function cubixsol_place_woo_order($order_id)
    {
        if (!$order_id) {
//            CLogger::log('Order Id not Found', $order_id);
            return;
        }

        // Prevent duplicate execution
        if (get_post_meta($order_id, OrderProcessor::META_SYNC_FLAG, true) === 'yes') {
//            CLogger::log('Order already processed, skipping', $order_id);
            return;
        }

//        CLogger::log('Run process method', $order_id);
        (new OrderProcessor())->process($order_id);
    }
}
