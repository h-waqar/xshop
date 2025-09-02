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
