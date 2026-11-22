<?php

/**
 * Just enough WP-CLI for the golden capture's cli context: the plugin and
 * the harness plugins see a WP-CLI runtime and register their commands,
 * which are recorded instead of run. Loaded only by a cli probe process.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace,PSR1.Classes.ClassDeclaration.MultipleClasses,Squiz.Classes.ValidClassName.NotCamelCaps

if (! class_exists('WP_CLI', false)) {
    final class WP_CLI
    {
        /** @var array<int, array{0: string, 1: mixed, 2: string[]}> name, callable, arg keys */
        public static array $commands = [];

        /** @param array<string, mixed> $args */
        public static function add_command(string $name, $callable, array $args = []): bool
        {
            $keys = array_keys($args);
            sort($keys);
            self::$commands[] = [ $name, $callable, $keys ];
            return true;
        }

        /** @param array<int, mixed> $arguments */
        public static function __callStatic(string $name, array $arguments)
        {
            return null;
        }
    }
}

if (! class_exists('WP_CLI_Command', false)) {
    abstract class WP_CLI_Command
    {
    }
}
