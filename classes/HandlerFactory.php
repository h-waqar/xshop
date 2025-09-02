<?php

//  classes/HandlerFactory.php:3

namespace classes;

defined('ABSPATH') || exit;

use classes\Handlers\TopupHandler;
use classes\Handlers\VoucherHandler;
use classes\Handlers\GenericHandler;

class HandlerFactory
{
    public static function make(string $type, string $subtype)
    {
        $type = strtolower(trim($type));
        $subtype = strtolower(trim($subtype));

        if ($type === 'topup') {
            return new TopupHandler();
        }
        if ($type === 'voucher') {
            return new VoucherHandler();
        }

        return new GenericHandler();
    }
}
