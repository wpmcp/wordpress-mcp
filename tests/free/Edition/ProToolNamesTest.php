<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Edition;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Pro_Catalog;
use WPMCP\MCP\Refusal_Result;
use WPMCP\MCP\Registrar;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\MCP\Unavailable;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Tests\Free\MCP\Mcp_Wire;
use WPMCP\Tests\Support\Edition_Tree;

/**
 * A Pro tool called by name on the free zip (2.0 regression, issue #628).
 *
 * In 1.2.x the free plugin declared the Pro tools, so a call for one
 * answered with the Pro refusal and the upgrade link. From 2.0 their code is
 * in pro/ only, which the free zip does not carry, so the name was simply
 * unknown ("Tool not found"). The free build now knows the Pro tools by name
 * (build/registry/pro-tools.php, generated from the ability manifest): a
 * call for one answers pro_required as a tool error, the name is never
 * listed, and a name nothing knows stays a protocol error. With the Pro code
 * active the real tool answers.
 *
 * Runs in both trees: without the Pro code (bin/free-tree-smoke.sh) it
 * calls the real free tree; with it, as_the_free_zip() puts in a registrar
 * that declares no Pro tool and knows them by the build's names, which is
 * what the free zip boots with.
 *
 * @group free-tree
 */
final class ProToolNamesTest extends \WP_UnitTestCase
{
    use Mcp_Wire;

    /** A real Pro tool that reads nothing from the site. */
    private const PRO_ABILITY = 'wpmcp/check-contrast';

    private const PRO_TOOL = 'wpmcp-check-contrast';

    private const ARGUMENTS = [ 'foreground' => '#000000', 'background' => '#ffffff' ];

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        Rate_Limiter::set_clock_override(static fn (): int => 1_790_000_629);
        add_filter('wpmcp_rate_limit', static fn (): int => 100000);
        $id = self::factory()->user->create([ 'role' => 'administrator' ]);
        if (is_multisite()) {
            grant_super_admin($id);
        }
        wp_set_current_user($id);
    }

    protected function tearDown(): void
    {
        $this->restore_wire();
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_rate_limit');
        Rate_Limiter::set_clock_override(null);
        Governance::reset_for_tests();
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        parent::tearDown();
    }

    /**
     * The Pro tool names this build's registry carries.
     *
     * @return array<array-key, mixed>
     */
    private static function build_names(): array
    {
        return Plugin::instance()->kernel()->registry()->data_file(Pro_Catalog::DATA_FILE);
    }

    private function as_the_free_zip(): void
    {
        if (Edition_Tree::has_pro_code()) {
            $this->use_registrar(new Registrar(new Pro_Catalog(self::build_names())));
        }
    }

    public function test_the_build_knows_the_pro_tools_by_name_only(): void
    {
        $manifest = Edition_Tree::ability_manifest();
        $this->assertContains(self::PRO_ABILITY, self::build_names(), 'build/registry/pro-tools.php lists the Pro tools');
        $this->assertSame([], array_values(array_diff(self::build_names(), array_keys((array) (require dirname(__DIR__, 2) . '/support/ability-manifest.php')['abilities']))));
        foreach (self::build_names() as $name) {
            $this->assertArrayNotHasKey($name, array_filter($manifest['abilities'], static fn (string $tier): bool => 'free' === $tier), "{$name} is free");
        }

        // Where the Pro code runs, its own abilities answer: the catalog is empty.
        $this->assertSame([], Pro_Catalog::for_build(true, [ self::PRO_ABILITY ])->names());
        $catalog = Pro_Catalog::for_build(false, [ self::PRO_ABILITY, 7, null ]);
        $this->assertSame([ self::PRO_ABILITY ], $catalog->names());
        $this->assertTrue($catalog->has(self::PRO_ABILITY));
        $this->assertFalse($catalog->has('wpmcp/get-page'));
    }

    public function test_a_pro_tool_name_on_the_free_zip_answers_pro_required_with_the_upgrade_link(): void
    {
        $this->as_the_free_zip();
        $this->mount();

        foreach ([ false, true ] as $modern) {
            $result   = $this->on_both(self::PRO_TOOL, self::ARGUMENTS, $modern);
            $envelope = $result['_meta'][ Refusal_Result::META_KEY ] ?? null;

            $this->assertTrue($result['isError'], (string) wp_json_encode($result));
            $this->assertIsArray($envelope, (string) wp_json_encode($result));
            $this->assertSame('pro_required', $envelope['code']);
            $this->assertSame('pro_required', $envelope['reason']);
            $this->assertSame('account_owner', $envelope['action']['who']);
            $this->assertTrue($envelope['hidden']);
            $this->assertStringContainsString('"' . self::PRO_ABILITY . '" is a WPMCP Pro tool', $envelope['message']);

            $text = $result['content'][0]['text'];
            $this->assertStringStartsWith('Unavailable (pro_required): ', $text);
            $this->assertStringContainsString('https://wpmcp-pro.com/pricing', $text, 'the upgrade link');
            $this->assertStringContainsString('utm_medium=tool-refusal', $text);
            $this->assertStringEndsWith('Docs: ' . Unavailable::docs_url('pro_required'), $text);
            $this->assertSame($envelope, json_decode($result['content'][1]['text'], true));
        }

        // The meta-tools say the same, by ability name.
        $dispatched = $this->stdio('wpmcp-call-tool', [ 'name' => self::PRO_ABILITY, 'arguments' => self::ARGUMENTS ])['result'];
        $this->assertTrue($dispatched['isError']);
        $this->assertSame('pro_required', $dispatched['_meta'][ Refusal_Result::META_KEY ]['code']);
        $this->assertSame('pro_required', Plugin::instance()->registrar()->pro_required(self::PRO_ABILITY)?->get_error_code());
    }

    public function test_a_pro_tool_on_the_free_zip_is_never_listed(): void
    {
        $this->as_the_free_zip();
        $this->mount();

        foreach ([ false, true ] as $modern) {
            [ $status, $http ] = $this->http_request('tools/list', [], $modern);
            $stdio = $this->stdio_request('tools/list', [], $modern);

            $this->assertSame(200, $status);
            foreach ([ 'stdio' => $stdio, 'http' => $http ] as $transport => $answer) {
                $names = array_column((array) ($answer['result']['tools'] ?? []), 'name');
                $this->assertNotEmpty($names, $transport);
                $this->assertNotContains(self::PRO_TOOL, $names, $transport);
            }
        }
    }

    public function test_a_name_nothing_knows_stays_a_protocol_error(): void
    {
        $this->as_the_free_zip();
        $this->mount();
        $unknown = 'wpmcp-nothing-by-this-name';

        $this->assertSame(-32602, $this->stdio($unknown)['error']['code'] ?? null);
        $this->assertSame(-32602, $this->stdio($unknown, [], true)['error']['code'] ?? null);
        [ $status, $legacy ] = $this->http($unknown);
        $this->assertArrayNotHasKey('result', $legacy);
        $this->assertSame(-32003, $legacy['error']['code'] ?? null, 'the adapter keeps its "Tool not found"');
        $this->assertSame(404, $status);
        [ , $modern ] = $this->http($unknown, [], true);
        $this->assertSame(-32602, $modern['error']['code'] ?? null);
        $this->assertNull(Plugin::instance()->registrar()->pro_required('wpmcp/nothing-by-this-name'));
    }

    public function test_a_pro_tool_answers_normally_when_the_pro_code_is_active(): void
    {
        if (! Edition_Tree::has_pro_code()) {
            $this->markTestSkipped('This tree carries no Pro code; the free zip answers pro_required instead.');
        }

        $declared = null;
        foreach (Plugin::instance()->registrar()->declared() as $ability) {
            if (self::PRO_ABILITY === $ability->name) {
                $declared = $ability;
            }
        }
        $this->assertInstanceOf(Ability::class, $declared, 'the Pro code declares the tool');

        Gate::set_pro_for_tests(true);
        $this->register_on(new Registrar(), static function (Registrar $registrar) use ($declared): void {
            $registrar->register($declared);
        }, [ self::PRO_ABILITY ]);
        $this->mount();

        foreach ([ false, true ] as $modern) {
            $result = $this->on_both(self::PRO_TOOL, self::ARGUMENTS, $modern);

            $this->assertFalse($result['isError'], (string) wp_json_encode($result));
            $this->assertArrayNotHasKey(Refusal_Result::META_KEY, (array) ($result['_meta'] ?? []));
            $this->assertEqualsWithDelta(21.0, (float) ($result['structuredContent']['ratio'] ?? 0), 0.01, (string) wp_json_encode($result));
        }
    }
}
