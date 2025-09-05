<?php
// classes/ui/TopupUI.php

namespace classes\ui;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/CLogger.php';

use classes\CLogger;

class TopupUI
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_footer', [__CLASS__, 'print_modal_markup'], 999);
    }

    public static function enqueue_assets(): void
    {
        // enqueue script
        wp_register_script(
            'xshop-validate-js',
            plugins_url('../assets/js/xshop-validate.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );

        // pass config
        wp_localize_script('xshop-validate-js', 'xshopValidateConfig', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('xshop-validate'),
            'checkout_url' => wc_get_checkout_url(),
        ]);

        wp_enqueue_script('xshop-validate-js');
        // basic style for modal (you can move to css file later)
        wp_add_inline_style('xshop-validate-inline', '.xshop-validate-modal{display:none;position:fixed;left:0;top:0;width:100%;height:100%;z-index:100000;background:rgba(0,0,0,.6)}.xshop-validate-modal .panel{background:#fff;max-width:720px;margin:6% auto;padding:20px;border-radius:6px;}');
    }

    public static function print_modal_markup(): void
    {
        ?>
        <div id="xshop-validate-modal" class="xshop-validate-modal" style="display:none;">
            <div class="panel" role="dialog" aria-modal="true" aria-labelledby="xshop-validate-title">
                <h2 id="xshop-validate-title"><?php echo esc_html__('Confirm account & role', 'cubixsol'); ?></h2>
                <div id="xshop-validate-body">
                    <p><?php echo esc_html__('Validating... please wait', 'cubixsol'); ?></p>
                </div>
                <div style="margin-top:12px;text-align:right;">
                    <button type="button" id="xshop-validate-cancel"><?php echo esc_html__('Cancel', 'cubixsol'); ?></button>
                    <button type="button" id="xshop-validate-confirm"><?php echo esc_html__('Add to cart', 'cubixsol'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
}
