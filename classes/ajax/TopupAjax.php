<?php

// classes\ajax\TopupAjax.php

namespace classes\ajax;

include_once PLUGIN_DIR_PATH . 'classes/CLogger.php';
include_once PLUGIN_DIR_PATH . 'classes/Handlers/TopupHandler.php';

use classes\CLogger;
use classes\Handlers\TopupHandler;

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
     * Expects: nonce, product_id, sku, price, currency (optional), userAccount (string or json), server_id/name (optional)
     */
    public static function handle_validate(): void
    {
        // Nonce check
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'xshop-validate')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error(['message' => 'Missing product_id']);
        }

        // get posted values (sanitize as appropriate)
        $sku = sanitize_text_field($_POST['sku'] ?? '');
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0.0;
        $currency = sanitize_text_field($_POST['currency'] ?? '');
        $userAccount_raw = $_POST['userAccount'] ?? null;
        $userAccount = null;

        // userAccount may be JSON encoded object in request; attempt decode or keep as string
        if (is_string($userAccount_raw)) {
            $maybe = json_decode(stripslashes($userAccount_raw), true);
            $userAccount = $maybe !== null ? $maybe : sanitize_text_field($userAccount_raw);
        } else {
            $userAccount = $userAccount_raw;
        }

        // get product xshop_json
        $xshop_json_raw = get_post_meta($product_id, 'xshop_json', true);

        $base = [
            'sku' => $sku,
            'price' => $price,
            'quantity' => 1,
            'sku_data' => [
                'currency' => $currency,
                'description' => sanitize_text_field($_POST['description'] ?? ''),
            ],
            'product' => $xshop_json_raw ? json_decode($xshop_json_raw, true)['product'] ?? [] : [],
            'customerId' => is_user_logged_in() ? wp_get_current_user()->user_email : sanitize_text_field($_POST['customerId'] ?? ''),
        ];

        $handler = new TopupHandler();

        try {
            $payload = $handler->build_validate_payload($base, $xshop_json_raw, $userAccount);
            // resolve endpoint
            $endpoint_full = $handler->get_endpoint($xshop_json_raw, $sku);
            // path only if your wrapper expects path, but xshop_api_request_curl in your code accepts path. To be robust we'll pass full URL if wrapper accepts it.
            // If xshop_api_request_curl expects path string (e.g. 'pubg-mobile-cross-border'), adapt below.
            $apiPath = ltrim(parse_url($endpoint_full, PHP_URL_PATH) ?: '', '/');

            CLogger::log('AJAX validate - sending payload', ['product_id' => $product_id, 'payload' => $payload]);

            $res = xshop_api_request_curl($apiPath, $payload, 'POST');

            // normalize
            $http_status = $res['status'] ?? ($res['response']['code'] ?? 0);
            $decoded = $res['json'] ?? (isset($res['body']) ? json_decode($res['body'], true) : null);

            if (in_array((int)$http_status, [200, 201], true) && is_array($decoded) && isset($decoded['result'])) {
                // return the result to frontend (validate result contains orderId, message.username, roles etc.)
                wp_send_json_success(['result' => $decoded['result'], 'raw' => $res]);
            } else {
                CLogger::log('AJAX validate failed', ['status' => $http_status, 'decoded' => $decoded]);
                wp_send_json_error(['message' => 'Validation failed', 'status' => $http_status, 'decoded' => $decoded]);
            }
        } catch (\Throwable $e) {
            CLogger::log('AJAX validate exception', $e->getMessage());
            wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
        }
    }

    /**
     * Adds validated item to cart.
     * Expects: nonce, product_id, sku, validate_result (JSON), variation_id (optional)
     * We persist validate payload/response inside cart item via provided cart_item_data.
     */
    public static function handle_add_to_cart(): void
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'xshop-validate')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
        $sku = sanitize_text_field($_POST['sku'] ?? '');
        $validate_result_raw = $_POST['validate_result'] ?? null;

        if (!$product_id || !$validate_result_raw) {
            wp_send_json_error(['message' => 'Missing required data']);
        }

        $validate_result = is_string($validate_result_raw) ? json_decode(wp_unslash($validate_result_raw), true) : $validate_result_raw;
        if (!is_array($validate_result)) {
            wp_send_json_error(['message' => 'Invalid validate_result']);
        }

        // Build cart item meta we want persisted
        $cart_item_data = [
            'xshop_product' => json_decode(get_post_meta($product_id, 'xshop_json', true), true)['product'] ?? [],
            'xshop_selected_sku' => [
                'sku' => $sku,
                'description' => $validate_result['message']['sku']['description'] ?? '',
                'price' => $validate_result['message']['sku']['price']['amount'] ?? null,
                'currency' => $validate_result['message']['sku']['price']['currency'] ?? null,
            ],
            // store raw validate result for later topup during order processing
            'xshop_validate' => $validate_result,
        ];

        // Add to cart
        if (!function_exists('WC')) {
            wp_send_json_error(['message' => 'WooCommerce not available']);
        }

        try {
            $added = WC()->cart->add_to_cart($product_id, 1, $variation_id ?: 0, [], $cart_item_data);
            if (!$added) {
                wp_send_json_error(['message' => 'Failed to add to cart']);
            }

            // return success + redirect to check out
            $checkout = wc_get_checkout_url();
            wp_send_json_success(['redirect' => $checkout]);
        } catch (\Throwable $e) {
            CLogger::log('AJAX add_to_cart exception', $e->getMessage());
            wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
        }
    }
}