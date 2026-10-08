<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Operator-only OAuth compatibility preflight for a dedicated STAGING tenant.
 *
 * No HTTP routes, tokens, grants, local identity links, user records or
 * private Plan queries. The only permitted network call is an explicitly
 * requested GET to the already configured/pinned IdP discovery document.
 *
 * Passing this check does NOT authorize enabling tools in production.
 */
final class McpStagingProviderPreflightService
{
    public function __construct(
        private readonly McpProtectedResourceConfiguration $resource,
        private readonly McpOAuthAccountLinkConfiguration $linkConfig,
        private readonly McpAccessTokenIntrospector $introspector,
    ) {}

    /**
     * @return array{
     *  schema:string,
     *  local_checks:array<string, string>,
     *  provider_checks:array<string, string>,
     *  supported_client_modes:list<string>,
     *  preflight_passed:bool,
     *  production_authorized:bool,
     *  remaining_external_checks:list<string>
     * }
     */
    public function check(bool $probeMetadata = false): array
    {
        // Preserve only named pass/fail codes, never configuration contents.
        // Do not return issuer host, identities, fingerprints or secrets.
        $local = [
            'resource_discovery' => $this->resource->isReady() ? 'pass' : 'blocked',
            'introspection_enabled' => config('canovia_mcp.token_introspection_enabled') === true
                ? 'pass' : 'blocked',
            'introspection_configuration' => $this->introspector->isConfigured()
                ? 'pass' : 'blocked',
            'account_link_configuration' => $this->linkConfig->settings() !== null
                ? 'pass' : 'blocked',
            'plan_consent_enabled' => config('canovia_mcp.plan_consent_enabled') === true
                ? 'pass' : 'blocked',
            'delegated_policy_enabled' => config('canovia_mcp.delegated_policy_enabled') === true
                ? 'pass' : 'blocked',
            'tools_enabled' => config('canovia_mcp.tools_enabled') === true
                ? 'pass' : 'blocked',
        ];

        $provider = [
            'oauth_discovery' => 'not_probed',
            'issuer_and_endpoints' => 'not_probed',
            'pkce_and_callback_issuer' => 'not_probed',
            'canovia_confidential_client' => 'not_probed',
            'resource_scope' => 'not_probed',
            'introspection_endpoint' => 'not_probed',
            'chatgpt_client_registration' => 'not_probed',
        ];
        $modes = [];

        $settings = $this->linkConfig->settings();
        // Never make outbound HTTP calls when the local owner configuration
        // cannot be trusted, even if the CLI operator requested a probe.
        if ($probeMetadata && $settings !== null) {
            $this->inspectDiscovery($settings, $provider, $modes);
        }

        $allLocal = ! in_array('blocked', $local, true);
        $allRemote = ! in_array('not_probed', $provider, true)
            && ! in_array('blocked', $provider, true);

        return [
            'schema' => 'canovia.mcp.staging_provider_preflight.v1',
            'local_checks' => $local,
            'provider_checks' => $provider,
            'supported_client_modes' => $modes,
            'preflight_passed' => $allLocal && $allRemote,
            // Passing metadata assertions is NOT a real identity, token
            // binding, end-to-end OAuth or production security approval.
            'production_authorized' => false,
            'remaining_external_checks' => [
                'provision_isolated_canovia_staging_service_and_database',
                'register_canovia_confidential_oauth_client_and_exact_callback',
                'register_chatgpt_oauth_client_and_confirm_issued_client_id',
                'test_actual_chatgpt_resource_bound_tokens_and_introspection_claims',
                'test_explicit_plan_consent_revocation_and_read_only_mcp_end_to_end',
                'review_oauth_security_origin_concurrency_cost_and_rollback',
            ],
        ];
    }

    /**
     * @param array{issuer:string,resource:string,metadata:string,authorize:string,token:string,redirect:string,client_id:string,client_secret:string} $settings
     * @param array<string,string> $checks
     * @param list<string> $modes
     */
    private function inspectDiscovery(array $settings, array &$checks, array &$modes): void
    {
        try {
            $response = Http::acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(2)
                ->timeout(5)
                ->get($settings['metadata']);

            if (! $response->successful()
                || ! str_contains(strtolower((string) $response->header('Content-Type')), 'application/json')
                || strlen($response->body()) > 12288) {
                $checks['oauth_discovery'] = 'blocked';
                return;
            }

            $meta = $response->json();
            if (! is_array($meta) || array_is_list($meta)) {
                $checks['oauth_discovery'] = 'blocked';
                return;
            }
        } catch (Throwable) {
            // Provider errors might contain URL query strings; never log
            // exception content, response bodies or headers.
            $checks['oauth_discovery'] = 'blocked';
            return;
        }

        $checks['oauth_discovery'] = 'pass';
        $checks['issuer_and_endpoints'] =
            ($meta['issuer'] ?? null) === $settings['issuer']
            && ($meta['authorization_endpoint'] ?? null) === $settings['authorize']
            && ($meta['token_endpoint'] ?? null) === $settings['token']
                ? 'pass' : 'blocked';

        $checks['pkce_and_callback_issuer'] =
            ($meta['authorization_response_iss_parameter_supported'] ?? null) === true
            && is_array($meta['code_challenge_methods_supported'] ?? null)
            && in_array('S256', $meta['code_challenge_methods_supported'], true)
                ? 'pass' : 'blocked';

        $authMethods = $meta['token_endpoint_auth_methods_supported'] ?? null;
        $methods = is_array($authMethods) && array_is_list($authMethods)
            ? $authMethods : [];

        $checks['canovia_confidential_client'] =
            in_array('client_secret_basic', $methods, true)
                ? 'pass' : 'blocked';

        $scopes = $meta['scopes_supported'] ?? null;
        $checks['resource_scope'] =
            is_array($scopes) && array_is_list($scopes)
            && in_array(McpProtectedResourceConfiguration::READ_SCOPE, $scopes, true)
                ? 'pass' : 'blocked';

        // Discovery advertises RFC7662 support but it does not prove real
        // token claims, subject mapping, audience or token revocation.
        $introspection = $meta['introspection_endpoint'] ?? null;
        $configured = config('canovia_mcp.introspection_url');
        $checks['introspection_endpoint'] = is_string($introspection)
            && is_string($configured) && $introspection === $configured
                ? 'pass' : 'blocked';

        // ChatGPT uses CIMD public "none" or signed private_key_jwt.
        // DCR is a separate compatible path. Neither this nor registered
        // client IDs are implicitly authenticated by discovery alone.
        if (($meta['client_id_metadata_document_supported'] ?? null) === true
            && (in_array('none', $methods, true)
                || in_array('private_key_jwt', $methods, true))) {
            $modes[] = 'cimd';
        }
        $registration = $meta['registration_endpoint'] ?? null;
        if (is_string($registration)
            && $this->trustedRegistrationEndpoint($registration, $settings['issuer'])
            && (in_array('none', $methods, true)
                || in_array('client_secret_basic', $methods, true)
                || in_array('client_secret_post', $methods, true))) {
            $modes[] = 'dcr';
        }

        $checks['chatgpt_client_registration'] = $modes !== []
            ? 'pass' : 'blocked';
    }

    private function trustedRegistrationEndpoint(string $url, string $issuer): bool
    {
        if (strlen($url) > 512 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        $issuerParts = parse_url($issuer);
        return is_array($parts) && is_array($issuerParts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'], $issuerParts['host'])
            && strcasecmp((string) $parts['host'], (string) $issuerParts['host']) === 0
            && (! isset($parts['port']) || $parts['port'] === 443)
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment'])
            && isset($parts['path']) && $parts['path'] !== '/';
    }
}
