<?php
// classes/OrderProcessor.php

namespace classes;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/HandlerFactory.php';
include_once PLUGIN_DIR_PATH . 'classes/Handlers/TopupHandler.php';
include_once PLUGIN_DIR_PATH . 'classes/Handlers/VoucherHandler.php';

use classes\HandlerFactory;

class OrderProcessor
{
    const META_SYNC_FLAG = 'cubixsol_woo_order_sync';
    const META_EXTERNAL_IDS = '_cubixsol_external_order_ids';
    const META_EXTERNAL_STATUS = '_cubixsol_external_statuses';

    private $http_timeout = 15;

    /**
     * Preview payloads without sending them.
     * Call manually (e.g. via ?debug_payload=ORDER_ID).
     */
    public function preview_payloads($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die("❌ No order found with ID $order_id");
        }

        $items = $order->get_items();
        if (empty($items)) {
            wp_die("❌ Order $order_id has no items");
        }

        $payloads = [];

        foreach ($items as $item) {
            /** @var \WC_Order_Item_Product $item */

            $item_id = $item->get_id();
            $variation_product = $item->get_product();
            $variation_sku = $variation_product ? $variation_product->get_sku() : null;
            $parent_id = $variation_product ? $variation_product->get_parent_id() : $item->get_product_id();
            $parent_sku = get_post_meta($parent_id, '_sku', true);

            $xshop_json = $item->get_meta('xshop_json') ?: get_post_meta($parent_id, 'xshop_json', true);
            $type = get_post_meta($parent_id, '_xshop_type', true);
            $subtype = get_post_meta($parent_id, '_xshop_subtype', true);

            $payload_base = [
                'order_id' => $order->get_id(),
                'order_key' => $order->get_order_key(),
                'item_id' => $item_id,
                'sku' => $variation_sku ?: $parent_sku,
                'quantity' => (int)$item->get_quantity(),
                'price' => (float)$item->get_total(),
                'meta' => [
                    'variation_id' => $item->get_variation_id(),
                    'xshop_variation_name' => $item->get_meta('xshop_variation_name'),
                    'xshop_server' => $item->get_meta('xshop_server'),
                ],
            ];

            $handler = HandlerFactory::make((string)$type, (string)$subtype);
            $payload = $handler->build_payload($payload_base, $xshop_json, $item, $order, $variation_product);

            $payloads[] = [
                'handler' => get_class($handler),
                'endpoint' => $handler->get_endpoint(),
                'payload' => $payload,
            ];
        }

        // Pretty print JSON
        wp_die(
            '<pre style="text-align:left;direction:ltr;">' .
            esc_html(json_encode($payloads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) .
            '</pre>'
        );
    }

    /**
     * The actual processor (unchanged, still sends request).
     */
    public function process($order_id)
    {

        if (!$order_id) return false;

        $synced = get_post_meta($order_id, self::META_SYNC_FLAG, true);
        if ($synced === 'yes') {
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order) return false;

        $items = $order->get_items();
        if (empty($items)) return false;

        $external_ids = (array)get_post_meta($order_id, self::META_EXTERNAL_IDS, true);
        $external_statuses = (array)get_post_meta($order_id, self::META_EXTERNAL_STATUS, true);

        foreach ($items as $item) {
            /** @var \WC_Order_Item_Product $item */
            $item_id = $item->get_id();

            if (!empty($external_ids[$item_id])) {
                continue; // already processed
            }

            $variation_product = $item->get_product();
            $variation_sku = $variation_product ? $variation_product->get_sku() : null;
            $parent_id = $variation_product ? $variation_product->get_parent_id() : $item->get_product_id();
            $parent_sku = get_post_meta($parent_id, '_sku', true);

            $xshop_json = $item->get_meta('xshop_json') ?: get_post_meta($parent_id, 'xshop_json', true);
            $type = get_post_meta($parent_id, '_xshop_type', true);
            $subtype = get_post_meta($parent_id, '_xshop_subtype', true);

            $payload_base = [
                'order_id' => $order->get_id(),
                'order_key' => $order->get_order_key(),
                'item_id' => $item_id,
                'sku' => $variation_sku ?: $parent_sku,
                'quantity' => (int)$item->get_quantity(),
                'price' => (float)$item->get_total(),
                'meta' => [
                    'variation_id' => $item->get_variation_id(),
                    'xshop_variation_name' => $item->get_meta('xshop_variation_name'),
                    'xshop_server' => $item->get_meta('xshop_server'),
                ],
            ];

            $handler = HandlerFactory::make((string)$type, (string)$subtype);
            $payload = $handler->build_payload($payload_base, $xshop_json, $item, $order, $variation_product);

            $response = $this->send_request($handler->get_endpoint(), $payload, $handler->get_headers());

            // handle response (status, ids)
            if (is_wp_error($response)) {
                $external_statuses[$item_id] = [
                    'success' => false,
                    'error' => $response->get_error_message(),
                    'ts' => current_time('mysql'),
                ];
                error_log('[Cubixsol] Request failed for order ' . $order->get_id() . ' item ' . $item_id . ': ' . $response->get_error_message());
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                $decoded = json_decode($body, true);

                if ($code >= 200 && $code < 300) {
                    // (!) Adjust extraction depending on external API response shape
                    $external_id = $decoded['data']['id'] ?? $decoded['order_id'] ?? ($decoded['id'] ?? null);

                    if (!$external_id) {
                        // store raw response for debug if no id
                        $external_statuses[$item_id] = [
                            'success' => false,
                            'error' => 'no_external_id',
                            'response' => $decoded,
                            'ts' => current_time('mysql'),
                        ];
                    } else {
                        $external_ids[$item_id] = $external_id;
                        $external_statuses[$item_id] = [
                            'success' => true,
                            'external_id' => $external_id,
                            'response' => $decoded,
                            'ts' => current_time('mysql'),
                        ];
                    }
                } else {
                    $external_statuses[$item_id] = [
                        'success' => false,
                        'http_code' => $code,
                        'response' => $body,
                        'ts' => current_time('mysql'),
                    ];
                    error_log('[Cubixsol] Non-2xx response for order ' . $order->get_id() . ' item ' . $item_id . ' code ' . $code . ' body: ' . substr($body, 0, 1000));
                }
            }

            // persist after each item to avoid losing progress on fatal errors
            update_post_meta($order->get_id(), self::META_EXTERNAL_IDS, $external_ids);
            update_post_meta($order->get_id(), self::META_EXTERNAL_STATUS, $external_statuses);
        }

        // if at least one item got processed successfully, mark order synced.
        $any_success = array_filter($external_statuses, function ($s) {
            return !empty($s['success']);
        });
        if (!empty($any_success)) {
            update_post_meta($order->get_id(), self::META_SYNC_FLAG, 'yes');
        }

        return true;
    }

    private function send_request($endpoint, $payload, $headers = [])
    {
        if (empty($endpoint)) {
            return new \WP_Error('no_endpoint', 'No endpoint provided');
        }

        $args = [
            'timeout' => $this->http_timeout,
            'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
            'body' => wp_json_encode($payload),
        ];

        return wp_remote_post($endpoint, $args);
    }
}



add_action('init', function() {
    if (!empty($_GET['debug_payload'])) {
        $order_id = (int) $_GET['debug_payload'];
        $processor = new OrderProcessor();
        $processor->preview_payloads($order_id);
        wp_die();
    }
});
