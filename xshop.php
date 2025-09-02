<?php /** @noinspection SpellCheckingInspection */

/**
 * Plugin Name: xShop API
 * Description: Integrate xShop API with WooCommerce
 * Version: 1.0.0
 * Author: CubixSol
 * Author URI: https://cubixsol.com
 * Text Domain: xshop-api
 */

// Exit if accessed directly
defined('ABSPATH') || exit;
ini_set('memory_limit', '-1');
ini_set('max_execution_time', '-1');
ini_set('display_errors', 1);

// BASE URL

$version = '1.0.0';

cubixsol_define_constants($version);
cubixsol_init_hooks();

/**
 *  Initializing Required Hooks
 */
function cubixsol_init_hooks()
{
    /**
     * Activation/Deactivation
     */
    register_activation_hook(PLUGIN_FILE, 'cubixsol_activation');
    register_deactivation_hook(PLUGIN_FILE, 'cubixsol_deactivation');

    /**
     * Enqueue Admin/Front styles and scripts
     */
    add_action('admin_enqueue_scripts', 'enqueue_admin_scripts');
    add_action('wp_enqueue_scripts', 'enqueue_front_scripts');

    /**
     * API Sync Ajax
     */

    add_action('wp_ajax_xshop_sync_product', 'xshop_sync_product');

    /**
     * Metaboxes & save
     */
    add_action('admin_menu', 'cubixsol_admin_menu_page');
}

function cubixsol_activation()
{
}

function cubixsol_deactivation()
{
}

/**
 *  Enqueue all admin styles and scripts
 */
function enqueue_admin_scripts()
{
    wp_enqueue_style('xshop', plugins_url('/assets/admin/css/xshop.css', PLUGIN_FILE), [], VERSION);
    wp_enqueue_script('xshop', plugins_url('/assets/admin/js/xshop.js', PLUGIN_FILE), [], VERSION);
}

/**
 * Enqueue all frontend styles and scripts
 */
function enqueue_front_scripts()
{
    wp_enqueue_style('xshop', plugins_url('/assets/css/xshop.css', PLUGIN_FILE), [], VERSION);
    wp_enqueue_script('xshop', plugins_url('/assets/js/xshop.js', PLUGIN_FILE), ['jquery'], VERSION);
}


/**
 * Define Constants.
 *
 * @param string $version Plugin version.
 */
function cubixsol_define_constants( $version ) {
    $plugin_file = __FILE__;
    $plugin_dir  = plugin_dir_path( $plugin_file );
    $plugin_url  = plugin_dir_url( $plugin_file );

    // Keep your existing constants
    define( 'API_BASE_URL', 'https://xshop-sandbox.codashop.com/v2' );
    // define( 'API_BASE_URL', 'https://xshop.codashop.com/v2' ); // Production
    define( 'PLUGIN_FILE', $plugin_file );
    define( 'VERSION', $version );

    // Add extra useful constants
    define( 'PLUGIN_BASENAME', plugin_basename( $plugin_file ) );
    define( 'PLUGIN_DIR_PATH', $plugin_dir );
    define( 'PLUGIN_DIR_URL', $plugin_url );
    define( 'PLUGIN_ASSETS_URL', trailingslashit( $plugin_url . 'assets' ) );
}



function cubixsol_admin_menu_page()
{
    add_submenu_page('edit.php?post_type=product', 'API Settings', 'API Settings', 'manage_options', 'properties-settings', 'cubixsol_product_setting_page');
}

function cubixsol_product_setting_page()
{
    global $wpdb;
    if (isset($_POST['submit'])) {
        update_option('xshop_client_id', sanitize_text_field($_POST['xshop_client_id']));
        update_option('xshop_client_secret', sanitize_text_field($_POST['xshop_client_secret']));
        update_option('xshop_api_key', sanitize_text_field($_POST['xshop_api_key']));
    }

    $html = '';
    $html .= '<form method="POST" action="" class="cubixsol-settings" autocomplete="off">';
    $html .= '<div class="wrap" id="bricklink_setting_page">';
    $html .= '<h1>API Settings</h1>';
    $html .= '<table class="form-table" role="presentation">';
    $html .= '<tbody>';

    $html .= '<tr>';
    $html .= '<td colspan="2" class="heading-pad">';
    $html .= '<h4 class="setting-headings">xShop API Credentials</h4>';
    $html .= '</td>';
    $html .= '</tr>';

    $html .= ' <tr>';
    $html .= '<th scope="row">';
    $html .= '<label for="client-id">Client ID</label>';
    $html .= '</th>';
    $html .= '<td>';
    $html .= '<input name="xshop_client_id" type="text" id="xshop_client_id" value="' . get_option("xshop_client_id") . '" class="regular-text" />';
    $html .= '</td>';
    $html .= '</tr>';

    $html .= '<tr>';
    $html .= '<th scope="row">';
    $html .= '<label for="client-secret">Client Secret</label>';
    $html .= '</th>';
    $html .= '<td>';
    $html .= '<input name="xshop_client_secret" type="text" id="xshop_client_secret" value="' . get_option("xshop_client_secret") . '" class="regular-text" />';
    $html .= '</td>';
    $html .= '</tr>';

    $html .= '<tr>';
    $html .= '<th scope="row">';
    $html .= '<label for="api-key">API Key</label>';
    $html .= '</th>';
    $html .= '<td>';
    $html .= '<input name="xshop_api_key" type="text" id="xshop_api_key" value="' . get_option("xshop_api_key") . '" class="regular-text"/>';
    $html .= '</td>';
    $html .= '</tr>';

    $html .= '<tr>';
    $html .= '<th scope="row">';
    $html .= '</th>';
    $html .= '<td>';
    $html .= '<input type="submit" name="submit" class="cubixsol_submit">';
    $html .= '</td>';
    $html .= '</tr>';

    $html .= '</tbody>';
    $html .= '</table>';
    $html .= '</div>';
    $html .= '</form>';

    echo $html;
}

// Base64url Encode
function xshop_jwt_base64url_encode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// generate JWT from an exact JSON string (no re-encoding differences)
function xshop_generate_jwt_from_json($json_payload)
{
    $client_id = get_option('xshop_client_id');
    $client_secret = get_option('xshop_client_secret');
    $api_key = get_option('xshop_api_key');

    if (!$client_id || !$client_secret || !$api_key) {
        return false;
    }

    $header = ["alg" => "HS256", "typ" => "JWT", "x-api-key" => $api_key, "x-api-version" => "2.0", "x-client-id" => $client_id];

    $base64Header = xshop_jwt_base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $base64Payload = xshop_jwt_base64url_encode($json_payload);

    $unsigned = $base64Header . '.' . $base64Payload;
    $signature = hash_hmac('sha256', $unsigned, $client_secret, true);
    $base64Sig = xshop_jwt_base64url_encode($signature);

    return $unsigned . '.' . $base64Sig;
}

// Request wrapper
function xshop_api_request_curl($endpoint, $body = null, $method = 'GET', $args = [])
{
    $api_key = get_option('xshop_api_key');

    if (!$api_key) {
        return ['error' => 'Missing API key option'];
    }

    // Build URL
    // $base = defined('API_BASE_URL') ? API_BASE_URL : 'https://xshop.codashop.com/v2/';
    $base = defined('API_BASE_URL') ? API_BASE_URL : ' https://xshop-sandbox.codashop.com/v2/';
    $url = rtrim($base, '/') . '/' . ltrim($endpoint, '/');

    $is_get = strtoupper($method) === 'GET';
    if ($is_get) {
        $json_body = '[]';
    } else {
        if (is_string($body)) {
            $json_body = $body;
        } else {
            $json_body = json_encode($body ?: new stdClass(), JSON_UNESCAPED_SLASHES);
        }
    }

    $jwt = xshop_generate_jwt_from_json($json_body);

    if (!$jwt) {
        return ['error' => 'Missing JWT credentials (client_id/secret/api_key)'];
    }

    if (!empty($args['query']) && is_array($args['query'])) {
        $qs = http_build_query($args['query']);
        $url = $url . (strpos($url, '?') === false ? '?' : '&') . $qs;
    }

    $headers = ["x-api-key: {$api_key}", "x-api-version: 2.0", "Authorization: Bearer {$jwt}", "Content-Type: application/json", "Accept: application/json"];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // ✅ SSL verification enabled
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    if (!$is_get) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif (strtoupper($method) === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        } elseif (strtoupper($method) === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['error' => "cURL error: {$err}"];
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    return ['status' => $status, 'json' => $decoded, 'header' => $headers, 'url' => $url,];
}


/**
 * Get Product ID from Database
 */
function cubixsol_get_product_by_xshop_id($key, $xshop_id)
{
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", $key, $xshop_id));
}


function xshop_sync_product()
{
    // Get products
    $res1 = xshop_api_request_curl('products', '', 'GET');
    $res2 = xshop_api_request_curl('cross-border-products', '', 'GET');

    $list1 = $res1['json']['result']['productList'] ?? [];
    $list2 = $res2['json']['result']['productList'] ?? [];

    // Merge products
    $products = array_merge($list1, $list2);

//
//    echo '<pre>';
//    print_r($products);
//    echo '</pre>';
//
//    exit;
//    echo"<pre>";print_r($products);echo"</pre>";exit("SDSDSD");


    if (empty($products)) {
        echo json_encode(['success' => false, 'message' => 'No products found from API. Check credentials or connection.',]);
        wp_die();
    }

    foreach ($products as $product) {
        $name = $product['name'] ?? '';
        $apiPath = $product['apiPath'] ?? '';

        // Skip product early
        if (strcasecmp(trim($name), 'Tamashi: Rise of Yokai') === 0) {
            continue;
        }

        $supportedCountries = !empty($product['supportedCountries']) ? (array)$product['supportedCountries'] : [];
        $subtype = !empty($product['subtype']) ? $product['subtype'] : '';
        $haveVerify = !empty($product['haveVerify']) ? (bool)$product['haveVerify'] : false;

        if (!$apiPath) {
            continue;
        }

//        $apiPath = 'steam-voucher';

        $normalized_key = 'xshop_' . str_replace('-', '_', ltrim($apiPath, '/'));

        // ✅ Check duplication
        $maybe_post_id = cubixsol_get_product_by_xshop_id('xshop_product_key', $normalized_key);

        $post_id = $maybe_post_id ? $maybe_post_id : 0;

        // If product exists, skip
        if ($post_id > 0 && get_post_status($post_id)) {
            continue;
        }

        // If product was deleted, clean up meta
        if ($post_id > 0 && !get_post_status($post_id)) {
            delete_post_meta($post_id, 'xshop_product_key');
            $post_id = 0;
        }

        // Fetch SKU list for this product
        $sku_endpoint = ltrim($apiPath, '/');

        $body = ["jsonrpc" => "2.0", "id" => uniqid(), "method" => "listSku", "params" => ["iat" => time()]];

        $sku_res = xshop_api_request_curl($sku_endpoint, $body, 'POST');
        $skus = $sku_res['json']['result']['skuList'] ?? [];

        $origin = '';
        $sku_data = [];
        foreach ($skus as $sku) {
            $sku_data[] = [

                    'sku' => $sku['sku'] ?? '', 'description' => $sku['description'] ?? '', 'price' => $sku['price']['amount'] ?? '', 'currency' => $sku['price']['currency'] ?? '', 'originalPrice' => $sku['originalPrice']['amount'] ?? '', 'originalCurrency' => $sku['originalPrice']['currency'] ?? '', 'retailPrice' => $sku['retailPrice']['amount'] ?? '', 'retailCurrency' => $sku['retailPrice']['currency'] ?? '', 'countryCode' => $sku['countryCode'] ?? '', 'origin' => $sku['origin'] ?? '',];

            $origin = $sku['origin'];
            $countryCode = $sku['countryCode'];
        }

        // Fetch Server list ONLY if subtype = 2
        $servers = [];
        if ($subtype == 2) {
            $server_body = ["jsonrpc" => "2.0", "id" => uniqid(), "method" => "listServer", "params" => ["iat" => time()]];
            $server_endpoint = ltrim($apiPath, '/');
            $server_res = xshop_api_request_curl($server_endpoint, $server_body, 'POST');
            $servers = $server_res['json']['result']['servers'] ?? [];


        }


        // Call ListSkuPric ONLY if haveVerify = true (Applicable only for Mobile Legends Brazil)
        $listSkuPrices = [];
        if ($haveVerify) {
            $listSkuPrice_body = ["jsonrpc" => "2.0", "id" => uniqid(), "method" => "listSkuPrice", "params" => ["iat" => time(), "countryOfOrigin" => "br"]];

            $listSkuPrice_endpoint = ltrim($apiPath,'/');
            $listSkuPrice_res = xshop_api_request_curl($listSkuPrice_endpoint, $listSkuPrice_body, 'POST');

            $listSkuPrices = $listSkuPrice_res['json']['result']['skuList'] ?? [];

            // Place Order
        }

        // Place Order
        $place_order_body = ["jsonrpc" => "2.0", "id" => uniqid(), "method" => "placeOrder", "params" => ["items" => [["sku" => "SKUA1_KWD", "quantity" => 1, "price" => ["currency" => "KWD", "amount" => 10]]], "customerId" => "jali69034@gmail.com", "iat" => time()]];

        $place_order_endpoint = ltrim($apiPath, '/');
        // $place_res = xshop_api_request_curl($place_order_endpoint, $place_order_body, 'POST');

        // Get Order
        $get_order_body = ["jsonrpc" => "2.0", "id" => 'f5429b63-51d4-4961-b95e-90511e6ec7af', "method" => "getOrder", "params" => ["requestId" => "68a47c1e98375", "iat" => time()]];

        $get_order_endpoint = ltrim($apiPath, '/');
//        $get_res = xshop_api_request_curl($get_order_endpoint, $get_order_body, 'POST');

//        echo "<pre>";
//        print_r($get_res);
//        echo "</pre>";

        // exit("SDSDSDS");

        // Prepare only current product for update
        $current_product_data = ['product' => ['name' => $name, 'apiPath' => $apiPath, 'type' => $product['type'] ?? '', 'subtype' => $product['subtype'] ?? '', 'haveRole' => $product['haveRole'] ?? '', 'supportedCountries' => $supportedCountries, 'haveVerify' => $haveVerify,

        ], 'skus' => $sku_data, 'servers' => $servers, 'skuPrices' => $listSkuPrices,];

        // create/update propert

//        if ($name == 'Steam Voucher')
            cubixsol_update_product($post_id, $current_product_data);

    }
}

function cubixsol_update_product($post_id, $data)
{
//    echo "<pre>";
//    print_r($data);
//    echo "</pre>";
//    exit;

    wc_set_time_limit(0);

    $variation_status = 'no';
    $attributes_preserve = [];

    $product = ($post_id != 0) ? new WC_Product($post_id) : new WC_Product();

    $product_name = (!empty($data['product']['name'])) ? $data['product']['name'] : '';
    $api_path = (!empty($data['product']['apiPath'])) ? $data['product']['apiPath'] : '';
    $product_type = (!empty($data['product']['type'])) ? $data['product']['type'] : '';
    $sub_type = (!empty($data['product']['subtype'])) ? $data['product']['subtype'] : '';

    // Details about SubType
    // 1.	Only userId
    // 2.	UserId and ServerId. ServerId must come from the list of server returned by listServer method
    // 3.	UserId and ZoneId

    $have_role = (!empty($data['product']['haveRole'])) ? $data['product']['haveRole'] : '';
    $have_verify = (!empty($data['product']['haveVerify'])) ? $data['product']['haveVerify'] : '';
    $supported_countries = (!empty($data['product']['supportedCountries'])) ? $data['product']['supportedCountries'] : '';
    $list_skus = (!empty($data['skus'])) ? $data['skus'] : '';
    $normalized_key = 'xshop_' . str_replace('-', '_', ltrim($api_path, '/'));

    if ($post_id == 0) {
        // Insert new product
        $new_product = array('post_title' => $product_name, 'post_status' => 'publish', 'post_type' => 'product', 'post_author' => 1,);

        $post_id = wp_insert_post($new_product);
        wp_set_object_terms($post_id, 'variable', 'product_type');

        // Add custom meta
        update_post_meta($post_id, 'xshop_product_key', $normalized_key);
        update_post_meta($post_id, 'xshop_sync', 'yes');
        update_post_meta($post_id, 'xshop_json', json_encode($data));
        update_post_meta($post_id, '_sku', trim($normalized_key));
        update_post_meta($post_id, '_xshop_type', $product_type);
        update_post_meta($post_id, '_xshop_subtype', $sub_type);
        update_post_meta($post_id, '_xshop_haveRole', $have_role);
        update_post_meta($post_id, '_xshop_haveVerify', $have_verify);
        update_post_meta($post_id, '_xshop_supportedCountries', $supported_countries);
//        update_post_meta($post_id, '_xshop_servers', $data['servers']);


        // variations
        if (!empty($list_skus)) {
            foreach ($list_skus as $variation) {
                $variation_name = $variation['description'] ?? '';
                if (!empty($variation_name)) {
                    $returned = cubixsol_create_product_term("xShop", $variation_name, $post_id, 'yes');
                    if (!empty($returned)) {
                        $attributes_preserve = array_merge($attributes_preserve, $returned);
                    }
                }
            }

            if (!empty($attributes_preserve)) {
                update_post_meta($post_id, '_product_attributes', $attributes_preserve);
            }

            sleep(5);
            cubixsol_create_product_variation($list_skus, $post_id, $supported_countries);

            $product = new WC_Product_Variable($post_id);
            $product->save();
        }
    } else {
        // Update existing product
        $existing_product = array('ID' => $post_id, 'post_title' => $product_name,);
        wp_update_post($existing_product);
    }

    update_post_meta($post_id, '_stock_status', 'instock');
    update_post_meta($post_id, '_stock', 50);
    update_post_meta($post_id, '_manage_stock', 'yes');
}

function cubixsol_create_product_variation($variations, $post_id, $supported_countries = [])
{
    foreach ($variations as $variation) {
        $var_id = (!empty($variation['sku'])) ? $variation['sku'] : '';
        $var_name = (!empty($variation['description'])) ? $variation['description'] : '';
        $product_price = (!empty($variation['price'])) ? $variation['price'] : '';
        $product_currency = (!empty($variation['currency'])) ? $variation['currency'] : '';
        $retailPrice = (!empty($variation['retailPrice'])) ? $variation['retailPrice'] : '';
        $retailCurrency = (!empty($variation['retailCurrency'])) ? $variation['retailCurrency'] : '';
        $var_country = isset($variation['countryCode']) ? (int)$variation['countryCode'] : '';

        // supported_counries_and_price();

        // Skip if variation country not in supported list
        if (!in_array($var_country, $supported_countries)) {
            continue;
        }

        // Default: use price directly
        $var_price = $product_price;

        // $currency  = get_woocommerce_currency();

        // // If price is in USD → Convert to KWD
        // if ($currency === 'KWD') {
        //     $exchange_rate = exchange_rate_curl_call('USD', 'KWD');
        //     if ($exchange_rate) {
        //         $var_price = round($product_price * $exchange_rate, 2);
        //     }
        // }

        // Check if variation exists
        $maybe_post_id = cubixsol_get_product_by_xshop_id('_xshop_variation_id', $var_id);
        $variation_id = $maybe_post_id ? $maybe_post_id : 0;

        if ($variation_id == 0) {
            $variation_post = array('post_title' => get_the_title($post_id) . ' - ' . $var_name, 'post_name' => 'product-' . $post_id . '-variation-' . sanitize_title($var_id), 'post_status' => 'publish', 'post_parent' => $post_id, 'post_type' => 'product_variation', 'guid' => get_permalink($post_id));
            $variation_id = wp_insert_post($variation_post);
        }

        // Attribute setup
        $taxonomy = 'pa_xshop';
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, 'product_variation', array('hierarchical' => false, 'label' => 'xShop', 'query_var' => true));
        }
        wp_set_object_terms($variation_id, $var_name, $taxonomy, true);
        update_post_meta($variation_id, 'attribute_' . $taxonomy, sanitize_title($var_name));

        // Save variation meta
        update_post_meta($variation_id, '_xshop_variation_id', $var_id);
        update_post_meta($variation_id, '_regular_price', $var_price);
        update_post_meta($variation_id, '_price', $var_price);
        update_post_meta($variation_id, '_sku', $var_id);
    }
}

/**
 * Create/Assign the product category.
 */
function cubixsol_create_product_term($name, $options, $product_id, $is_variation = 'no')
{
    global $wpdb;
    $options = (array)$options;
    $attributes = [];

    // $position = 1;
    foreach ($options as $option) {
        $label = ucwords($name);
        $sanitize = sanitize_title($label);
        if (empty($name)) continue;

        $slug = 'pa_' . $sanitize;
        $attribute_name_array = wc_get_attribute_taxonomy_names();
        if (!in_array($slug, $attribute_name_array)) {
            $taxonomy_name = $slug;
            $attribute_id = wc_create_attribute(array('name' => $label, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false,));
            register_taxonomy($taxonomy_name, apply_filters('woocommerce_taxonomy_objects_' . $taxonomy_name, array('product')), apply_filters('woocommerce_taxonomy_args_' . $taxonomy_name, array('labels' => array('name' => $label), 'hierarchical' => true, 'show_ui' => false, 'query_var' => true, 'rewrite' => false,)));
            delete_transient('wc_attribute_taxonomies');
        }

        $is_variation = ($is_variation == 'yes') ? '1' : '0';
        if (!empty($option) && !empty($slug)) {

            $attributes[$slug] = array('name' => $slug, 'value' => $option, 'is_visible' => '1', 'is_variation' => $is_variation, 'is_taxonomy' => '1');

            cubixsol_create_product_catgory($option, $product_id, $slug);
        }
    }
    return $attributes;
}

/**
 * Create a product category
 */
function cubixsol_create_product_catgory($name, $id, $type, $parent = 0)
{
    if (!empty($name)) {
        $term_id = 0;
        $name = htmlspecialchars_decode($name);

        if ($parent == 0) {
            $terms = term_exists($name, $type);

            if ($terms !== 0 && $terms !== null) {
                $terms = (array)$terms;
                $term_id = $terms['term_id'];
            } else {
                $terms = wp_insert_term($name, $type);
                $terms = (array)$terms;
                if (array_key_exists('term_id', $terms)) {
                    $term_id = $terms['term_id'];
                }
            }
        } else {
            $in_parent = term_exists($name, $type, $parent);
            if (!empty($in_parent)) {
                $term_id = $in_parent['term_id'];
                $name = get_term($term_id)->slug;
            } else {
                $terms = wp_insert_term($name, $type, array('parent' => $parent));
                $terms = (array)$terms;
                if (array_key_exists('term_id', $terms)) {
                    $term_id = $terms['term_id'];
                    $name = get_term($term_id)->slug;
                }
            }
        }
        wp_set_object_terms($id, array($name), $type, true);
        return $term_id;
    }
}

/**
 *  Exchange rate curl call
 */
function exchange_rate_curl_call($base_currency, $target_currency)
{
    $curl = curl_init();

    curl_setopt_array($curl, array(CURLOPT_URL => "https://openexchangerates.org/api/latest.json?app_id=1dda5cfe49cd4bf694c0cf4e5badffa7&base=$base_currency", CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => '', CURLOPT_MAXREDIRS => 10, CURLOPT_TIMEOUT => 0, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_CUSTOMREQUEST => 'GET',));

    $response = curl_exec($curl);
    curl_close($curl);

    $result = json_decode($response, true);

    // Ensure the target currency exists
    if (!empty($result) && !empty($result['rates'][$target_currency])) {
        return $result['rates'][$target_currency];
    }

    return false;
}

add_action('wp_footer', 'test', 999);
function test()
{
    if (isset($_GET['jahan'])) {
        $pos_id = 12978;
        echo "<pre>";
        print_r(get_post_meta($pos_id));
        echo "</pre>";
    }
}



add_action('wp_footer', function () {
    if (!isset($_GET['debug'])) {
        return;
    }

    $post_id = intval($_GET['debug']);
    if (!$post_id) {
        wp_die('<pre style="direction:ltr;text-align:left;">Invalid or missing post ID.</pre>');
    }

    $post_data = get_post($post_id);
    $meta_data = get_post_meta($post_id);

    echo "<div style='padding: 1rem; background-color: lightgrey;'>";
    echo '<pre style="direction:ltr;text-align:left; white-space:pre-wrap;">';
    echo "--- POST OBJECT ---\n";
    print_r($post_data);
    echo "\n--- META DATA ---\n";
    print_r($meta_data);
    echo '</pre>';
    echo "</div>";

    wp_die(); // stop before theme loads
});



//require_once __DIR__ . '/classes/ui/ServerSelect.php';
require_once __DIR__ . '/classes/Cubixsol_Woo_Order.php';
require_once __DIR__ . '/classes/Debug_Meta_Helper.php';
//require_once __DIR__ . '/classes/OrderProcessor.php';

use classes\Cubixsol_Woo_Order;
use classes\Debug_Meta_Helper;
use classes\ui\ServerSelect;

add_action('plugins_loaded', function () {
//    new ServerSelect();
    Cubixsol_Woo_Order::instance();
    new Debug_Meta_Helper();
});