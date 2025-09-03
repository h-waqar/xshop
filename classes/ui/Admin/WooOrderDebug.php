<?php
// classes/ui/Admin/WooOrderDebug.php

namespace classes\ui\Admin;

defined('ABSPATH') || exit;

use WC_Order;

class WooOrderDebug
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        // Hook right below order details
        add_action('woocommerce_admin_order_data_after_order_details', [__CLASS__, 'render_debug_button']);

        // Hide raw xshop_json from default item meta
        add_filter('woocommerce_hidden_order_itemmeta', function (array $hidden) {
            $hidden[] = 'xshop_json';
            return $hidden;
        });
    }

    public static function render_debug_button(WC_Order $order): void
    {
        $items_debug = [];
        foreach ($order->get_items() as $item_id => $item) {
            $debug_data = get_post_meta($order->get_id(), '_xshop_debug_' . $item_id, true);
            if ($debug_data) {
                $items_debug[$item_id] = [
                    'payload'  => $debug_data['payload'] ?? null,
                    'response' => $debug_data['response'] ?? null,
                ];
            }
        }

        if (!$items_debug) {
            return; // Nothing to show
        }

        $output = json_encode($items_debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        ?>
        <div class="form-field form-field-wide wc-customer-user" style="margin-top:2rem;">
            <button type="button" class="button button-secondary " id="xshop-debug-btn">
                <?php echo esc_html__('View API Response', 'cubixsol'); ?>
            </button>
        </div>

        <div id="xshop-debug-modal" style="display:none;">
            <div class="xshop-debug-modal-overlay"></div>
            <div class="xshop-debug-modal-content">
                <span class="xshop-debug-close">&times;</span>
                <h2><?php echo esc_html__('XShop API Debug Data', 'cubixsol'); ?></h2>
                <pre><?php echo esc_html($output); ?></pre>
            </div>
        </div>

        <style>
            #xshop-debug-modal {
                position: fixed;
                top: 0; left: 0;
                width: 100%; height: 100%;
                background: rgba(0,0,0,0.6);
                z-index: 100000;
            }
            .xshop-debug-modal-overlay {
                position: absolute;
                width: 100%; height: 100%;
                top: 0; left: 0;
            }
            .xshop-debug-modal-content {
                position: absolute;
                top: 50%; left: 50%;
                transform: translate(-50%, -50%);
                background: #fff;
                padding: 20px;
                max-width: 800px;
                max-height: 80%;
                overflow: auto;
                border-radius: 6px;
                box-shadow: 0 2px 20px rgba(0,0,0,0.3);
            }
            .xshop-debug-modal-content pre {
                background: #f7f7f7;
                border: 1px solid #ddd;
                padding: 10px;
                font-size: 12px;
                line-height: 1.4;
                white-space: pre-wrap;
                word-break: break-word;
                max-height: 500px;
                overflow: auto;
            }
            .xshop-debug-close {
                position: absolute;
                right: 15px;
                top: 10px;
                font-size: 20px;
                cursor: pointer;
            }
        </style>

        <script>
            (function($){
                $(document).ready(function(){
                    $('#xshop-debug-btn').on('click', function(){
                        $('#xshop-debug-modal').fadeIn(200);
                    });
                    $('.xshop-debug-close, .xshop-debug-modal-overlay').on('click', function(){
                        $('#xshop-debug-modal').fadeOut(200);
                    });
                });
            })(jQuery);
        </script>
        <?php
    }
}
