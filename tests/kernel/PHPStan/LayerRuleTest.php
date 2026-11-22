<?php

declare(strict_types=1);

namespace WPMCP\Tests\Kernel\PHPStan;

use PHPStan\Rules\Rule;
use WPMCP\PHPStan\LayerRule;

/** @extends GateRuleTestCase<LayerRule> */
final class LayerRuleTest extends GateRuleTestCase
{
    private const HOST_TIP = 'Add a method to the module adapter interface and implement it in Adapter\\.';
    private const MODULE_TIP = 'Move the logic to Hooks\\ or a service; only the availability method, boot() and is_available() may live on the Module class.';

    protected function getRule(): Rule
    {
        return new LayerRule($this->modules(), $this->origin(), $this->createReflectionProvider());
    }

    public function test_tools_depend_only_on_the_adapter_interface_and_values(): void
    {
        $layer = static fn (string $class, string $kind, string $from): string => sprintf('Tools\\ code of module "alpha" names %s (%s) from %s\\. Tools\\ may depend on Adapter\\, Value\\ (Adapter\\ interfaces only) (rule S1).', self::NS . $class, $kind, $from);
        $impl = static fn (string $kind): string => sprintf('Tools\\ code of module "alpha" names the adapter implementation %s (%s). Outside Adapter\\, type against the adapter interface (rule S1).', self::NS . 'Alpha\\Adapter\\Wp_Alpha_Adapter', $kind);
        $host = static fn (string $what): string => sprintf('Tools\\ code of module "alpha" uses %s, a host plugin symbol (not PHP, WordPress or WPMCP). Only Adapter\\ touches the host plugin (rule S1).', $what);
        $this->analyse([self::data('Alpha/Tools/Bad_Tool.php')], [
            [$impl('parameter type'), 19],
            [$layer('Alpha\\Hooks\\Item_Hooks', '::class', 'Hooks'), 26],
            [$layer('Alpha\\Ops\\Item_Ops', 'static call', 'Ops'), 27],
            [$impl('new'), 30],
            [$host('acme_gate_orders() (function call)'), 31, self::HOST_TIP],
            [$host('Acme_Gate_Order (static call)'), 32, self::HOST_TIP],
            [$host('Acme_Gate_Order (new)'), 33, self::HOST_TIP],
        ]);
    }

    public function test_the_adapter_does_not_reach_up(): void
    {
        $message = static fn (string $kind): string => sprintf('Adapter\\ code of module "alpha" names %s (%s) from Tools\\. Adapter\\ may depend on Value\\ (rule S1).', self::NS . 'Alpha\\Tools\\List_Items', $kind);
        $this->analyse([self::data('Alpha/Adapter/Leaky_Adapter.php')], [
            [$message('parameter type'), 12],
            [$message('return type'), 16],
        ]);
    }

    public function test_allowed_layering_and_host_calls_in_the_adapter(): void
    {
        $this->analyse([
            self::data('Alpha/Module.php'),
            self::data('Alpha/Adapter/Alpha_Adapter.php'),
            self::data('Alpha/Adapter/Wp_Alpha_Adapter.php'),
            self::data('Alpha/Tools/List_Items.php'),
            self::data('Alpha/Ops/Item_Ops.php'),
            self::data('Alpha/Hooks/Item_Hooks.php'),
            self::data('Alpha/Value/Item.php'),
        ], []);
    }

    public function test_module_class_declares_and_never_executes(): void
    {
        $message = static fn (string $what): string => sprintf('The Module class of module "beta" declares %s. Module.php declares and never executes (rule S1).', $what);
        $this->analyse([self::data('Beta/Module.php')], [
            [$message('a function statement next to the class'), 11, self::MODULE_TIP],
            [$message('a trait use in Beta_Module'), 18, self::MODULE_TIP],
            [$message('a property in Beta_Module'), 20, self::MODULE_TIP],
            [$message('method Beta_Module::register()'), 26, self::MODULE_TIP],
        ]);
    }

    public function test_reports_a_folder_outside_the_layer_list(): void
    {
        $this->analyse([self::data('Alpha/Helpers/Util.php')], [
            ['Module "alpha" has code in Helpers\\, which is not a module layer. Use Tools, Ops, Snapshots, Hooks, Admin, Adapter or Value (rule S1).', 7],
        ]);
    }
}
