<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan;

use PHPStan\Rules\Rule;
use WPMCP\PHPStan\NoForeignNewRule;

/** @extends GateRuleTestCase<NoForeignNewRule> */
final class NoForeignNewRuleTest extends GateRuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoForeignNewRule($this->modules(), $this->origin(), $this->createReflectionProvider(), ['WPMCP\\Api\\V1\\Value\\']);
    }

    public function test_reports_new_of_services(): void
    {
        $message = static fn (string $class): string => sprintf('Module "alpha" creates %s with new. A module creates only value objects (its Value\\ namespace, WPMCP\\Api\\V1\\Value), exceptions, and PHP or WordPress classes; services arrive through the constructor (rule S5).', $class);
        $tip = 'Add a typed constructor parameter; the compiled container resolves it.';
        $this->analyse([self::data('Alpha/Tools/Bad_Tool.php')], [
            [$message('WPMCP\\Kernel\\Container'), 29, $tip],
            [$message(self::NS . 'Alpha\\Adapter\\Wp_Alpha_Adapter'), 30, $tip],
            ['Module "alpha" creates an object from a dynamic class name. Inject the service instead (rule S5).', 37],
        ]);
    }

    public function test_allows_values_exceptions_php_wordpress_and_host_classes(): void
    {
        $this->analyse([
            self::data('Alpha/Tools/List_Items.php'),
            self::data('Alpha/Value/Item.php'),
            self::data('Alpha/Adapter/Wp_Alpha_Adapter.php'),
            self::data('Beta/Value/Token.php'),
            self::data('Alpha/Module.php'),
        ], []);
    }

    public function test_leaves_another_modules_class_to_the_cross_module_rule(): void
    {
        $this->analyse([self::data('Convention/Gamma_Tool.php')], []);
    }

    public function test_ignores_code_outside_modules(): void
    {
        $this->analyse([self::data('Legacy/Service.php'), self::data('Kernel/Thing.php')], []);
    }
}
