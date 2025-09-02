<?php

//  classes/Debug_Meta_Helper.php:3

namespace classes;

defined('ABSPATH') || exit;

class Debug_Meta_Helper
{
    public function __construct()
    {
        // Single product page
        add_action('woocommerce_after_single_product', [$this, 'debug_product_meta']);

        // Cart page
        add_action('woocommerce_after_cart', [$this, 'debug_cart_meta']);

        // Checkout page
        add_action('woocommerce_after_checkout_form', [$this, 'debug_checkout_meta']);

        // Admin: single order screen
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'debug_order_meta']);

//        add_action('woocommerce_after_checkout_form', [$this, 'debug_order_payload_preview']);

    }

    public function debug_order_payload_preview()
    {
        if (!WC()->cart || WC()->cart->is_empty()) return;

        echo '<pre style="background:#111;color:#0f0;padding:10px;">';
        echo "<h3>🚧 Payload Preview (Cart → Order)</h3>";

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product        = $cart_item['data'];
            $variation_sku  = $product ? $product->get_sku() : null;
            $parent_id      = $product ? $product->get_parent_id() : $cart_item['product_id'];
            $parent_sku     = get_post_meta($parent_id, '_sku', true);

            // Parse the important parts of xshop_json
            $xshop_meta = [];
            if (!empty($cart_item['xshop_json'])) {
                $decoded = json_decode($cart_item['xshop_json'], true);
                if (is_array($decoded) && isset($decoded['product'])) {
                    $xshop_meta = [
                        'supportedCountries' => $decoded['product']['supportedCountries'] ?? [],
                        'type'               => $decoded['product']['type'] ?? null,
                        'subtype'            => $decoded['product']['subtype'] ?? null,
                        'haveRole'           => $decoded['product']['haveRole'] ?? null,
                        'haveVerify'         => $decoded['product']['haveVerify'] ?? null,
                    ];
                }
            }

            $payload_base = [
                'order_id' => 'preview-' . uniqid(),
                'item_id'  => $cart_item['key'],
                'sku'      => $variation_sku ?: $parent_sku,
                'quantity' => (int) $cart_item['quantity'],
                'price'    => (float) $cart_item['line_total'],
                'meta'     => array_merge([
                    'variation_id'         => $cart_item['variation_id'] ?? null,
                    'xshop_variation_name' => $cart_item['xshop_variation_name'] ?? null,
                    'xshop_server'         => $cart_item['xshop_server'] ?? null,
                ], $xshop_meta),
            ];

            print_r($payload_base);
        }

        echo '</pre>';
    }



    /**
     * Dump product meta on single product page
     */
    public function debug_product_meta()
    {
        global $post;
        if (!$post) {
            return;
        }

        echo '<pre style="background:#111;color:#0f0;padding:10px;">';
        echo "<h3>🔎 Product Post Meta (ID: {$post->ID})</h3>";
        print_r(get_post_meta($post->ID));
        echo '</pre>';
    }

    /**
     * Dump cart contents + meta
     */
    public function debug_cart_meta()
    {
        if (!WC()->cart) {
            return;
        }

        echo '<pre style="background:#111;color:#0f0;padding:10px;">';
        echo "<h3>🛒 Cart Items Meta</h3>";
        foreach (WC()->cart->get_cart() as $key => $item) {
            echo "Cart Key: $key\n";
            print_r($item);
            echo "-----------------------------\n";
        }
        echo '</pre>';
    }

    /**
     * Dump checkout cart contents
     */
    public function debug_checkout_meta()
    {
        if (!WC()->cart) {
            return;
        }

        echo '<pre style="background:#111;color:#0f0;padding:10px;">';
        echo "<h3>💳 Checkout Meta</h3>";
        foreach (WC()->cart->get_cart() as $key => $item) {
            echo "Cart Key: $key\n";
            print_r($item);
            echo "-----------------------------\n";
        }
        echo '</pre>';
    }

    /**
     * Dump order meta in admin order page
     */
    public function debug_order_meta($order)
    {
        echo '<pre style="background:#111;color:#0f0;padding:10px;">';
        echo "<h3>📦 Order Meta (ID: {$order->get_id()})</h3>";
        print_r(get_post_meta($order->get_id()));
        echo '</pre>';
    }
}
