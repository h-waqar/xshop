<?php

// classes/BaseHandler.php:4

namespace classes;

defined('ABSPATH') || exit;

abstract class BaseHandler
{
    abstract public function build_payload(array $base, $xshop_json, $item, $order, $variation_product): array;

    public function get_endpoint(): string
    {
        return 'https://xshop-sandbox.codashop.com/v2';
    }

    public function get_headers(): array
    {
        return [];
    }
}
