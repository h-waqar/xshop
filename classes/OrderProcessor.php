<?php
// classes/OrderProcessor.php

namespace classes;

defined('ABSPATH') || exit;

//include_once PLUGIN_DIR_PATH . 'classes/HandlerFactory.php';
//include_once PLUGIN_DIR_PATH . 'classes/CLogger.php';
//include_once PLUGIN_DIR_PATH . 'classes/XshopApiClient.php';

use classes\HandlerFactory;
use classes\CLogger;
use classes\XshopApiClient;
use WC_Order;

class OrderProcessor
{
    // --- Meta keys used for syncing ---
    const META_SYNC_FLAG         = 'cubixsol_woo_order_sync';         // flag so order isn't re-processed
    const META_EXTERNAL_IDS      = '_cubixsol_external_order_ids';    // external orderId(s)
    const META_EXTERNAL_STATUS   = '_cubixsol_external_statuses';     // external status(es)
    const META_EXTERNAL_VOUCHERS = '_cubixsol_external_vouchers';     // external voucher codes

    private int $http_timeout = 15;

    /**
     * Main processor entry point
     *
     * @param int $order_id
     */
    public function process(int $order_id)
    {
        // Start logging session
//        CLogger::startSession("order{$order_id}");
//        CLogger::log('--- START PROCESS ---', ['order_id' => $order_id]);

        // Get Woo order object
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
//            CLogger::log('Invalid Order Object', $order_id);
            return;
        }

        // Loop through each order item
        foreach ($order->get_items() as $item_id => $item) {
//            CLogger::log('--- ITEM START ---', $item_id);

            // --- Retrieve stored meta ---
            $product_info    = $item->get_meta('xshop_product');
            $sku_data        = $item->get_meta('xshop_selected_sku');
            $sku_prices      = $item->get_meta('xshop_skuPrices');
            $xshop_json_raw  = $item->get_meta('xshop_json');
            $xshop_json      = is_string($xshop_json_raw) ? json_decode($xshop_json_raw, true) : $xshop_json_raw;

            $validate_meta   = $item->get_meta('xshop_validate', true);
            $validate_id     = $item->get_meta('xshop_validate_id', true) ?? $validate_meta['id'] ?? null;
            $validate_orderId = $item->get_meta('xshop_validate_orderId', true)
                ?? $validate_meta['orderId']
                ?? ($validate_meta['result']['orderId'] ?? null);

            $server_id       = $sku_data['selected_server_id'] ?? ($sku_data['selected_server']['id'] ?? null);
            $server_name     = is_array($sku_data['selected_server']) ? ($sku_data['selected_server']['name'] ?? null) : ($sku_data['selected_server'] ?? null);
            $zone_id         = $sku_data['zoneId'] ?? $item->get_meta('xshop_zoneId') ?? null;
            $role_id         = $item->get_meta('xshop_role_id') ?? null;

            // --- Validation: must have product & sku meta ---
            if (empty($product_info) || empty($sku_data)) {
//                CLogger::log('Missing product/sku meta - skipping item', [
//                    'item_id'      => $item_id,
//                    'product_info' => $product_info,
//                    'sku_data'     => $sku_data,
//                ]);
                continue;
            }

            // --- Create proper handler for product/subtype ---
            $handler = HandlerFactory::make($product_info['type'] ?? '', $product_info['subtype'] ?? '');
            if (!$handler) {
//                CLogger::log('Handler not found - skipping item', $product_info);
                continue;
            }

            // --- Build API payload ---
            try {
                $base = [
                    'sku'             => $sku_data['sku'] ?? null,
                    'price'           => (float) $item->get_total(),
                    'quantity'        => $item->get_quantity(),
                    'sku_data'        => $sku_data,
                    'skuPrices'       => $sku_prices,
                    'product'         => $product_info,
                    'customerId'      => $order->get_billing_email() ?? (string)$order->get_customer_id(),
                    'server_id'       => $server_id,
                    'server_name'     => $server_name,
                    'zone_id'         => $zone_id,
                    'validate_id'     => $validate_id,
                    'validate_order_id'=> $validate_orderId,
                    'role_id'         => $role_id,
                ];

                $payload = $handler->build_payload($base, $xshop_json, $item, $order, $item->get_product());
            } catch (\Throwable $e) {
//                CLogger::log('Payload Error', $e->getMessage());
                continue;
            }

            // --- Resolve API endpoint ---
            $apiPath = $product_info['apiPath'] ?? null;
            if (empty($apiPath)) {
                try {
                    $endpoint_full = $handler->get_endpoint($xshop_json, $sku_data['sku'] ?? null);
                    $apiPath       = ltrim(parse_url($endpoint_full, PHP_URL_PATH) ?: '', '/');
                } catch (\Throwable $e) {
//                    CLogger::log('Endpoint Resolve Error', $e->getMessage());
                    continue;
                }
            }

            // --- Make API Request ---
            try {
                $res = XshopApiClient::request($apiPath, $payload, 'POST');

                // Store debug info for troubleshooting
                $debug_data = [
                    'endpoint' => $res['url'],
                    'payload'  => $payload,
                    'status'   => $res['status'],
                    'response' => $res['decoded'],
                    'raw'      => $res,
                ];
                update_post_meta($order_id, '_xshop_debug_' . $item_id, $debug_data);

                // ✅ Success: store external data
                if ($res['success'] && isset($res['decoded']['result'])) {
                    $decoded = $res['decoded'];

                    // Save external orderId
                    $external_order_id = $decoded['result']['orderId'] ?? ($decoded['result']['id'] ?? null);
                    if ($external_order_id) {
                        $existing           = get_post_meta($order_id, self::META_EXTERNAL_IDS, true) ?: [];
                        $existing[$item_id] = $external_order_id;
                        update_post_meta($order_id, self::META_EXTERNAL_IDS, $existing);
                    }

                    // Save status
                    $status_existing           = get_post_meta($order_id, self::META_EXTERNAL_STATUS, true) ?: [];
                    $status_existing[$item_id] = $decoded['result']['status'] ?? 'ok';
                    update_post_meta($order_id, self::META_EXTERNAL_STATUS, $status_existing);

                    // Save voucher codes (if any)
                    if (!empty($decoded['result']['items'])) {
                        $voucher_existing = get_post_meta($order_id, self::META_EXTERNAL_VOUCHERS, true) ?: [];
                        foreach ($decoded['result']['items'] as $entry) {
                            if (!empty($entry['codes'])) {
                                $voucher_existing[$item_id][] = $entry['codes'];
                            }
                        }
                        update_post_meta($order_id, self::META_EXTERNAL_VOUCHERS, $voucher_existing);
                    }

                } else {
                    // ❌ API error → fail order
                    $error_message = $res['decoded']['error']['message'] ?? 'Unknown API error';
//                    CLogger::log('API Response NOT successful', [
//                        'status'   => $res['status'],
//                        'response' => $res['decoded'],
//                    ]);

                    $order->update_status('failed', 'XShop API Error: ' . $error_message);
                    $order->add_order_note('Order failed due to XShop API error: ' . $error_message);

                    if (is_checkout()) {
                        wc_add_notice(__('Your order could not be processed: ', 'xshop') . $error_message, 'error');
                    }

                    return false; // Stop further processing
                }

            } catch (\Throwable $e) {
//                CLogger::log('Request Exception', $e->getMessage());

                $order->update_status('failed', 'XShop API Exception: ' . $e->getMessage());
                $order->add_order_note('Order failed due to exception: ' . $e->getMessage());

                if (is_checkout()) {
                    wc_add_notice(__('Your order could not be processed due to an internal error.', 'xshop'), 'error');
                }

                return false; // Stop further processing
            }

//            CLogger::log('--- ITEM END ---', $item_id);
        }

        // Mark order as synced (processed once only)
        update_post_meta($order_id, self::META_SYNC_FLAG, 'yes');
//        CLogger::log('--- END PROCESS ---', ['order_id' => $order_id]);
    }
}
