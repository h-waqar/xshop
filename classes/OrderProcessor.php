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
    const META_SYNC_FLAG         = 'cubixsol_woo_order_sync';
    const META_EXTERNAL_IDS      = '_cubixsol_external_order_ids';
    const META_EXTERNAL_STATUS   = '_cubixsol_external_statuses';
    const META_EXTERNAL_VOUCHERS = '_cubixsol_external_vouchers';

    private int $http_timeout = 15;

    public function process($order_id)
    {
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

            $product_info = $item->get_meta('xshop_product', true);
            $sku_data     = $item->get_meta('xshop_selected_sku', true);
            $sku_prices   = $item->get_meta('xshop_skuPrices', true);
            $xshop_json   = $item->get_meta('xshop_json', true);

            if (empty($product_info) || empty($sku_data)) {
                CLogger::log('Missing product/sku meta - skipping item', [
                    'item_id'      => $item_id,
                    'product_info' => $product_info,
                    'sku_data'     => $sku_data,
                ]);
                CLogger::log('--- ITEM END (skipped) ---', $item_id);
                continue;
            }

            CLogger::log('Product Info', $product_info);
            CLogger::log('Selected SKU', $sku_data);

            if (!empty($sku_prices)) {
                update_post_meta($order_id, '_xshop_prices_' . $item_id, $sku_prices);
                CLogger::log('Saved Prices Meta', [
                    'meta_key' => '_xshop_prices_' . $item_id,
                    'value'    => $sku_prices,
                ]);
            }

            $handler = HandlerFactory::make($product_info['type'] ?? '', $product_info['subtype'] ?? '');
            if (!$handler) {
                CLogger::log('Handler not found - skipping item', $product_info);
                CLogger::log('--- ITEM END (no handler) ---', $item_id);
                continue;
            }
            CLogger::log('Handler Created', get_class($handler));

            try {
                $base = [
                    'sku'       => $sku_data['sku'] ?? null,
                    'price'     => (float) $item->get_total(),
                    'quantity'  => $item->get_quantity(),
                    'sku_data'  => $sku_data,
                    'skuPrices' => $sku_prices,
                    'product'   => $product_info,
                ];
                $payload = $handler->build_payload($base, $xshop_json, $item, $order, $item->get_product());
                CLogger::log('Payload Built', $payload);
            } catch (\Throwable $e) {
                CLogger::log('Payload Error', $e->getMessage());
                CLogger::log('--- ITEM END (payload error) ---', $item_id);
                continue;
            }

            // --- Resolve endpoint ---
            $apiPath = $product_info['apiPath'] ?? null;
            if (empty($apiPath)) {
                try {
                    $endpoint_full = $handler->get_endpoint($xshop_json, $sku_data['sku'] ?? null);
                    $apiPath = ltrim(parse_url($endpoint_full, PHP_URL_PATH) ?: '', '/');
                    CLogger::log('Endpoint (from handler)', $endpoint_full);
                } catch (\Throwable $e) {
                    CLogger::log('Endpoint Resolve Error', $e->getMessage());
                    CLogger::log('--- ITEM END (no endpoint) ---', $item_id);
                    continue;
                }
            } else {
                CLogger::log('apiPath resolved', $apiPath);
            }

            // --- API Request ---
            try {
                CLogger::log('Sending Request', [
                    'apiPath' => $apiPath,
                    'payload' => $payload,
                ]);

                $res = xshop_api_request_curl($apiPath, $payload, 'POST');

                // Normalize response
                $http_status = $res['status'] ?? ($res['response']['code'] ?? 0);
                $raw_body    = $res['body'] ?? null;
                $decoded     = $res['json'] ?? (json_decode($raw_body, true) ?: null);
                $raw_url     = $res['url'] ?? null;
                $sent_headers= $res['header'] ?? null;

                // Full response logging
                CLogger::log('HTTP Response', [
                    'status'  => $http_status,
                    'body'    => $raw_body,
                    'decoded' => $decoded,
                ]);

                // Debug meta storage
                $debug_data = [
                    'endpoint'     => (defined('API_BASE_URL') ? rtrim(API_BASE_URL, '/') . '/' . ltrim($apiPath, '/') : $apiPath),
                    'url'          => $raw_url,
                    'sent_headers' => $sent_headers,
                    'payload'      => $payload,
                    'status'       => $http_status,
                    'raw'          => $res,
                    'body'         => $raw_body,
                    'response'     => $decoded,
                ];
                update_post_meta($order_id, '_xshop_debug_' . $item_id, $debug_data);
                CLogger::log('Saved Debug Data', $debug_data);

            } catch (\Throwable $e) {
                CLogger::log('Request Exception', $e->getMessage());
                CLogger::log('--- ITEM END (request exception) ---', $item_id);
                continue;
            }

            // --- Handle success/failure ---
            $is_success = false;
            if (in_array((int) $http_status, [200, 201], true)) {
                if (is_array($decoded) && isset($decoded['result'])) {
                    $is_success = true;
                }
            }

            if ($is_success) {
                $external_order_id = $decoded['result']['orderId'] ?? ($decoded['result']['id'] ?? null);
                if ($external_order_id) {
                    $existing = get_post_meta($order_id, self::META_EXTERNAL_IDS, true) ?: [];
                    $existing[$item_id] = $external_order_id;
                    update_post_meta($order_id, self::META_EXTERNAL_IDS, $existing);
                    CLogger::log('Saved external order id', $external_order_id);
                }

                $status_existing = get_post_meta($order_id, self::META_EXTERNAL_STATUS, true) ?: [];
                $status_existing[$item_id] = $decoded['result']['status'] ?? 'ok';
                update_post_meta($order_id, self::META_EXTERNAL_STATUS, $status_existing);

                // ✅ Save voucher codes
                if (!empty($decoded['result']['items'])) {
                    $voucher_existing = get_post_meta($order_id, self::META_EXTERNAL_VOUCHERS, true) ?: [];
                    foreach ($decoded['result']['items'] as $entry) {
                        if (!empty($entry['codes'])) {
                            $voucher_existing[$item_id][] = $entry['codes'];
                        }
                    }
                    update_post_meta($order_id, self::META_EXTERNAL_VOUCHERS, $voucher_existing);
                    CLogger::log('Saved voucher codes', $voucher_existing);
                }
            } else {
                CLogger::log('API Response NOT successful', [
                    'status'   => $http_status,
                    'response' => $decoded,
                ]);
            }

            CLogger::log('--- ITEM END ---', $item_id);
        }

        update_post_meta($order_id, self::META_SYNC_FLAG, 'yes');
        CLogger::log('--- END PROCESS ---', ['order_id' => $order_id]);
    }
}
