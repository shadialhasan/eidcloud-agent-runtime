<?php

declare(strict_types=1);

namespace EidCloud\AgentRuntime\Tools;

use InvalidArgumentException;
use RuntimeException;

/**
 * Safe HTTP Tool for executing web requests with timeout, SSL, and domain whitelist boundaries.
 */
class HttpTool extends AbstractTool
{
    /** @var list<string> */
    private array $allowedDomains = [];

    /**
     * @param list<string> $allowedDomains Empty array means all domains permitted
     */
    public function __construct(array $allowedDomains = [])
    {
        $this->name = 'http_request';
        $this->description = 'Perform HTTP GET or POST requests with custom headers, query params, or JSON payloads.';
        $this->allowedDomains = $allowedDomains;
        $this->timeout = 15;

        $this->parameters = [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The absolute URL to request (http:// or https://)',
                ],
                'method' => [
                    'type' => 'string',
                    'description' => 'HTTP method (GET, POST, PUT, DELETE)',
                    'enum' => ['GET', 'POST', 'PUT', 'DELETE'],
                    'default' => 'GET',
                ],
                'headers' => [
                    'type' => 'object',
                    'description' => 'Key-value map of HTTP headers',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Request body (for POST, PUT)',
                ],
                'json' => [
                    'type' => 'object',
                    'description' => 'JSON payload (will automatically set Content-Type: application/json)',
                ],
            ],
            'required' => ['url'],
        ];
    }

    /**
     * Set allowed domains for this tool.
     *
     * @param list<string> $domains
     */
    public function setAllowedDomains(array $domains): self
    {
        $this->allowedDomains = $domains;
        return $this;
    }

    public function execute(array $args): array|string
    {
        $this->validateRequired($args, ['url']);
        $url = (string) $args['url'];
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        $headers = (array) ($args['headers'] ?? []);

        // Validate URL format and scheme
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'], true)) {
            throw new InvalidArgumentException("Invalid or unsupported URL scheme: '$url'");
        }

        $host = strtolower($parsed['host'] ?? '');
        if (!empty($this->allowedDomains)) {
            $allowed = false;
            foreach ($this->allowedDomains as $domain) {
                if ($host === strtolower($domain) || str_ends_with($host, '.' . strtolower($domain))) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                throw new RuntimeException("Domain '$host' is not in the allowed domains whitelist.");
            }
        }

        $body = null;
        if (isset($args['json'])) {
            $body = json_encode($args['json'], JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = 'application/json';
        } elseif (isset($args['body'])) {
            $body = (string) $args['body'];
        }

        return $this->dispatchRequest($url, $method, $headers, $body);
    }

    /**
     * Dispatch HTTP request using cURL or fallback stream context.
     *
     * @param array<string, string> $headers
     */
    private function dispatchRequest(string $url, string $method, array $headers, ?string $body): array
    {
        if (extension_loaded('curl')) {
            return $this->dispatchCurl($url, $method, $headers, $body);
        }

        return $this->dispatchStream($url, $method, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     */
    private function dispatchCurl(string $url, string $method, array $headers, ?string $body): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, 'EidCloud-Agent-Runtime/1.0.0 (PHP 8.2+)');

        $headerList = [];
        foreach ($headers as $k => $v) {
            $headerList[] = "$k: $v";
        }
        if (!empty($headerList)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerList);
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException("HTTP Request failed: " . ($error ?: "Unknown error"));
        }

        $jsonDecoded = json_decode((string) $responseBody, true);

        return [
            'status' => $statusCode,
            'url' => $url,
            'method' => $method,
            'body' => $jsonDecoded ?? (string) $responseBody,
            'is_json' => $jsonDecoded !== null,
        ];
    }

    /**
     * Fallback HTTP dispatcher using PHP streams.
     *
     * @param array<string, string> $headers
     */
    private function dispatchStream(string $url, string $method, array $headers, ?string $body): array
    {
        $headerLines = ["User-Agent: EidCloud-Agent-Runtime/1.0.0 (PHP 8.2+)"];
        foreach ($headers as $k => $v) {
            $headerLines[] = "$k: $v";
        }

        $options = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ];

        if ($body !== null) {
            $options['http']['content'] = $body;
        }

        $context = stream_context_create($options);
        $responseBody = @file_get_contents($url, false, $context);

        if ($responseBody === false) {
            $err = error_get_last();
            throw new RuntimeException("HTTP Request failed: " . ($err['message'] ?? 'Unknown network error'));
        }

        $statusCode = 200;
        if (isset($http_response_header[0]) && preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $m)) {
            $statusCode = (int) $m[1];
        }

        $jsonDecoded = json_decode($responseBody, true);

        return [
            'status' => $statusCode,
            'url' => $url,
            'method' => $method,
            'body' => $jsonDecoded ?? $responseBody,
            'is_json' => $jsonDecoded !== null,
        ];
    }
}
