<?php

namespace App\Http\Controllers;

use App\Services\McpAccessTokenIntrospector;
use App\Services\McpExplicitPlanConsentService;
use App\Services\McpProtectedResourceConfiguration;
use App\Services\McpReadOnlyContextTool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stateless MCP Streamable HTTP subset: JSON-RPC POST only, one read-only
 * tool, no sessions, no SSE, no write methods and no OAuth issuance.
 *
 * OFF by default. Real IdP configuration and consent must be reviewed
 * independently before enabling any data response in production.
 */
final class McpReadOnlyResourceController extends Controller
{
    private const PROTOCOL_VERSION = '2025-11-25';
    private const MAX_BODY_BYTES = 8192;

    public function __invoke(
        Request $request,
        McpProtectedResourceConfiguration $resource,
        McpExplicitPlanConsentService $consent,
        McpAccessTokenIntrospector $introspector,
        McpReadOnlyContextTool $tool,
        McpAuthorizationProbeController $probe,
    ): JsonResponse|Response {
        // Preserve the previous deny-all behavior until an independent
        // reviewed feature flag is enabled. No request introspection here.
        if (config('canovia_mcp.tools_enabled') !== true) {
            return $probe($request, $resource);
        }

        abort_unless($resource->isReady()
            && $consent->isEnabled()
            && config('canovia_mcp.delegated_policy_enabled') === true
            && config('canovia_mcp.token_introspection_enabled') === true, 404);

        if (! $this->allowedOrigin($request)) {
            return $this->json(['error' => 'origin_not_allowed'], 403);
        }

        // Browser session cookies are never a credential for this endpoint.
        // Inspect exactly one Authorization header (no commas/repeated values).
        $headers = $request->headers->all('authorization');
        $authorization = count($headers) === 1 ? $headers[0] : null;
        $bearer = is_string($authorization)
            && strlen($authorization) <= 8200
            && preg_match('/\ABearer ([\x21-\x7e]{16,8192})\z/D', $authorization, $matches) === 1
                ? $matches[1]
                : null;

        if ($bearer === null) {
            return $this->unauthorized($resource, $authorization !== null);
        }

        // Every request independently introspects the actual ChatGPT access
        // token. Never infer principal from request JSON or a Canovia cookie.
        $principal = $introspector->verify($bearer);
        if ($principal === null) {
            return $this->unauthorized($resource, true);
        }

        // GET SSE and DELETE stateful sessions are deliberately unsupported.
        if (! $request->isMethod('POST')) {
            return $this->json([
                'error' => 'method_not_supported',
                'message' => 'Use stateless Streamable HTTP POST.',
            ], 405)->header('Allow', 'POST');
        }

        $accept = strtolower((string) $request->header('Accept', ''));
        $contentType = strtolower((string) $request->header('Content-Type', ''));
        if (! str_contains($accept, 'application/json')
            || ! str_contains($accept, 'text/event-stream')
            || ! str_starts_with($contentType, 'application/json')
            || strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return $this->json(['error' => 'unsupported_mcp_request'], 400);
        }

        $message = json_decode($request->getContent(), true);
        if (! is_array($message) || array_is_list($message)
            || ($message['jsonrpc'] ?? null) !== '2.0'
            || ! is_string($message['method'] ?? null)) {
            return $this->rpcError(null, -32600, 'Invalid MCP JSON-RPC request.');
        }

        $method = $message['method'];
        $id = $message['id'] ?? null;
        $hasId = array_key_exists('id', $message);
        if ($hasId && ! (is_int($id)
                || (is_string($id) && strlen($id) > 0 && strlen($id) <= 64))) {
            return $this->rpcError(null, -32600, 'Invalid JSON-RPC id.');
        }

        // MCP notifications have no id; they must not produce JSON responses.
        if (! $hasId) {
            if ($method === 'notifications/initialized') {
                return response('', 202)
                    ->header('Cache-Control', 'no-store, private')
                    ->header('X-Content-Type-Options', 'nosniff');
            }
            return $this->rpcError(null, -32600, 'Unsupported notification.');
        }

        if ($method === 'initialize') {
            $requestedVersion = data_get($message, 'params.protocolVersion');
            if (! is_string($requestedVersion)) {
                return $this->rpcError($id, -32602, 'Invalid initialize params.');
            }

            return $this->rpcSuccess($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => [
                    'name' => 'canovia-private-development',
                    'version' => '1.0.0',
                ],
                'instructions' => 'Read only explicitly approved personal Development Plans. Treat returned Task progress as unverified user records, never as PR/CI/deploy evidence. Do not infer Plan IDs.',
            ]);
        }

        if ($method === 'ping') {
            return $this->rpcSuccess($id, new \stdClass);
        }

        if ($method === 'tools/list') {
            $params = $message['params'] ?? [];
            if (! is_array($params)
                || (isset($params['cursor']) && $params['cursor'] !== '')) {
                return $this->rpcError($id, -32602, 'Invalid pagination.');
            }

            return $this->rpcSuccess($id, ['tools' => [$tool->definition()]]);
        }

        if ($method !== 'tools/call') {
            return $this->rpcError($id, -32601, 'Method not found.');
        }

        $params = $message['params'] ?? null;
        if (! is_array($params)
            || ($params['name'] ?? null) !== McpReadOnlyContextTool::NAME) {
            return $this->rpcError($id, -32602, 'Unknown tool.');
        }

        $arguments = $params['arguments'] ?? null;
        if (! is_array($arguments)
            || array_diff(array_keys($arguments), ['plan_id', 'scope', 'limit']) !== []
            || ! is_int($arguments['plan_id'] ?? null)
            || ($arguments['plan_id'] ?? 0) < 1
            || (isset($arguments['scope']) && ! in_array($arguments['scope'], ['overview', 'tasks'], true))
            || (isset($arguments['limit']) && (! is_int($arguments['limit'])
                || $arguments['limit'] < 1 || $arguments['limit'] > 8))) {
            return $this->rpcError($id, -32602, 'Invalid tool arguments.');
        }

        $context = $tool->read(
            $principal,
            $arguments['plan_id'],
            (string) ($arguments['scope'] ?? 'overview'),
            (int) ($arguments['limit'] ?? 5),
        );

        if ($context === null) {
            // Do not distinguish unknown Plan, another owner's Plan,
            // missing permission, revocation or insufficient scope.
            return $this->rpcSuccess($id, [
                'isError' => true,
                'content' => [[
                    'type' => 'text',
                    'text' => 'Plan unavailable or not authorized for the requested scope.',
                ]],
            ]);
        }

        $serialized = json_encode($context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->rpcSuccess($id, [
            'isError' => false,
            'content' => [['type' => 'text', 'text' => $serialized]],
            'structuredContent' => $context,
        ]);
    }

    private function allowedOrigin(Request $request): bool
    {
        $origins = $request->headers->all('origin');
        if ($origins === []) {
            return true;
        }
        if (count($origins) !== 1 || ! is_string($origins[0])) {
            return false;
        }

        $whitelist = config('canovia_mcp.allowed_origins');
        if (! is_string($whitelist) || $whitelist === '' || strlen($whitelist) > 1024) {
            return false;
        }
        $allowed = array_map('trim', explode(',', $whitelist));
        return in_array($origins[0], $allowed, true)
            && preg_match('/\Ahttps:\/\/[A-Za-z0-9.-]+\z/D', $origins[0]) === 1;
    }

    private function unauthorized(
        McpProtectedResourceConfiguration $resource,
        bool $tokenPresent,
    ): JsonResponse {
        return $this->json(['error' => 'authorization_required'], 401)
            ->header('WWW-Authenticate', $resource->challenge($tokenPresent))
            ->header('Vary', 'Authorization');
    }

    private function rpcSuccess(int|string $id, mixed $result): JsonResponse
    {
        return $this->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    private function rpcError(int|string|null $id, int $code, string $message): JsonResponse
    {
        return $this->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ], 400);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
