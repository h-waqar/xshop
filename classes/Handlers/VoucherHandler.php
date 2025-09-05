<?php
// classes/Handlers/VoucherHandler.php

namespace classes\Handlers;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/BaseHandler.php';

use classes\BaseHandler;

class VoucherHandler extends BaseHandler
{
    public function get_type(): string
    {
        return 'voucher';
    }

    public function build_payload(array $base, array $xshop_json, $item, $order, $variation_product): array
    {
        $decoded = $this->decode_json($xshop_json);

        return [
            'jsonrpc' => '2.0',
            'id'      => 'voucher_' . uniqid('', true),
            'method'  => 'placeOrder',
            'params'  => [
                'items' => [[
                    'sku'      => $base['sku'],
                    'quantity' => $base['quantity'],
                    'price'    => [
                        'amount'   => $base['price'],
                        'currency' => $decoded['currency'] ?? 'USD',
                    ],
                ]],
                'customerId' => $order->get_billing_email(),
                'iat'        => time(),
            ],
        ];
    }

    public function get_endpoint($xshop_json = null, $sku = null): string
    {
        $decoded = $this->decode_json($xshop_json);
        $apiPath = ltrim($decoded['product']['apiPath'] ?? '', '/');

        return "https://xshop-sandbox.codashop.com/v2/{$apiPath}";
    }
}
