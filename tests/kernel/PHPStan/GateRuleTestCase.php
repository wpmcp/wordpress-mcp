<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use WPMCP\PHPStan\ModuleMap;
use WPMCP\PHPStan\SymbolOrigin;

/**
 * Shared wiring for the gate rule tests: the fixture tree under Data/ (two
 * modules, alpha and beta, from Data/boot.php), its WordPress and host
 * stand-ins, and the real WPMCP\Api.
 *
 * PHPStan's test harness ignores scanDirectories and scanFiles: it finds
 * classes through composer's PSR-4 map and the runtime autoloaders. So the
 * fixture namespace (WPMCP\Tests\Kernel\PHPStan\Data\) follows the
 * directory exactly, and the global stand-ins are loaded at runtime, which
 * lets PHPStan locate them by the file that declared them.
 *
 * @template TRule of Rule
 * @extends RuleTestCase<TRule>
 */
abstract class GateRuleTestCase extends RuleTestCase
{
    protected const NS = 'WPMCP\\Tests\\Kernel\\PHPStan\\Data\\';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once self::data('stubs/wordpress.php');
        require_once self::data('stubs/host.php');
    }

    protected static function data(string $file): string
    {
        return __DIR__ . '/Data/' . $file;
    }

    /** @param list<string> $registries */
    protected function modules(?array $registries = null): ModuleMap
    {
        return new ModuleMap(
            $registries ?? [self::data('boot.php')],
            dirname(__DIR__, 3),
            ['WPMCP\\Modules\\'],
            $this->createReflectionProvider(),
        );
    }

    protected function origin(): SymbolOrigin
    {
        return new SymbolOrigin($this->createReflectionProvider(), ['*/PHPStan/Data/stubs/wordpress.php']);
    }
}
