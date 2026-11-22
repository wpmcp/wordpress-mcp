<?php

declare(strict_types=1);

namespace WPMCP\Tools\Compose;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The composers build-page can use, keyed by dialect. The free build knows
 * no dialect beyond gutenberg (Block_Composer, called directly); an edition
 * adds the others through the filter.
 */
final class Page_Composers
{
    public const FILTER = 'wpmcp_page_composers';

    /** @param array<string, Page_Composer> $composers */
    public function __construct(private readonly array $composers)
    {
    }

    /**
     * The elementor composer, named by string: it ships only where the Pro
     * code does (the unsplit tree and the premium zip), and the free zip has
     * no class by that name.
     */
    private const ELEMENTOR = '\\WPMCP\\Tools\\Compose\\Elementor_Page_Composer';

    public static function from_hooks(): self
    {
        $defaults = [];
        if (class_exists(self::ELEMENTOR)) {
            $defaults['elementor'] = new (self::ELEMENTOR)();
        }
        $value = apply_filters(self::FILTER, $defaults);
        $composers = [];
        if (is_array($value)) {
            foreach ($value as $dialect => $composer) {
                if (is_string($dialect) && $composer instanceof Page_Composer) {
                    $composers[ $dialect ] = $composer;
                }
            }
        }

        return new self($composers);
    }

    public function for_dialect(string $dialect): ?Page_Composer
    {
        return $this->composers[ $dialect ] ?? null;
    }
}
