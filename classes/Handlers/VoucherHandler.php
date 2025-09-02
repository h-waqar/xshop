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
