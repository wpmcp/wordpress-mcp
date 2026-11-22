<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Adapter;

use WPMCP\Integrations\Support\Site_Gates;
use WPMCP\Modules\Site_Gate_Settings\Value\Bypass_Change;
use WPMCP\Modules\Site_Gate_Settings\Value\Bypass_State;
use WPMCP\Modules\Site_Gate_Settings\Value\Content_Change;
use WPMCP\Modules\Site_Gate_Settings\Value\Gate_Plugin;
use WPMCP\Modules\Site_Gate_Settings\Value\Refusal;
use WPMCP\Modules\Site_Gate_Settings\Value\Stored;
use WPMCP\Modules\Site_Gate_Settings\Value\Write_Plan;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * SeedProd (coming-soon 6.20.13) keeps its landing page as a page whose
 * post_content_filtered is the builder's JSON and whose post_content is the
 * HTML the builder renders in the browser on save. Visitors get that HTML
 * (resources/views/seedprod-preview.php echoes post_content), so editing a
 * text or image in the JSON alone changes nothing they see, and rewriting
 * the builder's HTML to match is not safe: content edits are refused,
 * naming the SeedProd editor for the page.
 *
 * Bypass: render_comingsoon_page() reads access_by_role from the top level
 * of that JSON (role slugs, or anyoneloggedin; empty lets every logged-in
 * user through). That one key is rewritten, every other node is kept as
 * decoded (objects stay objects), and the undo point is a post snapshot of
 * the page. The Lite plugin never sets the bypass cookie its bypass_phrase
 * would need, and does not read access_by_ip, so neither is editable here;
 * whether a phrase is set is still reported, never the phrase.
 */
final class Seedprod_Store implements Gate_Store
{
    public function __construct(
        private readonly Field_Rules $rules,
        private readonly Site_Gates $gates,
    ) {
    }

    public function slug(): string
    {
        return Gate_Plugin::Seedprod->value;
    }

    public function label(): string
    {
        return 'SeedProd';
    }

    /** The page of the mode that is on, else the coming soon page, else the maintenance page. */
    private function page(): ?\WP_Post
    {
        $settings = $this->gates->seedprod_settings();
        $keys = (bool) ($settings['enable_maintenance_mode'] ?? false) && ! (bool) ($settings['enable_coming_soon_mode'] ?? false)
            ? ['seedprod_maintenance_mode_page_id', 'seedprod_coming_soon_page_id']
            : ['seedprod_coming_soon_page_id', 'seedprod_maintenance_mode_page_id'];
        foreach ($keys as $key) {
            $id = Stored::int(get_option($key));
            $page = $id > 0 ? get_post($id) : null;
            if ($page instanceof \WP_Post) {
                return $page;
            }
        }
        return null;
    }

    private static function json(\WP_Post $page): ?\stdClass
    {
        $json = json_decode($page->post_content_filtered);
        return $json instanceof \stdClass ? $json : null;
    }

    private static function editor_url(int $page_id): string
    {
        return admin_url('admin.php?page=seedprod_lite_builder&id=' . $page_id);
    }

    /** @return array<string, mixed> */
    public function content(): array
    {
        $page = $this->page();
        return [
            'page_id' => $page instanceof \WP_Post ? $page->ID : null,
            'headline' => $page instanceof \WP_Post ? get_the_title($page) : null,
            'editable' => [],
            'editor_url' => $page instanceof \WP_Post ? self::editor_url($page->ID) : null,
            'note' => 'SeedProd pages are edited in its builder; the page title is shown as the headline.',
        ];
    }

    public function plan_content(Content_Change $change): Refusal
    {
        $page = $this->page();
        $id = $page instanceof \WP_Post ? $page->ID : null;
        return new Refusal(
            'seedprod_builder_content',
            'SeedProd shows visitors the HTML its builder renders when the page is saved there, so editing the builder JSON here would change nothing they see. Edit the text and images in the SeedProd editor' . (null === $id ? '.' : sprintf(' (page %d).', $id)),
            ['page_id' => $id, 'editor_url' => null === $id ? null : self::editor_url($id)],
        );
    }

    public function bypass(): Bypass_State
    {
        $page = $this->page();
        return self::state_of($page instanceof \WP_Post ? self::json($page) : null);
    }

    private static function state_of(?\stdClass $json): Bypass_State
    {
        return new Bypass_State(
            null === $json ? [] : Stored::strings(Stored::prop($json, 'access_by_role') ?? []),
            null,
            null,
            null,
            null !== $json && (bool) Stored::prop($json, 'bypass_phrase'),
        );
    }

    /** @return list<string> */
    public function bypass_fields(): array
    {
        return ['roles'];
    }

    public function plan_bypass(Bypass_Change $change): Write_Plan|Refusal
    {
        $refusal = $this->rules->first_unsupported($this->label(), $change->fields(), $this->bypass_fields())
            ?? $this->rules->role_refusal($change->roles ?? [], is_multisite() ? ['anyoneloggedin', 'superadmin'] : ['anyoneloggedin']);
        if (null !== $refusal) {
            return $refusal;
        }
        $page = $this->page();
        $json = $page instanceof \WP_Post ? self::json($page) : null;
        if (null === $page || null === $json) {
            return new Refusal('seedprod_page_missing', 'SeedProd has no coming soon or maintenance page set up whose settings can be read. Create and choose it in SeedProd first.');
        }
        $json->access_by_role = $change->roles ?? [];

        return new Write_Plan(
            [],
            $page->ID,
            (string) wp_json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            self::state_of($json),
        );
    }

    public function lets_through(Bypass_State $state): bool
    {
        if ([] === ($state->roles ?? [])) {
            return true; // Every logged-in user passes.
        }
        foreach ($state->roles ?? [] as $role) {
            $role = str_replace(' ', '', strtolower($role));
            if ('anyoneloggedin' === $role || current_user_can($role)) {
                return true;
            }
        }
        return false;
    }
}
