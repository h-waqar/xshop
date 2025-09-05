<?php
// classes/OrderProcessor.php

namespace classes;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/HandlerFactory.php';
include_once PLUGIN_DIR_PATH . 'classes/CLogger.php';
include_once PLUGIN_DIR_PATH . 'classes/XshopApiClient.php';

use classes\HandlerFactory;
use classes\CLogger;
use classes\XshopApiClient;
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
        CLogger::log('--- START PROCESS ---', ['order_id' => $order_id]);

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            CLogger::log('Invalid Order Object', $order_id);
            return;
        }

        foreach ($order->get_items() as $item_id => $item) {
            CLogger::log('--- ITEM START ---', $item_id);

            $product_info   = $item->get_meta('xshop_product', true);
            $sku_data       = $item->get_meta('xshop_selected_sku', true);
            $sku_prices     = $item->get_meta('xshop_skuPrices', true);
            $xshop_json_raw = $item->get_meta('xshop_json', true);
            $xshop_json     = is_string($xshop_json_raw) ? json_decode($xshop_json_raw, true) : $xshop_json_raw;

            if (empty($product_info) || empty($sku_data)) {
                CLogger::log('Missing product/sku meta - skipping item', [
                    'item_id'      => $item_id,
                    'product_info' => $product_info,
                    'sku_data'     => $sku_data,
                ]);
                continue;
            }

            $handler = HandlerFactory::make($product_info['type'] ?? '', $product_info['subtype'] ?? '');
            if (!$handler) {
                CLogger::log('Handler not found - skipping item', $product_info);
                continue;
            }

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
            } catch (\Throwable $e) {
                CLogger::log('Payload Error', $e->getMessage());
                continue;
            }

            // Resolve endpoint
            $apiPath = $product_info['apiPath'] ?? null;
            if (empty($apiPath)) {
                try {
                    $endpoint_full = $handler->get_endpoint($xshop_json, $sku_data['sku'] ?? null);
                    $apiPath       = ltrim(parse_url($endpoint_full, PHP_URL_PATH) ?: '', '/');
                } catch (\Throwable $e) {
                    CLogger::log('Endpoint Resolve Error', $e->getMessage());
                    continue;
                }
            }

            try {
                $res = XshopApiClient::request($apiPath, $payload, 'POST');

                $debug_data = [
                    'endpoint' => $res['url'],
                    'payload'  => $payload,
                    'status'   => $res['status'],
                    'response' => $res['decoded'],
                    'raw'      => $res,
                ];
                update_post_meta($order_id, '_xshop_debug_' . $item_id, $debug_data);

                if ($res['success'] && isset($res['decoded']['result'])) {
                    $decoded = $res['decoded'];

                    $external_order_id = $decoded['result']['orderId'] ?? ($decoded['result']['id'] ?? null);
                    if ($external_order_id) {
                        $existing            = get_post_meta($order_id, self::META_EXTERNAL_IDS, true) ?: [];
                        $existing[$item_id]  = $external_order_id;
                        update_post_meta($order_id, self::META_EXTERNAL_IDS, $existing);
                    }

                    $status_existing            = get_post_meta($order_id, self::META_EXTERNAL_STATUS, true) ?: [];
                    $status_existing[$item_id]  = $decoded['result']['status'] ?? 'ok';
                    update_post_meta($order_id, self::META_EXTERNAL_STATUS, $status_existing);

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
                    CLogger::log('API Response NOT successful', [
                        'status'   => $res['status'],
                        'response' => $res['decoded'],
                    ]);
                }
            } catch (\Throwable $e) {
                CLogger::log('Request Exception', $e->getMessage());
                continue;
            }

            CLogger::log('--- ITEM END ---', $item_id);
        }

        update_post_meta($order_id, self::META_SYNC_FLAG, 'yes');
        CLogger::log('--- END PROCESS ---', ['order_id' => $order_id]);
    }
}
