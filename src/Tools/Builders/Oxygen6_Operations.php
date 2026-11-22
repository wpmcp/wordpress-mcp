<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registration glue for the Oxygen 6 writes of issue #805 on
 * update-builder-content (builder oxygen):
 *
 * - set_properties (post_id, path: a Component instance node id, attrs:
 *   {propertyKey: value}, expected_hash), see Oxygen6_Property_Writer;
 * - create_page (element {title, post_type?, status?}, content?), see
 *   Oxygen6_Page_Creator;
 * - save_template with element {title, kind: template|header|footer,
 *   settings} and set_conditions (post_id: the template, element
 *   {settings}, expected_hash), see Oxygen6_Template_Writer;
 * - import (content: an export package, post_id? of a blank post), see
 *   Oxygen6_Library_Importer.
 *
 * save_template without a kind (a Component) and every other operation stay
 * on Update_Builder_Content's Breakdance engine path. Each write is refused
 * with plugin_missing while Oxygen 6 is not active.
 */
final class Oxygen6_Operations
{
    public const OPERATIONS = ['set_properties', 'create_page', 'set_conditions', 'import'];

    public function __construct(
        private readonly Oxygen6_Guard $guard,
        private readonly Oxygen6_Property_Writer $properties,
        private readonly Oxygen6_Page_Creator $pages,
        private readonly Oxygen6_Template_Writer $templates,
        private readonly Oxygen6_Library_Importer $importer
    ) {
    }

    public static function standard(): self
    {
        $store      = new Oxygen6_Store();
        $snapshots  = new Oxygen6_Snapshots();
        $guard      = new Oxygen6_Guard();
        $vocabulary = new Oxygen6_Vocabulary_Source();

        return new self(
            $guard,
            new Oxygen6_Property_Writer($store, $snapshots, $guard, new Oxygen6_Component_Properties()),
            new Oxygen6_Page_Creator($store, $snapshots, $guard),
            new Oxygen6_Template_Writer($store, $snapshots, $guard, $vocabulary),
            new Oxygen6_Library_Importer($store, $snapshots, $guard, $vocabulary)
        );
    }

    /** @param array<string,mixed> $args */
    public static function handles(array $args): bool
    {
        if ('oxygen' !== ($args['builder'] ?? '') || '' !== Oxygen6_Values::str($args['scope'] ?? null)) {
            return false;
        }
        $operation = Oxygen6_Values::str($args['operation'] ?? null);
        $element   = $args['element'] ?? null;

        return in_array($operation, self::OPERATIONS, true)
            || ('save_template' === $operation && is_array($element) && isset($element['kind']) && 'component' !== $element['kind']);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public function run(array $args): array|\WP_Error
    {
        $unavailable = $this->guard->unavailable();
        if (null !== $unavailable) {
            return $unavailable;
        }

        try {
            switch (Oxygen6_Values::str($args['operation'] ?? null)) {
                case 'set_properties':
                    return $this->properties->set($args);
                case 'create_page':
                    return $this->pages->create($args);
                case 'set_conditions':
                    return $this->templates->set_conditions($args);
                case 'import':
                    return $this->importer->import($args);
                default:
                    return $this->templates->save($args);
            }
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_oxygen_request', $e->getMessage());
        }
    }
}
