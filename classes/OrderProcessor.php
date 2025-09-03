<?php
// classes/OrderProcessor.php

namespace classes;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/HandlerFactory.php';
include_once PLUGIN_DIR_PATH . 'classes/CLogger.php';

use classes\HandlerFactory;
use classes\CLogger;
use WC_Order;

class OrderProcessor
{
    const META_SYNC_FLAG       = 'cubixsol_woo_order_sync';
    const META_EXTERNAL_IDS    = '_cubixsol_external_order_ids';
    const META_EXTERNAL_STATUS = '_cubixsol_external_statuses';

    private int $http_timeout = 15;

    /**
     * Process an order: build payloads and call xShop API using central wrapper xshop_api_request_curl()
     *
     * @param int $order_id
     * @return void
     */
    public function process($order_id)
    {
        // Start per-order log file
        CLogger::startSession("order{$order_id}");
        CLogger::log('Run process method', $order_id);
        CLogger::log('--- START PROCESS ---', ['order_id' => $order_id]);

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            CLogger::log('Invalid Order Object', $order_id);
            return;
        }

        foreach ($order->get_items() as $item_id => $item) {
            CLogger::log('--- ITEM START ---', $item_id);

            // Our structured meta saved on checkout
            $product_info = $item->get_meta('xshop_product', true);
            $sku_data     = $item->get_meta('xshop_selected_sku', true);
            $sku_prices   = $item->get_meta('xshop_skuPrices', true);
            $xshop_json   = $item->get_meta('xshop_json', true); // legacy, handlers may still use it

            // Sanity check
            if (empty($product_info) || empty($sku_data)) {
                CLogger::log('Missing product/sku meta - skipping item', [
                    'item_id'      => $item_id,
                    'product_info' => $product_info,
                    'sku_data'     => $sku_data
                ]);
                CLogger::log('--- ITEM END (skipped) ---', $item_id);
                continue;
            }

            CLogger::log('Product Info', $product_info);
            CLogger::log('Selected SKU', $sku_data);
            if (!empty($sku_prices)) {
                CLogger::log('SKU Prices', $sku_prices);
            }

            // Persist skuPrices into a separate order meta (optional, convenient)
            if (!empty($sku_prices)) {
                update_post_meta($order_id, '_xshop_prices_' . $item_id, $sku_prices);
                CLogger::log('Saved Prices Meta', [
                    'meta_key' => '_xshop_prices_' . $item_id,
                    'value'    => $sku_prices,
                ]);
            }

            // Resolve handler using the structured product_info
            $handler = HandlerFactory::make($product_info['type'] ?? '', $product_info['subtype'] ?? '');
            if (!$handler) {
                CLogger::log('Handler not found for type/subtype - skipping item', [
                    'type'    => $product_info['type'] ?? null,
                    'subtype' => $product_info['subtype'] ?? null,
                ]);
                CLogger::log('--- ITEM END (no handler) ---', $item_id);
                continue;
            }
            CLogger::log('Handler Created', get_class($handler));

            // Build payload via handler (keep passing legacy xshop_json for backward compatibility)
            try {
                $base = [
                    'sku'       => $sku_data['sku'] ?? null,
                    'price'     => (float)$item->get_total(),   // keep using WC item total for final amount
                    'quantity'  => $item->get_quantity(),
                    'sku_data'  => $sku_data,
                    'skuPrices' => $sku_prices,
                    'product'   => $product_info,
                ];

                // handler's signature: build_payload(array $base, $xshop_json, $item, $order, $variation_product)
                $payload = $handler->build_payload($base, $xshop_json, $item, $order, $item->get_product());
                CLogger::log('Payload Built', $payload);
            } catch (\Throwable $e) {
                CLogger::log('Payload Error - skipping item', $e->getMessage());
                CLogger::log('--- ITEM END (payload error) ---', $item_id);
                continue;
            }

            // Determine api endpoint path (use product_info->apiPath if available)
            $apiPath = $product_info['apiPath'] ?? null;
            if (empty($apiPath)) {
                // Fallback to handler provided endpoint if product_info lacks apiPath
                try {
                    $endpoint_full = $handler->get_endpoint($xshop_json, $sku_data['sku'] ?? null);
                    CLogger::log('Endpoint (from handler)', $endpoint_full);
                    // extract endpoint path relative to base if possible
                    $apiPath = ltrim(parse_url($endpoint_full, PHP_URL_PATH) ?: '', '/');
                } catch (\Throwable $e) {
                    CLogger::log('Endpoint Resolve Error - skipping item', $e->getMessage());
                    CLogger::log('--- ITEM END (no endpoint) ---', $item_id);
                    continue;
                }
            } else {
                CLogger::log('apiPath resolved', $apiPath);
            }

            // Use central request wrapper that sets JWT and headers
            try {
                // xshop_api_request_curl expects endpoint path (without base) per your existing implementation
                CLogger::log('About to call xshop_api_request_curl', [
                    'apiPath'  => $apiPath,
                    'payload'  => $payload,
                ]);

                // send request
                $res = xshop_api_request_curl($apiPath, $payload, 'POST');

                // Log wrapper raw response
                CLogger::log('xshop_api_request_curl response', $res);
            } catch (\Throwable $e) {
                CLogger::log('Request Exception', $e->getMessage());
                CLogger::log('--- ITEM END (request exception) ---', $item_id);
                continue;
            }

            // Normalize response values from wrapper
            $http_status = $res['status'] ?? ($res['response']['code'] ?? 0);
            $decoded     = $res['json'] ?? null; // wrapper decodes into 'json'
            $raw_url     = $res['url'] ?? null;
            $sent_headers = $res['header'] ?? null;

            // Log HTTP status and body
            CLogger::log('HTTP Response Summary', [
                'status' => $http_status,
                'decoded' => $decoded,
                'url' => $raw_url,
                'sent_headers' => $sent_headers,
            ]);

            // Build debug payload to save on order
            $debug_data = [
                'endpoint'     => (defined('API_BASE_URL') ? rtrim(API_BASE_URL, '/') . '/' . ltrim($apiPath, '/') : $apiPath),
                'url'          => $raw_url,
                'sent_headers' => $sent_headers,
                'payload'      => $payload,
                'status'       => $http_status,
                'raw'          => $res,            // full wrapper return (status,json,header,url)
                'response'     => $decoded ?: ($res['json'] ?? null),
            ];

            // Save debug to order meta (item-specific)
            update_post_meta($order_id, '_xshop_debug_' . $item_id, $debug_data);
            CLogger::log('Saved Debug Data', $debug_data);

            // Optionally: decide success/failure logic
            // Example: mark synced only if HTTP 200 and response contains expected data
            $is_success = false;
            if (in_array((int)$http_status, [200, 201])) {
                // If API returns JSON with a success marker, check it. Example: ['result'] or ['success'] etc.
                if (is_array($decoded) && (isset($decoded['result']) || isset($decoded['success']) || isset($decoded['data']))) {
                    $is_success = true;
                } else {
                    // If API returns 200 but no expected structure, still mark false and log
                    CLogger::log('HTTP OK but unexpected body structure', $decoded);
                }
            } else {
                CLogger::log('HTTP Error status from API', $http_status);
            }

            // If success, optionally save external order id or statuses to post meta
            if ($is_success) {
                // example: try to extract order id from 'result' if present
                $external_order_id = null;
                if (!empty($decoded['result']['orderId'])) {
                    $external_order_id = $decoded['result']['orderId'];
                } elseif (!empty($decoded['result']['id'])) {
                    $external_order_id = $decoded['result']['id'];
                }

                if ($external_order_id) {
                    // Save mapping post meta (preserve multiple)
                    $existing = get_post_meta($order_id, self::META_EXTERNAL_IDS, true);
                    $existing = is_array($existing) ? $existing : [];
                    $existing[$item_id] = $external_order_id;
                    update_post_meta($order_id, self::META_EXTERNAL_IDS, $existing);
                    CLogger::log('Saved external order id', ['item_id' => $item_id, 'external_order_id' => $external_order_id]);
                }

                // Optionally store status
                $existing_status = get_post_meta($order_id, self::META_EXTERNAL_STATUS, true);
                $existing_status = is_array($existing_status) ? $existing_status : [];
                $existing_status[$item_id] = $decoded['result']['status'] ?? 'ok';
                update_post_meta($order_id, self::META_EXTERNAL_STATUS, $existing_status);
            }

            CLogger::log('--- ITEM END ---', $item_id);
        }

        // mark order processed (guard for multi-hook triggers)
        update_post_meta($order_id, self::META_SYNC_FLAG, 'yes');
        CLogger::log('--- END PROCESS ---', ['order_id' => $order_id]);
    }
}
