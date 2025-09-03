<?php

//  classes/Cubixsol_Woo_Order.php:3

namespace classes;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/OrderProcessor.php';
include_once PLUGIN_DIR_PATH . 'classes/CLogger.php';

use classes\CLogger;

class Cubixsol_Woo_Order
{
    private static ?Cubixsol_Woo_Order $instance;

    public function __construct()
    {
//        add_action(
//            'woocommerce_thankyou',
//            [$this, 'cubixsol_place_woo_order']
//        );
        add_action('woocommerce_payment_complete', [$this,
            'cubixsol_place_woo_order']);
        add_action('woocommerce_order_status_completed', [$this,
            'cubixsol_place_woo_order']);
        add_action('woocommerce_order_status_processing', [$this,
            'cubixsol_place_woo_order']);

        add_filter('woocommerce_add_cart_item_data', [$this,
            'add_data_to_cart'], 10, 3);
        add_action('woocommerce_checkout_create_order_line_item', [$this,
            'append_item_meta'], 10, 4);
    }

    public static function instance(): Cubixsol_Woo_Order
    {
        return self::$instance ?? self::$instance = new self();
    }

    public function cubixsol_place_woo_order($order_id)
    {
        if (!$order_id) {
            return;
        }

        // Prevent duplicate execution
        if (get_post_meta($order_id, OrderProcessor::META_SYNC_FLAG, true) === 'yes') {
            CLogger::log('Order already processed, skipping', $order_id);
            return;
        }

        CLogger::log('Run process method', $order_id);
        (new OrderProcessor())->process($order_id);
    }

    public function add_data_to_cart($cart_item_data, $product_id, $variation_id)
    {
        $xshop_json_raw = get_post_meta($product_id, 'xshop_json', true);
        $selected_sku_data = null;
        $product_info = null;

        if (!empty($xshop_json_raw)) {
            $decoded = json_decode($xshop_json_raw, true);

            // --- 1) Product info ---
            if (!empty($decoded['product'])) {
                $product = $decoded['product'];
                $product_info = ['name' => $product['name'] ?? null,
                    'apiPath' => $product['apiPath'] ?? null,
                    'type' => $product['type'] ?? null,
                    'subtype' => $product['subtype'] ?? null,
                    'haveRole' => $product['haveRole'] ?? null,
                    'haveVerify' => $product['haveVerify'] ?? null,
                    'supportedCountries' => $product['supportedCountries'] ?? [],];
            }

            // --- 2) Find selected SKU ---
            if (!empty($_POST['attribute_pa_xshop']) && !empty($decoded['skus'])) {
                // Normalizer: remove spaces, dashes, underscores, lowercase
                $normalize = fn($str) => strtolower(preg_replace('/[\s\-_]+/', '', $str));

                $selected_variation = $normalize($_POST['attribute_pa_xshop']);

                foreach ($decoded['skus'] as $sku) {
                    $sku_desc = $normalize($sku['description'] ?? '');

                    if ($sku_desc === $selected_variation) {
                        $selected_sku_data = ['sku' => $sku['sku'] ?? null,
                            'description' => $sku['description'] ?? null,
                            'price' => $sku['price'] ?? null,
                            'currency' => $sku['currency'] ?? null,
                            'originalPrice' => $sku['originalPrice'] ?: null,
                            'originalCurrency' => $sku['originalCurrency'] ?: null,
                            'retailPrice' => $sku['retailPrice'] ?? null,
                            'retailCurrency' => $sku['retailCurrency'] ?? null,
                            'countryCode' => $sku['countryCode'] ?? null,
                            'origin' => $sku['origin'] ?? null,
                            'selected_server' => isset($_POST['server']) ? sanitize_text_field($_POST['server']) : null,
                            'selected_server_id' => isset($_POST['server_id']) ? intval($_POST['server_id']) : null,];
                        break;
                    }
                }
            }


            // --- 3) skuPrices (if any) ---
            $sku_prices = !empty($decoded['skuPrices']) ? $decoded['skuPrices'] : [];
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

    public function append_item_meta($item, $cart_item_key, $values, $order)
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