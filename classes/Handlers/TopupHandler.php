<?php
// classes/Handlers/TopupHandler.php

namespace classes\Handlers;

defined('ABSPATH') || exit;

//include_once XSHOP_PLUGIN_DIR_PATH . 'classes/BaseHandler.php';
//include_once XSHOP_PLUGIN_DIR_PATH . 'classes/CLogger.php';

use classes\BaseHandler;
use classes\CLogger;

class TopupHandler extends BaseHandler
{
    public function get_type(): string
    {
        return 'topup';
    }

    public function build_payload(array $base, $xshop_json, $item, $order, $variation_product): array
    {
        $decoded = $this->decode_json($xshop_json);
        $userAccount = $item->get_meta('xshop_userAccount', true);

        // If we’re in an actual Woo order (has ID) → topup
        if ($order && $order->get_id()) {
            // prefer validate_order_id returned by validate call (must be used in topup)
            $orderIdFromValidate = $base['validate_order_id'] ?? null;

            // if not present, fall back to using validate_id or last fallback use Woo order id (less preferred)
            $usingValidateIdForTopup = !empty($base['validate_id']);
            $orderIdToSend = $orderIdFromValidate ?: ($base['validate_id'] ?? (string) $order->get_id());

            return $this->build_topup_payload($base, $decoded, $userAccount, (string) $orderIdToSend, (bool) $usingValidateIdForTopup);
        }

        // Otherwise (cart/validate flow) → validate
        return $this->build_validate_payload($base, $decoded, $userAccount);
    }


    public function build_validate_payload(array $base, $xshop_json, $userAccount): array
    {
        $decoded = $this->decode_json($xshop_json);
        $currency = $base['sku_data']['currency'] ?? $decoded['product']['currency'] ?? 'USD';
        $subtype = $decoded['product']['subtype'] ?? null;

        //        CLogger::log('build_validate_payload', '');
//        CLogger::log('$base', $base);
//        CLogger::log('userAccount from "build_validate_payload()"', $userAccount);

        // Build the common item
        $item = [
            'sku' => $base['sku'] ?? null,
            'description' => $base['sku_data']['description'] ?? null,
            'quantity' => (int) ($base['quantity'] ?? 1),
            'price' => [
                 'amount'   => (float)($base['price'] ?? 0.0),
                 'currency' => $currency,
            ],
        ];

        // Build userAccount dynamically based on subtype
        switch ((string) $subtype) {
            case '1':
                // simple userId only (string)
//                CLogger::log('userAccount from "case 1"', $userAccount);
                $ua = (string) ($userAccount ?: '');
                break;

            case '2':
                // requires userId + server {id, name}
                $ua = [
                    'userId' => $userAccount ?? '',
                    'server' => [
                        'id' => $base['server_id'] ?? '',
                        'name' => $base['server_name'] ?? '',
                    ],
                ];
                break;

            case '3':
                // requires userId + zoneId
                $ua = [
                    'userId' => $userAccount ?? '',
                    'zoneId' => $base['zone_id'] ?? '',
                ];
                break;

            default:
                // fallback (treat like subtype 1)
//                CLogger::log('Default Case Called');
                $ua = is_string($userAccount) ? $userAccount : ($userAccount['userId'] ?? '');
                break;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => 'validate_' . uniqid('', true),
            'method' => 'validate',
            'params' => [
                'items' => [$item],
                'userAccount' => $ua,
                'customerId' => $base['customerId'] ?? '',
                'iat' => time(),
            ],
        ];
    }


    public function build_topup_payload(array $base, $xshop_json, $userAccount, string $orderId, bool $usingValidateIdForTopup = false): array
    {

        $decoded = $this->decode_json($xshop_json);
        $currency = $base['sku_data']['currency'] ?? $decoded['product']['currency'] ?? 'USD';
        $subtype = $decoded['product']['subtype'] ?? null;

//        CLogger::log('---------------------BAM-----------------------------');
//
//        CLogger::log('Decoded', $decoded);
//
//        CLogger::log('Currency ', $currency);
//
//        CLogger::log('Subtype ', $subtype);
//
//        CLogger::log('---------------------BAM-----------------------------');

        // Build the item
        $item = [
            'sku' => $base['sku'] ?? '',
            'description' => $base['sku_data']['description'] ?? '',
            'quantity' => (int) ($base['quantity'] ?? 1),
            'price' => [
                'amount' => (float) ($base['price'] ?? 0.0),
                'currency' => $currency,
            ],
        ];

        // Build userAccount same way as validate
        switch ((string) $subtype) {
            case '1':
                $ua = (string) ($userAccount ?: '');
                // if role provided, topup expects userAccount as object with roleId for subtype=1 with role (rare)
                if (!empty($base['role_id'])) {
                    $ua = [
                        'userId' => (string) $userAccount,
                        'roleId' => (string) $base['role_id']
                    ];
                }
                break;

            case '2':
                $ua = [
                    'userId' => $userAccount['userId'] ?? '',
                    'server' => [
                        'id' => $userAccount['server']['id'] ?? '',
                        //                        'name' => $base['server_name'] ?? '',
                    ],
                ];
                if (!empty($base['role_id'])) {
                    $ua['roleId'] = (string) $base['role_id'];
                }
                break;

            case '3':
                $ua = [
                    'userId' => $userAccount['userId'] ?? '',
                    'zoneId' => $userAccount['zoneId'] ?? '',
                    //                    $userAccount ?? ''
                ];
                if (!empty($base['role_id'])) {
                    $ua['roleId'] = (string) $base['role_id'];
                }
                break;

            default:
                $ua = is_string($userAccount) ? $userAccount : ($userAccount['userId'] ?? '');
                if (!empty($base['role_id'])) {
                    if (is_string($ua)) {
                        $ua = ['userId' => $ua, 'roleId' => (string) $base['role_id']];
                    } else {
                        $ua['roleId'] = (string) $base['role_id'];
                    }
                }
                break;
        }

        $params = [
            'items' => [$item],
            'userAccount' => $ua,
            'orderId' => $orderId,
            'customerId' => $base['customerId'] ?? '',
            'iat' => time(),
        ];

        $payload = [
            'jsonrpc' => '2.0',
            'id' => $usingValidateIdForTopup
                ? ($base['validate_id'] ?? 'topup_' . uniqid('', true))
                : ('topup_' . uniqid('', true)),
            'method' => 'topup',
            'params' => $params,
        ];

        if ($usingValidateIdForTopup) {
            $payload['usingValidateIdForTopup'] = true;
        }

        return $payload;
    }

    public function get_endpoint($xshop_json = null, $sku = null): string
    {
        $decoded = $this->decode_json($xshop_json);
        $apiPath = ltrim($decoded['product']['apiPath'] ?? '', '/');

        return API_BASE_URL . $apiPath;
    }
}
