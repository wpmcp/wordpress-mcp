<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Ability_Grid_Page;
use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;

/**
 * Issue #1128: the Abilities screen opens with a summary (total, enabled,
 * disabled, high-risk enabled), groups domains under headings with readable
 * names and the slug in small text, shows read, write and destructive
 * counts on each collapsed domain, and keeps its filters and domain toggle.
 */
class AbilityGridSummaryTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Plugin::instance()->declared_abilities();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        Governance::reset_for_tests();
        delete_option(Governance_Audit_Log::OPTION);
        $_POST = [];
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        Governance::reset_for_tests();
        delete_option(Governance_Audit_Log::OPTION);
        $_POST = [];
        parent::tearDown();
    }

    private function render(): string
    {
        set_current_screen('toplevel_page_' . Ability_Grid_Page::SLUG);
        ob_start();
        (new Ability_Grid_Page())->render();
        return (string) ob_get_clean();
    }

    /** @return array<int, array<string, mixed>> */
    private function all_rows(): array
    {
        return array_merge(...array_values((new Ability_Grid_Page())->rows()));
    }

    public function test_summary_counts_match_the_rows_and_follow_a_toggle(): void
    {
        $page = new Ability_Grid_Page();
        $rows = $this->all_rows();
        $high = static fn (array $r): bool => $r['enabled'] && ($r['destructive'] || $r['dangerous']);

        $summary = $page->summary($page->rows());
        $this->assertSame(count($rows), $summary['total']);
        $this->assertSame(count(array_filter($rows, static fn ($r) => $r['enabled'])), $summary['enabled']);
        $this->assertSame($summary['total'] - $summary['enabled'], $summary['disabled']);
        $this->assertSame(count(array_filter($rows, $high)), $summary['high_risk_enabled']);

        Governance::set_ability_toggle('wpmcp/get-page', false);
        $after = $page->summary($page->rows());
        $this->assertSame($summary['enabled'] - 1, $after['enabled']);
        $this->assertSame($summary['disabled'] + 1, $after['disabled']);

        $html = $this->render();
        $this->assertStringContainsString('class="wpmcp-ability-summary"', $html);
        $this->assertMatchesRegularExpression('#data-count="disabled"[^>]*>' . $after['disabled'] . '<#', $html);
        $this->assertMatchesRegularExpression('#data-count="total"[^>]*>' . $after['total'] . '<#', $html);
        $this->assertMatchesRegularExpression('#data-count="high-risk-enabled"[^>]*>' . $after['high_risk_enabled'] . '<#', $html);
    }

    public function test_domains_are_grouped_with_readable_names_and_the_slug_in_small_text(): void
    {
        $groups = (new Ability_Grid_Page())->groups();

        $this->assertArrayHasKey('content', $groups['WordPress']);
        $this->assertArrayHasKey('elementor', $groups['Page builders']);
        $this->assertSame('Site tools', array_keys($groups)[2] ?? null);
        $this->assertSame('The Events Calendar', Ability_Grid_Page::domain_label('tec'));
        $this->assertSame('Paid Memberships Pro', Ability_Grid_Page::domain_label('pmpro'));
        $this->assertSame('Some New Thing', Ability_Grid_Page::domain_label('some-new_thing'), 'an unknown slug still reads as words');

        $html = $this->render();
        foreach (array_keys($groups) as $label) {
            $this->assertStringContainsString('<h2 class="wpmcp-domain-group">' . esc_html($label) . '</h2>', $html);
        }
        $this->assertMatchesRegularExpression('#<span class="wpmcp-domain-name">Content</span>\s*<code class="wpmcp-domain-slug"><small>content</small></code>#', $html);
    }

    public function test_each_domain_row_shows_read_write_and_destructive_counts(): void
    {
        $grid    = (new Ability_Grid_Page())->rows();
        $content = $grid['content'];
        $counts  = Ability_Grid_Page::risk_counts($content);

        $this->assertSame(count($content), $counts['read'] + $counts['write']);
        $this->assertSame(count(array_filter($content, static fn ($r) => 'read' === $r['operation'])), $counts['read']);
        $this->assertSame(count(array_filter($content, static fn ($r) => $r['destructive'] || $r['dangerous'])), $counts['destructive']);

        $html = $this->render();
        $this->assertMatchesRegularExpression(
            '#data-domain="content"[^>]*>\s*<summary[^>]*>.*?' . $counts['read'] . ' read, ' . $counts['write'] . ' write, ' . $counts['destructive'] . ' destructive#s',
            $html
        );
    }

    public function test_a_domain_toggle_switches_it_as_a_whole_and_is_audited(): void
    {
        $_POST = [
            'wpmcp_grid_action' => 'toggle_domain',
            '_wpnonce'          => wp_create_nonce(Ability_Grid_Page::NONCE_ACTION),
            'domain'            => 'content',
            'enabled'           => '0',
        ];
        $html = $this->render();

        foreach ((new Ability_Grid_Page())->rows()['content'] as $row) {
            $this->assertFalse($row['enabled'], $row['name']);
        }
        $this->assertSame('domain:content', Governance_Audit_Log::list(1)[0]['ability']);
        $this->assertStringContainsString('is now disabled', $html);
    }

    public function test_plain_intro_labelled_new_tools_toggle_title_and_filters_kept(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('<h1>WP MCP: Abilities</h1>', $html);
        $this->assertStringNotContainsString('governance state', $html);
        $this->assertStringNotContainsString('narrowing layer', $html);
        $this->assertMatchesRegularExpression('#<input type="checkbox" id="wpmcp-auto-enable-new" name="enabled" value="1"[^>]*>\s*<label for="wpmcp-auto-enable-new">#', $html);
        $this->assertMatchesRegularExpression('#<label for="wpmcp-auto-enable-new">.*?</label>.*?<p class="description">#s', $html);
        foreach ([ 'wpmcp-ability-row', 'data-state=', 'data-risk=', 'class="wpmcp-domain"' ] as $hook) {
            $this->assertStringContainsString($hook, $html, 'the filter script still finds ' . $hook);
        }
        $this->assertStringContainsString('class="wpmcp-ability-list"', $html);
    }
}
