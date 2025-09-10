<?php
//// classes/Ajax/TopupAjax.php
//
//namespace classes\ajax;
//
//defined('ABSPATH') || exit;
//
//include_once PLUGIN_DIR_PATH . 'classes/CLogger.php';
//include_once PLUGIN_DIR_PATH . 'classes/Handlers/TopupHandler.php';
//include_once PLUGIN_DIR_PATH . 'classes/XshopApiClient.php';
//
//use classes\CLogger;
//use classes\Handlers\TopupHandler;
//use classes\XshopApiClient;
//use Throwable;
//
//class TopupAjax
//{
//    public static function init(): void
//    {
//        add_action('wp_ajax_xshop_validate', [__CLASS__, 'handle_validate']);
//        add_action('wp_ajax_nopriv_xshop_validate', [__CLASS__, 'handle_validate']);
//
//        add_action('wp_ajax_xshop_add_to_cart', [__CLASS__, 'handle_add_to_cart']);
//        add_action('wp_ajax_nopriv_xshop_add_to_cart', [__CLASS__, 'handle_add_to_cart']);
//    }
//
//    public static function handle_validate(): void
//    {
//        CLogger::log('AJAX validate - raw $_POST', $_POST);
//
//        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'xshop-validate')) {
//            CLogger::log('AJAX validate - nonce failed', $_POST['nonce'] ?? null);
//            wp_send_json_error(['message' => 'Invalid nonce'], 403);
//        }
//
//        $product_id = absint($_POST['product_id'] ?? 0);
//        if (!$product_id) {
//            CLogger::log('AJAX validate - missing product_id');
//            wp_send_json_error(['message' => 'Missing product_id']);
//        }
//
//        $variation_id = absint($_POST['variation_id'] ?? 0);
//        $quantity = absint($_POST['quantity'] ?? 1);
//
//        $form_fields = [];
//        if (!empty($_POST['form_data'])) {
//            parse_str(wp_unslash($_POST['form_data']), $form_fields);
//        }
//        CLogger::log('AJAX validate - parsed form_fields', $form_fields);
//
//        $userAccount = $form_fields['user_account'] ?? '11460050';
//
//        $xshop_json_raw = get_post_meta($product_id, 'xshop_json', true);
//        $xshop_json = is_string($xshop_json_raw) ? json_decode($xshop_json_raw, true) : $xshop_json_raw;
//        CLogger::log('AJAX validate - loaded xshop_json', $xshop_json);
//
//        if (!$xshop_json || empty($xshop_json['skus'])) {
//            CLogger::log('AJAX validate - invalid xshop_json');
//            wp_send_json_error(['message' => 'Invalid xshop_json for product']);
//        }
//
//        // Resolve SKU
//        $sku = '';
//
//        if (!empty($form_fields['sku'])) {
//            // If SKU explicitly posted
//            $sku = sanitize_text_field($form_fields['sku']);
//        } elseif (!empty($form_fields['attribute_pa_xshop'])) {
//            // Map attribute slug (e.g. "growth-bundle") to real xShop SKU
//            $attr_value = sanitize_title($form_fields['attribute_pa_xshop']);
//
//            foreach ($xshop_json['skus'] as $entry) {
//                $slug = sanitize_title($entry['description']); // "Growth Bundle" → "growth-bundle"
//                if ($slug === $attr_value) {
//                    $sku = $entry['sku'];
//                    break;
//                }
//            }
//        } elseif ($variation_id) {
//            // Optional: look for SKU saved in variation meta
//            $maybe_sku = get_post_meta($variation_id, 'xshop_sku', true);
//            if ($maybe_sku) {
//                $sku = $maybe_sku;
//            }
//        }
//
//        // Fallback: first SKU
//        if (!$sku && !empty($xshop_json['skus'][0]['sku'])) {
//            $sku = $xshop_json['skus'][0]['sku'];
//        }
//
//        CLogger::log('AJAX validate - final resolved SKU', $sku);
//
//        $sku_data = null;
//        foreach ($xshop_json['skus'] as $entry) {
//            if ($entry['sku'] === $sku) {
//                $sku_data = $entry;
//                break;
//            }
//        }
//
//        if (!$sku_data) {
//            CLogger::log('AJAX validate - could not resolve SKU', $sku);
//            wp_send_json_error(['message' => 'Could not resolve SKU']);
//        }
//
//        $base = ['sku' => $sku, 'price' => (float)($sku_data['price'] ?? 0.0), 'quantity' => $quantity, 'sku_data' => $sku_data, 'product' => $xshop_json['product'] ?? [], 'customerId' => is_user_logged_in() ? wp_get_current_user()->user_email : '',];
//        CLogger::log('AJAX validate - base payload', $base);
//
//        $handler = new TopupHandler();
//
//        try {
//            $payload = $handler->build_validate_payload($base, $xshop_json, $userAccount);
//            $endpoint = $handler->get_endpoint($xshop_json, $sku);
//            $apiPath = ltrim(parse_url($endpoint, PHP_URL_PATH) ?: '', '/');
//
//            CLogger::log('AJAX validate - final payload', $payload);
//            CLogger::log('AJAX validate - endpoint', $endpoint);
//
//            $res = XshopApiClient::request($apiPath, $payload, 'POST');
//            CLogger::log('AJAX validate - API response', $res);
//
//            if ($res['success'] && isset($res['decoded']['result'])) {
//                wp_send_json_success(['result' => $res['decoded']['result'], 'raw' => $res]);
//            } else {
//                wp_send_json_error(['message' => 'Validation failed', 'status' => $res['status'], 'decoded' => $res['decoded'],]);
//            }
//        } catch (Throwable $e) {
//            CLogger::log('AJAX validate exception', $e->getMessage());
//            wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
//        }
//    }
//
//    public static function handle_add_to_cart(): void
//    {
//        CLogger::log('AJAX add_to_cart - raw $_POST', $_POST);
//
//        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'xshop-validate')) {
//            CLogger::log('AJAX add_to_cart - nonce failed', $_POST['nonce'] ?? null);
//            wp_send_json_error(['message' => 'Invalid nonce'], 403);
//        }
//
//        $product_id = absint($_POST['product_id'] ?? 0);
//        $variation_id = absint($_POST['variation_id'] ?? 0);
//        $sku = sanitize_text_field($_POST['sku'] ?? '');
//        $validate_result_raw = $_POST['validate_result'] ?? null;
//
//        if (!$product_id || !$validate_result_raw) {
//            CLogger::log('AJAX add_to_cart - missing product_id or validate_result');
//            wp_send_json_error(['message' => 'Missing required data']);
//        }
//
//        $validate_result = is_string($validate_result_raw) ? json_decode(wp_unslash($validate_result_raw), true) : $validate_result_raw;
//        CLogger::log('AJAX add_to_cart - decoded validate_result', $validate_result);
//
//        if (!is_array($validate_result)) {
//            CLogger::log('AJAX add_to_cart - invalid validate_result');
//            wp_send_json_error(['message' => 'Invalid validate_result']);
//        }
//
//        $xshop_json_raw = get_post_meta($product_id, 'xshop_json', true);
//        $xshop_json = is_string($xshop_json_raw) ? json_decode($xshop_json_raw, true) : $xshop_json_raw;
//        CLogger::log('AJAX add_to_cart - loaded xshop_json', $xshop_json);
//
//        $cart_item_data = ['xshop_product' => $xshop_json['product'] ?? [], 'xshop_selected_sku' => ['sku' => $sku, 'description' => $validate_result['message']['sku']['description'] ?? '', 'price' => $validate_result['message']['sku']['price']['amount'] ?? null, 'currency' => $validate_result['message']['sku']['price']['currency'] ?? null,], 'xshop_validate' => $validate_result, 'xshop_userAccount' => $validate_result['message']['userAccount'] ?? '',];
//        CLogger::log('AJAX add_to_cart - cart_item_data', $cart_item_data);
//
//        if (!function_exists('WC')) {
//            CLogger::log('AJAX add_to_cart - WooCommerce not available');
//            wp_send_json_error(['message' => 'WooCommerce not available']);
//        }
//
//        try {
//            $added = WC()->cart->add_to_cart($product_id, 1, $variation_id ?: 0, [], $cart_item_data);
//            CLogger::log('AJAX add_to_cart - add_to_cart result', $added);
//
//            if (!$added) {
//                wp_send_json_error(['message' => 'Failed to add to cart']);
//            }
//
//            wp_send_json_success(['redirect' => wc_get_checkout_url()]);
//        } catch (Throwable $e) {
//            CLogger::log('AJAX add_to_cart exception', $e->getMessage());
//            wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
//        }
//    }
//}


// classes/Ajax/TopupAjax.php

namespace classes\ajax;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/CLogger.php';
include_once PLUGIN_DIR_PATH . 'classes/Handlers/TopupHandler.php';
include_once PLUGIN_DIR_PATH . 'classes/XshopApiClient.php';

use classes\CLogger;
use classes\Handlers\TopupHandler;
use classes\XshopApiClient;
use Throwable;

/**
 * TopupAjax
 *
 * Handles xshop_validate and xshop_add_to_cart AJAX endpoints.
 * Adds robust resolution for WCPA / custom product addon fields that use hashed/random keys.
 */
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
     * AJAX: validate payload with xShop API
     */
    public static function handle_validate(): void
    {
        CLogger::log('AJAX validate - raw $_POST', $_POST);

        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'xshop-validate')) {
            CLogger::log('AJAX validate - nonce failed', $_POST['nonce'] ?? null);
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        if (!$product_id) {
            CLogger::log('AJAX validate - missing product_id');
            wp_send_json_error(['message' => 'Missing product_id']);
        }

        $variation_id = absint($_POST['variation_id'] ?? 0);
        $quantity = absint($_POST['quantity'] ?? 1);

        // parse serialized form_data from the client (your JS sends full form serialization)
        $form_fields = [];
        if (!empty($_POST['form_data'])) {
            parse_str(wp_unslash($_POST['form_data']), $form_fields);
        }
        CLogger::log('AJAX validate - parsed form_fields', $form_fields);

        // Resolve WCPA/custom addon fields to [ label => value ] if possible
        $resolved = self::resolve_wcpa_fields($product_id, $form_fields);
        CLogger::log('AJAX validate - resolved wcpa fields', $resolved);

        /**
         * Determine user account value.
         * Priority:
         * 1) Specific known keys in $form_fields (if your templates add a stable name)
         * 2) Resolved labels mapped from WCPA (search common label names)
         * 3) Fallback: try a best-effort guess (first numeric-looking field)
         * 4) Hard fallback default you had previously
         */
        $userAccount = strval(self::find_user_account_from_fields($form_fields, $resolved) ?? '007');

        CLogger::log('AJAX validate - userAccount chosen', $userAccount);

        $xshop_json_raw = get_post_meta($product_id, 'xshop_json', true);
        $xshop_json = is_string($xshop_json_raw) ? json_decode($xshop_json_raw, true) : $xshop_json_raw;
//        CLogger::log('AJAX validate - loaded xshop_json', $xshop_json);

        if (!$xshop_json || empty($xshop_json['skus'])) {
            CLogger::log('AJAX validate - invalid xshop_json');
            wp_send_json_error(['message' => 'Invalid xshop_json for product']);
        }

        // Resolve SKU (keeps your existing logic)
        $sku = '';

        if (!empty($form_fields['sku'])) {
            $sku = sanitize_text_field($form_fields['sku']);
        } elseif (!empty($form_fields['attribute_pa_xshop'])) {
            $attr_value = sanitize_title($form_fields['attribute_pa_xshop']);
            foreach ($xshop_json['skus'] as $entry) {
                $slug = sanitize_title($entry['description'] ?? '');
                if ($slug === $attr_value) {
                    $sku = $entry['sku'];
                    break;
                }
            }
        } elseif ($variation_id) {
            $maybe_sku = get_post_meta($variation_id, 'xshop_sku', true);
            if ($maybe_sku) {
                $sku = $maybe_sku;
            }
        }

        if (!$sku && !empty($xshop_json['skus'][0]['sku'])) {
            $sku = $xshop_json['skus'][0]['sku'];
        }

        CLogger::log('AJAX validate - final resolved SKU', $sku);

        $sku_data = null;
        foreach ($xshop_json['skus'] as $entry) {
            if ($entry['sku'] === $sku) {
                $sku_data = $entry;
                break;
            }
        }

        if (!$sku_data) {
            CLogger::log('AJAX validate - could not resolve SKU', $sku);
            wp_send_json_error(['message' => 'Could not resolve SKU']);
        }

        $base = [
            'sku' => $sku,
            'price' => (float)($sku_data['price'] ?? 0.0),
            'quantity' => $quantity,
            'sku_data' => $sku_data,
            'product' => $xshop_json['product'] ?? [],
            'customerId' => is_user_logged_in() ? wp_get_current_user()->user_email : '',
        ];
//        CLogger::log('AJAX validate - base payload', $base);

        $handler = new TopupHandler();

        try {
            $payload = $handler->build_validate_payload($base, $xshop_json, $userAccount);
            $endpoint = $handler->get_endpoint($xshop_json, $sku);
            $apiPath = ltrim(parse_url($endpoint, PHP_URL_PATH) ?: '', '/');

            CLogger::log('AJAX validate - final payload', $payload);
            CLogger::log('AJAX validate - endpoint', $endpoint);

            $res = XshopApiClient::request($apiPath, $payload, 'POST');
            CLogger::log('AJAX validate - API response', $res);

            if ($res['success'] && isset($res['decoded']['result'])) {
                // include resolved fields in the result as meta (useful for client debug)
                $out = [
                    'result' => $res['decoded']['result'],
                    'raw' => $res,
                    'resolved_fields' => $resolved,
                ];
                wp_send_json_success($out);
            } else {
                wp_send_json_error(['message' => 'Validation failed', 'status' => $res['status'], 'decoded' => $res['decoded']]);
            }
        } catch (Throwable $e) {
            CLogger::log('AJAX validate exception', $e->getMessage());
            wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
        }
    }

    /**
     * AJAX: add to cart after validation
     */
    public static function handle_add_to_cart(): void
    {
        CLogger::log('AJAX add_to_cart - raw $_POST', $_POST);

        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'xshop-validate')) {
            CLogger::log('AJAX add_to_cart - nonce failed', $_POST['nonce'] ?? null);
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $variation_id = absint($_POST['variation_id'] ?? 0);
        $sku = sanitize_text_field($_POST['sku'] ?? '');
        $validate_result_raw = $_POST['validate_result'] ?? null;

        if (!$product_id || !$validate_result_raw) {
            CLogger::log('AJAX add_to_cart - missing product_id or validate_result');
            wp_send_json_error(['message' => 'Missing required data']);
        }

        $validate_result = is_string($validate_result_raw) ? json_decode(wp_unslash($validate_result_raw), true) : $validate_result_raw;
        CLogger::log('AJAX add_to_cart - decoded validate_result', $validate_result);

        if (!is_array($validate_result)) {
            CLogger::log('AJAX add_to_cart - invalid validate_result');
            wp_send_json_error(['message' => 'Invalid validate_result']);
        }

        $xshop_json_raw = get_post_meta($product_id, 'xshop_json', true);
        $xshop_json = is_string($xshop_json_raw) ? json_decode($xshop_json_raw, true) : $xshop_json_raw;
        CLogger::log('AJAX add_to_cart - loaded xshop_json', $xshop_json);

        // Attempt to fetch user account from validate_result first (preferred)
        $userAccountFromValidate = $validate_result['message']['userAccount'] ?? null;

        // Fallback: if client sent form_data, try to parse and resolve again (helpful for edge cases)
        $form_fields = [];
        if (!empty($_POST['form_data'])) {
            parse_str(wp_unslash($_POST['form_data']), $form_fields);
        }
        $resolved = self::resolve_wcpa_fields($product_id, $form_fields);
        CLogger::log('AJAX add_to_cart - resolved wcpa fields (add_to_cart)', $resolved);

        $cart_item_data = [
            'xshop_product' => $xshop_json['product'] ?? [],
            'xshop_selected_sku' => [
                'sku' => $sku,
                'description' => $validate_result['message']['sku']['description'] ?? '',
                'price' => $validate_result['message']['sku']['price']['amount'] ?? null,
                'currency' => $validate_result['message']['sku']['price']['currency'] ?? null,
            ],
            'xshop_validate' => $validate_result,
            'xshop_userAccount' => $userAccountFromValidate ?? ($resolved['Account Number'] ?? $resolved['Account'] ?? $resolved['user_account'] ?? ''),
        ];
        CLogger::log('AJAX add_to_cart - cart_item_data', $cart_item_data);

        if (!function_exists('WC')) {
            CLogger::log('AJAX add_to_cart - WooCommerce not available');
            wp_send_json_error(['message' => 'WooCommerce not available']);
        }

        try {
            $added = WC()->cart->add_to_cart($product_id, 1, $variation_id ?: 0, [], $cart_item_data);
            CLogger::log('AJAX add_to_cart - add_to_cart result', $added);

            if (!$added) {
                wp_send_json_error(['message' => 'Failed to add to cart']);
            }

            wp_send_json_success(['redirect' => wc_get_checkout_url()]);
        } catch (Throwable $e) {
            CLogger::log('AJAX add_to_cart exception', $e->getMessage());
            wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
        }
    }

    /**
     * Try to resolve WCPA / product-addons fields for $product_id using:
     *  - the posted $form_fields (hashed names)
     *  - product meta entries that many addons plugins use (various keys attempted)
     *
     * Returns an associative array: [ label_or_key => value ]
     */
    private static function resolve_wcpa_fields(int $product_id, array $form_fields): array
    {
        $result = [];

        // Early exit if no form data
        if (empty($form_fields) || !is_array($form_fields)) {
            return $result;
        }

        // 1) If any form key is already semantic (like 'user_account'), include it directly
        foreach ($form_fields as $k => $v) {
            if (in_array($k, ['user_account', 'account', 'account_number', 'phone', 'msisdn', 'mobile'], true)) {
                $result[$k] = sanitize_text_field($v);
            }
        }

        // 2) Try to detect product meta that describes addon fields.
        //    Check multiple common meta keys used by different addons/plugins.
        $candidate_meta_keys = [
            '_wcpa_fields',        // hypothetical / custom
            'wcpa_fields',
            '_product_addons',     // WooCommerce Product Add-Ons (WC core or plugin)
            'product_addons',
            'woo_product_addons',
            'wc_addons',
            '_product_addons_data',
            'acfw_fields',         // just in case
            '_acf_product_fields', // just in case
            'product_addons_options',
            'addons',              // generic
        ];

        $found_fields = null;

        foreach ($candidate_meta_keys as $meta_key) {
            $raw = get_post_meta($product_id, $meta_key, true);
            if (empty($raw)) {
                continue;
            }

            // Accept several formats: JSON string, serialized array, or array
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $found_fields = $decoded;
                    break;
                }
            }
            if (is_array($raw)) {
                $found_fields = $raw;
                break;
            }

            // if it was serialized PHP (rare), try @unserialize
            if (is_string($raw)) {
                $maybe = @unserialize($raw);
                if ($maybe !== false && is_array($maybe)) {
                    $found_fields = $maybe;
                    break;
                }
            }
        }

        // 3) If we found meta describing fields, try to map hashed keys -> labels
        if (is_array($found_fields)) {
            // two common shapes:
            // A) indexed array of field definitions: [ ['field_key' => 'text_123', 'label' => 'Account'], ... ]
            // B) associative: [ 'text_123' => [ 'label' => 'Account', ... ], ... ]
            foreach ($found_fields as $k => $entry) {
                if (is_array($entry)) {
                    // case A
                    if (isset($entry['field_key']) && isset($entry['label'])) {
                        $fk = $entry['field_key'];
                        if (isset($form_fields[$fk])) {
                            $result[$entry['label']] = sanitize_text_field($form_fields[$fk]);
                        }
                    } elseif (isset($entry['name']) && isset($entry['label'])) {
                        // some plugins use name/label
                        $fk = $entry['name'];
                        if (isset($form_fields[$fk])) {
                            $result[$entry['label']] = sanitize_text_field($form_fields[$fk]);
                        }
                    } else {
                        // case where the array key is the field key
                        if (is_string($k) && isset($form_fields[$k])) {
                            $label = $entry['label'] ?? $entry['title'] ?? $k;
                            $result[$label] = sanitize_text_field($form_fields[$k]);
                        }
                    }
                } elseif (is_string($k) && isset($form_fields[$k])) {
                    // fallback: found_fields is like [ 'text_123' => 'some config string' ]
                    $result[$k] = sanitize_text_field($form_fields[$k]);
                }
            }
        }

        // 4) Generic mapping fallback: attempt to map any hashed-looking keys to friendly labels
        //    Use behaviours: if field name starts with known prefixes, create a readable label.
        foreach ($form_fields as $key => $value) {
            if (isset($result[$key]) || $value === '') {
                // already mapped or empty => skip
                continue;
            }

            // skip standard WC fields we don't care about
            if (in_array($key, ['add-to-cart', 'product_id', 'variation_id', 'quantity', 'alt_s', 'attribute_pa_xshop', 'sku'], true)) {
                continue;
            }

            // hashed key heuristics (text_, textarea_, qfngjt..., etc.)
            if (preg_match('/^(text|textarea|select|radio|qf|field|custom|input)[_\-]*/i', $key) || preg_match('/^\w+_\d+$/', $key)) {
                // create label from key: "text_3422827131" -> "text 3422827131"
                $label = str_replace(['_', '-'], ' ', $key);
                $label = ucwords($label);
                $result[$label] = sanitize_text_field($value);
                continue;
            }

            // fallback: if key looks semantic already, include as-is
            $result[$key] = sanitize_text_field($value);
        }

        return $result;
    }

    /**
     * Attempt to find the "user account" value from posted and resolved fields.
     * Returns string or null.
     */
    private static function find_user_account_from_fields(array $form_fields, array $resolved_fields): ?string
    {
        // 1) Check explicit, stable keys in posted form fields
        $stable_keys = ['user_account', 'account', 'account_number', 'msisdn', 'mobile', 'phone', 'subscriber'];
        foreach ($stable_keys as $k) {
            if (!empty($form_fields[$k])) {
                return sanitize_text_field($form_fields[$k]);
            }
        }

        // 2) Look in resolved labels for common label names (case-insensitive)
        $label_candidates = ['account number', 'account', 'user account', 'msisdn', 'mobile', 'phone', 'subscriber id', 'subscriber'];
        foreach ($resolved_fields as $label => $value) {
            $label_norm = strtolower(trim($label));
            foreach ($label_candidates as $cand) {
                if (strpos($label_norm, $cand) !== false && $value !== '') {
                    return sanitize_text_field($value);
                }
            }
        }

        // 3) Best-effort: return first numeric-looking resolved field (many account numbers are numeric)
        foreach ($resolved_fields as $label => $value) {
            if (preg_match('/^\d{5,}$/', preg_replace('/\D+/', '', $value))) {
                return sanitize_text_field($value);
            }
        }

        // 4) also check any posted keys that contain "text_" or hashed pattern and appear numeric
        foreach ($form_fields as $k => $v) {
            if (preg_match('/^(text|qf|field|input)_?\d+$/i', $k) && preg_match('/^\d{5,}$/', preg_replace('/\D+/', '', $v))) {
                return sanitize_text_field($v);
            }
        }

        return null;
    }
}
