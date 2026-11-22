<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One JetFormBuilder field as its jet-forms/<type> block declares it
 * (issue #676): the name its value is stored under in jet_fb_records_fields,
 * the block type without its prefix, the label, whether it is required and
 * the options of a choice field.
 */
final class JetFormBuilder_Field
{
    /** @param list<array{label: string, value: string}> $options */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $label,
        public readonly bool $required,
        public readonly array $options
    ) {
    }

    /** @return array{name: string, type: string, label: string, required: bool, options?: list<array{label: string, value: string}>} */
    public function to_array(): array
    {
        $out = [ 'name' => $this->name, 'type' => $this->type, 'label' => $this->label, 'required' => $this->required ];
        if ([] !== $this->options) {
            $out['options'] = $this->options;
        }
        return $out;
    }
}
