<?php

declare(strict_types=1);

namespace WPMCP\Modules\Site_Gate_Settings\Adapter;

use WPMCP\Modules\Site_Gate_Settings\Value\Bypass_Change;
use WPMCP\Modules\Site_Gate_Settings\Value\Bypass_State;
use WPMCP\Modules\Site_Gate_Settings\Value\Content_Change;
use WPMCP\Modules\Site_Gate_Settings\Value\Refusal;
use WPMCP\Modules\Site_Gate_Settings\Value\Write_Plan;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One gate plugin's storage for its page content and its bypass settings
 * (issue #1099), read and planned in the plugin's own format. A store never
 * writes: it returns a Write_Plan, which Plan_Writer snapshots and applies.
 */
interface Gate_Store
{
    /** The plugin slug, as Gate_Plugin names it. */
    public function slug(): string;

    public function label(): string;

    /** @return array<string, mixed> headline, message, logo and countdown as stored, plus the fields this op can edit. */
    public function content(): array;

    public function plan_content(Content_Change $change): Write_Plan|Refusal;

    public function bypass(): Bypass_State;

    /** @return list<string> the set-site-gate-bypass fields this plugin has. */
    public function bypass_fields(): array;

    public function plan_bypass(Bypass_Change $change): Write_Plan|Refusal;

    /** Whether this bypass state lets the acting user through the gate, by the plugin's own rule. */
    public function lets_through(Bypass_State $state): bool;
}
