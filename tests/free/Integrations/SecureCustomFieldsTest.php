<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\ACF_Integration;

/**
 * Issue #1089: Secure Custom Fields, the ACF fork on wordpress.org, reports
 * through the existing ACF pair. The pair detects ACF by function
 * (acf_get_field_groups, get_fields, update_field), never by plugin file or
 * class, and calls only ACF's global API, so a site running SCF instead of
 * ACF gets the same operations. This checks both halves against the global
 * functions SCF 6.9.10 declares (tests/support/scf-6.9.10-functions.txt):
 * every function the pair detects ACF by, and every ACF API function the
 * pair and the flat ACF tools call, must be one SCF defines.
 */
final class SecureCustomFieldsTest extends \WP_UnitTestCase
{
    /** ACF's template API functions that carry no acf_ prefix. */
    private const TEMPLATE_API = [ 'get_field', 'get_fields', 'get_field_object', 'get_field_objects', 'update_field', 'delete_field', 'have_rows', 'the_row', 'get_sub_field', 'update_sub_field', 'get_row', 'the_field' ];

    /** @return array<string, true> */
    private function scf_functions(): array
    {
        $lines = file(dirname(__DIR__, 2) . '/support/scf-6.9.10-functions.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $names = [];
        foreach ((array) $lines as $line) {
            if ('' !== $line && '#' !== $line[0]) {
                $names[ trim($line) ] = true;
            }
        }
        return $names;
    }

    /** @return string[] the ACF pair's source files and the flat ACF tools. */
    private function acf_sources(): array
    {
        $root  = dirname(__DIR__, 3);
        $files = array_merge(glob($root . '/src/Integrations/ACF_*.php') ?: [], glob($root . '/src/Tools/ACF/*.php') ?: []);
        sort($files);
        return $files;
    }

    /**
     * Global functions a file calls: a name followed by "(" that is not a
     * method, a static call, a declaration or a "new".
     *
     * @return string[]
     */
    private function called_functions(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $names  = [];
        $count  = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[ $i ];
            if (! is_array($token) || ! in_array($token[0], [ T_STRING, T_NAME_FULLY_QUALIFIED ], true)) {
                continue;
            }
            $next = $i + 1;
            while ($next < $count && is_array($tokens[ $next ]) && T_WHITESPACE === $tokens[ $next ][0]) {
                $next++;
            }
            if ($next >= $count || '(' !== $tokens[ $next ]) {
                continue;
            }
            $prev = $i - 1;
            while ($prev >= 0 && is_array($tokens[ $prev ]) && T_WHITESPACE === $tokens[ $prev ][0]) {
                $prev--;
            }
            if ($prev >= 0 && is_array($tokens[ $prev ]) && in_array($tokens[ $prev ][0], [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ], true)) {
                continue;
            }
            $names[] = strtolower(ltrim($token[1], '\\'));
        }
        return array_values(array_unique($names));
    }

    /** @return string[] the function names given to function_exists() in a file. */
    private function detected_functions(string $file): array
    {
        preg_match_all("/function_exists\\(\\s*'([a-z0-9_]+)'\\s*\\)/i", (string) file_get_contents($file), $m);
        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    public function test_the_fixture_is_the_scf_function_list(): void
    {
        $scf = $this->scf_functions();

        $this->assertGreaterThan(500, count($scf));
        $this->assertArrayHasKey('scf_current_user_has_capability', $scf, 'the list is SCF\'s, not ACF\'s');
    }

    public function test_the_acf_pair_detects_acf_by_functions_scf_defines(): void
    {
        $scf      = $this->scf_functions();
        $detected = $this->detected_functions(dirname(__DIR__, 3) . '/src/Integrations/ACF_Integration.php');

        $this->assertSame([ 'acf_get_field_groups', 'get_fields', 'update_field' ], $detected);
        foreach ($detected as $name) {
            $this->assertArrayHasKey($name, $scf, $name . ' is not defined by Secure Custom Fields');
        }
    }

    public function test_every_acf_function_the_pair_and_tools_call_is_defined_by_scf(): void
    {
        $scf     = $this->scf_functions();
        $checked = [];
        foreach ($this->acf_sources() as $file) {
            $names = array_merge($this->called_functions($file), $this->detected_functions($file));
            foreach ($names as $name) {
                if (0 !== strpos($name, 'acf_') && ! in_array($name, self::TEMPLATE_API, true)) {
                    continue;
                }
                $checked[ $name ] = true;
                $this->assertArrayHasKey($name, $scf, sprintf('%s calls %s(), which Secure Custom Fields 6.9.10 does not define', basename($file), $name));
            }
        }

        $this->assertArrayHasKey('acf_get_field_groups', $checked);
        $this->assertArrayHasKey('update_field', $checked);
        $this->assertGreaterThan(10, count($checked));
    }

    public function test_the_pair_reports_available_through_the_loaded_acf_api(): void
    {
        if (! function_exists('acf_get_field_groups')) {
            $this->markTestSkipped('ACF or Secure Custom Fields not active');
        }

        $catalog = (new ACF_Integration())->catalog();

        $this->assertTrue($catalog['available']);
        $this->assertContains('get-fields', array_column($catalog['operations'], 'name'));
    }
}
