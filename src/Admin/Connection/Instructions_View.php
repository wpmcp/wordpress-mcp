<?php

declare(strict_types=1);

namespace WPMCP\Admin\Connection;

use WPMCP\Admin\Connection_Page;
use WPMCP\Connect\Client_Config_Generator;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Step 3 of the Connect flow: only the chosen app's two or three sub-steps,
 * its one snippet (or the bundle, or the address to paste) and a simplified
 * picture of what the app shows. Other routes sit behind "Other ways". The
 * steps of the OAuth apps come from Client_Config_Generator, the text that
 * get-connection-info returns too.
 */
final class Instructions_View
{
    /** Tags the sub-step text may carry. */
    private const TEXT_TAGS = [
        'strong' => [],
        'code'   => [],
        'span'   => [ 'class' => true ],
    ];

    public function __construct(
        private readonly Snippet_View $snippets,
    ) {
    }

    public function render(Connect_Screen $screen): void
    {
        $app = $screen->app;
        if (null === $app) {
            return;
        }

        if (Sign_In::Oauth === $screen->model->sign_in) {
            $this->oauth($screen, $app);
            return;
        }

        $made = $screen->provisioned;
        if (null === $made) {
            printf('<p class="wpmcp-fine">%s</p>', esc_html__('The settings for your app, with the password inside, were shown once, when the connection was made. Lost them? Revoke the connection on the Connections tab and create a new one.', 'wpmcp'));
            return;
        }

        switch ($app) {
            case Client_App::ClaudeCode:
                $this->claude_code($made);
                return;
            case Client_App::ClaudeDesktop:
                $this->claude_desktop($screen, $made);
                return;
            case Client_App::Cursor:
                $this->cursor($made);
                return;
            case Client_App::Antigravity:
                $this->antigravity($made);
                return;
            default:
                $this->other($screen, $made);
        }
    }

    private function claude_code(Provisioned $made): void
    {
        $this->open(true);
        $this->item(__('Open a terminal in the project folder where you use Claude Code.', 'wpmcp'));
        $this->item(__('Run this command.', 'wpmcp'), false);
        $this->snippets->code('wpmcp-snippet-claude-code', __('Terminal', 'wpmcp'), $made->configs['claude-code']['command'] ?? '', $this->secret($made), __('Copy the Claude Code command', 'wpmcp'), true);
        echo '</li>';
        $this->item(sprintf(
            /* translators: 1: the /mcp command, 2: the server name wpmcp */
            __('Start Claude Code and type %1$s. You should see %2$s listed as connected.', 'wpmcp'),
            '<strong>/mcp</strong>',
            '<strong>wpmcp</strong>'
        ));
        $this->close();
        $this->picture_terminal();
        $this->end_howto();

        $this->other_way(__('Use a .mcp.json file instead', 'wpmcp'), function () use ($made): void {
            $this->text(sprintf(
                /* translators: 1: the file name .mcp.json, 2: the key mcpServers */
                __('Paste this into %1$s in your project. Already have one? Add the wpmcp entry inside its %2$s.', 'wpmcp'),
                '<code>.mcp.json</code>',
                '<code>mcpServers</code>'
            ));
            $this->snippets->code('wpmcp-snippet-claude-code-json', '.mcp.json', $made->configs['claude-code']['snippet'] ?? '', $this->secret($made), __('Copy the .mcp.json block', 'wpmcp'));
        });
    }

    private function claude_desktop(Connect_Screen $screen, Provisioned $made): void
    {
        $this->routes($screen);
        $this->open(true);
        $this->item(__('Download the WP MCP bundle for Claude Desktop.', 'wpmcp'), false);
        ?>
        <div class="wpmcp-actions"><a class="button button-primary" href="<?php echo esc_url($screen->bundle_url); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span><?php echo esc_html__('Download wpmcp.mcpb', 'wpmcp'); ?></a></div>
        <p class="wpmcp-fine"><?php echo esc_html__('No password inside: only this site\'s address and a small connector program.', 'wpmcp'); ?></p>
        </li>
        <?php
        $this->item(sprintf(
            /* translators: %s: the file name wpmcp.mcpb */
            __('Double-click %s. Claude Desktop opens and offers to install WP MCP.', 'wpmcp'),
            '<strong>wpmcp.mcpb</strong>'
        ));
        $this->item(sprintf(
            /* translators: 1: the user login, 2: the Install button label */
            __('When it asks, enter the username %1$s and the password from step 2, then click %2$s.', 'wpmcp'),
            '<strong>' . esc_html($made->user_login) . '</strong>',
            '<strong>Install</strong>'
        ));
        $this->close();
        $this->picture_install($made->user_login);
        $this->end_howto();

        $this->other_way(__('Edit claude_desktop_config.json instead (needs Node.js)', 'wpmcp'), function () use ($made): void {
            $this->text(sprintf(
                /* translators: %s: the key mcpServers */
                __('Merge this into claude_desktop_config.json under %s, then quit and reopen Claude Desktop. The file only runs programs on your computer, so this starts the mcp-remote bridge to this site.', 'wpmcp'),
                '<code>mcpServers</code>'
            ));
            $this->snippets->code('wpmcp-snippet-claude-desktop', 'claude_desktop_config.json', $made->configs['claude-desktop']['snippet'] ?? '', $this->secret($made), __('Copy the Claude Desktop config', 'wpmcp'));
        });
    }

    private function cursor(Provisioned $made): void
    {
        $this->open(false);
        $this->item(sprintf(
            /* translators: 1: the project file .cursor/mcp.json, 2: the global file ~/.cursor/mcp.json */
            __('Open %1$s in your project, or %2$s to use WP MCP in all your projects. Create the file if it isn\'t there.', 'wpmcp'),
            '<code>.cursor/mcp.json</code>',
            '<code>~/.cursor/mcp.json</code>'
        ));
        $this->item(sprintf(
            /* translators: 1: the server name wpmcp, 2: the key mcpServers */
            __('Paste this and save. Already have other servers? Add the %1$s entry inside your existing %2$s.', 'wpmcp'),
            '<code>wpmcp</code>',
            '<code>mcpServers</code>'
        ), false);
        $this->snippets->code('wpmcp-snippet-cursor', '.cursor/mcp.json', $made->configs['cursor']['snippet'] ?? '', $this->secret($made), __('Copy the Cursor config', 'wpmcp'));
        echo '</li>';
        $this->item(sprintf(
            /* translators: %s: the server name wpmcp */
            __('In Cursor\'s settings, find %s in the MCP list and make sure it is turned on.', 'wpmcp'),
            '<strong>wpmcp</strong>'
        ));
        $this->close();
        $this->end_howto();
    }

    private function antigravity(Provisioned $made): void
    {
        $this->open(false);
        $this->item(sprintf(
            /* translators: 1: the menu path Customizations > Installed, 2: the section MCP Servers, 3: the button Copy Config File Path */
            __('In Antigravity, open %1$s, scroll to %2$s and click %3$s.', 'wpmcp'),
            '<span class="wpmcp-path">Customizations &gt; Installed</span>',
            '<strong>MCP Servers</strong>',
            '<strong>Copy Config File Path</strong>'
        ), false);
        $this->picture_antigravity();
        echo '</li>';
        $this->item(sprintf(
            /* translators: %s: the file name mcp_config.json */
            __('Open that file (%s), paste this and save.', 'wpmcp'),
            '<code>mcp_config.json</code>'
        ), false);
        $this->snippets->code('wpmcp-snippet-antigravity', 'mcp_config.json', $made->configs['antigravity']['snippet'] ?? '', $this->secret($made), __('Copy the Antigravity config', 'wpmcp'));
        echo '</li>';
        $this->item(__('Quit and reopen Antigravity, then start a new conversation so the WP MCP tools load.', 'wpmcp'));
        $this->close();
        $this->end_howto();
    }

    private function other(Connect_Screen $screen, Provisioned $made): void
    {
        $base = admin_url('admin.php?page=' . Connection_Page::SLUG . '&client=other');
        ?>
        <nav class="wpmcp-seg" aria-label="<?php echo esc_attr__('Which app', 'wpmcp'); ?>" data-wpmcp-variants>
            <a href="<?php echo esc_url($base . '#wpmcp-step-3'); ?>" data-variant="vscode"<?php echo $screen->generic ? '' : ' aria-current="true"'; ?>>VS Code</a>
            <a href="<?php echo esc_url($base . '&variant=generic#wpmcp-step-3'); ?>" data-variant="generic"<?php echo $screen->generic ? ' aria-current="true"' : ''; ?>><?php echo esc_html__('Any MCP client', 'wpmcp'); ?></a>
        </nav>
        <div class="wpmcp-variant<?php echo esc_attr($screen->generic ? ' is-inactive' : ''); ?>" data-variant-panel="vscode">
        <?php
        $this->open(false);
        $this->item(sprintf(
            /* translators: %s: the file name .vscode/mcp.json */
            __('In your project, open or create %s.', 'wpmcp'),
            '<code>.vscode/mcp.json</code>'
        ));
        $this->item(sprintf(
            /* translators: %s: the key servers */
            __('Paste this and save. VS Code reads the %s key.', 'wpmcp'),
            '<code>servers</code>'
        ), false);
        $this->snippets->code('wpmcp-snippet-vscode', '.vscode/mcp.json', $made->configs['vscode']['snippet'] ?? '', $this->secret($made), __('Copy the VS Code config', 'wpmcp'));
        echo '</li>';
        $this->item(sprintf(
            /* translators: %s: the server name wpmcp */
            __('If VS Code doesn\'t pick it up, reload the window and check that %s is in its MCP server list.', 'wpmcp'),
            '<strong>wpmcp</strong>'
        ));
        $this->close();
        $this->end_howto();
        ?>
        </div>
        <div class="wpmcp-variant<?php echo esc_attr($screen->generic ? '' : ' is-inactive'); ?>" data-variant-panel="generic">
        <?php
        $this->open(false);
        $this->item(__('Most MCP apps need two things. The address:', 'wpmcp'), false);
        $this->snippets->copy_row('wpmcp-snippet-endpoint', $screen->endpoint, __('Copy the address', 'wpmcp'));
        echo '</li>';
        $this->item(__('And this header (transport: streamable HTTP):', 'wpmcp'), false);
        $this->snippets->code('wpmcp-snippet-header', __('Header', 'wpmcp'), 'Authorization: ' . $made->auth_header, $this->secret($made), __('Copy the header', 'wpmcp'), true);
        echo '</li>';
        $this->item(__('If your app takes a JSON file, this is the common shape:', 'wpmcp'), false);
        $this->snippets->code('wpmcp-snippet-generic', 'mcp.json', $made->configs['generic']['snippet'] ?? '', $this->secret($made), __('Copy the JSON block', 'wpmcp'));
        echo '</li>';
        $this->close();
        $this->end_howto();
        ?>
        </div>
        <?php
    }

    /** ChatGPT and the Claude connector: the generator's steps, with the address to copy where a step names it. */
    private function oauth(Connect_Screen $screen, Client_App $app): void
    {
        $chatgpt = Client_App::Chatgpt === $app;
        if (! $chatgpt) {
            $this->routes($screen);
        }
        $entry = $chatgpt ? Client_Config_Generator::chatgpt(true) : Client_Config_Generator::custom_connector(true);

        $this->open(true);
        foreach ($entry['steps'] as $step) {
            $names_url = str_contains($step, $screen->endpoint);
            $this->item(str_replace(esc_html($screen->endpoint), '<code>' . esc_html($screen->endpoint) . '</code>', esc_html($step)), ! $names_url);
            if (! $names_url) {
                continue;
            }
            $this->snippets->copy_row('wpmcp-snippet-url', $screen->endpoint, __('Copy the MCP server address', 'wpmcp'));
            echo '</li>';
        }
        $this->close();
        $this->picture_connector($chatgpt, $screen->endpoint);
        $this->end_howto();

        if ($chatgpt) {
            printf('<p class="wpmcp-callout">%s</p>', esc_html(Client_Config_Generator::chatgpt_developer_mode_note()));
        }
    }

    /** Claude Desktop's two routes, as links: the bundle (default) and the custom connector. */
    private function routes(Connect_Screen $screen): void
    {
        $base = admin_url('admin.php?page=' . Connection_Page::SLUG . '&client=claude-desktop');
        ?>
        <nav class="wpmcp-seg" aria-label="<?php echo esc_attr__('How to connect Claude Desktop', 'wpmcp'); ?>">
            <a href="<?php echo esc_url($base . '#wpmcp-step-2'); ?>"<?php echo $screen->connector ? '' : ' aria-current="true"'; ?>><?php echo esc_html__('Bundle', 'wpmcp'); ?> <span class="wpmcp-rec"><?php echo esc_html__('Recommended', 'wpmcp'); ?></span></a>
            <a href="<?php echo esc_url($base . '&route=connector#wpmcp-step-2'); ?>"<?php echo $screen->connector ? ' aria-current="true"' : ''; ?>><?php echo esc_html__('Custom connector (OAuth)', 'wpmcp'); ?></a>
        </nav>
        <?php
    }

    private function open(bool $picture): void
    {
        printf('<div class="wpmcp-howto%s"><ol class="wpmcp-sub">', $picture ? '' : ' no-picture');
    }

    private function close(): void
    {
        echo '</ol>';
    }

    private function end_howto(): void
    {
        echo '</div>';
    }

    /** A sub-step; with $close false the caller adds a block and closes the item. */
    private function item(string $html, bool $close = true): void
    {
        echo '<li>' . wp_kses($html, self::TEXT_TAGS);
        if ($close) {
            echo '</li>';
        }
    }

    private function text(string $html): void
    {
        echo '<p class="wpmcp-fine">' . wp_kses($html, self::TEXT_TAGS) . '</p>';
    }

    private function other_way(string $summary, callable $body): void
    {
        ?>
        <details class="wpmcp-details">
            <summary><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span><?php echo esc_html($summary); ?></summary>
            <div class="wpmcp-details__body"><?php $body(); ?></div>
        </details>
        <?php
    }

    /** The base64 part of the Authorization header: the text the page hides on screen. */
    private function secret(Provisioned $made): string
    {
        return str_starts_with($made->auth_header, 'Basic ') ? substr($made->auth_header, 6) : $made->auth_header;
    }

    private function picture_terminal(): void
    {
        ?>
        <figure class="wpmcp-hint">
            <div class="wpmcp-mock wpmcp-mock--term" role="img" aria-label="<?php echo esc_attr__('Claude Code in a terminal: the /mcp command lists wpmcp as connected.', 'wpmcp'); ?>">
                <div class="wpmcp-mock__bar"><i></i><i></i><i></i><span>Terminal</span></div>
                <div class="wpmcp-mock__body"><div><span class="d">$</span> claude</div><div><span class="y">&gt;</span> /mcp</div><div class="d">MCP servers</div><div><span class="g">&#9679;</span> wpmcp <span class="g">connected</span></div></div>
            </div>
            <figcaption><?php echo esc_html__('In Claude Code, /mcp lists wpmcp as connected (simplified).', 'wpmcp'); ?></figcaption>
        </figure>
        <?php
    }

    private function picture_install(string $user_login): void
    {
        ?>
        <figure class="wpmcp-hint">
            <div class="wpmcp-mock" role="img" aria-label="<?php echo esc_attr__('Claude Desktop install dialog with a username field, an application password field and an Install button.', 'wpmcp'); ?>">
                <div class="wpmcp-mock__bar"><i></i><i></i><i></i><span>Claude</span></div>
                <div class="wpmcp-mock__body">
                    <div class="wpmcp-mock__title">Install WP MCP?</div>
                    <div class="wpmcp-mock__field"><b>Username</b><span><?php echo esc_html($user_login); ?></span></div>
                    <div class="wpmcp-mock__field"><b>Application password</b><span class="is-focus">&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;</span></div>
                    <span class="wpmcp-mock__button is-claude">Install</span>
                </div>
            </div>
            <figcaption><?php echo esc_html__('Claude Desktop asks for the username and password once (simplified).', 'wpmcp'); ?></figcaption>
        </figure>
        <?php
    }

    private function picture_connector(bool $chatgpt, string $endpoint): void
    {
        ?>
        <figure class="wpmcp-hint">
            <div class="wpmcp-mock" role="img" aria-label="<?php echo esc_attr($chatgpt ? __('ChatGPT\'s connector form: a name, the MCP server URL and OAuth as the authentication.', 'wpmcp') : __('Claude\'s custom connector form: a name and the remote MCP server URL.', 'wpmcp')); ?>">
                <div class="wpmcp-mock__bar"><i></i><i></i><i></i><span><?php echo esc_html($chatgpt ? 'ChatGPT' : 'Claude'); ?></span></div>
                <div class="wpmcp-mock__body">
                    <div class="wpmcp-mock__title"><?php echo esc_html($chatgpt ? 'New connector' : 'Add custom connector'); ?></div>
                    <div class="wpmcp-mock__field"><b>Name</b><span>WP MCP</span></div>
                    <div class="wpmcp-mock__field"><b><?php echo esc_html($chatgpt ? 'MCP server URL' : 'Remote MCP server URL'); ?></b><span class="is-focus"><?php echo esc_html($endpoint); ?></span></div>
                    <?php if ($chatgpt) : ?>
                        <div class="wpmcp-mock__field"><b>Authentication</b><span>OAuth</span></div>
                    <?php endif; ?>
                    <span class="wpmcp-mock__button<?php echo esc_attr($chatgpt ? '' : ' is-claude'); ?>"><?php echo esc_html($chatgpt ? 'Create' : 'Add'); ?></span>
                </div>
            </div>
            <figcaption><?php echo esc_html($chatgpt ? __('ChatGPT\'s connector form (simplified).', 'wpmcp') : __('The connector needs only the address (simplified).', 'wpmcp')); ?></figcaption>
        </figure>
        <?php
    }

    private function picture_antigravity(): void
    {
        ?>
        <figure class="wpmcp-hint is-inline">
            <div class="wpmcp-mock" role="img" aria-label="<?php echo esc_attr__('Antigravity: Customizations in the sidebar, the Installed tab, and the MCP Servers section with a Copy Config File Path button.', 'wpmcp'); ?>">
                <div class="wpmcp-mock__bar"><i></i><i></i><i></i><span>Antigravity</span></div>
                <div class="wpmcp-mock__ag">
                    <div class="wpmcp-mock__side"><div>Agent Manager</div><div class="is-on">Customizations</div><div>Settings</div></div>
                    <div class="wpmcp-mock__main"><div class="wpmcp-mock__tabs"><span>Browse</span><span class="is-on">Installed</span></div><div class="wpmcp-mock__title">MCP Servers (0)</div><span class="wpmcp-mock__button is-outline">Copy Config File Path</span></div>
                </div>
            </div>
            <figcaption><?php echo esc_html__('Where the button is in Antigravity (simplified).', 'wpmcp'); ?></figcaption>
        </figure>
        <?php
    }
}
