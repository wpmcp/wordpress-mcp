<?php

declare(strict_types=1);

namespace WPMCP\Tests\Pro\Builders;

use PHPUnit\Framework\TestCase;
use WPMCP\Tools\Builders\Engine_Template_Settings;

/**
 * Breakdance and Oxygen 6 template settings as their rows hold them: a JSON
 * object, a JSON string of a JSON object (the plugins' own set_meta encodes a
 * value that is already JSON), or an array a caller stored directly.
 */
final class EngineTemplateSettingsTest extends TestCase
{
    private Engine_Template_Settings $settings;

    protected function setUp(): void
    {
        $this->settings = new Engine_Template_Settings();
    }

    public function test_a_json_object_decodes_to_its_array(): void
    {
        $this->assertSame(['type' => 'all'], $this->settings->decode('{"type":"all"}'));
    }

    public function test_a_json_string_of_a_json_object_decodes_to_its_array(): void
    {
        $stored = (string) json_encode((string) json_encode(['type' => 'all', 'ruleGroups' => []]));

        $this->assertSame(['type' => 'all', 'ruleGroups' => []], $this->settings->decode($stored));
    }

    public function test_an_array_is_returned_as_is(): void
    {
        $this->assertSame(['type' => 'all'], $this->settings->decode(['type' => 'all']));
    }

    /** @dataProvider unreadable */
    public function test_anything_else_is_no_settings(mixed $stored): void
    {
        $this->assertSame([], $this->settings->decode($stored));
    }

    /** @return array<string, array{mixed}> */
    public static function unreadable(): array
    {
        return [
            'empty row'     => [''],
            'not json'      => ['{type'],
            'a json scalar' => ['"all"'],
            'a json number' => ['3'],
            'false'         => [false],
            'null'          => [null],
        ];
    }
}
