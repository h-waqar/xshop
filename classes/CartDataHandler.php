<?php
// classes/CartDataHandler.php

namespace classes;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/CLogger.php';

use classes\CLogger;

class CartDataHandler
{
    public static function add_data_to_cart($cart_item_data, $product_id, $variation_id)
    {
        $xshop_json_raw = get_post_meta($product_id, 'xshop_json', true);
        $product_info = null;
        $selected_sku_data = null;
        $sku_prices = [];

        if (!empty($xshop_json_raw)) {
            $decoded = json_decode($xshop_json_raw, true);

            // --- 1) Product info ---
            if (!empty($decoded['product'])) {
                $product = $decoded['product'];
                $product_info = [
                    'name' => $product['name'] ?? null,
                    'apiPath' => $product['apiPath'] ?? null,
                    'type' => $product['type'] ?? null,
                    'subtype' => $product['subtype'] ?? null,
                    'haveRole' => $product['haveRole'] ?? null,
                    'haveVerify' => $product['haveVerify'] ?? null,
                    'supportedCountries' => $product['supportedCountries'] ?? [],
                ];
            }

            // --- 2) Selected SKU ---
            if (!empty($_POST['attribute_pa_xshop']) && !empty($decoded['skus'])) {
                $normalize = fn($str) => strtolower(preg_replace('/[\s\-_]+/', '', $str));
                $selected_variation = $normalize($_POST['attribute_pa_xshop']);

                foreach ($decoded['skus'] as $sku) {
                    $sku_desc = $normalize($sku['description'] ?? '');

                    if ($sku_desc === $selected_variation) {
                        $selected_sku_data = [
                            'sku' => $sku['sku'] ?? null,
                            'description' => $sku['description'] ?? null,
                            'price' => $sku['price'] ?? null,
                            'currency' => $sku['currency'] ?? null,
                            'originalPrice' => $sku['originalPrice'] ?: null,
                            'originalCurrency' => $sku['originalCurrency'] ?: null,
                            'retailPrice' => $sku['retailPrice'] ?? null,
                            'retailCurrency' => $sku['retailCurrency'] ?? null,
                            'countryCode' => $sku['countryCode'] ?? null,
                            'origin' => $sku['origin'] ?? null,
                            'selected_server' => $_POST['server'] ?? null,
                            'selected_server_id' => isset($_POST['server_id']) ? intval($_POST['server_id']) : null,
                        ];
                        break;
                    }
                }
            }

            // --- 3) skuPrices ---
            $sku_prices = $decoded['skuPrices'] ?? [];
        }

        // Attach structured data
        if ($product_info) {
            $cart_item_data['xshop_product'] = $product_info;
        }
        if ($selected_sku_data) {
            $cart_item_data['xshop_selected_sku'] = $selected_sku_data;
        }
        if (!empty($sku_prices)) {
            $cart_item_data['xshop_skuPrices'] = $sku_prices;
        }

        // Keep raw JSON for legacy/debug
        if (!empty($xshop_json_raw)) {
            $cart_item_data['xshop_json'] = $xshop_json_raw;
        }

        // Keep raw POST for debugging
        if (!empty($_POST)) {
            $cart_item_data['_POST'] = $_POST;
        }

        return $cart_item_data;
    }

    public static function append_item_meta($item, $cart_item_key, $values, $order)
    {
        if (isset($values['xshop_product'])) {
            $item->add_meta_data('xshop_product', $values['xshop_product'], true);
        }
        if (isset($values['xshop_selected_sku'])) {
            $item->add_meta_data('xshop_selected_sku', $values['xshop_selected_sku'], true);
        }
        if (isset($values['xshop_skuPrices'])) {
            $item->add_meta_data('xshop_skuPrices', $values['xshop_skuPrices'], true);
        }
        if (isset($values['xshop_json'])) {
            $item->add_meta_data('xshop_json', $values['xshop_json'], true);
        }
    }
}
