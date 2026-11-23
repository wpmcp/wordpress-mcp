<?php

declare(strict_types=1);

namespace WPMCP\Tests\Free\Platform;

use WPMCP\Kernel\Lazy_Handler;
use WPMCP\MCP\Ability;
use WPMCP\Tests\Free\Platform\Session_Flow_Fixture as Fixture;
use WPMCP\Tests\Support\Edition_Tree;

/**
 * A write ability whose handler records the caller's session id declares
 * session_id in its input schema (issue #1255).
 *
 * rollback-session undoes every write recorded under one session id. A
 * handler that passes $args['session_id'] to Safe_Mutation records under it,
 * but an agent only sends a session id to a tool that advertises one: left
 * out of the schema, the writes of every chat fall into the shared
 * 'default' session, and rolling one chat back undoes the others too.
 *
 * Data-driven: the abilities are those of the ability manifest
 * (tests/support/ability-manifest.php) this tree registers, resolved
 * through the real registration path (RegisteredAbilities). Whether a
 * handler records the id is read from its code by Session_Id_Flow: the
 * handler, or a method it passes its input to (Element_Tree::write(),
 * write_settings(), Elementor_Kit_Data::write() and the like), reads
 * ['session_id'] from that input. No list of abilities is kept here.
 */
final class SessionIdSchemaGuardTest extends \WP_UnitTestCase
{
    private const WRITES = ['create', 'update', 'delete'];

    public function test_every_write_that_records_a_session_id_declares_it(): void
    {
        $root  = dirname(__DIR__, 3);
        $flow  = new Session_Id_Flow([ $root . '/src', $root . '/pro/src' ]);
        $names = array_keys(Edition_Tree::ability_manifest()['abilities']);

        $registered = [];
        foreach (RegisteredAbilities::all() as $ability) {
            $registered[ $ability->name ] = $ability;
        }
        $this->assertSame([], array_values(array_diff($names, array_keys($registered))), 'Manifest abilities this tree did not register');

        $missing  = [];
        $recorded = 0;
        foreach ($names as $name) {
            $ability = $registered[ $name ];
            if (! in_array($ability->operation, self::WRITES, true)) {
                continue;
            }
            $target = self::target($ability);
            $this->assertNotNull($target, $name . ': handler is neither [object, method] nor a Lazy_Handler');
            $path = $flow->path($target[0], $target[1]);
            if (null === $path) {
                continue;
            }
            $recorded++;
            if (! self::declares_session_id($ability)) {
                $missing[] = $name . ' (' . $path . ')';
            }
        }

        $this->assertGreaterThan(0, $recorded, 'No write ability was found recording a session id: the scan is broken');
        $this->assertSame(
            [],
            $missing,
            'These write abilities record the caller\'s session id but do not declare session_id in their input schema, so agents never send one and rollback-session cannot tell their chats apart. Add \'session_id\' => [ \'type\' => \'string\' ] to the schema.'
        );
    }

    public function test_the_flow_finds_the_session_id_where_a_handler_reads_or_passes_it(): void
    {
        $flow = new Session_Id_Flow([ __DIR__ . '/Session_Flow_Fixture' ]);

        $this->assertSame('Direct::handle', $flow->path(Fixture\Direct::class, 'handle'));
        $this->assertSame('Static_Call::handle > Writer::write', $flow->path(Fixture\Static_Call::class, 'handle'));
        $this->assertSame('Own::handle > Own::save > Writer::write', $flow->path(Fixture\Own::class, 'handle'));
        $this->assertSame('Property::handle > Store::put', $flow->path(Fixture\Property::class, 'handle'));
        $this->assertSame('Named::handle > Writer::write', $flow->path(Fixture\Named::class, 'handle'));
        $this->assertSame('New_Object::handle > Store::put', $flow->path(Fixture\New_Object::class, 'handle'));
        $this->assertSame('Base::handle > Base::route', $flow->path(Fixture\Child::class, 'handle'));
    }

    public function test_the_flow_ignores_a_handler_that_does_not_pass_its_input_on(): void
    {
        $flow = new Session_Id_Flow([ __DIR__ . '/Session_Flow_Fixture' ]);

        $this->assertNull($flow->path(Fixture\None::class, 'handle'));
        $this->assertNull($flow->path(Fixture\Part::class, 'handle'));
        $this->assertNull($flow->path(Fixture\Other_Key::class, 'handle'));
    }

    /** @return array{0: string, 1: string}|null */
    private static function target(Ability $ability): ?array
    {
        $handler = $ability->handler;
        if ($handler instanceof Lazy_Handler) {
            return $handler->target();
        }
        if (is_array($handler) && 2 === count($handler)) {
            [ $object, $method ] = array_values($handler);
            return [ is_object($object) ? get_class($object) : (string) $object, (string) $method ];
        }
        return null;
    }

    private static function declares_session_id(Ability $ability): bool
    {
        $properties = $ability->input_schema['properties'] ?? [];
        return is_array($properties) && array_key_exists('session_id', $properties);
    }
}
