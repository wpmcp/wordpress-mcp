<?php

declare(strict_types=1);

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The stored side of a Visual Composer page for structural writes (issue
 * #1112): the editor document (vcv-pageContent), the saved markup
 * (post_content) and the CSS postmeta the editor compiles. Recorded from
 * Visual Composer 45.16.4:
 *
 * - Modules/Assets/DataController::updateSourceAssets()/updateGlobalAssets()
 *   store vcvSourceAssetsFiles, vcvSourceCss, vcvSettingsSourceCustomCss and
 *   vcv-globalElementsCssData (each element type's base, mixins and
 *   attributes CSS); DataAjax/Controller::updatePostMeta() stores
 *   _vcv-pageDesignOptionsData and _vcv-pageDesignOptionsCompiledCss.
 * - Helpers/Assets::generateSourceCssFile() builds the page's CSS file from
 *   vcvSourceCss and vcv-globalElementsCssData and records it in
 *   vcvSourceCssFileUrl, _vcv-sourceChecksum and _vcv-globalCssMigrated;
 *   Modules/Assets/EnqueueController::enqueueSourceAssetsBySourceId() runs
 *   it, on the server, when a page without _vcv-globalCssMigrated is viewed.
 *
 * All of it is ordinary post row and postmeta, which the post snapshot
 * captures and restores.
 */
final class Visual_Composer_Page_Store
{
    public const ELEMENTS_CSS_KEY = 'vcv-globalElementsCssData';

    /** The CSS postmeta a page made from a template takes with it. */
    public const CSS_KEYS = [
        'vcvSourceCss',
        self::ELEMENTS_CSS_KEY,
        'vcvSourceAssetsFiles',
        'vcvSettingsSourceCustomCss',
        '_vcv-pageDesignOptionsData',
        '_vcv-pageDesignOptionsCompiledCss',
    ];

    /** The record of a built CSS file, cleared so Visual Composer builds the page's own. */
    public const CSS_FILE_KEYS = ['_vcv-globalCssMigrated', 'vcvSourceCssFileUrl', '_vcv-sourceChecksum'];

    public function document(int $post_id): ?object
    {
        return Visual_Composer_Content::document($post_id);
    }

    public function html(int $post_id): string
    {
        $post = get_post($post_id);

        return $post instanceof \WP_Post ? $post->post_content : '';
    }

    public function hash(int $post_id): string
    {
        return Visual_Composer_Content::hash($post_id);
    }

    /** A page with no content and no Visual Composer document: one a template can fill. */
    public function is_blank(int $post_id): bool
    {
        if ('' !== trim($this->html($post_id)) || 'classic' !== Builder_Detector::detect($post_id)) {
            return false;
        }
        $doc = $this->document($post_id);

        return null === $doc || ! isset($doc->elements) || ! is_object($doc->elements) || [] === get_object_vars($doc->elements);
    }

    public function template(int $template_id): Visual_Composer_Template
    {
        $css = [];
        foreach (self::CSS_KEYS as $key) {
            if (metadata_exists('post', $template_id, $key)) {
                $css[ $key ] = get_post_meta($template_id, $key, true);
            }
        }

        return new Visual_Composer_Template($template_id, $this->document($template_id), $this->html($template_id), $css);
    }

    public function save(int $post_id, Visual_Composer_Structure_Plan $plan): void
    {
        Visual_Composer_Content::save($post_id, $plan->document, $plan->html);
        foreach ($plan->meta as $key => $value) {
            update_post_meta($post_id, $key, wp_slash($value));
        }
        foreach ($plan->delete_meta as $key) {
            delete_post_meta($post_id, $key);
        }
    }
}
