<?php

//  classes/HandlerFactory.php:3

namespace classes;

defined('ABSPATH') || exit;

include_once PLUGIN_DIR_PATH . 'classes/Handlers/TopupHandler.php';
include_once PLUGIN_DIR_PATH . 'classes/Handlers/VoucherHandler.php';


use classes\Handlers\TopupHandler;
use classes\Handlers\VoucherHandler;

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

        return false;
    }
}
