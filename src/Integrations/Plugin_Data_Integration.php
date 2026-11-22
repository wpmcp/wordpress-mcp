<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Custom field and translation plugins beyond ACF, Meta Box and Polylang
 * (issue #299) behind one free dispatcher pair, wpmcp/plugin-data-read and
 * wpmcp/plugin-data-write, so several plugins cost the tools/list surface
 * one pair rather than one each:
 *  - JetEngine: field definitions per post type and post field values
 *    (JetEngine_Pack);
 *  - Pods: field definitions per post type pod and post field values, in
 *    post meta or in the pod's own table when it uses table storage
 *    (Pods_Pack);
 *  - TranslatePress: languages and string translations in its dictionary
 *    tables (TranslatePress_Pack);
 *  - BuddyPress (issue #354): groups, group members, activity and extended
 *    profile field definitions, with group writes, profile field updates
 *    and activity moderation (BuddyPress_Pack).
 *  - Code Snippets (issue #576): PHP snippets listed, read, created
 *    inactive and edited behind the static check, and deactivated, with
 *    'code_snippet' row snapshots (Code_Snippets_Pack, presence through
 *    wpmcp_code_snippets_active).
 *  - Issue #676 adds the WS Form ops (WS_Form_Pack): forms, fields,
 *    actions and email notifications, submissions listed with every value
 *    masked and read one at a time in full, and status changes and deletes
 *    (off until a site opts in), each under a db_rows before-image.
 *    It also adds the Everest Forms ops (Everest_Forms_Pack) on the same
 *    shape: forms, fields and email notifications, entries listed masked
 *    and read one at a time, spam, starred and read changes and deletes
 *    (off until wpmcp_enable_everest_forms_write), each undoable.
 *  - Issue #682 adds the link management ops: Pretty Links links
 *    (Pretty_Links_Pack, rows of its prli_links table) and ThirstyAffiliates
 *    links (Thirsty_Affiliates_Pack, thirstylink posts), read with click
 *    counts and masked visitor IPs, and created, changed and trashed (off
 *    until wpmcp_enable_pretty_links_write or
 *    wpmcp_enable_thirstyaffiliates_write), each write undoable.
 *  - Issue #685 adds the Popup Maker and OptinMonster ops
 *    (Popup_Maker_Pack, OptinMonster_Pack): popups listed, read, created as
 *    drafts, changed, given triggers, enabled and disabled (off until
 *    wpmcp_enable_popup_maker_write), and synced OptinMonster campaigns
 *    listed, read and switched on or off on this site (off until
 *    wpmcp_enable_optinmonster_write), each write under a post snapshot.
 *  - Issue #678 adds the Events Manager ops (Events_Manager_Pack): events
 *    listed and read, bookings listed (bookers masked), and events created
 *    and changed through EM_Event::save() (off until
 *    wpmcp_enable_events_manager_write), each write undoable.
 *  - Issue #688 adds the Advanced Ads ops (Advanced_Ads_Pack): ads and
 *    placements listed and read, ads created as drafts, changed, enabled
 *    and disabled, and placements pointed at an ad or group (off until
 *    wpmcp_enable_advanced_ads_write), each write under a post snapshot.
 *  - Issue #901 adds the Discount Rules for WooCommerce ops
 *    (Discount_Rules_Pack): pricing rules listed and read, created staged
 *    disabled, changed (a live rule only with confirm_live), enabled and
 *    disabled, over 100 percent off refused (off until
 *    wpmcp_enable_discount_rules_write), each write a wdr_rules row image.
 *  - Issue #898 adds the popup ops: Popup Builder popups listed and read
 *    with their display rules, triggers and views, and changed (title,
 *    content, display rules) and switched on or off (off until
 *    wpmcp_enable_popup_builder_write), each write under a post snapshot
 *    (Popup_Builder_Pack); Hustle modules and Depicter documents listed and
 *    read with their schedule, display conditions and counts (Hustle_Pack,
 *    Depicter_Pack, read-only).
 *  - Issue #687 adds the Site Reviews ops (Site_Reviews_Pack): reviews
 *    listed (reviewer emails and IPs masked), read one at a time and
 *    summarised, and approved, unapproved, trashed or replied to (off until
 *    wpmcp_enable_site_reviews_write), each write under a post snapshot.
 *  - Issue #394 adds the paid-tier LMS ops (LMS_Pack): Tutor LMS,
 *    LifterLMS, LearnDash and LearnPress course, section, lesson and quiz trees with
 *    snapshotted writes, and read-only enrollments (LearnDash also enrolls,
 *    unenrolls and resets progress, each snapshotted).
 *  - Issue #833 adds the paid-tier GamiPress ops (Gamipress_Pack): point,
 *    achievement and rank types, achievements, ranks and a user's points,
 *    earnings and rank, with points, achievement and rank writes through
 *    GamiPress (off until wpmcp_enable_gamipress_write), each under a
 *    'gamipress_user' snapshot of the user.
 *  - Issue #827 adds the paid-tier Thrive Optimize ops
 *    (Thrive_Optimize_Pack): A/B tests read with their variations' results,
 *    and traffic split changes and stopping a test (undoable) or picking a
 *    winner (not undoable, confirm:true), off until
 *    wpmcp_enable_thrive_optimize_write.
 *  - Issue #825 adds the paid-tier Thrive Leads ops (Thrive_Leads_Pack):
 *    lead groups, opt-in forms, shortcodes and conversion counts read, and
 *    form triggers and display, form switches and group display rules
 *    written (off until wpmcp_enable_thrive_leads_write), each undoable.
 *  - Issue #701 adds the paid-tier WP Offload Media ops
 *    (Offload_Media_Pack): offload status, bucket and counts, and per
 *    attachment whether it is in the bucket and the URL the site serves.
 *  - Issue #829 adds the paid-tier Thrive Ovation ops (Thrive_Ovation_Pack):
 *    testimonials listed with author names and emails masked, read one at a
 *    time, tags listed, and testimonials created, edited, set to a status and
 *    tagged (off until wpmcp_enable_thrive_ovation_write), each undoable.
 *  - Issue #684 adds the paid-tier Jetpack CRM and HubSpot ops
 *    (Jetpack_Crm_Pack, HubSpot_Pack): Jetpack CRM contacts (masked in
 *    lists) and forms, contacts created and changed through its own API
 *    (off until wpmcp_enable_jetpack_crm_write) under 'plugin_table_rows'
 *    images, and the HubSpot forms placed on the site.
 *  - Issue #679 adds the paid-tier appointment booking ops (Booking_Pack):
 *    Bookly, Simply Schedule Appointments, BookingPress and LatePoint
 *    services, staff and bookings read with customers masked in lists, and
 *    bookings cancelled (off until wpmcp_enable_booking_write), each cancel
 *    undoable from a 'plugin_table_rows' before-image.
 *  - Issue #697 adds the paid-tier WPCode ops (WPCode_Pack): snippets and
 *    the global header and footer scripts read, snippets created inactive,
 *    changed, activated behind the PHP execution or code injection gate and
 *    deactivated, and the global scripts replaced, each write off until
 *    wpmcp_enable_wpcode_write and undoable.
 *  - Issue #481 adds the paid-tier TablePress ops (TablePress_Pack): tables
 *    read, edited cell by cell, imported, exported and placed through
 *    TablePress's own table model, with snapshotted writes.
 *  - Issue #683 adds the paid-tier mailing list ops (Mailing_List_Pack):
 *    Newsletter, Groundhogg, MC4WP and Brevo lists, forms and contacts
 *    (masked in lists), with undoable Newsletter contact writes.
 *  - Issue #477 adds the paid-tier CRM ops (CRM_Pack): FluentCRM and
 *    MailPoet contacts, lists, tags, campaigns and automations, with writes
 *    through each plugin's own API, snapshotted as 'crm_rows' images.
 *  - Issue #499 adds the paid-tier Amelia ops (Amelia_Pack): services,
 *    employees, customers, appointments and events, with writes through
 *    Amelia's own application layer, snapshotted as 'amelia_rows' images.
 *  - Issue #498 adds the paid-tier page translation ops (I18n_Pack): for
 *    Polylang and WPML, find untranslated posts, create a linked
 *    translation, and read and write a page's text as segments.
 *  - bbPress (BbPress_Pack): forums, topics and replies, posting topics
 *    and replies, and topic moderation (close, stick, spam) through
 *    bbPress's own API, snapshotted as 'bbpress_posts' images.
 *  - Issue #668 adds the paid-tier MemberPress ops (MemberPress_Pack):
 *    memberships, rules and their access conditions, read and written as
 *    MemberPress stores them, plus read-only members and transactions.
 *  - Issue #654 adds the paid-tier Elementor Pro ops
 *    (Elementor_Pro_Forms_Pack): forms found in pages, and form submissions
 *    read, exported, marked read or trashed, and deleted, each write under
 *    a db_rows before-image.
 *  - Issue #698 adds the read-only activity log ops (Activity_Log_Pack):
 *    WP Activity Log and Simple History events listed, with IPs and emails
 *    masked, and read one at a time in full.
 *  - Issue #905 adds the read-only email log ops (Email_Log_Pack): WP Mail
 *    Logging, Check and Log Email and Email Log emails listed with
 *    recipients masked and no bodies, and read one at a time with the body
 *    cut and one-time links removed. Resend and delete are not part of it.
 *  - Issue #708 adds the paid-tier knowledge base and support desk ops:
 *    BetterDocs docs read, created and edited (BetterDocs_Pack), and
 *    Fluent Support and Awesome Support tickets read with customer emails
 *    masked in lists, replied to, noted and set to a status, each ticket
 *    write off until wpmcp_enable_helpdesk_write and snapshotted as a
 *    'helpdesk_ticket' image (Helpdesk_Pack).
 *  - Issue #830 adds the paid-tier Thrive Automator ops
 *    (Thrive_Automator_Pack): automations listed and read with credentials
 *    redacted, the trigger and action catalog, and automations turned on
 *    (confirm:true) or off (off until wpmcp_enable_thrive_automator_write),
 *    each under a post snapshot.
 *  - Issue #690 adds the paid-tier translation settings ops
 *    (Translation_Settings_Pack): Weglot languages and exclusions read, and
 *    GTranslate languages read and set (off until
 *    wpmcp_enable_translation_settings_write), the write snapshotted on the
 *    GTranslate option.
 *  - Issue #900 adds the paid-tier email marketing ops
 *    (Email_Marketing_Pack): MailerLite, Klaviyo, Kit, Omnisend and
 *    ActiveCampaign connection, forms and list mapping read with keys never
 *    returned, and Klaviyo checkout signup and Kit default forms set (off
 *    until wpmcp_enable_email_marketing_write), snapshotted on each option.
 *  - Issue #660 adds the paid-tier Checkout Field Editor for WooCommerce
 *    ops (Checkout_Field_Editor_Pack): billing, shipping and additional
 *    checkout fields listed, and existing fields changed (off until
 *    wpmcp_enable_checkout_field_editor_write), the write snapshotted on the
 *    section's option.
 *  - Issue #665 adds the paid-tier SureCart ops (SureCart_Pack): products,
 *    orders and customers (masked) and coupons read from SureCart's cloud,
 *    and product edits and coupon creates (off until
 *    wpmcp_enable_surecart_write), each snapshotted as a 'surecart_object'
 *    image read from SureCart's API.
 *  - Issue #702 adds the Action Scheduler ops (Action_Scheduler_Pack): queue
 *    actions listed with args masked and read one at a time with their log,
 *    and pending actions canceled (undoable, as an 'action_scheduler'
 *    snapshot) or run now (not undoable, confirm:true), both off until
 *    wpmcp_enable_action_scheduler_write.
 *  - Issue #706 adds the paid-tier wpDataTables and Ninja Tables ops
 *    (Data_Tables_Pack): tables listed and read, and cells set and rows
 *    inserted and deleted, snapshotted and off until
 *    wpmcp_enable_data_tables_write.
 *  - Issue #671 adds the forum ops beside bbPress's, named the same way:
 *    wpForo forums, topics and replies read, topics closed, opened and
 *    moved (WpForo_Pack), and Asgaros Forum forums, topics and replies
 *    read, topics and replies posted, topics moderated and moved, and
 *    replies deleted (Asgaros_Forum_Pack), every write off until
 *    wpmcp_enable_wpforo_write or wpmcp_enable_asgaros_write and each
 *    snapshotted as 'plugin_table_rows' images.
 *  - Issue #666 adds read-only WP Simple Pay payment forms (Simple_Pay_Pack:
 *    status, test or live mode, display type, prices) and Paid Member
 *    Subscriptions plans and members (Paid_Member_Subscriptions_Pack,
 *    member names and emails masked).
 *  - Issue #899 adds the tracking ops (Tracking_Pack): GTM4WP,
 *    PixelYourSite, Meta Pixel and Microsoft Clarity IDs, events, data
 *    layer settings and excluded roles read with tokens masked, IDs loaded
 *    twice (theme snippets included) reported, and GTM4WP settings and the
 *    Clarity project changed (off until wpmcp_enable_tracking_write), each
 *    write snapshotted on the plugin's option.
 *  - Issue #704 adds the read-only WPGraphQL ops (WPGraphQL_Pack): a
 *    GraphQL query run as the current user with mutations and
 *    subscriptions refused and personal values masked, and a schema
 *    summary without the mutation root.
 *  - Issue #895 adds database search and replace for sites running Better
 *    Search Replace or Search Regex (Search_Replace_Pack): tables and saved
 *    presets listed, a mandatory dry run whose token the run needs, and a
 *    serialization-safe run (off until wpmcp_enable_search_replace_write,
 *    confirm:true) that snapshots every changed row as a db_rows image, so
 *    rollback-session undoes it.
 *  - Issue #828 adds the paid-tier Thrive Ultimatum ops
 *    (Thrive_Ultimatum_Pack): countdown campaigns read, and created, given a
 *    schedule and display rules, paused and resumed (off until
 *    wpmcp_enable_thrive_ultimatum_write), each write undoable.
 *  - Issue #705 adds the FooGallery ops (FooGallery_Pack): galleries listed
 *    and read with their images, created, and images added, removed and
 *    reordered (off until wpmcp_enable_foogallery_write), each write
 *    undoable.
 *  - Issue #669 adds the Restrict Content ops (Restrict_Content_Pack:
 *    membership levels with active member counts, memberships with member
 *    names and emails masked, and level changes off until
 *    wpmcp_enable_restrict_content_write, snapshotted as a
 *    'plugin_table_rows' image) and the Ultimate Member ops
 *    (Ultimate_Member_Pack: roles, members masked, forms and member
 *    directories, and a member's UM role set behind confirm:true, off until
 *    wpmcp_enable_ultimate_member_write, snapshotted as a 'user' snapshot).
 *  - Issue #710 adds the custom post type builder ops (Cpt_Builder_Pack):
 *    post types and taxonomies listed with the plugin that owns each, read,
 *    created and changed in Custom Post Type UI or MB Custom Post Types
 *    (off until wpmcp_enable_cpt_builder_write), each write undoable.
 *  - Issue #709 adds the PublishPress editorial workflow ops
 *    (PublishPress_Pack): the Planner content calendar read and editorial
 *    status changes, Checklists requirements read, and PublishPress Future
 *    scheduled changes listed, scheduled and canceled (writes off until
 *    wpmcp_enable_publishpress_write), each write under a post snapshot.
 *  - Issue #707 adds the directory listing ops (Listings_Pack): WP Job
 *    Manager, GeoDirectory and Directorist listings listed (contact fields
 *    masked), read, filed under their terms, created, changed and set to a
 *    status (off until wpmcp_enable_listings_write), each write undoable.
 *  - Issue #664 adds Easy Digital Downloads (EDD_Pack): downloads, orders,
 *    customers (masked in lists), discounts and a sales summary, and
 *    download and discount writes (undoable, off until
 *    wpmcp_enable_edd_write).
 *
 * The pair registers only while at least one of them is loaded (the LMS
 * and TablePress ops only on an install that may run the paid tier), and
 * every op declares a 'requires' check, so a plugin that is not active is
 * skipped cleanly: its ops stay documented in list-operations
 * (dependency_met:false) and answer <plugin>_inactive without touching
 * anything. Presence is filterable per plugin (wpmcp_jetengine_active,
 * wpmcp_pods_active, wpmcp_translatepress_active, wpmcp_buddypress_active,
 * wpmcp_bbpress_active).
 *
 * Every write is snapshotted: field values in post meta as a post snapshot,
 * Pods table rows and TranslatePress dictionary rows as a
 * 'plugin_table_rows' row image, BuddyPress rows as a 'buddypress_rows'
 * image, so rollback-operation restores exactly.
 * Field value writes follow the ACF and Meta Box posture and are off until a
 * site opts in (wpmcp_enable_jetengine_write, wpmcp_enable_pods_write).
 */
final class Plugin_Data_Integration extends Integration_Dispatcher
{
    public function integration(): string
    {
        return 'plugin-data';
    }

    public function is_available(): bool
    {
        return Events_Manager_Pack::active() || BbPress_Pack::active() || JetEngine_Pack::active() || Pods_Pack::active() || TranslatePress_Pack::active() || Code_Snippets_Pack::active() || WS_Form_Pack::active() || Everest_Forms_Pack::active() || Activity_Log_Pack::active() || Action_Scheduler_Pack::active() || Email_Log_Pack::active() || Pretty_Links_Pack::active() || Thirsty_Affiliates_Pack::active() || Advanced_Ads_Pack::active() || Popup_Maker_Pack::active() || OptinMonster_Pack::active() || Site_Reviews_Pack::active() || WPGraphQL_Pack::active() || Simple_Pay_Pack::active() || Paid_Member_Subscriptions_Pack::active() || FooGallery_Pack::active() || WpForo_Pack::active() || Asgaros_Forum_Pack::active() || Restrict_Content_Pack::active() || Ultimate_Member_Pack::active() || Cpt_Builder_Pack::active() || PublishPress_Pack::active() || Listings_Pack::active() || EDD_Pack::active() || Search_Replace_Pack::active() || Popup_Builder_Pack::active() || Hustle_Pack::active() || Depicter_Pack::active() || Tracking_Pack::active() || Discount_Rules_Pack::active() || BuddyPress_Pack::active()
            || Gamipress_Pack::active()
            || LMS_Pack::active()
            || Thrive_Ultimatum_Pack::active()
            || Thrive_Optimize_Pack::active()
            || Offload_Media_Pack::active()
            || Thrive_Ovation_Pack::active()
            || Thrive_Leads_Pack::active()
            || Jetpack_Crm_Pack::active()
            || HubSpot_Pack::active()
            || Booking_Pack::active()
            || WPCode_Pack::active()
            || TablePress_Pack::active()
            || Data_Tables_Pack::active()
            || CRM_Pack::active()
            || Mailing_List_Pack::active()
            || Amelia_Pack::active()
            || I18n_Pack::active()
            || MemberPress_Pack::active()
            || Elementor_Pro_Forms_Pack::active()
            || BetterDocs_Pack::active()
            || Helpdesk_Pack::active()
            || Thrive_Automator_Pack::active()
            || Translation_Settings_Pack::active()
            || Easy_TOC_Pack::active()
            || Email_Marketing_Pack::active()
            || Checkout_Field_Editor_Pack::active()
            || SureCart_Pack::active();
    }

    public function registers_only_when_available(): bool
    {
        return true;
    }

    protected function summary(): string
    {
        return 'JetEngine and Pods fields, TranslatePress strings, Code Snippets, BuddyPress and BuddyBoss Platform community data, bbPress forums, WS Form and Everest Forms forms, WP Activity Log and Simple History logs, Pretty Links and ThirstyAffiliates links, Advanced Ads ads and placements, Popup Maker popups, OptinMonster campaigns, Events Manager events and bookings, Action Scheduler queues, Site Reviews reviews, WP Mail Logging, Check and Log Email and Email Log emails, WPGraphQL queries, WP Simple Pay forms, Paid Member Subscriptions plans, wpForo and Asgaros Forum forums, Restrict Content and Restrict Content Pro memberships, Ultimate Member roles and forms, Easy Digital Downloads stores, WP Job Manager, GeoDirectory and Directorist listings, PublishPress Planner, PublishPress Checklists and PublishPress Future, Custom Post Type UI and MB Custom Post Types post types, Better Search Replace and Search Regex search and replace, Popup Builder, Hustle and Depicter popups, GTM4WP, PixelYourSite, Meta Pixel for WordPress and Microsoft Clarity tracking IDs, Discount Rules for WooCommerce pricing rules'
            . ', FooGallery galleries'
            . ', Tutor LMS, LifterLMS, LearnDash, LearnPress, Sensei LMS and Thrive Apprentice courses, TablePress tables'
            . ', GamiPress points, achievements and ranks'
            . ', wpDataTables and Ninja Tables tables'
            . ', WP Offload Media status'
            . ', Thrive Ultimatum campaigns'
            . ', Thrive Optimize A/B tests'
            . ', Thrive Ovation testimonials'
            . ', Thrive Leads forms'
            . ', Jetpack CRM contacts and forms, HubSpot forms'
            . ', Bookly, Simply Schedule Appointments, BookingPress and LatePoint bookings'
            . ', WPCode snippets'
            . ', FluentCRM and MailPoet, FunnelKit Automations'
            . ', Newsletter, Groundhogg, MC4WP and Brevo lists'
            . ', Amelia bookings, Polylang and WPML translations, MemberPress memberships, Elementor Pro forms'
            . ', BetterDocs docs, Fluent Support and Awesome Support tickets'
            . ', Easy Table of Contents settings'
            . ', MailerLite, Klaviyo, Kit, Omnisend and ActiveCampaign settings'
            . ', Thrive Automator automations'
            . ', Weglot and GTranslate language settings, Loco Translate .po strings'
            . ', Checkout Field Editor fields'
            . ', SureCart store data';
    }

    /**
     * The plugin each op needs (issue #815), so a refusal while none of
     * them is active names the one plugin the asked op works with: every
     * pack operations() merges, a pack with adapters by each adapter's own
     * label, and ops either of two plugins serves by both. Ops this
     * install's tier may not run are left out, since activating the plugin
     * alone would not bring them back.
     */
    public function operation_hosts(): array
    {
        $groups = [
            [ 'bbPress, BuddyBoss Platform', true, BbPress_Pack::operations() ],
            [ 'JetEngine', false, JetEngine_Pack::operations() ],
            [ 'Pods', false, Pods_Pack::operations() ],
            [ 'TranslatePress', false, TranslatePress_Pack::operations() ],
            [ 'Code Snippets', false, Code_Snippets_Pack::operations() ],
            [ 'BuddyPress, BuddyBoss Platform', true, BuddyPress_Pack::operations() ],
            [ 'WS Form', false, WS_Form_Pack::operations() ],
            [ 'Everest Forms', false, Everest_Forms_Pack::operations() ],
            [ 'WP Activity Log, Simple History', true, Activity_Log_Pack::operations() ],
            [ 'Action Scheduler', false, Action_Scheduler_Pack::operations() ],
            [ 'WP Mail Logging, Check and Log Email, Email Log', true, Email_Log_Pack::operations() ],
            [ 'Pretty Links', false, Pretty_Links_Pack::operations() ],
            [ 'ThirstyAffiliates', false, Thirsty_Affiliates_Pack::operations() ],
            [ 'Advanced Ads', false, Advanced_Ads_Pack::operations() ],
            [ 'Discount Rules for WooCommerce', false, Discount_Rules_Pack::operations() ],
            [ 'Popup Builder', false, Popup_Builder_Pack::operations() ],
            [ 'Hustle', false, Hustle_Pack::operations() ],
            [ 'Depicter', false, Depicter_Pack::operations() ],
            [ 'Popup Maker', false, Popup_Maker_Pack::operations() ],
            [ 'OptinMonster', false, OptinMonster_Pack::operations() ],
            [ 'Site Reviews', false, Site_Reviews_Pack::operations() ],
            [ 'WP Simple Pay', false, Simple_Pay_Pack::operations() ],
            [ 'Paid Member Subscriptions', false, Paid_Member_Subscriptions_Pack::operations() ],
            [ 'WPGraphQL', false, WPGraphQL_Pack::operations() ],
            [ 'GTM4WP, PixelYourSite, Meta Pixel for WordPress, Microsoft Clarity', true, Tracking_Pack::shared_operations() ],
            [ 'GTM4WP', false, Tracking_Pack::gtm4wp_operations() ],
            [ 'Microsoft Clarity', false, Tracking_Pack::clarity_operations() ],
            [ 'Better Search Replace, Search Regex', true, Search_Replace_Pack::operations() ],
            [ 'FooGallery', false, FooGallery_Pack::operations() ],
            [ 'wpForo', false, WpForo_Pack::operations() ],
            [ 'Asgaros Forum', false, Asgaros_Forum_Pack::operations() ],
            [ 'Restrict Content', false, Restrict_Content_Pack::operations() ],
            [ 'Ultimate Member', false, Ultimate_Member_Pack::operations() ],
            [ 'Custom Post Type UI, MB Custom Post Types', true, Cpt_Builder_Pack::operations() ],
            [ 'PublishPress Planner', false, PublishPress_Pack::planner_operations() ],
            [ 'PublishPress Checklists', false, PublishPress_Pack::checklists_operations() ],
            [ 'PublishPress Future', false, PublishPress_Pack::future_operations() ],
            [ 'Events Manager', false, Events_Manager_Pack::operations() ],
            [ 'Easy Digital Downloads', false, EDD_Pack::operations() ],
        ];
        $groups[] = [ 'GamiPress', false, Gamipress_Pack::operations() ];
        $groups[] = [ 'Thrive Optimize', false, Thrive_Optimize_Pack::operations() ];
        foreach (Listings_Pack::prefixes() as $prefix) {
            $groups[] = [ Listings_Pack::label($prefix), false, Listings_Pack::operations_for($prefix) ];
        }
        foreach (LMS_Pack::adapters() as $adapter) {
            $groups[] = [ $adapter->label(), false, $adapter->operations() ];
        }
        $groups[] = [ 'WP Offload Media', false, Offload_Media_Pack::operations() ];
        $groups[] = [ 'Thrive Ultimatum', false, Thrive_Ultimatum_Pack::operations() ];
        $groups[] = [ 'Thrive Ovation', false, Thrive_Ovation_Pack::operations() ];
        $groups[] = [ 'Thrive Leads', false, Thrive_Leads_Pack::operations() ];
        $groups[] = [ 'Jetpack CRM', false, Jetpack_Crm_Pack::operations() ];
        $groups[] = [ 'HubSpot', false, HubSpot_Pack::operations() ];
        array_push($groups, ...Booking_Pack::host_groups());
        $groups[] = [ 'WPCode', false, WPCode_Pack::operations() ];
        $groups[] = [ 'TablePress', false, TablePress_Pack::operations() ];
        $groups[] = [ 'wpDataTables', false, Data_Tables_Pack::wpdatatables_operations() ];
        $groups[] = [ 'Ninja Tables', false, Data_Tables_Pack::ninja_tables_operations() ];
        foreach (CRM_Pack::adapters() as $adapter) {
            $groups[] = [ $adapter->label(), false, $adapter->operations() ];
        }
        foreach (Mailing_List_Pack::hosts() as $plugin => $ops) {
            $groups[] = [ $plugin, false, $ops ];
        }
        $groups[] = [ 'Amelia', false, Amelia_Pack::operations() ];
        $groups[] = [ 'Polylang, WPML', true, I18n_Pack::operations() ];
        $groups[] = [ 'MemberPress', false, MemberPress_Pack::operations() ];
        $groups[] = [ 'Elementor Pro', false, Elementor_Pro_Forms_Pack::operations() ];
        $groups[] = [ 'BetterDocs', false, BetterDocs_Pack::operations() ];
        foreach (Helpdesk_Pack::adapters() as $adapter) {
            $groups[] = [ $adapter->label(), false, $adapter->operations() ];
        }
        $groups[] = [ 'Thrive Automator', false, Thrive_Automator_Pack::operations() ];
        $groups[] = [ 'Weglot', false, Translation_Settings_Pack::weglot_operations() ];
        $groups[] = [ 'GTranslate', false, Translation_Settings_Pack::gtranslate_operations() ];
        $groups[] = [ 'Easy Table of Contents', false, Easy_TOC_Pack::operations() ];
        foreach (Email_Marketing_Pack::groups() as [ $label, $ops ]) {
            $groups[] = [ $label, false, $ops ];
        }
        $groups[] = [ 'Loco Translate', false, Loco_Translate_Pack::operations() ];
        $groups[] = [ 'Checkout Field Editor', false, Checkout_Field_Editor_Pack::operations() ];
        $groups[] = [ 'SureCart', false, SureCart_Pack::operations() ];

        $hosts = [];
        foreach ($groups as [ $plugin, $several, $ops ]) {
            foreach ($ops as $op => $def) {
                if (Registrar::tier_permitted((string) ($def['tier'] ?? 'free'))) {
                    $hosts[ (string) $op ] = [ 'name' => $plugin, 'several' => $several ];
                }
            }
        }
        return $hosts;
    }

    protected function operations(): array
    {
        return array_merge(
            BbPress_Pack::operations(),
            JetEngine_Pack::operations(),
            Pods_Pack::operations(),
            TranslatePress_Pack::operations(),
            Code_Snippets_Pack::operations(),
            WS_Form_Pack::operations(),
            Everest_Forms_Pack::operations(),
            Activity_Log_Pack::operations(),
            Action_Scheduler_Pack::operations(),
            Email_Log_Pack::operations(),
            Pretty_Links_Pack::operations(),
            Thirsty_Affiliates_Pack::operations(),
            Advanced_Ads_Pack::operations(),
            Discount_Rules_Pack::operations(),
            Popup_Builder_Pack::operations(),
            Hustle_Pack::operations(),
            Depicter_Pack::operations(),
            Popup_Maker_Pack::operations(),
            OptinMonster_Pack::operations(),
            Site_Reviews_Pack::operations(),
            Simple_Pay_Pack::operations(),
            Paid_Member_Subscriptions_Pack::operations(),
            WPGraphQL_Pack::operations(),
            Tracking_Pack::operations(),
            Search_Replace_Pack::operations(),
            FooGallery_Pack::operations(),
            WpForo_Pack::operations(),
            Asgaros_Forum_Pack::operations(),
            Restrict_Content_Pack::operations(),
            Ultimate_Member_Pack::operations(),
            Cpt_Builder_Pack::operations(),
            PublishPress_Pack::operations(),
            Listings_Pack::operations(),
            Events_Manager_Pack::operations(),
            EDD_Pack::operations(),
            BuddyPress_Pack::operations(),
            Gamipress_Pack::operations(),
            LMS_Pack::operations(),
            Thrive_Optimize_Pack::operations(),
            Offload_Media_Pack::operations(),
            Thrive_Ultimatum_Pack::operations(),
            Thrive_Ovation_Pack::operations(),
            Thrive_Leads_Pack::operations(),
            Jetpack_Crm_Pack::operations(),
            HubSpot_Pack::operations(),
            Booking_Pack::operations(),
            WPCode_Pack::operations(),
            TablePress_Pack::operations(),
            Data_Tables_Pack::operations(),
            CRM_Pack::operations(),
            Mailing_List_Pack::operations(),
            Amelia_Pack::operations(),
            I18n_Pack::operations(),
            MemberPress_Pack::operations(),
            Elementor_Pro_Forms_Pack::operations(),
            BetterDocs_Pack::operations(),
            Helpdesk_Pack::operations(),
            Thrive_Automator_Pack::operations(),
            Translation_Settings_Pack::operations(),
            Easy_TOC_Pack::operations(),
            Email_Marketing_Pack::operations(),
            Checkout_Field_Editor_Pack::operations(),
            SureCart_Pack::operations()
        );
    }

    /** Input schema shared by the list-fields ops. */
    public static function post_type_schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [ 'post_type' => [ 'type' => 'string', 'minLength' => 1 ] ],
            'required'   => [ 'post_type' ],
        ];
    }

    /** Input schema shared by the get-fields ops. */
    public static function get_fields_schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                'keys'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            ],
            'required'   => [ 'post_id' ],
        ];
    }

    /** Input schema shared by the update-fields ops. */
    public static function update_fields_schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                'fields'  => [ 'type' => 'object', 'minProperties' => 1 ],
            ],
            'required'   => [ 'post_id', 'fields' ],
        ];
    }

    /**
     * Refusal for a post the current user may not act on, or null. $write
     * asks for edit_post, otherwise read_post.
     *
     * @return array{code: string, message: string, data: array}|null
     */
    public static function post_refusal(int $post_id, bool $write): ?array
    {
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            return [ 'code' => 'post_not_found', 'message' => sprintf('Post %d does not exist.', $post_id), 'data' => [] ];
        }
        if (! current_user_can($write ? 'edit_post' : 'read_post', $post_id)) {
            return [
                'code'    => 'operation_denied',
                'message' => sprintf('You are not allowed to %s post %d.', $write ? 'edit' : 'read', $post_id),
                'data'    => [ 'reason' => 'capability' ],
            ];
        }
        return null;
    }

    /**
     * Refusal naming the keys that are not fields of the post's type, or null.
     *
     * @param array<string, mixed> $defined name => definition
     * @param string[]             $keys
     * @return array{code: string, message: string, data: array}|null
     */
    public static function unknown_keys_refusal(array $defined, array $keys, string $post_type, string $plugin): ?array
    {
        $unknown = array_values(array_diff($keys, array_keys($defined)));
        if ([] === $unknown) {
            return null;
        }
        return [
            'code'    => 'unknown_field',
            'message' => sprintf('Not a %s field of post type "%s": %s.', $plugin, $post_type, implode(', ', $unknown)),
            'data'    => [ 'unknown' => $unknown, 'fields' => array_keys($defined) ],
        ];
    }

    /** The current value of one post meta key, or null when it is not set. */
    public static function meta_value(int $post_id, string $key)
    {
        return metadata_exists('post', $post_id, $key) ? get_post_meta($post_id, $key, true) : null;
    }
}
