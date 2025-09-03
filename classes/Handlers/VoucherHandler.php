<?php

namespace classes\Handlers;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/BaseHandler.php';

use classes\BaseHandler;

class VoucherHandler extends BaseHandler
{
    /**
     * Build payload for placeOrder
     */
    public function build_payload(array $base, $xshop_json, $item, $order, $variation_product): array
    {
        $decoded = json_decode($xshop_json, true);

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

    /**
     * Build endpoint dynamically from product API path
     */
    public function get_endpoint($xshop_json = null, $sku = null): string
    {
        $decoded  = json_decode($xshop_json, true);
        $apiPath  = $decoded['product']['apiPath'] ?? '';
        $apiPath  = ltrim($apiPath, '/');

        return "https://xshop-sandbox.codashop.com/v2/{$apiPath}";
    }

    /**
     * Add required headers
     */
    public function get_headers(): array
    {
        return [
            'Content-Type' => 'application/json',
        ];
    }
}
