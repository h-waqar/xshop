<?php

namespace classes\Ui\Admin;

defined('ABSPATH') || exit;

use WC_Order;

class WooOrderDetails
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) return;
        self::$initialized = true;

        add_action('woocommerce_admin_order_data_after_order_details', [__CLASS__, 'render_order_details']);

        // Hide raw xshop_json from default item meta
        add_filter('woocommerce_hidden_order_itemmeta', function ($hidden) {
            return array_merge($hidden, ['xshop_json', 'xshop_role_id']);
        });

        // Remove unwanted meta from frontend order item display
        add_filter('woocommerce_order_item_get_formatted_meta_data', function ($formatted_meta, $item) {
            $blocked_keys = ['xshop_json', 'xshop_validate_orderId', 'xshop_role_id',];

            foreach ($formatted_meta as $meta_id => $meta) {
                if (in_array($meta->key, $blocked_keys, true)) {
                    unset($formatted_meta[$meta_id]);
                }
            }

            return $formatted_meta;
        }, 10, 2);
    }

    public static function render_order_details(WC_Order $order): void
    {
        $items_debug = [];
        foreach ($order->get_items() as $item_id => $item) {
            $relevant_keys = ['xshop_product', 'xshop_selected_sku', 'xshop_validate', 'xshop_userAccount', 'xshop_resolved_fields', 'xshop_role_id',];

            $item_data = [];
            foreach ($relevant_keys as $key) {
                $meta = $item->get_meta($key, true);
                if (!empty($meta)) $item_data[$key] = $meta;
            }

            $api_debug = get_post_meta($order->get_id(), '_xshop_debug_' . $item_id, true);

            if ($item_data) {
                $items_debug[$item_id] = ['name' => $item->get_name(), 'data' => $item_data, 'api_debug' => $api_debug ?? null];
            }
        }

        if (empty($items_debug)) {
            echo '<p><strong>No XShop data found for this order.</strong></p>';
            return;
        }

        // CSS
        echo <<<HTML
<style>
.xshop-order-details { display:flex; flex-wrap:wrap; gap:20px; margin-top:15px; }
.xshop-item-card { flex:1 1 30%; min-width:280px; border:1px solid #ddd; border-radius:8px; background:#fff; padding:20px; box-sizing:border-box; }
.xshop-item-card h4 { margin-bottom:10px; font-size:16px; color:#0073aa; }
.xshop-item-card h5 { margin:15px 0 8px; font-size:14px; color:#555; font-weight:700; border-bottom:1px solid #eee; padding-bottom:3px; }
.xshop-item-card table { width:100%; border-collapse:collapse; margin-bottom:10px; }
.xshop-item-card td { padding:6px; vertical-align:top; }
.xshop-item-card td:first-child { font-weight:600; color:#333; width:40%; }
.supported-countries-label { font-weight:600; margin-top:10px; display:block; color:#333; }
.supported-countries { display:flex; flex-wrap:nowrap; gap:6px; overflow-x:auto; padding:6px 0; max-width:100%; }
.supported-countries span { background:#f1f1f1; border-radius:12px; padding:4px 8px; font-size:12px; white-space:nowrap; }
.api-debug-btn { margin-top:10px; display:inline-block; }
.xshop-debug-modal { display:none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.85); z-index:100000; }
.xshop-debug-modal-overlay { position:absolute; width:100%; height:100%; top:0; left:0; }
.xshop-debug-modal-content { position:absolute; top:0; left:0; width:100%; height:100%; background:#fff; padding:20px; overflow:auto; box-sizing:border-box; }
.xshop-debug-modal-content pre { background:#f7f7f7; border:1px solid #ddd; padding:10px; font-size:12px; line-height:1.4; white-space:pre-wrap; word-break:break-word; max-height:fit-content; overflow:auto; }
.xshop-debug-close { position:absolute; right:15px; top:10px; font-size:24px; cursor:pointer; }
@media(max-width:1024px) { .xshop-item-card { flex:1 1 45%; } }
@media(max-width:768px) { .xshop-item-card { flex:1 1 100%; } }
</style>
HTML;

        echo '<h3 style="margin:15px 0;">XShop Order Details</h3>';
        echo '<div class="xshop-order-details">';

        foreach ($items_debug as $item_id => $item_info) {
            echo '<div class="xshop-item-card">';
            echo '<h4>' . esc_html($item_info['name']) . '</h4>';

            foreach ($item_info['data'] as $category => $values) {
                if (empty($values)) continue;

                echo '<h5>' . esc_html(self::humanize_category($category)) . '</h5>';

                // Supported Countries
                if ($category === 'xshop_product' && !empty($values['supportedCountries'])) {
                    echo '<span class="supported-countries-label">Supported Countries:</span>';
                    echo '<div class="supported-countries">';
                    foreach ($values['supportedCountries'] as $country) {
                        echo '<span>' . esc_html($country) . '</span>';
                    }
                    echo '</div>';
                }

                // Role
                if ($category === 'xshop_role_id') {
                    echo '<table><tbody>';
                    echo '<tr><td>Role</td><td>' . esc_html($values) . '</td></tr>';
                    echo '</tbody></table>';
                    continue;
                }

                $flat = self::flatten_value($values);
                $flat = array_filter($flat, fn($v) => $v !== '' && $v !== null);

                echo '<table><tbody>';
                foreach ($flat as $key => $val) {
                    if ($category === 'xshop_product' && strpos($key, 'supportedCountries') === 0) continue;

                    if ($category === 'xshop_resolved_fields') {
                        $allowed = ['product_id', 'variation_id', 'quantity', 'server', 'server_id', 'user_id'];
                        if (!in_array(strtolower($key), $allowed)) continue;
                    }

                    echo '<tr><td>' . esc_html(self::humanize_key($key)) . '</td><td>' . esc_html($val) . '</td></tr>';
                }
                echo '</tbody></table>';
            }

            // API Debug Button
            if (!empty($item_info['api_debug'])) {
                $json_output = json_encode($item_info['api_debug'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $btn_id = "xshop-debug-btn-{$item_id}";
                $modal_id = "xshop-debug-modal-{$item_id}";

//                echo "<button class='button button-secondary api-debug-btn' id='{$btn_id}'>View API Response</button>";
                echo "<button type='button' class='button button-secondary api-debug-btn' id='{$btn_id}'>View API Response</button>";

                echo "<div id='{$modal_id}' class='xshop-debug-modal'>
                        <div class='xshop-debug-modal-overlay'></div>
                        <div class='xshop-debug-modal-content'>
                            <span class='xshop-debug-close'>&times;</span>
                            <h2>XShop API Debug Data</h2>
                            <pre>" . esc_html($json_output) . "</pre>
                        </div>
                    </div>";

                echo <<<JS
<script>
(function($){
    $(document).ready(function(){
        $('#{$btn_id}').on('click', function(){
            $('#{$modal_id}').fadeIn(200);
        });
        $('#{$modal_id} .xshop-debug-close, #{$modal_id} .xshop-debug-modal-overlay').on('click', function(){
            $('#{$modal_id}').fadeOut(200);
        });
    });
})(jQuery);
</script>
JS;
            }

            echo '</div>'; // end card
        }

        echo '</div>'; // end grid
    }

    private static function humanize_category(string $category): string
    {
        return match ($category) {
            'xshop_product' => 'Product Info',
            'xshop_selected_sku' => 'Selected SKU',
            'xshop_validate' => 'Validation',
            'xshop_userAccount' => 'User Account',
            'xshop_resolved_fields' => 'Resolved Fields',
            'xshop_role_id' => 'Role',
            default => ucwords(str_replace('_', ' ', $category)),
        };
    }

    private static function flatten_value($data, string $prefix = ''): array
    {
        $result = [];
        if (is_object($data)) $data = (array)$data;

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $full_key = $prefix === '' ? $key : $prefix . '.' . $key;
                if (is_array($value) || is_object($value)) {
                    $result = array_merge($result, self::flatten_value($value, $full_key));
                } else {
                    $result[$full_key] = $value;
                }
            }
        } else {
            $result[$prefix] = $data;
        }

        return $result;
    }

    private static function humanize_key(string $key): string
    {
        $key = str_replace(['_', '.'], ' ', $key);
        $key = preg_replace('/\d+/', '', $key);
        return ucwords($key);
    }
}
