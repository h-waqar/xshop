<?php
// classes/BaseHandler.php

namespace classes;

defined('ABSPATH') || exit;

abstract class BaseHandler
{
    /**
     * Build the generic payload for this handler (voucher or topup).
     * For Topup, this is usually the "validate" payload.
     */
    abstract public function build_payload(
        array $base,
        array $xshop_json,
              $item,
              $order,
              $variation_product
    ): array;

    /**
     * Every handler must declare its type: 'voucher' or 'topup'
     */
    abstract public function get_type(): string;

    /**
     * Helper: decode xshop_json consistently into array
     */
    protected function decode_json($xshop_json): array
    {
        if (is_string($xshop_json)) {
            $decoded = json_decode($xshop_json, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($xshop_json) ? $xshop_json : [];
    }

    public function get_endpoint(): string
    {
        return 'https://xshop-sandbox.codashop.com/v2';
    }

    public function get_headers(): array
    {
        return ['Content-Type' => 'application/json'];
    }
}
