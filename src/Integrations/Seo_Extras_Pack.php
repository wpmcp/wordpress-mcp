<?php

declare(strict_types=1);

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registration glue for the paid SEO plugin extras of issue #681 on the
 * theme pair: Yoast SEO Premium redirects and the schema template reads.
 * Theme_Integration merges operations() with one line.
 */
final class Seo_Extras_Pack
{
    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return (new Yoast_Premium_Redirect_Ops(new Yoast_Redirect_Store()))->operations()
            + (new Seo_Schema_Template_Ops())->operations();
    }
}
