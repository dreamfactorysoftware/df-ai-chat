<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Services;

use DreamFactory\Core\AIChat\Exceptions\ChatException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * PHP mirror of the MCP daemon's DreamFactoryService.
 *
 * Makes HTTP calls to the local DreamFactory API using a session token
 * that carries the AI role's permissions. Every call goes through
 * DreamFactory's standard RBAC enforcement.
 */
class DataToolClient
{
    private Client $client;
    private string $baseApiUrl;

    /** Allowed shape for a DreamFactory service name. */
    private const SERVICE_NAME_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /**
     * Reject any $serviceName that could traverse out of the intended
     * service path or smuggle URL syntax (slash, query, fragment, scheme).
     *
     * @throws \InvalidArgumentException when the name fails the allowlist.
     */
    public static function validateServiceName(string $serviceName): void
    {
        if ($serviceName === '' || preg_match(self::SERVICE_NAME_PATTERN, $serviceName) !== 1) {
            throw new \InvalidArgumentException(
                'Invalid DreamFactory service name; must match [A-Za-z0-9_-]+'
            );
        }
    }

    public function __construct(
        private readonly string $sessionToken,
        private readonly ?string $apiKey = null,
    ) {
        $this->baseApiUrl = rtrim(
            self::resolveInternalApiUrl(),
            '/',
        );

        $this->client = new Client([
            'timeout' => 60,
            'headers' => array_filter([
                'Accept'                       => 'application/json',
                'Content-Type'                 => 'application/json',
                'X-DreamFactory-Session-Token'  => $this->sessionToken,
                'X-DreamFactory-API-Key'        => $this->apiKey,
                // Propagate the platform trace id so the data rows this chat
                // turn touches join the same trace as the prompt/usage rows.
                \DreamFactory\Core\Utility\TraceId::HEADER => \DreamFactory\Core\Utility\TraceId::get(),
            ]),
        ]);
    }

    // ────────────────────────────────────────────────────────
    // Schema discovery
    // ────────────────────────────────────────────────────────

    public function getTables(string $serviceName): array
    {
        self::validateServiceName($serviceName);
        return $this->request('GET', "/{$serviceName}/_schema");
    }

    public function getTableSchema(string $serviceName, string $tableName): array
    {
        self::validateServiceName($serviceName);
        return $this->request('GET', "/{$serviceName}/_schema/" . urlencode($tableName));
    }

    public function getTableFields(string $serviceName, string $tableName): array
    {
        self::validateServiceName($serviceName);
        return $this->request('GET', "/{$serviceName}/_schema/" . urlencode($tableName) . "/_field");
    }

    public function getTableRelationships(string $serviceName, string $tableName): array
    {
        self::validateServiceName($serviceName);
        return $this->request('GET', "/{$serviceName}/_schema/" . urlencode($tableName) . "/_related");
    }

    // ────────────────────────────────────────────────────────
    // Data operations
    // ────────────────────────────────────────────────────────

    public function getTableData(string $serviceName, string $tableName, array $options = []): array
    {
        self::validateServiceName($serviceName);
        $query = [];
        foreach (['fields', 'filter', 'limit', 'offset', 'order', 'group', 'related'] as $key) {
            if (isset($options[$key]) && $options[$key] !== '' && $options[$key] !== null) {
                $query[$key] = is_array($options[$key]) ? implode(',', $options[$key]) : $options[$key];
            }
        }
        foreach (['include_count', 'include_schema', 'count_only'] as $key) {
            if (!empty($options[$key])) {
                $query[$key] = 'true';
            }
        }
        if (!empty($options['ids'])) {
            $query['ids'] = is_array($options['ids']) ? implode(',', $options['ids']) : $options['ids'];
        }

        return $this->request('GET', "/{$serviceName}/_table/" . urlencode($tableName), ['query' => $query]);
    }

    // ────────────────────────────────────────────────────────
    // Stored procedures / functions
    // ────────────────────────────────────────────────────────

    public function getStoredProcedures(string $serviceName): array
    {
        self::validateServiceName($serviceName);
        return $this->request('GET', "/{$serviceName}/_proc");
    }

    public function callStoredProcedure(string $serviceName, string $name, ?array $params = null): array
    {
        self::validateServiceName($serviceName);
        return $this->request('POST', "/{$serviceName}/_proc/" . urlencode($name), [
            'json' => $params ?? [],
        ]);
    }

    public function getStoredFunctions(string $serviceName): array
    {
        self::validateServiceName($serviceName);
        return $this->request('GET', "/{$serviceName}/_func");
    }

    public function callStoredFunction(string $serviceName, string $name, ?array $params = null): array
    {
        self::validateServiceName($serviceName);
        return $this->request('POST', "/{$serviceName}/_func/" . urlencode($name), [
            'json' => $params ?? [],
        ]);
    }

    // ────────────────────────────────────────────────────────
    // Generic tool dispatcher
    // ────────────────────────────────────────────────────────

    /**
     * Execute a named tool with the given arguments.
     *
     * @throws ChatException when tool name is unknown
     */
    public function executeTool(string $serviceName, string $toolName, array $args): array
    {
        return match ($toolName) {
            'get_tables'             => $this->getTables($serviceName),
            'get_table_schema'       => $this->getTableSchema($serviceName, $args['tableName'] ?? ''),
            'get_table_data'         => $this->getTableData($serviceName, $args['tableName'] ?? '', $args),
            'get_table_fields'       => $this->getTableFields($serviceName, $args['tableName'] ?? ''),
            'get_table_relationships'=> $this->getTableRelationships($serviceName, $args['tableName'] ?? ''),
            'get_stored_procedures'  => $this->getStoredProcedures($serviceName),
            'call_stored_procedure'  => $this->callStoredProcedure($serviceName, $args['procedureName'] ?? '', $args['parameters'] ?? null),
            'get_stored_functions'   => $this->getStoredFunctions($serviceName),
            'call_stored_function'   => $this->callStoredFunction($serviceName, $args['functionName'] ?? '', $args['parameters'] ?? null),
            default => throw new ChatException("Unknown tool: {$toolName}"),
        };
    }

    // ────────────────────────────────────────────────────────
    // Internal HTTP helper
    // ────────────────────────────────────────────────────────

    /**
     * Resolve the base URL the tool client uses to call DreamFactory back.
     *
     * Priority:
     *   1. AI_CHAT_INTERNAL_API_URL (env / `ai-chat.internal_api_url` config)
     *      — explicit override for unusual deployments
     *   2. http://localhost/api/v2 — the same-container loopback that
     *      works in the standard docker-compose setup. The PHP-FPM
     *      container bundles nginx on :80 internally; outbound :8080 is
     *      only the host-port mapping. We can't use Laravel's url()
     *      helper because its APP_URL is typically the external hostname.
     */
    public static function resolveInternalApiUrl(): string
    {
        $configured = config('ai-chat.internal_api_url');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        return 'http://localhost/api/v2';
    }

    private function request(string $method, string $uri, array $options = []): array
    {
        try {
            $response = $this->client->request($method, $this->baseApiUrl . $uri, $options);
            $raw = $response->getBody()->getContents();
            $body = json_decode($raw, true);
            if (!is_array($body)) {
                // Some PHP 8.5 setups leak deprecation/notice HTML ahead of the
                // JSON body (e.g. PDO::MYSQL_ATTR_SSL_CA). A raw decode of the
                // polluted string yields null, which would silently turn every
                // tool result into an empty array. Recover by decoding from the
                // first JSON delimiter — the same defense the chat app applies.
                $start = strcspn($raw, '{[');
                if ($start < strlen($raw)) {
                    $body = json_decode(substr($raw, $start), true);
                }
            }
            return is_array($body) ? $body : [];
        } catch (GuzzleException $e) {
            throw new ChatException(
                'DreamFactory API error: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e,
            );
        }
    }
}
