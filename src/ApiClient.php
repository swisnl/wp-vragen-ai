<?php

namespace VragenAI;

class ApiClient
{
    private string $baseUrl;

    public function __construct(string $customer, private readonly string $token)
    {
        $this->baseUrl = "https://{$customer}.vragen.ai/api/v1";
    }

    /**
     * Resolve the effective credentials. Constants defined in wp-config.php
     * (VRAGENAI_CUSTOMER / VRAGENAI_TOKEN) take precedence over the stored
     * option, so production secrets can live outside the database.
     *
     * @return array{customer: string, token: string}
     */
    public static function credentials(): array
    {
        $settings = (array) get_option('vragenai_settings', []);

        return [
            'customer' => defined('VRAGENAI_CUSTOMER') ? (string) VRAGENAI_CUSTOMER : (string) ($settings['customer'] ?? ''),
            'token'    => defined('VRAGENAI_TOKEN') ? (string) VRAGENAI_TOKEN : (string) ($settings['token'] ?? ''),
        ];
    }

    public static function fromSettings(): self
    {
        $creds = self::credentials();

        return new self($creds['customer'], $creds['token']);
    }

    public function createDocument(array $attributes): array|\WP_Error
    {
        return $this->request('POST', '/documents', [
            'type'       => 'documents',
            'attributes' => $attributes,
        ]);
    }

    public function updateDocument(string $id, array $attributes): array|\WP_Error
    {
        return $this->request('PATCH', "/documents/{$id}", [
            'type'       => 'documents',
            'id'         => $id,
            'attributes' => $attributes,
        ]);
    }

    public function deleteDocument(string $id): array|\WP_Error
    {
        return $this->request('DELETE', "/documents/{$id}");
    }

    public function findByExternalReference(string $ref): array|\WP_Error
    {
        return $this->request('GET', '/documents?' . http_build_query([
            'filter' => ['external_reference' => $ref],
            'page'   => ['size' => 1, 'number' => 1],
        ]));
    }

    public function getSystems(): array|\WP_Error
    {
        return $this->request('GET', '/systems');
    }

    private function request(string $method, string $path, array $body = []): array|\WP_Error
    {
        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept'        => 'application/vnd.api+json',
                'Content-Type'  => 'application/vnd.api+json',
            ],
            'timeout' => 15,
        ];

        if ($body !== []) {
            $args['body'] = wp_json_encode(['data' => $body]);
        }

        $response = wp_remote_request($this->baseUrl . $path, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);

        if ($code >= 400) {
            return new \WP_Error('vragenai_api_error', "HTTP {$code}: {$raw}");
        }

        return empty($raw) ? [] : (json_decode($raw, true) ?? []);
    }
}
