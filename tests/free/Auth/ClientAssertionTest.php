<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\Authorization_Grant;
use WPMCP\Auth\Authorization_Server_Metadata;
use WPMCP\Auth\Client_Assertion;
use WPMCP\Auth\Client_Metadata_Document;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Code_Store;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Grant;
use WPMCP\Auth\Token_Store;
use WPMCP\Governance\Governance_Audit_Log;

/**
 * Issue #1098: ChatGPT cannot connect over OAuth.
 *
 * ChatGPT's client metadata document (https://chatgpt.com/oauth/client.json,
 * fetched 2026-11-19) declares token_endpoint_auth_method "private_key_jwt"
 * with an RS256 key at https://chatgpt.com/oauth/jwks.json. The CIMD draft
 * says the server MUST then require RFC 7523 client authentication, so the
 * token endpoint verifies a signed client assertion against the client's
 * published JWKS: signature, iss = sub = client_id, aud naming this server,
 * a bounded exp and a single-use jti.
 */
class ClientAssertionTest extends \WP_UnitTestCase
{
    private const CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
    private const JWKS_URI  = 'https://chatgpt.com/oauth/jwks.json';
    private const REDIRECT  = 'https://chatgpt.com/connector_platform_oauth_redirect';
    private const VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const KID       = 'cimd-20260428030119';

    /** @var array<string, string> PEM private keys, generated once per run. */
    private static array $keys = [];

    /** @var array<int, string> */
    private array $requests = [];

    /** @var array<string, array{code: int, headers: array, body: string}> */
    private array $responses = [];

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        foreach (['good', 'other'] as $name) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            self::$keys[ $name ] = $pem;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->reset_state();
        $this->requests  = [];
        $this->responses = [
            self::CLIENT_ID => $this->json_response($this->chatgpt_document()),
            self::JWKS_URI  => $this->json_response($this->jwks('good')),
        ];

        add_filter('pre_http_request', [$this, 'fake_transport'], 10, 3);
        add_filter('wpmcp_remote_host_addresses', [$this, 'public_addresses'], 10, 2);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_transport'], 10);
        remove_filter('wpmcp_remote_host_addresses', [$this, 'public_addresses'], 10);
        $this->reset_state();
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function reset_state(): void
    {
        delete_option(Client_Store::OPTION);
        delete_option(Code_Store::OPTION);
        delete_option(Token_Store::OPTION);
        delete_option(Refresh_Token_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        delete_option(Client_Metadata_Document::REGISTRY_OPTION);
        delete_option(Client_Metadata_Document::APPROVAL_OPTION);
        delete_option(Client_Assertion::JTI_OPTION);
        Client_Metadata_Document::flush_cache(self::CLIENT_ID);
        Client_Assertion::flush_jwks_cache(self::JWKS_URI);
    }

    public function fake_transport($pre, array $args, string $url)
    {
        $this->requests[] = $url;
        $response         = $this->responses[ $url ] ?? ['code' => 404, 'headers' => [], 'body' => ''];

        return [
            'headers'  => $response['headers'],
            'body'     => $response['body'],
            'response' => ['code' => $response['code'], 'message' => ''],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /** @return string[] */
    public function public_addresses($addresses, string $host): array
    {
        return 'internal.example.com' === $host ? ['10.0.0.5'] : ['93.184.216.34'];
    }

    /** The document ChatGPT publishes, verbatim as fetched on 2026-11-19. */
    private function chatgpt_document(array $overrides = []): array
    {
        return array_merge([
            'client_id'                             => self::CLIENT_ID,
            'client_uri'                            => 'https://chatgpt.com/',
            'redirect_uris'                         => [self::REDIRECT],
            'token_endpoint_auth_method'            => 'private_key_jwt',
            'token_endpoint_auth_methods_supported' => ['none', 'private_key_jwt'],
            'grant_types'                           => ['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:jwt-bearer'],
            'response_types'                        => ['code'],
            'client_name'                           => 'ChatGPT',
            'logo_uri'                              => 'https://persistent.oaistatic.com/sonic/misc/openai-logo.png',
            'token_endpoint_auth_signing_alg'       => 'RS256',
            'jwks_uri'                              => self::JWKS_URI,
        ], $overrides);
    }

    private function json_response(array $body, int $code = 200): array
    {
        return [
            'code'    => $code,
            'headers' => ['content-type' => 'application/json', 'cache-control' => 'public, max-age=300'],
            'body'    => (string) wp_json_encode($body),
        ];
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function jwk(string $name, string $kid = self::KID): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(self::$keys[ $name ]));

        return [
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'RS256',
            'n'   => self::b64($details['rsa']['n']),
            'e'   => self::b64($details['rsa']['e']),
        ];
    }

    private function jwks(string $name, string $kid = self::KID): array
    {
        return ['keys' => [$this->jwk($name, $kid)]];
    }

    private function token_endpoint(): string
    {
        return untrailingslashit(home_url()) . '/wp-json/wpmcp/v1/oauth/token';
    }

    private function assertion(array $claims = [], array $header = [], string $key = 'good'): string
    {
        $now     = time();
        $header  = array_merge(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => self::KID], $header);
        $payload = array_merge([
            'iss' => self::CLIENT_ID,
            'sub' => self::CLIENT_ID,
            'aud' => $this->token_endpoint(),
            'iat' => $now,
            'exp' => $now + 120,
            'jti' => wp_generate_uuid4(),
        ], $claims);

        $input = self::b64((string) wp_json_encode($header)) . '.' . self::b64((string) wp_json_encode($payload));
        openssl_sign($input, $signature, self::$keys[ $key ], OPENSSL_ALGO_SHA256);

        return $input . '.' . self::b64($signature);
    }

    private function log_in(): int
    {
        $user = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user);
        return $user;
    }

    private function authorize()
    {
        return Authorization_Grant::authorize([
            'response_type'         => 'code',
            'client_id'             => self::CLIENT_ID,
            'redirect_uri'          => self::REDIRECT,
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope'                 => 'mcp',
        ]);
    }

    private function code(): string
    {
        $this->log_in();
        $authorized = $this->authorize();
        $this->assertIsArray($authorized, is_wp_error($authorized) ? $authorized->get_error_message() : '');

        return $authorized['code'];
    }

    private function exchange(string $code, ?string $assertion, array $extra = [])
    {
        $params = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::REDIRECT,
            'client_id'     => self::CLIENT_ID,
            'code_verifier' => self::VERIFIER,
        ];
        if (null !== $assertion) {
            $params['client_assertion_type'] = Client_Assertion::ASSERTION_TYPE;
            $params['client_assertion']      = $assertion;
        }

        return Token_Grant::exchange(array_merge($params, $extra));
    }

    private function assertInvalidClient($result): void
    {
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_client', $result->get_error_code());
    }

    // Discovery.

    public function test_metadata_advertises_private_key_jwt_and_its_algorithms(): void
    {
        $doc = Authorization_Server_Metadata::build('https://example.com');

        $this->assertSame(['client_secret_post', 'none', 'private_key_jwt'], $doc['token_endpoint_auth_methods_supported']);
        $this->assertSame(['RS256', 'RS384', 'RS512'], $doc['token_endpoint_auth_signing_alg_values_supported']);
    }

    public function test_metadata_without_cimd_advertises_neither(): void
    {
        add_filter('wpmcp_oauth_cimd_enabled', '__return_false');
        $doc = Authorization_Server_Metadata::build('https://example.com');
        remove_all_filters('wpmcp_oauth_cimd_enabled');

        $this->assertSame(['client_secret_post'], $doc['token_endpoint_auth_methods_supported']);
        $this->assertArrayNotHasKey('token_endpoint_auth_signing_alg_values_supported', $doc);
    }

    // The ChatGPT flow.

    public function test_chatgpt_document_is_accepted_at_authorize(): void
    {
        $this->log_in();

        $authorized = $this->authorize();

        $this->assertIsArray($authorized, is_wp_error($authorized) ? $authorized->get_error_message() : '');
        $this->assertSame('private_key_jwt', Client_Metadata_Document::clients()[ self::CLIENT_ID ]['auth_method']);
    }

    public function test_valid_assertion_completes_the_code_and_refresh_grants(): void
    {
        $code = $this->code();

        $token = $this->exchange($code, $this->assertion());
        $this->assertIsArray($token, is_wp_error($token) ? $token->get_error_message() : '');
        $this->assertSame(self::CLIENT_ID, Token_Store::validate($token['access_token'])['client_id']);

        $refreshed = Token_Grant::exchange([
            'grant_type'            => 'refresh_token',
            'refresh_token'         => $token['refresh_token'],
            'client_id'             => self::CLIENT_ID,
            'client_assertion_type' => Client_Assertion::ASSERTION_TYPE,
            'client_assertion'      => $this->assertion(),
        ]);
        $this->assertIsArray($refreshed, is_wp_error($refreshed) ? $refreshed->get_error_message() : '');
    }

    public function test_client_id_may_be_omitted_when_the_assertion_names_it(): void
    {
        $code = $this->code();

        $token = $this->exchange($code, $this->assertion(), ['client_id' => '']);

        $this->assertIsArray($token, is_wp_error($token) ? $token->get_error_message() : '');
    }

    public function test_aud_may_be_the_issuer_identifier(): void
    {
        $code = $this->code();

        $token = $this->exchange($code, $this->assertion(['aud' => [untrailingslashit(home_url())]]));

        $this->assertIsArray($token, is_wp_error($token) ? $token->get_error_message() : '');
    }

    public function test_jwks_is_cached_between_token_requests(): void
    {
        $code  = $this->code();
        $token = $this->exchange($code, $this->assertion());
        $this->assertIsArray($token);

        Token_Grant::exchange([
            'grant_type'            => 'refresh_token',
            'refresh_token'         => $token['refresh_token'],
            'client_id'             => self::CLIENT_ID,
            'client_assertion_type' => Client_Assertion::ASSERTION_TYPE,
            'client_assertion'      => $this->assertion(),
        ]);

        $this->assertSame(1, count(array_keys($this->requests, self::JWKS_URI, true)));
    }

    public function test_an_unknown_kid_refetches_the_jwks_once_for_key_rotation(): void
    {
        $code = $this->code();
        // Warm the cache with the old key set.
        Client_Assertion::flush_jwks_cache(self::JWKS_URI);
        $this->assertIsArray(Client_Assertion::keys_for(self::JWKS_URI));

        $this->responses[ self::JWKS_URI ] = $this->json_response($this->jwks('other', 'rotated'));
        $token = $this->exchange($code, $this->assertion([], ['kid' => 'rotated'], 'other'));

        $this->assertIsArray($token, is_wp_error($token) ? $token->get_error_message() : '');
    }

    // Refusals.

    public function test_a_private_key_jwt_client_without_an_assertion_is_refused_and_keeps_its_code(): void
    {
        $code = $this->code();

        $this->assertInvalidClient($this->exchange($code, null));

        // The failed authentication did not burn the code.
        $this->assertIsArray($this->exchange($code, $this->assertion()));
    }

    public function test_bad_signature_is_refused(): void
    {
        $code = $this->code();

        $this->assertInvalidClient($this->exchange($code, $this->assertion([], [], 'other')));
    }

    public function test_tampered_payload_is_refused(): void
    {
        $code  = $this->code();
        $parts = explode('.', $this->assertion());
        $parts[1] = self::b64((string) wp_json_encode(['iss' => self::CLIENT_ID, 'sub' => self::CLIENT_ID, 'aud' => $this->token_endpoint(), 'exp' => time() + 9000, 'jti' => 'x']));

        $this->assertInvalidClient($this->exchange($code, implode('.', $parts)));
    }

    public function test_wrong_audience_is_refused(): void
    {
        $code = $this->code();

        $this->assertInvalidClient($this->exchange($code, $this->assertion(['aud' => 'https://other.example.net/token'])));
    }

    public function test_expired_assertion_is_refused(): void
    {
        $code = $this->code();

        $this->assertInvalidClient($this->exchange($code, $this->assertion(['iat' => time() - 900, 'exp' => time() - 300])));
    }

    public function test_assertion_without_exp_or_with_a_far_exp_is_refused(): void
    {
        $code = $this->code();

        $this->assertInvalidClient($this->exchange($code, $this->assertion(['exp' => null])));
        $this->assertInvalidClient($this->exchange($code, $this->assertion(['exp' => time() + 86400])));
        $this->assertInvalidClient($this->exchange($code, $this->assertion(['nbf' => time() + 3600])));
    }

    public function test_replayed_jti_is_refused(): void
    {
        $code      = $this->code();
        $assertion = $this->assertion();

        $token = $this->exchange($code, $assertion);
        $this->assertIsArray($token);

        $replay = Token_Grant::exchange([
            'grant_type'            => 'refresh_token',
            'refresh_token'         => $token['refresh_token'],
            'client_id'             => self::CLIENT_ID,
            'client_assertion_type' => Client_Assertion::ASSERTION_TYPE,
            'client_assertion'      => $assertion,
        ]);
        $this->assertInvalidClient($replay);
    }

    public function test_assertion_without_jti_is_refused(): void
    {
        $code = $this->code();

        $this->assertInvalidClient($this->exchange($code, $this->assertion(['jti' => null])));
    }

    public function test_iss_or_sub_other_than_the_client_is_refused(): void
    {
        $code = $this->code();

        $this->assertInvalidClient($this->exchange($code, $this->assertion(['iss' => 'https://evil.example.net/c.json'])));
        $this->assertInvalidClient($this->exchange($code, $this->assertion(['sub' => 'someone-else'])));
    }

    public function test_unsupported_algorithms_and_key_headers_are_refused(): void
    {
        $code = $this->code();

        $this->assertInvalidClient($this->exchange($code, $this->assertion([], ['alg' => 'none'])));
        $this->assertInvalidClient($this->exchange($code, $this->assertion([], ['alg' => 'HS256'])));
        $this->assertInvalidClient($this->exchange($code, $this->assertion([], ['jku' => 'https://evil.example.net/jwks.json'])));
        $this->assertInvalidClient($this->exchange($code, $this->assertion([], ['jwk' => $this->jwk('other')], 'other')));
    }

    public function test_assertion_type_must_be_jwt_bearer(): void
    {
        $code = $this->code();

        $this->assertInvalidClient($this->exchange($code, $this->assertion(), ['client_assertion_type' => 'urn:example:other']));
    }

    public function test_assertion_with_a_client_secret_too_is_refused(): void
    {
        $code = $this->code();

        $this->assertInvalidClient($this->exchange($code, $this->assertion(), ['client_secret' => 'guess']));
    }

    public function test_jwks_fetch_failure_is_refused(): void
    {
        $code = $this->code();
        $this->responses[ self::JWKS_URI ] = ['code' => 500, 'headers' => [], 'body' => ''];

        $this->assertInvalidClient($this->exchange($code, $this->assertion()));
    }

    public function test_jwks_that_is_not_a_key_set_is_refused(): void
    {
        $code = $this->code();
        $this->responses[ self::JWKS_URI ] = $this->json_response(['keys' => [['kty' => 'oct', 'k' => 'c2VjcmV0']]]);

        $this->assertInvalidClient($this->exchange($code, $this->assertion()));
    }

    public function test_pkce_is_still_required_for_a_private_key_jwt_client(): void
    {
        $code = $this->code();

        $result = $this->exchange($code, $this->assertion(), ['code_verifier' => str_repeat('a', 43)]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_grant', $result->get_error_code());
    }

    public function test_a_public_cimd_client_cannot_present_an_assertion(): void
    {
        $this->responses[ self::CLIENT_ID ] = $this->json_response($this->chatgpt_document([
            'token_endpoint_auth_method' => 'none',
            'jwks_uri'                   => null,
        ]));
        $code = $this->code();

        $this->assertInvalidClient($this->exchange($code, $this->assertion()));
        // It still works the public way.
        $this->assertIsArray($this->exchange($code, null));
    }

    public function test_a_dcr_client_cannot_authenticate_with_an_assertion(): void
    {
        $client = Client_Store::create(['Test App'], ['https://example.com/cb']);

        $result = Token_Grant::exchange([
            'grant_type'            => 'authorization_code',
            'code'                  => 'whatever',
            'client_id'             => $client['client_id'],
            'client_assertion_type' => Client_Assertion::ASSERTION_TYPE,
            'client_assertion'      => $this->assertion(['iss' => $client['client_id'], 'sub' => $client['client_id']]),
        ]);

        $this->assertInvalidClient($result);
    }

    // Document validation.

    public function test_private_key_jwt_document_without_keys_is_refused(): void
    {
        $this->log_in();
        $this->responses[ self::CLIENT_ID ] = $this->json_response($this->chatgpt_document(['jwks_uri' => null]));

        $result = $this->authorize();

        $this->assertInvalidClient($result);
        $this->assertStringContainsString('jwks_uri', $result->get_error_message());
    }

    public function test_jwks_uri_on_another_origin_or_unsafe_is_refused(): void
    {
        $this->log_in();
        foreach (['https://keys.example.net/jwks.json', 'https://internal.example.com/jwks.json', 'http://chatgpt.com/oauth/jwks.json'] as $uri) {
            Client_Metadata_Document::flush_cache(self::CLIENT_ID);
            $this->responses[ self::CLIENT_ID ] = $this->json_response($this->chatgpt_document(['jwks_uri' => $uri]));

            $this->assertInvalidClient($this->authorize());
        }
    }

    public function test_unsupported_signing_alg_is_refused(): void
    {
        $this->log_in();
        $this->responses[ self::CLIENT_ID ] = $this->json_response($this->chatgpt_document(['token_endpoint_auth_signing_alg' => 'HS256']));

        $this->assertInvalidClient($this->authorize());
    }

    public function test_inline_jwks_is_accepted(): void
    {
        $this->responses[ self::CLIENT_ID ] = $this->json_response($this->chatgpt_document([
            'jwks_uri' => null,
            'jwks'     => $this->jwks('good'),
        ]));
        $code = $this->code();

        $token = $this->exchange($code, $this->assertion());

        $this->assertIsArray($token, is_wp_error($token) ? $token->get_error_message() : '');
        $this->assertNotContains(self::JWKS_URI, $this->requests);
    }

    public function test_shared_secret_document_names_the_value_and_points_at_dynamic_registration(): void
    {
        $this->log_in();
        $this->responses[ self::CLIENT_ID ] = $this->json_response($this->chatgpt_document(['token_endpoint_auth_method' => 'client_secret_basic']));

        $result = $this->authorize();

        $this->assertInvalidClient($result);
        $this->assertStringContainsString('"client_secret_basic"', $result->get_error_message());
        $this->assertStringContainsString('dynamic client registration', $result->get_error_message());
    }

    public function test_the_token_route_accepts_the_form_encoded_request_chatgpt_sends(): void
    {
        $code = $this->code();
        wp_set_current_user(0);

        add_filter('wpmcp_oauth_enabled', '__return_true');
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init', $wp_rest_server);

        // RFC 7523 section 2.2 over RFC 6749 section 4.1.3: an
        // application/x-www-form-urlencoded POST with the code, PKCE
        // verifier and a signed client assertion, and no client secret.
        // WordPress hands a form POST's fields to the route from $_POST,
        // which set_body_params() stands in for.
        $request = new \WP_REST_Request('POST', '/wpmcp/v1/oauth/token');
        $request->set_header('content-type', 'application/x-www-form-urlencoded');
        $request->set_body_params([
            'grant_type'            => 'authorization_code',
            'code'                  => $code,
            'redirect_uri'          => self::REDIRECT,
            'client_id'             => self::CLIENT_ID,
            'code_verifier'         => self::VERIFIER,
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion'      => $this->assertion(),
        ]);
        $response = rest_do_request($request);
        remove_all_filters('wpmcp_oauth_enabled');

        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertSame('Bearer', $response->get_data()['token_type'] ?? null);
        $this->assertNotEmpty($response->get_data()['access_token'] ?? '');
    }

    public function test_the_reported_refusal_no_longer_appears_for_the_chatgpt_document(): void
    {
        $this->log_in();

        $result = $this->authorize();

        $this->assertNotInstanceOf(\WP_Error::class, $result, 'was: only token_endpoint_auth_method "none" is supported for client metadata documents.');
    }
}
