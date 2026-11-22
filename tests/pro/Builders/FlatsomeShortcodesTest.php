<?php

declare(strict_types=1);

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Tools\Builders\{Flatsome_Shortcodes, WPBakery_Shortcodes};

/**
 * The Flatsome (UX Builder) dialect of the shared shortcode layout parser.
 * The fixture follows Flatsome's documented page storage: section > row >
 * col, a nested row_inner > col_inner, ux_text with an HTML body (and a
 * shortcode inside it), elements with no closing tag (button, gap,
 * ux_image), a UX Block placed with [block id], and a ux_banner holding a
 * text_box, with the blank lines UX Builder writes between tags.
 */
class FlatsomeShortcodesTest extends \WP_UnitTestCase
{
    public const BUTTON = '[button text="Shop now" style="outline" link="/shop"]';

    public const FIXTURE = '[section bg_color="rgb(247, 247, 247)" padding="60px"]' . "\n\n"
        . '[row style="large" v_align="middle"]' . "\n\n"
        . '[col span="6" span__sm="12"]' . "\n\n"
        . '[ux_text font_size="1.2" text_align="left"]' . "\n"
        . '<h2>Welcome</h2>' . "\n" . '<p>Hello \\ world [ux_current_year]</p>' . "\n"
        . '[/ux_text]' . "\n"
        . self::BUTTON . "\n"
        . '[gap height="20px"]' . "\n\n"
        . '[/col]' . "\n"
        . '[col span="6" span__sm="12"]' . "\n\n"
        . '[ux_image id="42" image_size="large"]' . "\n"
        . '[row_inner][col_inner span="6"][title text="Inner"][/col_inner][/row_inner]' . "\n\n"
        . '[/col]' . "\n\n"
        . '[/row]' . "\n\n"
        . '[/section]' . "\n"
        . '[block id="17"]' . "\n"
        . '[ux_banner height="500px" bg="12"][text_box width="60"]<h3>Banner</h3>[/text_box][/ux_banner]';

    public function test_flatsome_reuses_the_shared_shortcode_parser(): void
    {
        $this->assertTrue(is_subclass_of(Flatsome_Shortcodes::class, WPBakery_Shortcodes::class));
    }

    public function test_tree_exposes_sections_rows_columns_and_elements(): void
    {
        $tree = Flatsome_Shortcodes::tree(self::FIXTURE);

        $this->assertSame(['section', 'block', 'ux_banner'], array_column($tree, 'tag'));
        $this->assertSame('60px', $tree[0]['attrs']['padding']);
        $this->assertSame('17', $tree[1]['attrs']['id']);

        $col = $tree[0]['children'][0]['children'][0];
        $this->assertSame('0.0.0', $col['path']);
        $this->assertSame('12', $col['attrs']['span__sm']);
        $this->assertSame(['ux_text', 'button', 'gap'], array_column($col['children'], 'tag'));
        $this->assertSame("\n<h2>Welcome</h2>\n<p>Hello \\ world [ux_current_year]</p>\n", $col['children'][0]['text']);
        $this->assertSame('Shop now', $col['children'][1]['attrs']['text']);
        $this->assertArrayNotHasKey('children', $col['children'][1]);

        $inner = $tree[0]['children'][0]['children'][1]['children'][1];
        $this->assertSame('row_inner', $inner['tag']);
        $this->assertSame('0.0.1.1.0.0', $inner['children'][0]['children'][0]['path']);
        $this->assertSame('<h3>Banner</h3>', $tree[2]['children'][0]['text']);
    }

    public function test_new_elements_follow_ux_builder_conventions(): void
    {
        $this->assertSame(
            '[row][col span="12"][/col][/row]',
            Flatsome_Shortcodes::serialize(['tag' => 'row', 'children' => [['tag' => 'col', 'attrs' => ['span' => 12]]]])
        );
        $this->assertSame('[ux_text][/ux_text]', Flatsome_Shortcodes::serialize(['tag' => 'ux_text']));
        $this->assertSame('[gap height="30px"]', Flatsome_Shortcodes::serialize(['tag' => 'gap', 'attrs' => ['height' => '30px']]));
        $this->assertSame(
            '[button text="Say &quot;hi&quot; &#91;now&#93;"]',
            Flatsome_Shortcodes::serialize(['tag' => 'button', 'attrs' => ['text' => 'Say "hi" [now]']])
        );
    }

    public function test_edits_keep_every_byte_outside_the_touched_element(): void
    {
        $updated = Flatsome_Shortcodes::update(self::FIXTURE, '0.0.0.1', ['style' => 'primary'], null);
        $this->assertSame(
            str_replace(self::BUTTON, '[button text="Shop now" style="primary" link="/shop"]', self::FIXTURE),
            $updated
        );

        $text = Flatsome_Shortcodes::update(self::FIXTURE, '2.0', null, '<h3>New</h3>');
        $this->assertSame(str_replace('<h3>Banner</h3>', '<h3>New</h3>', self::FIXTURE), $text);

        [$added, $path] = Flatsome_Shortcodes::add(self::FIXTURE, '0.0.1', 0, ['tag' => 'divider']);
        $this->assertSame('0.0.1.0', $path);
        $this->assertSame(str_replace('[ux_image id="42"', '[divider][ux_image id="42"', self::FIXTURE), $added);
        $this->assertSame(self::FIXTURE, Flatsome_Shortcodes::remove($added, '0.0.1.0'));

        $moved = Flatsome_Shortcodes::move(self::FIXTURE, '1', '', 0);
        $this->assertSame(['block', 'section', 'ux_banner'], array_column(Flatsome_Shortcodes::tree($moved), 'tag'));
    }

    public function test_a_void_element_takes_no_text(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Flatsome_Shortcodes::update(self::FIXTURE, '1', null, 'x');
    }
}
