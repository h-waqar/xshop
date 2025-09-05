<?php
// classes/Handlers/TopupHandler.php

namespace classes\Handlers;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/BaseHandler.php';

use classes\BaseHandler;

class TopupHandler extends BaseHandler
{
    public function get_type(): string
    {
        return 'topup';
    }

    public function build_payload(array $base, array $xshop_json, $item, $order, $variation_product): array
    {
        // Default build_payload acts like validate
        return $this->build_validate_payload($base, $xshop_json, $item->get_meta('xshop_userAccount', true));
    }

    public function build_validate_payload(array $base, array $xshop_json, $userAccount): array
    {
        $decoded  = $this->decode_json($xshop_json);
        $currency = $base['sku_data']['currency'] ?? $decoded['product']['currency'] ?? 'USD';

        $item = [
            'sku'        => $base['sku'] ?? null,
            'description'=> $base['sku_data']['description'] ?? null,
            'quantity'   => (int)($base['quantity'] ?? 1),
            'price'      => [
                'amount'   => (float)($base['price'] ?? 0.0),
                'currency' => $currency,
            ],
        ];

        return [
            'jsonrpc' => '2.0',
            'id'      => 'validate_' . uniqid('', true),
            'method'  => 'validate',
            'params'  => [
                'items'      => [$item],
                'userAccount'=> $userAccount,
                'customerId' => $base['customerId'] ?? '',
                'iat'        => time(),
            ],
        ];
    }

    public function build_topup_payload(array $base, array $xshop_json, $userAccount, string $orderId, bool $usingValidateIdForTopup = false): array
    {
        $decoded  = $this->decode_json($xshop_json);
        $currency = $base['sku_data']['currency'] ?? $decoded['product']['currency'] ?? 'USD';

        $item = [
            'sku'        => $base['sku'] ?? null,
            'description'=> $base['sku_data']['description'] ?? null,
            'quantity'   => (int)($base['quantity'] ?? 1),
            'price'      => [
                'amount'   => (float)($base['price'] ?? 0.0),
                'currency' => $currency,
            ],
        ];

        $params = [
            'items'      => [$item],
            'userAccount'=> $userAccount,
            'orderId'    => $orderId,
            'customerId' => $base['customerId'] ?? '',
            'iat'        => time(),
        ];

        $payload = [
            'jsonrpc' => '2.0',
            'id'      => $usingValidateIdForTopup
                ? ($base['validate_id'] ?? 'topup_' . uniqid('', true))
                : ('topup_' . uniqid('', true)),
            'method'  => 'topup',
            'params'  => $params,
        ];

        if ($usingValidateIdForTopup) {
            $payload['usingValidateIdForTopup'] = true;
        }

        return $payload;
    }

    public function get_endpoint($xshop_json = null, $sku = null): string
    {
        $decoded  = $this->decode_json($xshop_json);
        $apiPath  = ltrim($decoded['product']['apiPath'] ?? '', '/');
        return "https://xshop-sandbox.codashop.com/v2/{$apiPath}";
    }
}
