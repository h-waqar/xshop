/* File: classes/BaseHandler.php */
<?php

// classes/BaseHandler.php:4

namespace classes;

defined('ABSPATH') || exit;

abstract class BaseHandler
{
    abstract public function build_payload(array $base, $xshop_json, $item, $order, $variation_product): array;

    public function get_endpoint(): string
    {
        return 'https://api.example.com/orders';
    }

    public function get_headers(): array
    {
        return [];
    }
}



/* File: classes/Cubixsol_Woo_Order.php */
<?php

//  classes/Cubixsol_Woo_Order.php:3

namespace classes;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/OrderProcessor.php';

use classes\OrderProcessor;

class Cubixsol_Woo_Order
{
    private static ?Cubixsol_Woo_Order $instance;

    public function __construct()
    {
        add_action('woocommerce_thankyou', [$this, 'cubixsol_place_woo_order']);
        add_action('woocommerce_payment_complete', [$this, 'cubixsol_place_woo_order']);
        add_action('woocommerce_order_status_completed', [$this, 'cubixsol_place_woo_order']);
        add_action('woocommerce_order_status_processing', [$this, 'cubixsol_place_woo_order']);

        add_filter('woocommerce_add_cart_item_data', [$this, 'add_data_to_cart'], 10, 3);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'append_item_meta'], 10, 4);
    }

    public static  function instance(): Cubixsol_Woo_Order
    {
        return self::$instance ?? self::$instance = new self();
    }

    public function cubixsol_place_woo_order($order_id)
    {
        (new OrderProcessor())->process($order_id);
    }

    public function add_data_to_cart($cart_item_data, $product_id, $variation_id)
    {
        if (!empty($_POST['server'])) {
            $cart_item_data['xshop_server'] = sanitize_text_field($_POST['server']);
        }
        if (!empty($_POST['attribute_pa_xshop'])) {
            $cart_item_data['xshop_variation_name'] = sanitize_text_field($_POST['attribute_pa_xshop']);
        }
        if (!empty($_POST['variation_id'])) {
            $cart_item_data['xshop_variation_id'] = sanitize_text_field($_POST['variation_id']);
        }
        if (!empty($_POST['server_id'])) {
            $cart_item_data['xshop_server_id'] = intval($_POST['server_id']);
        }
        $xshop_json = get_post_meta($product_id, 'xshop_json', true);
        if ($xshop_json) {
            $cart_item_data['xshop_json'] = $xshop_json;
        }
        $cart_item_data['_POST'] = $_POST;

        return $cart_item_data;
    }

    public function append_item_meta($item, $cart_item_key, $values, $order)
    {
        if (isset($values['xshop_server'])) $item->add_meta_data('xshop_server', sanitize_text_field($values['xshop_server']), true);
        if (isset($values['xshop_variation_name'])) $item->add_meta_data('xshop_variation_name', sanitize_text_field($values['xshop_variation_name']), true);
        if (isset($values['xshop_variation_id'])) $item->add_meta_data('xshop_variation_id', sanitize_text_field($values['xshop_variation_id']), true);
        if (isset($values['xshop_server_id'])) $item->add_meta_data('xshop_server_id', intval($values['xshop_server_id']), true);
        if (isset($values['xshop_json'])) $item->add_meta_data('xshop_json', $values['xshop_json'], true);
    }
}



/* File: classes/Debug_Meta_Helper.php */
<?php

//  classes/Debug_Meta_Helper.php:3

namespace classes;

defined('ABSPATH') || exit;

class Debug_Meta_Helper
{
    public function __construct()
    {
        // Single product page
        add_action('woocommerce_after_single_product', [$this, 'debug_product_meta']);

        // Cart page
        add_action('woocommerce_after_cart', [$this, 'debug_cart_meta']);

        // Checkout page
        add_action('woocommerce_after_checkout_form', [$this, 'debug_checkout_meta']);

        // Admin: single order screen
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'debug_order_meta']);

//        add_action('woocommerce_after_checkout_form', [$this, 'debug_order_payload_preview']);

    }

    public function debug_order_payload_preview()
    {
        if (!WC()->cart || WC()->cart->is_empty()) return;

        echo '<pre style="background:#111;color:#0f0;padding:10px;">';
        echo "<h3>🚧 Payload Preview (Cart → Order)</h3>";

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product        = $cart_item['data'];
            $variation_sku  = $product ? $product->get_sku() : null;
            $parent_id      = $product ? $product->get_parent_id() : $cart_item['product_id'];
            $parent_sku     = get_post_meta($parent_id, '_sku', true);

            // Parse the important parts of xshop_json
            $xshop_meta = [];
            if (!empty($cart_item['xshop_json'])) {
                $decoded = json_decode($cart_item['xshop_json'], true);
                if (is_array($decoded) && isset($decoded['product'])) {
                    $xshop_meta = [
                        'supportedCountries' => $decoded['product']['supportedCountries'] ?? [],
                        'type'               => $decoded['product']['type'] ?? null,
                        'subtype'            => $decoded['product']['subtype'] ?? null,
                        'haveRole'           => $decoded['product']['haveRole'] ?? null,
                        'haveVerify'         => $decoded['product']['haveVerify'] ?? null,
                    ];
                }
            }

            $payload_base = [
                'order_id' => 'preview-' . uniqid(),
                'item_id'  => $cart_item['key'],
                'sku'      => $variation_sku ?: $parent_sku,
                'quantity' => (int) $cart_item['quantity'],
                'price'    => (float) $cart_item['line_total'],
                'meta'     => array_merge([
                    'variation_id'         => $cart_item['variation_id'] ?? null,
                    'xshop_variation_name' => $cart_item['xshop_variation_name'] ?? null,
                    'xshop_server'         => $cart_item['xshop_server'] ?? null,
                ], $xshop_meta),
            ];

            print_r($payload_base);
        }

        echo '</pre>';
    }



    /**
     * Dump product meta on single product page
     */
    public function debug_product_meta()
    {
        global $post;
        if (!$post) {
            return;
        }

        echo '<pre style="background:#111;color:#0f0;padding:10px;">';
        echo "<h3>🔎 Product Post Meta (ID: {$post->ID})</h3>";
        print_r(get_post_meta($post->ID));
        echo '</pre>';
    }

    /**
     * Dump cart contents + meta
     */
    public function debug_cart_meta()
    {
        if (!WC()->cart) {
            return;
        }

        echo '<pre style="background:#111;color:#0f0;padding:10px;">';
        echo "<h3>🛒 Cart Items Meta</h3>";
        foreach (WC()->cart->get_cart() as $key => $item) {
            echo "Cart Key: $key\n";
            print_r($item);
            echo "-----------------------------\n";
        }
        echo '</pre>';
    }

    /**
     * Dump checkout cart contents
     */
    public function debug_checkout_meta()
    {
        if (!WC()->cart) {
            return;
        }

        echo '<pre style="background:#111;color:#0f0;padding:10px;">';
        echo "<h3>💳 Checkout Meta</h3>";
        foreach (WC()->cart->get_cart() as $key => $item) {
            echo "Cart Key: $key\n";
            print_r($item);
            echo "-----------------------------\n";
        }
        echo '</pre>';
    }

    /**
     * Dump order meta in admin order page
     */
    public function debug_order_meta($order)
    {
        echo '<pre style="background:#111;color:#0f0;padding:10px;">';
        echo "<h3>📦 Order Meta (ID: {$order->get_id()})</h3>";
        print_r(get_post_meta($order->get_id()));
        echo '</pre>';
    }
}



/* File: classes/HandlerFactory.php */
<?php

//  classes/HandlerFactory.php:3

namespace classes;

defined('ABSPATH') || exit;

use classes\Handlers\TopupHandler;
use classes\Handlers\VoucherHandler;
use classes\Handlers\GenericHandler;

class HandlerFactory
{
    public static function make(string $type, string $subtype)
    {
        $type = strtolower(trim($type));
        $subtype = strtolower(trim($subtype));

        if ($type === 'topup') {
            return new TopupHandler();
        }
        if ($type === 'voucher') {
            return new VoucherHandler();
        }

        return new GenericHandler();
    }
}



/* File: classes/Handlers/TopupHandler.php */
<?php

//  classes/Handlers/TopupHandler.php:3

namespace classes\Handlers;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/BaseHandler.php';

use classes\BaseHandler;

class TopupHandler extends BaseHandler
{
    public function build_payload(array $base, $xshop_json, $item, $order, $variation_product): array
    {
        $sku_object = $this->find_sku_in_json($base['sku'], $xshop_json);

        $payload = [
            'type' => 'topup',
            'client_order_id' => $base['order_id'],
            'item' => [
                'sku' => $base['sku'],
                'quantity' => $base['quantity'],
                'price' => $base['price'],
                'description' => $item->get_name(),
            ],
            'meta' => $base['meta'],
        ];

        if ($sku_object) {
            $payload['item']['vendor_sku_info'] = $sku_object;
        }

        $payload['customer'] = [
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        ];

        return $payload;
    }

    public function get_endpoint(): string
    {
        return 'https://your-external-api.example.com/topups';
    }

    private function find_sku_in_json($sku, $json)
    {
        $decoded = is_array($json) ? $json : json_decode($json, true);
        $skus = $decoded['skus'] ?? ($decoded[0]['skus'] ?? null);

        if (empty($skus) || !is_array($skus)) return null;

        foreach ($skus as $s) {
            if (($s['sku'] ?? null) === $sku) {
                return $s;
            }
        }
        return null;
    }
}



/* File: classes/Handlers/VoucherHandler.php */
<?php

//  classes/Handlers/VoucherHandler.php:3

namespace classes\Handlers;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/BaseHandler.php';

use classes\BaseHandler;

class VoucherHandler extends BaseHandler
{
    public function build_payload(array $base, $xshop_json, $item, $order, $variation_product): array
    {
        return [
            'type' => 'voucher',
            'client_order_id' => $base['order_id'] . '-' . $base['item_id'],
            'item' => [
                'sku' => $base['sku'],
                'qty' => $base['quantity'],
            ],
            'meta' => $base['meta'],
        ];
    }

    public function get_endpoint(): string
    {
        return 'https://your-external-api.example.com/vouchers';
    }
}



/* File: classes/OrderProcessor.php */
<?php
// classes/OrderProcessor.php

namespace classes;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/HandlerFactory.php';
include_once PLUGIN_DIR_PATH . 'classes/Handlers/TopupHandler.php';
include_once PLUGIN_DIR_PATH . 'classes/Handlers/VoucherHandler.php';

use classes\HandlerFactory;

class OrderProcessor
{
    const META_SYNC_FLAG = 'cubixsol_woo_order_sync';
    const META_EXTERNAL_IDS = '_cubixsol_external_order_ids';
    const META_EXTERNAL_STATUS = '_cubixsol_external_statuses';

    private $http_timeout = 15;

    /**
     * Preview payloads without sending them.
     * Call manually (e.g. via ?debug_payload=ORDER_ID).
     */
    public function preview_payloads($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die("❌ No order found with ID $order_id");
        }

        $items = $order->get_items();
        if (empty($items)) {
            wp_die("❌ Order $order_id has no items");
        }

        $payloads = [];

        foreach ($items as $item) {
            /** @var \WC_Order_Item_Product $item */

            $item_id = $item->get_id();
            $variation_product = $item->get_product();
            $variation_sku = $variation_product ? $variation_product->get_sku() : null;
            $parent_id = $variation_product ? $variation_product->get_parent_id() : $item->get_product_id();
            $parent_sku = get_post_meta($parent_id, '_sku', true);

            $xshop_json = $item->get_meta('xshop_json') ?: get_post_meta($parent_id, 'xshop_json', true);
            $type = get_post_meta($parent_id, '_xshop_type', true);
            $subtype = get_post_meta($parent_id, '_xshop_subtype', true);

            $payload_base = [
                'order_id' => $order->get_id(),
                'order_key' => $order->get_order_key(),
                'item_id' => $item_id,
                'sku' => $variation_sku ?: $parent_sku,
                'quantity' => (int)$item->get_quantity(),
                'price' => (float)$item->get_total(),
                'meta' => [
                    'variation_id' => $item->get_variation_id(),
                    'xshop_variation_name' => $item->get_meta('xshop_variation_name'),
                    'xshop_server' => $item->get_meta('xshop_server'),
                ],
            ];

            $handler = HandlerFactory::make((string)$type, (string)$subtype);
            $payload = $handler->build_payload($payload_base, $xshop_json, $item, $order, $variation_product);

            $payloads[] = [
                'handler' => get_class($handler),
                'endpoint' => $handler->get_endpoint(),
                'payload' => $payload,
            ];
        }

        // Pretty print JSON
        wp_die(
            '<pre style="text-align:left;direction:ltr;">' .
            esc_html(json_encode($payloads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) .
            '</pre>'
        );
    }

    /**
     * The actual processor (unchanged, still sends request).
     */
    public function process($order_id)
    {

        if (!$order_id) return false;

        $synced = get_post_meta($order_id, self::META_SYNC_FLAG, true);
        if ($synced === 'yes') {
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order) return false;

        $items = $order->get_items();
        if (empty($items)) return false;

        $external_ids = (array)get_post_meta($order_id, self::META_EXTERNAL_IDS, true);
        $external_statuses = (array)get_post_meta($order_id, self::META_EXTERNAL_STATUS, true);

        foreach ($items as $item) {
            /** @var \WC_Order_Item_Product $item */
            $item_id = $item->get_id();

            if (!empty($external_ids[$item_id])) {
                continue; // already processed
            }

            $variation_product = $item->get_product();
            $variation_sku = $variation_product ? $variation_product->get_sku() : null;
            $parent_id = $variation_product ? $variation_product->get_parent_id() : $item->get_product_id();
            $parent_sku = get_post_meta($parent_id, '_sku', true);

            $xshop_json = $item->get_meta('xshop_json') ?: get_post_meta($parent_id, 'xshop_json', true);
            $type = get_post_meta($parent_id, '_xshop_type', true);
            $subtype = get_post_meta($parent_id, '_xshop_subtype', true);

            $payload_base = [
                'order_id' => $order->get_id(),
                'order_key' => $order->get_order_key(),
                'item_id' => $item_id,
                'sku' => $variation_sku ?: $parent_sku,
                'quantity' => (int)$item->get_quantity(),
                'price' => (float)$item->get_total(),
                'meta' => [
                    'variation_id' => $item->get_variation_id(),
                    'xshop_variation_name' => $item->get_meta('xshop_variation_name'),
                    'xshop_server' => $item->get_meta('xshop_server'),
                ],
            ];

            $handler = HandlerFactory::make((string)$type, (string)$subtype);
            $payload = $handler->build_payload($payload_base, $xshop_json, $item, $order, $variation_product);

            $response = $this->send_request($handler->get_endpoint(), $payload, $handler->get_headers());

            // handle response (status, ids)
            if (is_wp_error($response)) {
                $external_statuses[$item_id] = [
                    'success' => false,
                    'error' => $response->get_error_message(),
                    'ts' => current_time('mysql'),
                ];
                error_log('[Cubixsol] Request failed for order ' . $order->get_id() . ' item ' . $item_id . ': ' . $response->get_error_message());
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                $decoded = json_decode($body, true);

                if ($code >= 200 && $code < 300) {
                    // (!) Adjust extraction depending on external API response shape
                    $external_id = $decoded['data']['id'] ?? $decoded['order_id'] ?? ($decoded['id'] ?? null);

                    if (!$external_id) {
                        // store raw response for debug if no id
                        $external_statuses[$item_id] = [
                            'success' => false,
                            'error' => 'no_external_id',
                            'response' => $decoded,
                            'ts' => current_time('mysql'),
                        ];
                    } else {
                        $external_ids[$item_id] = $external_id;
                        $external_statuses[$item_id] = [
                            'success' => true,
                            'external_id' => $external_id,
                            'response' => $decoded,
                            'ts' => current_time('mysql'),
                        ];
                    }
                } else {
                    $external_statuses[$item_id] = [
                        'success' => false,
                        'http_code' => $code,
                        'response' => $body,
                        'ts' => current_time('mysql'),
                    ];
                    error_log('[Cubixsol] Non-2xx response for order ' . $order->get_id() . ' item ' . $item_id . ' code ' . $code . ' body: ' . substr($body, 0, 1000));
                }
            }

            // persist after each item to avoid losing progress on fatal errors
            update_post_meta($order->get_id(), self::META_EXTERNAL_IDS, $external_ids);
            update_post_meta($order->get_id(), self::META_EXTERNAL_STATUS, $external_statuses);
        }

        // if at least one item got processed successfully, mark order synced.
        $any_success = array_filter($external_statuses, function ($s) {
            return !empty($s['success']);
        });
        if (!empty($any_success)) {
            update_post_meta($order->get_id(), self::META_SYNC_FLAG, 'yes');
        }

        return true;
    }

    private function send_request($endpoint, $payload, $headers = [])
    {
        if (empty($endpoint)) {
            return new \WP_Error('no_endpoint', 'No endpoint provided');
        }

        $args = [
            'timeout' => $this->http_timeout,
            'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
            'body' => wp_json_encode($payload),
        ];

        return wp_remote_post($endpoint, $args);
    }
}



add_action('init', function() {
    if (!empty($_GET['debug_payload'])) {
        $order_id = (int) $_GET['debug_payload'];
        $processor = new OrderProcessor();
        $processor->preview_payloads($order_id);
        wp_die();
    }
});



/* File: classes/ui/ServerSelect.php */
<?php /** @noinspection SpellCheckingInspection */

namespace classes\ui;


class ServerSelect
{
    // Define the products where select should appear
    private array $targetSlugs = ['legacy-fate-sacred-and-fearless-crossborder','mobile-legends-codashop', 'mobile-legends-cross-border'];

    public function __construct()
    {
        add_action('woocommerce_before_add_to_cart_button', [$this, 'renderServerSelect'], 20);
    }

    /**
     * Render custom select on specific products
     */
    public function old_renderServerSelect()
    {
        global $post;

        if (!$post || empty($post->post_name)) return;

        // Only for products in our target array
        if (!in_array($post->post_name, $this->targetSlugs, true)) return;

        $meta = json_decode(get_post_meta($post->ID, 'xshop_json', true));

        $servers = [];
        foreach ($meta->servers as $s) {
            $servers[$s->id] = $s->name;
        }

        asort($servers, SORT_STRING);

        echo '<div class="cubixsol-server-select-wrapper" style="margin:15px 0;text-align: center;">';
        echo '<label for="server" class="cubixsol-server-select-label" style="display:block;margin-bottom:8px;">Server *</label>';
        echo '<select name="server" id="server" class="cubixsol-server-select" required>';
        echo '<option value="">-الرجاء الاختيار-</option>';

        foreach ($servers as $id => $srv) {
            echo '<option value="' . esc_attr(strtolower(str_replace(' ', '-', $srv))) . '" data-id="' . esc_attr($id) . '">'
                . esc_html($srv)
                . '</option>';
        }

        echo '</select>';
        echo '</div>';

// Add custom styles
        echo '<style>
            .cubixsol-server-select-wrapper {
                text-align: center;
            }
            
            .cubixsol-server-select-label {
                text-align: center;
            }
            
            .cubixsol-server-select {
                background: black;
                color: white;
                text-align: center;
                border: 1px solid #444;
                padding: 8px 12px;
                border-radius: 4px;
            }
            
            /* Default option style */
            .cubixsol-server-select option {
                background: black;
                color: white;
                text-align: center;
            }
            
            /* Selected option */
            .cubixsol-server-select option:checked {
                background: yellow;
                color: black;
            }
            
            /* Hover (works in Chrome/Edge/Firefox, not Safari/iOS) */
            .cubixsol-server-select option:hover {
                background: powderblue;
                color: black;
            }
        </style>';

    }


    public function renderServerSelect()
    {
        global $post;
        if (!$post || empty($post->post_name)) return;

        if (!in_array($post->post_name, $this->targetSlugs, true)) return;

        $meta = json_decode(get_post_meta($post->ID, 'xshop_json', true));
        if (empty($meta->servers)) return;

        $servers = [];
        foreach ($meta->servers as $s) {
            $servers[$s->id] = $s->name;
        }
        asort($servers, SORT_STRING);

        echo '<div class="cubixsol-server-select-wrapper" style="margin:15px 0;text-align:center;">';
        echo '<label for="server" class="cubixsol-server-select-label" style="display:block;margin-bottom:8px;">Server *</label>';

        // main select
        echo '<select name="server" id="server" class="cubixsol-server-select" required>';
        echo '<option value="">-الرجاء الاختيار-</option>';

        foreach ($servers as $id => $srv) {
            echo '<option value="' . esc_attr(strtolower(str_replace(' ', '-', $srv))) . '" data-id="' . esc_attr($id) . '">'
                . esc_html($srv)
                . '</option>';
        }

        echo '</select>';

        // hidden input for server_id
        echo '<input type="hidden" name="server_id" id="server_id" value="">';
        echo '</div>';

        // inline JS
        echo '<script>
    document.addEventListener("DOMContentLoaded", function () {
        const select = document.getElementById("server");
        const hidden = document.getElementById("server_id");

        if (select && hidden) {
            select.addEventListener("change", function () {
                const option = select.options[select.selectedIndex];
                hidden.value = option.getAttribute("data-id") || "";
            });
        }
    });
    </script>';

        // styles
        echo '<style>
        .cubixsol-server-select {
            background: black;
            color: white;
            text-align: center;
            border: 1px solid #444;
            padding: 8px 12px;
            border-radius: 4px;
        }
        .cubixsol-server-select option {
            background: black;
            color: white;
            text-align: center;
        }
        .cubixsol-server-select option:checked {
            background: yellow;
            color: black;
        }
        .cubixsol-server-select option:hover {
            background: powderblue;
            color: black;
        }
    </style>';
    }

}


