<?php
// classes/XshopApiClient.php

namespace classes;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/CLogger.php';

use classes\CLogger;
use Throwable;

class XshopApiClient
{
    /**
     * Send request to XShop API.
     *
     * @param string $apiPath   e.g. "pubg-mobile-cross-border"
     * @param array  $payload   JSON-RPC payload
     * @param string $method    HTTP method (default POST)
     * @return array normalized response
     */
    public static function request(string $apiPath, array $payload, string $method = 'POST'): array
    {
        $url = defined('API_BASE_URL')
            ? rtrim(API_BASE_URL, '/') . '/' . ltrim($apiPath, '/')
            : "https://xshop-sandbox.codashop.com/v2/" . ltrim($apiPath, '/');

        // Prevent double v2
        $url = str_replace('/v2/v2/', '/v2/', $url);

        try {



            CLogger::log("XShop API → {$url}", $payload);

            // Call existing curl wrapper
            $res = xshop_api_request_curl($apiPath, $payload, $method);

            $status    = $res['status'] ?? ($res['response']['code'] ?? 0);
            $rawBody   = $res['body'] ?? '';
            $decoded   = $res['json'] ?? (json_decode($rawBody, true) ?: null);

            $normalized = [
                'success' => in_array((int)$status, [200, 201], true) && is_array($decoded),
                'status'  => (int)$status,
                'url'     => $url,
                'payload' => $payload,
                'body'    => $rawBody,
                'decoded' => $decoded,
                'raw'     => $res,
            ];

            CLogger::log("XShop API Response [{$status}]", $normalized);

            return $normalized;
        } catch (Throwable $e) {
            CLogger::log("XShop API Exception", $e->getMessage());
            return [
                'success' => false,
                'status'  => 0,
                'url'     => $url,
                'payload' => $payload,
                'error'   => $e->getMessage(),
            ];
        }
    }
}
