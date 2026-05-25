<?php

namespace AshleyFae\SoftwareUpdater\Http;

use AshleyFae\SoftwareUpdater\DataTransferObjects\ActivationResponse;
use AshleyFae\SoftwareUpdater\DataTransferObjects\LicenseStatusResponse;
use AshleyFae\SoftwareUpdater\DataTransferObjects\ReleaseResponse;

class ApiClient
{
    private const BASE_URL = 'https://software.nosegraze.com/api/';
    private const TIMEOUT  = 15;

    public function activate(string $licenseKey, string $productId): ?ActivationResponse
    {
        $data = $this->post("licenses/{$licenseKey}/activations", [
            'product_id' => $productId,
            'url'        => home_url(),
        ]);

        return $data !== null ? ActivationResponse::fromArray($data) : null;
    }

    public function deactivate(string $licenseKey): void
    {
        $this->delete("licenses/{$licenseKey}/activations", [
            'url' => home_url(),
        ]);
    }

    /**
     * @param  string[]  $licenseKeys
     * @return array<string, LicenseStatusResponse|null>
     */
    public function bulkStatus(array $licenseKeys): array
    {
        $data = $this->post('licenses/status', ['license_keys' => $licenseKeys]);

        if ($data === null) {
            return [];
        }

        $result = [];
        foreach ($data as $key => $item) {
            $result[$key] = $item !== null ? LicenseStatusResponse::fromArray($item) : null;
        }

        return $result;
    }

    /**
     * @param  string[]  $licenseKeys
     * @return array<string, ReleaseResponse|null>
     */
    public function latestReleases(array $licenseKeys): array
    {
        $data = $this->get('products/releases/latest', [
            'license_keys' => $licenseKeys,
            'php_version'  => PHP_VERSION,
            'wp_version'   => get_bloginfo('version'),
        ]);

        if ($data === null) {
            return [];
        }

        $result = [];
        foreach ($data as $key => $item) {
            $result[$key] = $item !== null ? ReleaseResponse::fromArray($item) : null;
        }

        return $result;
    }

    private function post(string $endpoint, array $body): ?array
    {
        $response = wp_remote_post($this->url($endpoint), [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        return $this->parseResponse($response);
    }

    private function delete(string $endpoint, array $body): void
    {
        wp_remote_request($this->url($endpoint), [
            'method'  => 'DELETE',
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);
    }

    private function get(string $endpoint, array $params = []): ?array
    {
        $url = $this->url($endpoint);
        if (! empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);

        return $this->parseResponse($response);
    }

    private function parseResponse(mixed $response): ?array
    {
        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300 || empty($body)) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function url(string $endpoint): string
    {
        return self::BASE_URL . ltrim($endpoint, '/');
    }
}
