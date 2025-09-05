<?php
// classes/Ajax/TopupAjax.php

namespace classes\ajax;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/CLogger.php';
include_once PLUGIN_DIR_PATH . 'classes/Handlers/TopupHandler.php';
include_once PLUGIN_DIR_PATH . 'classes/XshopApiClient.php';

use classes\CLogger;
use classes\Handlers\TopupHandler;
use classes\XshopApiClient;

class TopupAjax
{
    public static function init(): void
    {
        add_action('wp_ajax_xshop_validate', [__CLASS__, 'handle_validate']);
        add_action('wp_ajax_nopriv_xshop_validate', [__CLASS__, 'handle_validate']);

        add_action('wp_ajax_xshop_add_to_cart', [__CLASS__, 'handle_add_to_cart']);
        add_action('wp_ajax_nopriv_xshop_add_to_cart', [__CLASS__, 'handle_add_to_cart']);
    }

    /**
     * Handle validate AJAX call.
     */
    public static function handle_validate(): void
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'xshop-validate')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        if (!$product_id) {
            wp_send_json_error(['message' => 'Missing product_id']);
        }

        $sku             = sanitize_text_field($_POST['sku'] ?? '');
        $price           = isset($_POST['price']) ? floatval($_POST['price']) : 0.0;
        $currency        = sanitize_text_field($_POST['currency'] ?? '');
        $userAccount_raw = $_POST['userAccount'] ?? null;

        // userAccount may be JSON
        if (is_string($userAccount_raw)) {
            $maybe = json_decode(stripslashes($userAccount_raw), true);
            $userAccount = $maybe !== null ? $maybe : sanitize_text_field($userAccount_raw);
        } else {
            $userAccount = $userAccount_raw;
        }

        $xshop_json_raw = get_post_meta($product_id, 'xshop_json', true);

        $base = [
            'sku'       => $sku,
            'price'     => $price,
            'quantity'  => 1,
            'sku_data'  => [
                'currency'    => $currency,
                'description' => sanitize_text_field($_POST['description'] ?? ''),
            ],
            'product'    => $xshop_json_raw ? (json_decode($xshop_json_raw, true)['product'] ?? []) : [],
            'customerId' => is_user_logged_in() ? wp_get_current_user()->user_email : sanitize_text_field($_POST['customerId'] ?? ''),
        ];

        $handler = new TopupHandler();

        try {
            $payload   = $handler->build_validate_payload($base, $xshop_json_raw, $userAccount);
            $endpoint  = $handler->get_endpoint($xshop_json_raw, $sku);
            $apiPath   = ltrim(parse_url($endpoint, PHP_URL_PATH) ?: '', '/');

            $res = XshopApiClient::request($apiPath, $payload, 'POST');

            if ($res['success'] && isset($res['decoded']['result'])) {
                wp_send_json_success(['result' => $res['decoded']['result'], 'raw' => $res]);
            } else {
                wp_send_json_error([
                    'message' => 'Validation failed',
                    'status'  => $res['status'],
                    'decoded' => $res['decoded'],
                ]);
            }
        } catch (\Throwable $e) {
            CLogger::log('AJAX validate exception', $e->getMessage());
            wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
        }
    }

    /**
     * Handle add_to_cart AJAX.
     */
    public static function handle_add_to_cart(): void
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'xshop-validate')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $product_id         = absint($_POST['product_id'] ?? 0);
        $variation_id       = absint($_POST['variation_id'] ?? 0);
        $sku                = sanitize_text_field($_POST['sku'] ?? '');
        $validate_result_raw = $_POST['validate_result'] ?? null;

        if (!$product_id || !$validate_result_raw) {
            wp_send_json_error(['message' => 'Missing required data']);
        }

        $validate_result = is_string($validate_result_raw) ? json_decode(wp_unslash($validate_result_raw), true) : $validate_result_raw;
        if (!is_array($validate_result)) {
            wp_send_json_error(['message' => 'Invalid validate_result']);
        }

        $cart_item_data = [
            'xshop_product'      => json_decode(get_post_meta($product_id, 'xshop_json', true), true)['product'] ?? [],
            'xshop_selected_sku' => [
                'sku'        => $sku,
                'description'=> $validate_result['message']['sku']['description'] ?? '',
                'price'      => $validate_result['message']['sku']['price']['amount'] ?? null,
                'currency'   => $validate_result['message']['sku']['price']['currency'] ?? null,
            ],
            'xshop_validate' => $validate_result,
        ];

        if (!function_exists('WC')) {
            wp_send_json_error(['message' => 'WooCommerce not available']);
        }

        try {
            $added = WC()->cart->add_to_cart($product_id, 1, $variation_id ?: 0, [], $cart_item_data);
            if (!$added) {
                wp_send_json_error(['message' => 'Failed to add to cart']);
            }

            wp_send_json_success(['redirect' => wc_get_checkout_url()]);
        } catch (\Throwable $e) {
            CLogger::log('AJAX add_to_cart exception', $e->getMessage());
            wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
        }
    }
}
