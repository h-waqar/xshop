<?php
// classes/Ajax/TopupAjax.php

namespace classes\Ajax;

defined('ABSPATH') || exit;

// Include required classes
//include_once PLUGIN_DIR_PATH . 'classes/CLogger.php';
//include_once PLUGIN_DIR_PATH . 'classes/Handlers/TopupHandler.php';
//include_once PLUGIN_DIR_PATH . 'classes/XshopApiClient.php';

use classes\CLogger;
use classes\Handlers\TopupHandler;
use classes\XshopApiClient;
use Throwable;

/**
 * TopupAjax
 *
 * Handles AJAX requests for xshop_validate and xshop_add_to_cart.
 * This class deals with validating product details with xShop API
 * and adding validated products to WooCommerce cart.
 */
class TopupAjax
{
    /**
     * Hook AJAX endpoints into WordPress
     */
    public static function init(): void
    {
        add_action('wp_ajax_xshop_validate', [__CLASS__, 'handle_validate']);
        add_action('wp_ajax_nopriv_xshop_validate', [__CLASS__, 'handle_validate']);

        add_action('wp_ajax_xshop_add_to_cart', [__CLASS__, 'handle_add_to_cart']);
        add_action('wp_ajax_nopriv_xshop_add_to_cart', [__CLASS__, 'handle_add_to_cart']);
    }

    /**
     * AJAX endpoint: Validate payload with xShop API
     */
    public static function handle_validate(): void
    {
        try {
            // Enable logging for debugging
            $error_logs = false;

            if ($error_logs) CLogger::log('AJAX validate - raw $_POST', $_POST);

            // Verify nonce (security check)
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'xshop-validate')) {
                if ($error_logs) CLogger::log('AJAX validate - nonce failed', $_POST['nonce'] ?? null);
                wp_send_json_error(['message' => 'Invalid nonce'], 403);
            }

            // Ensure product ID exists
            $product_id = absint($_POST['product_id'] ?? 0);
            if (!$product_id) {
                if ($error_logs) CLogger::log('AJAX validate - missing product_id');
                wp_send_json_error(['message' => 'Missing product_id']);
            }

            $variation_id = absint($_POST['variation_id'] ?? 0);
            $quantity = absint($_POST['quantity'] ?? 1);

            // Parse form data into an array
            $form_fields = [];
            if (!empty($_POST['form_data'])) {
                parse_str(wp_unslash($_POST['form_data']), $form_fields);
            }
            if ($error_logs) CLogger::log('AJAX validate - parsed form_fields', $form_fields);

            // Resolve custom addon fields (WCPA, etc.)
            $resolved = self::resolve_wcpa_fields($product_id, $form_fields);
            if ($error_logs) CLogger::log('AJAX validate - resolved wcpa fields', $resolved);

            // Handle "server" field
            $server = $_POST['server'] ?? null;
            $server_id = $server_name = null;
            if (is_array($server)) {
                $server_id = sanitize_text_field($server['id'] ?? '');
                $server_name = sanitize_text_field($server['name'] ?? '');
            }
            if ($error_logs) {
                CLogger::log('--Server Object', $server);
                CLogger::log('--Server Object Name', $server['name'] ?? null);
                CLogger::log('--Server Object Id', $server['id'] ?? null);
            }

            // Resolve user account and zone ID
            $wcpa_fields = self::find_account_fields($form_fields, $resolved);
            $userAccount = strval($wcpa_fields['userId'] ?? '007');
            $zoneId = isset($wcpa_fields['zoneId']) && $wcpa_fields['zoneId'] !== '' ? strval($wcpa_fields['zoneId']) : null;

            if ($error_logs) {
                CLogger::log('WCPA Fields', $wcpa_fields);
                CLogger::log('AJAX validate - userAccount chosen', $userAccount);
                CLogger::log('AJAX validate - zoneId chosen', $zoneId);
            }

            // Load product xShop JSON metadata
            $xshop_json_raw = get_post_meta($product_id, 'xshop_json', true);
            $xshop_json = is_string($xshop_json_raw) ? json_decode($xshop_json_raw, true) : $xshop_json_raw;

            if (!$xshop_json || empty($xshop_json['skus'])) {
                if ($error_logs) CLogger::log('AJAX validate - invalid xshop_json');
                wp_send_json_error(['message' => 'Invalid xshop_json for product']);
            }

            // Determine SKU
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
            if ($error_logs) CLogger::log('AJAX validate - final resolved SKU', $sku);

            // Match SKU with available SKU data
            $sku_data = null;
            foreach ($xshop_json['skus'] as $entry) {
                if ($entry['sku'] === $sku) {
                    $sku_data = $entry;
                    break;
                }
            }
            if (!$sku_data) {
                if ($error_logs) CLogger::log('AJAX validate - could not resolve SKU', $sku);
                wp_send_json_error(['message' => 'Could not resolve SKU']);
            }

            // Prepare base payload for API
            $base = [
                'sku' => $sku,
                'price' => (float)($sku_data['price'] ?? 0.0),
                'quantity' => $quantity,
                'sku_data' => $sku_data,
                'product' => $xshop_json['product'] ?? [],
                'customerId' => is_user_logged_in() ? wp_get_current_user()->user_email : '',
                'server_id' => $server_id ?? null,
                'server_name' => $server_name ?? null,
                'zone_id' => $zoneId != '' ? $zoneId : null,
            ];

            // Initialize handler
            $handler = new TopupHandler();

            // Build payload and call API
            $payload = $handler->build_validate_payload($base, $xshop_json, $userAccount);
            $validate_id = $payload['id'] ?? null;

            $endpoint = $handler->get_endpoint($xshop_json, $sku);
            $apiPath = ltrim(parse_url($endpoint, PHP_URL_PATH) ?: '', '/');

            $res = XshopApiClient::request($apiPath, $payload, 'POST');

            // Handle API response
            if ($res['success'] && isset($res['decoded']['result'])) {
                $result = $res['decoded']['result'];

                // Include validate_id and extracted orderId/roles in response
                $out = [
                    'result' => $result,
                    'raw' => $res,
                    'resolved_fields' => $resolved,
                    'validate_id' => $validate_id,
                    'orderId' => $result['orderId'] ?? null,
                    'roles' => $result['message']['roles'] ?? null,
                ];

                wp_send_json_success($out);
            } else {
                if ($error_logs) CLogger::log('AJAX validate - API failure', $res);
                wp_send_json_error(['message' => 'Validation failed', 'status' => $res['status'], 'decoded' => $res['decoded']]);
            }

        } catch (Throwable $e) {
            // Log exception properly
//            CLogger::log('AJAX validate exception', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
        }
    }

    /**
     * Try to resolve WCPA / product-addons fields for a product.
     *
     * This function maps hashed/random field names (like text_123) into readable
     * labels if possible, by looking up product meta or inferring from the key.
     *
     * @param int $product_id WooCommerce product ID
     * @param array $form_fields Form fields posted by the user
     * @return array   Associative array [ label => value ]
     */
    private static function resolve_wcpa_fields(int $product_id, array $form_fields): array
    {
        $result = [];

        try {
            // Early exit if no form data provided
            if (empty($form_fields) || !is_array($form_fields)) {
//                CLogger::log('resolve_wcpa_fields - no form_fields provided');
                return $result;
            }

            // 1) Directly add known/semantic keys
            foreach ($form_fields as $k => $v) {
                if (in_array($k, ['user_account', 'account', 'account_number', 'phone', 'msisdn', 'mobile'], true)) {
                    $result[$k] = sanitize_text_field($v);
                }
            }

            // 2) Check product meta for addon field definitions
            $candidate_meta_keys = ['_wcpa_fields', 'wcpa_fields', '_product_addons', 'product_addons', 'woo_product_addons', 'wc_addons', '_product_addons_data', 'acfw_fields', '_acf_product_fields', 'product_addons_options', 'addons',];

            $found_fields = null;

            foreach ($candidate_meta_keys as $meta_key) {
                $raw = get_post_meta($product_id, $meta_key, true);
                if (empty($raw)) continue;

                // JSON decode
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $found_fields = $decoded;
                        break;
                    }
                }

                // Already an array
                if (is_array($raw)) {
                    $found_fields = $raw;
                    break;
                }

                // Serialized PHP (rare)
                if (is_string($raw)) {
                    $maybe = @unserialize($raw);
                    if ($maybe !== false && is_array($maybe)) {
                        $found_fields = $maybe;
                        break;
                    }
                }
            }

            // 3) Map hashed field keys → labels using product meta
            if (is_array($found_fields)) {
                foreach ($found_fields as $k => $entry) {
                    if (is_array($entry)) {
                        // Case A: field_key + label
                        if (isset($entry['field_key'], $entry['label'])) {
                            $fk = $entry['field_key'];
                            if (isset($form_fields[$fk])) {
                                $result[$entry['label']] = sanitize_text_field($form_fields[$fk]);
                            }
                        } // Case B: name + label
                        elseif (isset($entry['name'], $entry['label'])) {
                            $fk = $entry['name'];
                            if (isset($form_fields[$fk])) {
                                $result[$entry['label']] = sanitize_text_field($form_fields[$fk]);
                            }
                        } // Case C: array key is the field key
                        elseif (is_string($k) && isset($form_fields[$k])) {
                            $label = $entry['label'] ?? $entry['title'] ?? $k;
                            $result[$label] = sanitize_text_field($form_fields[$k]);
                        }
                    } // Fallback: [ 'text_123' => 'config string' ]
                    elseif (is_string($k) && isset($form_fields[$k])) {
                        $result[$k] = sanitize_text_field($form_fields[$k]);
                    }
                }
            }

            // 4) Generic fallback: convert hashed-looking keys into readable labels
            foreach ($form_fields as $key => $value) {
                if (isset($result[$key]) || $value === '') continue;

                // Skip standard WooCommerce fields
                if (in_array($key, ['add-to-cart', 'product_id', 'variation_id', 'quantity', 'alt_s', 'attribute_pa_xshop', 'sku'], true)) {
                    continue;
                }

                // Heuristic: detect hashed keys like text_123, qfngjt...
                if (preg_match('/^(text|textarea|select|radio|qf|field|custom|input)[_\-]*/i', $key) || preg_match('/^\w+_\d+$/', $key)) {
                    $label = ucwords(str_replace(['_', '-'], ' ', $key));
                    $result[$label] = sanitize_text_field($value);
                    continue;
                }

                // If semantic, keep as-is
                $result[$key] = sanitize_text_field($value);
            }

            return $result;
        } catch (Throwable $e) {
//            CLogger::log('resolve_wcpa_fields exception', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $result;
        }
    }

    /**
     * Attempt to find the "user account" (userId) and "zoneId"
     * from posted and resolved fields.
     *
     * @param array $form_fields Posted fields
     * @param array $resolved_fields Result from resolve_wcpa_fields()
     * @return array ['userId' => string|null, 'zoneId' => string|null]
     */
    private static function find_account_fields(array $form_fields, array $resolved_fields): array
    {
        $userId = null;
        $zoneId = null;

        try {
            // 1) Check for stable keys in form_fields
            $stable_keys = ['user_account', 'account', 'account_number', 'msisdn', 'mobile', 'phone', 'subscriber'];
            foreach ($stable_keys as $k) {
                if (!empty($form_fields[$k])) {
                    $userId = sanitize_text_field($form_fields[$k]);
                    break;
                }
            }

            // 2) If not found, check resolved labels heuristically
            if (!$userId) {
                $label_candidates = ['account number', 'account', 'user account', 'msisdn', 'mobile', 'phone', 'subscriber id', 'subscriber'];
                foreach ($resolved_fields as $label => $value) {
                    $label_norm = strtolower(trim($label));
                    foreach ($label_candidates as $cand) {
                        if (strpos($label_norm, $cand) !== false && $value !== '') {
                            $userId = sanitize_text_field($value);
                            break 2;
                        }
                    }
                }
            }

            // 3) Last resort: any numeric-looking value (≥5 digits)
            if (!$userId) {
                foreach ($resolved_fields as $label => $value) {
                    if (preg_match('/^\d{5,}$/', preg_replace('/\D+/', '', $value))) {
                        $userId = sanitize_text_field($value);
                        break;
                    }
                }
            }

            // 4) ZoneId → numeric field different from userId
            foreach ($form_fields as $k => $v) {
                if (strpos($k, 'text_') === 0 && !empty($v) && preg_match('/^\d+$/', $v)) {
                    $clean = sanitize_text_field($v);
                    if ($clean !== $userId) {
                        $zoneId = $clean;
                        break;
                    }
                }
            }

            return ['userId' => $userId, 'zoneId' => $zoneId,];
        } catch (Throwable $e) {
//            CLogger::log('find_account_fields exception', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return ['userId' => null, 'zoneId' => null,];
        }
    }

    public static function handle_add_to_cart(): void
    {
        try {
//            CLogger::log('handle_add_to_cart', '***************************************************************');
//            CLogger::log('AJAX add_to_cart - raw $_POST', $_POST);

            // Nonce check
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'xshop-validate')) {
//                CLogger::log('AJAX add_to_cart - nonce failed', $_POST['nonce'] ?? null);
                wp_send_json_error(['message' => 'Invalid nonce'], 403);
            }

            $product_id = absint($_POST['product_id'] ?? 0);
            $variation_id = absint($_POST['variation_id'] ?? 0);
            $quantity = max(1, absint($_POST['quantity'] ?? 1));
            $validate_result_raw = $_POST['validate_result'] ?? null;
            $server_post = $_POST['server'] ?? null;
            $role_id = isset($_POST['role_id']) ? sanitize_text_field($_POST['role_id']) : null;

//            CLogger::log('handle_add_to_cart()', $role_id);

            // Decode validate_result (can be JSON string or already array)
            $validate_result = is_string($validate_result_raw)
                ? json_decode(wp_unslash($validate_result_raw), true)
                : $validate_result_raw;

            if (!is_array($validate_result)) {
//                CLogger::log('AJAX add_to_cart - invalid validate_result', $validate_result_raw);
                wp_send_json_error(['message' => 'Invalid validate_result']);
            }

            // Normalize shapes:
            // Accept: { result: { ... } } OR { decoded: { result: { ... } } } OR direct result
            if (!empty($validate_result['decoded']['result']) && is_array($validate_result['decoded']['result'])) {
                $validate_result = $validate_result['decoded']['result'];
            } elseif (!empty($validate_result['result']) && is_array($validate_result['result'])) {
                $validate_result = $validate_result['result'];
            }

            // Now we expect validate_result to contain orderId + message + sku (or at least orderId)
            $validate_orderId = $validate_result['orderId'] ?? null;
            $validate_id = $validate_result['id'] ?? ($validate_result['validate_id'] ?? null);
            $validate_message = $validate_result['message'] ?? [];

            if (empty($validate_orderId)) {
//                CLogger::log('AJAX add_to_cart - validation missing orderId', $validate_result);
                wp_send_json_error(['message' => 'Validation result missing orderId. Please validate again.']);
            }

            // Ensure product_id present
            if (!$product_id) {
//                CLogger::log('AJAX add_to_cart - missing product_id');
                wp_send_json_error(['message' => 'Missing required data']);
            }

            // Parse form_data fallback
            $form_fields = [];
            if (!empty($_POST['form_data'])) {
                parse_str(wp_unslash($_POST['form_data']), $form_fields);
            }

            // Load product meta (xshop_json)
            $xshop_json_raw = get_post_meta($product_id, 'xshop_json', true);
            $xshop_json = is_string($xshop_json_raw) ? json_decode($xshop_json_raw, true) : $xshop_json_raw;

            // Resolve SKU: prefer explicit sku field -> validate result sku -> attribute -> variation meta -> fallback first sku
            $sku = sanitize_text_field($_POST['sku'] ?? '');
            // try validate payload sku (could be in validate_result['sku']['sku'] or message.sku)
            if (empty($sku)) {
                if (!empty($validate_result['sku']['sku'])) {
                    $sku = sanitize_text_field($validate_result['sku']['sku']);
                } elseif (!empty($validate_message['sku']['sku'])) {
                    $sku = sanitize_text_field($validate_message['sku']['sku']);
                }
            }

            // try match by description (validate often returns only description)
            $descFromValidate = $validate_result['sku']['description'] ?? $validate_message['sku']['description'] ?? null;
            if (empty($sku) && $descFromValidate && !empty($xshop_json['skus'])) {
                $target = strtolower(trim($descFromValidate));
                foreach ($xshop_json['skus'] as $entry) {
                    if (strtolower(trim($entry['description'] ?? '')) === $target) {
                        $sku = $entry['sku'] ?? '';
                        break;
                    }
                }
            }

            // try attribute_pa_xshop
            if (empty($sku) && !empty($form_fields['attribute_pa_xshop']) && !empty($xshop_json['skus'])) {
                $attr_value = sanitize_title($form_fields['attribute_pa_xshop']);
                foreach ($xshop_json['skus'] as $entry) {
                    if (sanitize_title($entry['description'] ?? '') === $attr_value) {
                        $sku = $entry['sku'] ?? '';
                        break;
                    }
                }
            }

            // try variation meta
            if (empty($sku) && $variation_id) {
                $maybe_sku = get_post_meta($variation_id, 'xshop_sku', true);
                if ($maybe_sku) {
                    $sku = sanitize_text_field($maybe_sku);
                }
            }

            // fallback to first sku in xshop_json
            if (empty($sku) && !empty($xshop_json['skus'][0]['sku'])) {
                $sku = $xshop_json['skus'][0]['sku'];
            }

            // Find sku_data entry in xshop_json list
            $sku_data = null;
            if (!empty($xshop_json['skus'])) {
                foreach ($xshop_json['skus'] as $entry) {
                    if (($entry['sku'] ?? '') === $sku) {
                        $sku_data = $entry;
                        break;
                    }
                }
            }

            if (!$sku_data) {
                // last ditch: use validate_result sku object as "sku_data" if present
                if (!empty($validate_result['sku']) && is_array($validate_result['sku'])) {
                    $sku_data = $validate_result['sku'];
                }
            }

            if (!$sku_data) {
//                CLogger::log('AJAX add_to_cart - could not resolve sku_data', ['sku' => $sku, 'xshop_json' => $xshop_json]);
                wp_send_json_error(['message' => 'Could not resolve SKU data']);
            }

            // Resolve userAccount (prefer validate message), fallback to form fields
            $userAccount = $validate_message['userAccount'] ?? null;
            if (empty($userAccount)) {
                // try resolved fields by heuristics (reuse resolver)
                $resolved = self::resolve_wcpa_fields($product_id, $form_fields);
                $found = self::find_account_fields($form_fields, $resolved);
                $userAccount = $found['userId'] ?? null;
            }

            // Build xshop_selected_sku structure (merge xshop_json sku entry + selected server/zone)
            $selected_server = null;
            $selected_server_id = null;
            if (is_array($server_post)) {
                $selected_server = [
                    'id' => sanitize_text_field($server_post['id'] ?? ''),
                    'name' => sanitize_text_field($server_post['name'] ?? ''),
                ];
                $selected_server_id = $selected_server['id'] ?? null;
            } else {
                // maybe present on sku_data
                if (!empty($sku_data['selected_server'])) {
                    $selected_server = is_array($sku_data['selected_server']) ? $sku_data['selected_server'] : $sku_data['selected_server'];
                }
                $selected_server_id = $sku_data['selected_server_id'] ?? null;
            }

            $selected_sku_data = [
                'sku' => $sku,
                'description' => $sku_data['description'] ?? '',
                'price' => $sku_data['price'] ?? 0.0,
                'currency' => $sku_data['currency'] ?? '',
                'originalPrice' => $sku_data['originalPrice'] ?? null,
                'originalCurrency' => $sku_data['originalCurrency'] ?? null,
                'retailPrice' => $sku_data['retailPrice'] ?? null,
                'retailCurrency' => $sku_data['retailCurrency'] ?? null,
                'countryCode' => $sku_data['countryCode'] ?? null,
                'origin' => $sku_data['origin'] ?? null,
                'selected_server' => $selected_server,
                'selected_server_id' => $selected_server_id,
                'zoneId' => $form_fields['zone_id'] ?? ($sku_data['zoneId'] ?? null),
            ];

            // Build cart_item_data
            $cart_item_data = [
                'xshop_product' => $xshop_json['product'] ?? [],
                'xshop_selected_sku' => $selected_sku_data,
                'xshop_skuPrices' => $xshop_json['skuPrices'] ?? [],
                'xshop_json' => $xshop_json_raw,
                'xshop_validate' => $validate_result,
                'xshop_validate_id' => $validate_id,
                'xshop_role_id' => $role_id,
                'xshop_validate_orderId' => $validate_orderId,
                'xshop_userAccount' => $userAccount,
                'xshop_resolved_fields' => $form_fields, // helpful for debugging
            ];

//            CLogger::log('AJAX add_to_cart - cart_item_data', $cart_item_data);

            // WooCommerce check
            if (!function_exists('WC')) {
//                CLogger::log('AJAX add_to_cart - WooCommerce not available');
                wp_send_json_error(['message' => 'WooCommerce not available']);
            }

            // Attempt to add to cart
            $added = WC()->cart->add_to_cart($product_id, $quantity, $variation_id ?: 0, [], $cart_item_data);
//            CLogger::log('AJAX add_to_cart - add_to_cart result', $added);

            if (!$added) {
                wp_send_json_error(['message' => 'Failed to add to cart']);
            }

            // Success → redirect to check out
            wp_send_json_success(['redirect' => wc_get_checkout_url()]);
        } catch (Throwable $e) {
//            CLogger::log('AJAX add_to_cart exception', [
//                'message' => $e->getMessage(),
//                'trace' => $e->getTraceAsString(),
//            ]);
            wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
        }
    }


}
