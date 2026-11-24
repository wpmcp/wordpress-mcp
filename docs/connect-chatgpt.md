# Connect ChatGPT

ChatGPT adds a remote MCP server by its URL and signs in with OAuth. It cannot send an application password, so WPMCP's OAuth sign-in has to be on (issue #842).

## 1. Switch OAuth sign-in on

In wp-admin, open **WPMCP, Connection** and pick **ChatGPT**. Step 2 asks for OAuth sign-in: click **Turn on OAuth sign-in**. The same switch, **Allow OAuth sign-in**, is on the Settings tab. Only administrators who can manage WPMCP (the Management access list, #479) see it.

OAuth stays off on a fresh install until someone turns it on. Two developer seams keep working and win over the switch:

```php
// wp-config.php, above "That's all, stop editing!". Decides instead of the switch, true or false.
define('WPMCP_OAUTH_ENABLED', true);
```

```php
// a plugin or mu-plugin. Has the last word.
add_filter('wpmcp_oauth_enabled', '__return_true');
```

While either one is set, the screen hides the switch and says which one fixes the value.

ChatGPT connects from the internet, so the site needs a public HTTPS address. On a local or plain HTTP site, step 2 says so and offers the apps that run on your computer instead (#1149).

Turning the switch off stops new sign-ins and refuses every OAuth token issued earlier on its next request. Turning it back on lets tokens that have not expired work again; to cut one connection off for good, block it in the OAuth list on the Connections tab.

## 2. Add WPMCP to ChatGPT

Custom MCP connectors need ChatGPT's Developer mode, on the plans that offer it. In a workspace, an admin may have to allow it. ChatGPT renames its menus from time to time; if a step does not match, look for Developer mode under Settings.

1. On chatgpt.com, open Settings, then Apps & Connectors, open Advanced settings and turn on Developer mode.
2. Back in Apps & Connectors, choose Create and name the connector, for example "WPMCP". Enter the server URL, `https://your-site.com/wp-json/mcp/wpmcp-server` (step 3 of the Connection screen shows the exact one, with a Copy button), and choose OAuth as the authentication. Leave any client ID and secret empty: ChatGPT registers itself through dynamic client registration.
3. Confirm that you trust the connector and click Create, then sign in to WordPress in the window that opens and approve the access. The consent page offers full, read-only or identity-only access (#454). In a chat, open the + menu, choose Developer mode and turn on WPMCP for that chat. The desktop app uses the connectors added on the web.

Step 4, **Check connection**, then shows when ChatGPT registered, who approved it and when its token was issued. The `get-connection-info` tool returns the same steps under `clients.chatgpt`, with `oauth_enabled` showing the current state, so an agent can walk a user through it.

## What ChatGPT does on the wire

ChatGPT follows the MCP authorization flow: it reads the `WWW-Authenticate` header on the endpoint's 401, fetches the protected resource metadata and the authorization server metadata, registers itself, then runs the authorization code flow with PKCE and refreshes its token. Its redirect address, `https://chatgpt.com/connector_platform_oauth_redirect`, passes WPMCP's redirect URI check.

## Sites behind a proxy or CDN

The OAuth issuer and resource are built from `home_url()`. If the public address differs from it, fix the WordPress address settings first; a separate "public site address" setting is not part of this change.

## Not yet verified

The round trip against the live ChatGPT client (discovery, registration, consent, refresh) and ChatGPT's acceptance of every tool's input schema still need a manual test with screenshots. Record any schema shape ChatGPT rejects here.
