<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\Unit;

use PHPUnit\Framework\TestCase;
use WPMCP\Kernel\Container;
use WPMCP\Kernel\Lazy_Handler;
use WPMCP\Tests\Kernel\Fixture\No_Deps;

final class LazyHandlerTest extends TestCase
{
    public function test_does_not_construct_the_handler_until_called(): void
    {
        $before = No_Deps::$constructed;
        $handler = new Lazy_Handler(new Container(factories: [No_Deps::class => []]), No_Deps::class, 'handle');

        self::assertSame($before, No_Deps::$constructed);
        self::assertSame(['echo' => ['a' => 1]], $handler(['a' => 1]));
        self::assertSame($before + 1, No_Deps::$constructed);
    }

    public function test_works_through_call_user_func_and_exposes_its_target(): void
    {
        $handler = new Lazy_Handler(new Container(), No_Deps::class, 'handle');

        self::assertSame(['echo' => []], call_user_func($handler, []));
        self::assertSame([No_Deps::class, 'handle'], $handler->target());
        self::assertTrue(is_callable($handler));
    }
}
