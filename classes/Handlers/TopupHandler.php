<?php
// classes/Handlers/TopupHandler.php

namespace classes\Handlers;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/BaseHandler.php';

use classes\BaseHandler;

class TopupHandler extends BaseHandler
{

    /**
     * Build payload for validate or topup.
     * We’ll refine this logic depending on context (validate vs topup).
     */
    public function build_payload(array $base, $xshop_json, $item, $order, $variation_product): array
    {
        $decoded = json_decode($xshop_json, true);

        return [
            'jsonrpc' => '2.0',
            'id'      => 'topup_' . uniqid('', true),
            'method'  => 'validate', // or 'topup' depending on stage
            'params'  => [
                'items' => [[
                    'sku'      => $base['sku'],
                    'quantity' => $base['quantity'],
                    'price'    => [
                        'amount'   => $base['price'],
                        'currency' => $decoded['currency'] ?? 'USD',
                    ],
                ]],
                'userAccount' => $item->get_meta('xshop_userAccount', true) ?: '123456',
                'customerId'  => $order->get_billing_email(),
                'iat'         => time(),
            ],
        ];
    }

    /**
     * Build validate payload according to xShop docs.
     *
     * $base should contain keys: sku, price (amount), quantity, sku_data (array), product (array), customerId
     * $userAccount may be string or array (server/zone)
     */
    public function build_validate_payload(array $base, $xshop_json, $userAccount): array
    {
        $decoded = is_string($xshop_json) ? json_decode($xshop_json, true) : ($xshop_json ?: []);
        $currency = $base['sku_data']['currency'] ?? $decoded['product']['currency'] ?? 'USD';

        $item = [
            'sku' => $base['sku'] ?? null,
            'description' => $base['sku_data']['description'] ?? null,
            'quantity' => (int)($base['quantity'] ?? 1),
            'price' => [
                'amount' => (float)($base['price'] ?? 0.0),
                'currency' => $currency,
            ],
        ];

        return [
            'jsonrpc' => '2.0',
            'id'      => 'validate_' . uniqid('', true),
            'method'  => 'validate',
            'params'  => [
                'items' => [$item],
                'userAccount' => $userAccount,
                'customerId'  => $base['customerId'] ?? '',
                'iat'         => time(),
            ],
        ];
    }

    /**
     * Build topup payload (we include for later stages)
     *
     * $orderId is the orderId returned from validate (string)
     * $userAccount can include server/role info
     */
    public function build_topup_payload(array $base, $xshop_json, $userAccount, string $orderId, bool $usingValidateIdForTopup = false): array
    {
        $decoded = is_string($xshop_json) ? json_decode($xshop_json, true) : ($xshop_json ?: []);
        $currency = $base['sku_data']['currency'] ?? $decoded['product']['currency'] ?? 'USD';

        $item = [
            'sku' => $base['sku'] ?? null,
            'description' => $base['sku_data']['description'] ?? null,
            'quantity' => (int)($base['quantity'] ?? 1),
            'price' => [
                'amount' => (float)($base['price'] ?? 0.0),
                'currency' => $currency,
            ],
        ];

        $params = [
            'items' => [$item],
            'userAccount' => $userAccount,
            'orderId' => $orderId,
            'customerId' => $base['customerId'] ?? '',
            'iat' => time(),
        ];

        $payload = [
            'jsonrpc' => '2.0',
            'id'      => $usingValidateIdForTopup ? ($base['validate_id'] ?? 'topup_' . uniqid('', true)) : ('topup_' . uniqid('', true)),
            'method'  => 'topup',
            'params'  => $params,
        ];

        if ($usingValidateIdForTopup) {
            $payload['usingValidateIdForTopup'] = true;
        }

        return $payload;
    }

    /**
     * Endpoint builder from product apiPath same as VoucherHandler.
     */
    public function get_endpoint($xshop_json = null, $sku = null): string
    {
        $decoded  = is_string($xshop_json) ? json_decode($xshop_json, true) : ($xshop_json ?: []);
        $apiPath  = $decoded['product']['apiPath'] ?? '';
        $apiPath  = ltrim($apiPath, '/');

        return "https://xshop-sandbox.codashop.com/v2/{$apiPath}";
    }

    public function get_headers(): array
    {
        return [
            'Content-Type' => 'application/json',
        ];
    }
}
