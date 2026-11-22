<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan;

use PHPStan\Rules\Rule;
use WPMCP\PHPStan\NoStaticHelperCallRule;

/** @extends GateRuleTestCase<NoStaticHelperCallRule> */
final class NoStaticHelperCallRuleTest extends GateRuleTestCase
{
    /** @var list<string> */
    private array $allowlist = [];

    protected function getRule(): Rule
    {
        return new NoStaticHelperCallRule($this->modules(), $this->origin(), $this->createReflectionProvider(), ['WPMCP\\Api\\V1\\Value\\'], $this->allowlist);
    }

    private static function message(string $call): string
    {
        return sprintf('Module "alpha" calls %s() statically. Module code calls static methods only on self or static, enums and value objects; inject a service instead (rule S7).', $call);
    }

    public function test_reports_static_calls_on_wpmcp_classes(): void
    {
        $tip = 'Make it an instance method of a service the constructor receives.';
        $this->analyse([self::data('Alpha/Tools/Bad_Tool.php')], [
            [self::message(self::NS . 'Alpha\\Ops\\Item_Ops::operations'), 27, $tip],
            [self::message(self::NS . 'Legacy\\Service::instance'), 28, $tip],
            [self::message(self::NS . 'Legacy\\Service::operations'), 38, $tip],
        ]);
    }

    public function test_the_allowlist_grandfathers_a_legacy_call(): void
    {
        $this->allowlist = [self::NS . 'Legacy\\Service::OPERATIONS', self::NS . 'Alpha\\Ops\\*'];
        $this->analyse([self::data('Alpha/Tools/Bad_Tool.php')], [
            [self::message(self::NS . 'Legacy\\Service::instance'), 28, 'Make it an instance method of a service the constructor receives.'],
        ]);
    }

    public function test_allows_self_enums_values_php_wordpress_and_host(): void
    {
        $this->analyse([
            self::data('Alpha/Tools/List_Items.php'),
            self::data('Alpha/Ops/Item_Ops.php'),
            self::data('Alpha/Value/Item.php'),
            self::data('Alpha/Adapter/Wp_Alpha_Adapter.php'),
        ], []);
    }

    public function test_ignores_code_outside_modules(): void
    {
        $this->analyse([self::data('Kernel/Thing.php'), self::data('Legacy/Service.php')], []);
    }
}
