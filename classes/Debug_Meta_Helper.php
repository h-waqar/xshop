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
//        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'debug_order_meta']);

//        add_action('woocommerce_after_checkout_form', [$this, 'debug_order_payload_preview']);

        // run early in admin
//        add_action('admin_init', [$this, 'maybe_debug_post'], 1);

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


    public function maybe_debug_post()
    {
        if (!is_admin()) {
            return;
        }

        $debug_keys = ['post_debug', 'debug_post', 'post-debug'];
        $found = false;
        foreach ($debug_keys as $k) {
            if (isset($_GET[$k])) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return;
        }

        $candidates = ['post', 'post_id', 'post_ID', 'ID', 'id', 'order_id', 'order'];
        $post_id = null;
        foreach ($candidates as $p) {
            if (!empty($_GET[$p]) && intval($_GET[$p]) > 0) {
                $post_id = (int) $_GET[$p];
                break;
            }
        }

        if (!$post_id) {
            global $post;
            if (!empty($post->ID)) {
                $post_id = (int) $post->ID;
            }
        }

        if (!current_user_can('manage_options')) {
            if ($post_id) {
                if (!current_user_can('edit_post', $post_id)) {
                    wp_die('You do not have permission to debug this post.', 'Permission denied', 403);
                }
            } else {
                wp_die('No post ID found and insufficient permissions.', 'Permission denied', 403);
            }
        }

        $debug = [
            'context' => [
                'requested_by' => wp_get_current_user() ? wp_get_current_user()->user_login : null,
                'wp_admin_url' => isset($_SERVER['REQUEST_URI']) ? esc_html($_SERVER['REQUEST_URI']) : null,
                'GET'          => $_GET,
            ]
        ];

        if ($post_id) {
            $debug['post'] = [
                'post_object'   => get_post($post_id),
                'post_meta'     => get_post_meta($post_id),
                'post_custom'   => get_post_custom($post_id),
                'post_type'     => get_post_type($post_id),
            ];

            $order = null;
            if (function_exists('wc_get_order')) {
                $order = wc_get_order($post_id);
                if (!$order) {
                    $attempt_keys = ['order_id', '_order_id', 'wc_order_id', 'order'];
                    foreach ($attempt_keys as $k) {
                        $val = get_post_meta($post_id, $k, true);
                        if ($val && is_numeric($val)) {
                            $order = wc_get_order((int) $val);
                            if ($order) {
                                $debug['order']['found_via_meta_key'] = $k;
                                break;
                            }
                        }
                    }
                }
            }

            if ($order) {
                $debug['order']['general'] = $order->get_data();

                // Separate sections for clarity
                $debug['order']['billing']    = $order->get_address('billing');
                $debug['order']['shipping']   = $order->get_address('shipping');
                $debug['order']['totals']     = [
                    'total'       => $order->get_total(),
                    'subtotal'    => $order->get_subtotal(),
                    'discount'    => $order->get_discount_total(),
                    'shipping'    => $order->get_shipping_total(),
                    'tax'         => $order->get_total_tax(),
                    'refunds'     => $order->get_total_refunded(),
                ];

                // Customer
                if ($order->get_user_id()) {
                    $debug['order']['customer'] = get_userdata($order->get_user_id());
                }

                // Notes
                if (function_exists('wc_get_order_notes')) {
                    $debug['order']['notes'] = wc_get_order_notes(['order_id' => $order->get_id()]);
                }

                // Refunds
                $refunds = [];
                foreach ($order->get_refunds() as $refund) {
                    $refunds[] = $refund->get_data();
                }
                if ($refunds) {
                    $debug['order']['refunds'] = $refunds;
                }

                // Items by type
                $items = [
                    'line_items'   => [],
                    'shipping'     => [],
                    'fees'         => [],
                    'coupons'      => [],
                ];

                foreach ($order->get_items(['line_item', 'shipping', 'fee', 'coupon']) as $item_id => $item) {
                    $type = $item->get_type();
                    $item_data = $item->get_data();

                    if (function_exists('wc_get_order_item_meta')) {
                        $item_data['meta'] = wc_get_order_item_meta($item_id, '', false);
                    }

                    if ($type === 'line_item') {
                        // Attach product info
                        $product_id = $item->get_product_id();
                        if ($product_id && function_exists('wc_get_product')) {
                            $product = wc_get_product($product_id);
                            if ($product) {
                                $item_data['product'] = [
                                    'id'        => $product->get_id(),
                                    'sku'       => $product->get_sku(),
                                    'type'      => $product->get_type(),
                                    'data'      => $product->get_data(),
                                    'post_meta' => get_post_meta($product->get_id()),
                                ];
                            }
                        }
                    }

                    $items[$type][$item_id] = $item_data;
                }

                $debug['order']['items'] = $items;

                // Explicitly include raw post meta
                $debug['order']['post_meta'] = get_post_meta($post_id);
            }
        }

        $output = '<div style="padding:12px;background:#111;color:#0f0;font-family: monospace;max-height:90vh;overflow:auto;">';
        $output .= '<h2 style="margin:0 0 10px;">🔎 Post / Order Debug</h2>';
        $output .= '<pre style="white-space:pre-wrap;">' . esc_html(print_r($debug, true)) . '</pre>';
        $output .= '</div>';

        wp_die($output, '🔎 Post Debug', ['response' => 200]);
    }




}
