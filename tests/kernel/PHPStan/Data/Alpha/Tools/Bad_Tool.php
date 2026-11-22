<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Tools;

use WPMCP\Kernel\Container;
use WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Adapter\Wp_Alpha_Adapter;
use WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Hooks\Item_Hooks;
use WPMCP\Tests\Kernel\PHPStan\Data\Alpha\Ops\Item_Ops;
use WPMCP\Tests\Kernel\PHPStan\Data\Beta\Tools\Ping;
use WPMCP\Tests\Kernel\PHPStan\Data\Beta\Value\Token;
use WPMCP\Tests\Kernel\PHPStan\Data\Legacy\Beta_Owned;
use WPMCP\Tests\Kernel\PHPStan\Data\Legacy\Service;

// Every line in run() breaks at least one gate; the tests name which.
final class Bad_Tool
{
    public function __construct(private readonly Wp_Alpha_Adapter $adapter)
    {
    }

    public function run(Token $token): ?Beta_Owned
    {
        $ping = Ping::now();
        $hooks = Item_Hooks::class;
        $ops = Item_Ops::operations();
        $service = Service::instance();
        $container = new Container();
        $adapter = new Wp_Alpha_Adapter();
        $rows = acme_gate_orders();
        $order = \Acme_Gate_Order::find(1);
        $made = new \Acme_Gate_Order();
        $token = Token::make('x');
        $failure = new Alpha_Failure('allowed');
        $name = 'stdClass';
        $dynamic = new $name();
        $legacy = Service::operations();
        if ($token instanceof \WPMCP\Tests\Kernel\PHPStan\Data\Beta\Value\Token) {
            return null;
        }
        try {
            $this->adapter->items(1);
        } catch (\WPMCP\Tests\Kernel\PHPStan\Data\Beta\Value\Token_Error $e) {
            return null;
        }
        return [$ping, $hooks, $ops, $service, $container, $adapter, $rows, $order, $made, $failure, $dynamic, $legacy] === [] ? null : null;
    }
}
