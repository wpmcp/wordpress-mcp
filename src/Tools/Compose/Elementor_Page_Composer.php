<?php

declare(strict_types=1);

namespace WPMCP\Tools\Compose;

if (! defined('ABSPATH')) {
    exit;
}

/** The elementor dialect of build-page, registered by the Pro bootstrap. */
final class Elementor_Page_Composer implements Page_Composer
{
    /**
     * @param list<array<mixed>> $sections
     * @return array<string, mixed>
     */
    public function compose(array $sections): array
    {
        return Elementor_Composer::compose($sections);
    }
}
