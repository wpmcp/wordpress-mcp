<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan;

use PHPStan\Rules\Rule;
use WPMCP\PHPStan\NoCrossModuleReferenceRule;

/** @extends GateRuleTestCase<NoCrossModuleReferenceRule> */
final class NoCrossModuleReferenceRuleTest extends GateRuleTestCase
{
    /** @var list<string> */
    private array $kernel = ['WPMCP\\Kernel\\', 'WPMCP\\Api\\'];

    private bool $registry = true;

    protected function getRule(): Rule
    {
        return new NoCrossModuleReferenceRule(
            $this->modules($this->registry ? null : [self::data('missing-boot.php')]),
            $this->kernel,
            ['WPMCP\\Api\\'],
        );
    }

    public function test_reports_classes_of_another_module_and_internal_classes(): void
    {
        $cross = static fn (string $class, string $kind, string $owner = 'beta'): string => sprintf('Module "alpha" names %s (%s), a class of module "%s". Modules never name each other (rule S8).', self::NS . $class, $kind, $owner);
        $internal = static fn (string $class, string $kind): string => sprintf('Module "alpha" names %s (%s), an internal WPMCP class. Modules depend only on WPMCP\\Api and the platform namespaces (rule S8).', $class, $kind);
        $tip = 'Depend on an interface in WPMCP\\Api, or bind one with #[Provides] and inject it.';
        $this->analyse([self::data('Alpha/Tools/Bad_Tool.php')], [
            [$cross('Beta\\Value\\Token', 'parameter type'), 23, $tip],
            [$cross('Legacy\\Beta_Owned', 'return type'), 23, $tip],
            [$cross('Beta\\Tools\\Ping', 'static call'), 25, $tip],
            [$internal(self::NS . 'Legacy\\Service', 'static call'), 28],
            [$internal('WPMCP\\Kernel\\Container', 'new'), 29],
            [$cross('Beta\\Value\\Token', 'static call'), 34, $tip],
            [$internal(self::NS . 'Legacy\\Service', 'static call'), 38],
            [$cross('Beta\\Value\\Token', 'instanceof'), 39, $tip],
            [$cross('Beta\\Value\\Token_Error', 'catch'), 44, $tip],
        ]);
    }

    public function test_allows_own_classes_the_api_php_wordpress_and_the_host(): void
    {
        $this->analyse([
            self::data('Alpha/Module.php'),
            self::data('Alpha/Tools/List_Items.php'),
            self::data('Alpha/Ops/Item_Ops.php'),
            self::data('Alpha/Hooks/Item_Hooks.php'),
            self::data('Alpha/Adapter/Wp_Alpha_Adapter.php'),
            self::data('Beta/Module.php'),
        ], []);
    }

    public function test_the_kernel_never_names_a_module(): void
    {
        $this->kernel = [self::NS . 'Kernel\\'];
        $message = static fn (string $class, string $owner): string => sprintf('%s names %s (::class), a class of module "%s". The kernel and the API never name a module; modules register through attributes and the compiled registry.', self::NS . 'Kernel', self::NS . $class, $owner);
        $this->analyse([self::data('Kernel/Thing.php')], [
            [$message('Alpha\\Tools\\List_Items', 'alpha'), 22],
            [$message('Legacy\\Beta_Owned', 'beta'), 22],
        ]);
    }

    public function test_falls_back_to_the_namespace_convention_without_a_registry(): void
    {
        $this->registry = false;
        $message = 'Module "Gamma" names WPMCP\\Modules\\Delta\\Value\\Thing (%s), a class of module "Delta". Modules never name each other (rule S8).';
        $tip = 'Depend on an interface in WPMCP\\Api, or bind one with #[Provides] and inject it.';
        $this->analyse([self::data('Convention/Gamma_Tool.php')], [
            [sprintf($message, 'return type'), 10, $tip],
            [sprintf($message, 'new'), 12, $tip],
        ]);
    }
}
