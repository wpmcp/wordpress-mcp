<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One editable property an Oxygen 6 Component declares on one of its nodes
 * (data.properties.meta.component.editableProperties): the key an instance
 * stores its value under, the control path on that node the value replaces,
 * and whether it is enabled (Oxygen treats a missing flag as enabled).
 */
final class Oxygen6_Component_Property
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $control_path,
        public readonly int $node_id,
        public readonly bool $enabled
    ) {
    }

    /** @return array{key:string,label:string,control_path:string,node_id:int,enabled:bool} */
    public function to_array(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'control_path' => $this->control_path, 'node_id' => $this->node_id, 'enabled' => $this->enabled];
    }

    /**
     * The instance target, as Oxygen's editor computes it.
     *
     * @return array{nodeId:int,controlPath:string,propertyKey:string}
     */
    public function target(): array
    {
        return ['nodeId' => $this->node_id, 'controlPath' => $this->control_path, 'propertyKey' => $this->key];
    }
}
