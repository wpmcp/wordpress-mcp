<?php

declare(strict_types=1);

namespace WPMCP\Kernel;

use WPMCP\Api\V1\Value\Context;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The request context, detected once from the request itself (path,
 * rest_route, WP-CLI, cron, ajax, is_admin), before rest_api_init. Mcp is a
 * REST request on this plugin's MCP route under the (filtered) REST prefix,
 * pretty or ?rest_route=. A hook or module limited to rest also runs on mcp.
 */
final class Request_Context
{
    public const MCP_ROUTE = '/mcp/wpmcp-server';

    private readonly Context $context;

    /**
     * @param array<string, mixed> $query the request's query arguments ($_GET)
     */
    public function __construct(
        string $uri = '/',
        array $query = [],
        bool $is_cli = false,
        bool $doing_cron = false,
        bool $doing_ajax = false,
        bool $is_admin = false,
        string $rest_prefix = 'wp-json',
        string $home_path = '',
        string $mcp_route = self::MCP_ROUTE,
    ) {
        $this->context = self::detect($uri, $query, $is_cli, $doing_cron, $doing_ajax, $is_admin, $rest_prefix, $home_path, $mcp_route);
    }

    /** Reads the current request. Call once, after plugins_loaded. */
    public static function from_globals(): self
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched against fixed routes, never output or stored.
        $home = function_exists('home_url') ? (string) wp_parse_url(home_url('/'), PHP_URL_PATH) : '';
        $prefix = function_exists('rest_get_url_prefix') ? rest_get_url_prefix() : 'wp-json';
        $query = isset($_GET['rest_route']) ? ['rest_route' => (string) wp_unslash($_GET['rest_route'])] : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- routing only: matched against a fixed route, never stored or output.

        return new self(
            uri: $uri,
            query: $query,
            is_cli: defined('WP_CLI') && WP_CLI,
            doing_cron: function_exists('wp_doing_cron') && wp_doing_cron(),
            doing_ajax: function_exists('wp_doing_ajax') && wp_doing_ajax(),
            is_admin: function_exists('is_admin') && is_admin(),
            rest_prefix: $prefix,
            home_path: rtrim($home, '/'),
        );
    }

    public function context(): Context
    {
        return $this->context;
    }

    public function is_mcp(): bool
    {
        return Context::Mcp === $this->context;
    }

    /**
     * Whether this request is one of $contexts (empty: every context).
     *
     * @param list<Context|string> $contexts
     */
    public function matches(array $contexts): bool
    {
        if ([] === $contexts) {
            return true;
        }
        foreach ($contexts as $context) {
            $value = $context instanceof Context ? $context->value : $context;
            if ($value === $this->context->value || (Context::Rest->value === $value && Context::Mcp === $this->context)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $query */
    private static function detect(string $uri, array $query, bool $is_cli, bool $doing_cron, bool $doing_ajax, bool $is_admin, string $rest_prefix, string $home_path, string $mcp_route): Context
    {
        if ($is_cli) {
            return Context::Cli;
        }
        if ($doing_cron) {
            return Context::Cron;
        }
        if ($doing_ajax) {
            return Context::Ajax;
        }

        $route = null;
        if (isset($query['rest_route']) && is_string($query['rest_route'])) {
            $route = '/' . ltrim($query['rest_route'], '/');
        } else {
            $path = (string) parse_url($uri, PHP_URL_PATH); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- runs before WordPress in unit tests; a path-only parse.
            if ('' !== $home_path && str_starts_with($path, $home_path . '/')) {
                $path = substr($path, strlen($home_path));
            }
            $prefix = '/' . trim($rest_prefix, '/');
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $route = '/' . ltrim(substr($path, strlen($prefix)), '/');
            }
        }

        if (null !== $route) {
            $route = rtrim($route, '/');
            return ($route === $mcp_route || str_starts_with($route, $mcp_route . '/')) ? Context::Mcp : Context::Rest;
        }

        return $is_admin ? Context::Admin : Context::Frontend;
    }
}
