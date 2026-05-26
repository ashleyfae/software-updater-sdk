<?php

namespace AshleyFae\SoftwareUpdater\DataTransferObjects;

use AshleyFae\SoftwareUpdater\Enums\LicenseStatus;
use DateTimeImmutable;

class LicenseStatusResponse
{
    /**
     * @param ActivationResponse[] $siteActivations
     */
    public function __construct(
        public string $licenseKey,
        public LicenseStatus $status,
        public ?DateTimeImmutable $expiresAt,
        public ?int $bundleId,
        public array $siteActivations,
    ) {}

    public static function fromArray(array $data): static
    {
        $activations = array_map(
            fn(array $a) => ActivationResponse::fromArray($a),
            $data['site_activations'] ?? []
        );

        return new static(
            licenseKey:      $data['license_key'],
            status:          LicenseStatus::from($data['status']),
            expiresAt:       isset($data['expires_at']) ? new DateTimeImmutable($data['expires_at']) : null,
            bundleId:        isset($data['bundle_id']) ? (int) $data['bundle_id'] : null,
            siteActivations: $activations,
        );
    }

    public function toArray(): array
    {
        return [
            'license_key'      => $this->licenseKey,
            'status'           => $this->status->getValue(),
            'expires_at'       => $this->expiresAt?->format(\DateTimeInterface::ATOM),
            'bundle_id'        => $this->bundleId,
            'site_activations' => array_map(fn(ActivationResponse $a) => $a->toArray(), $this->siteActivations),
        ];
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Returns true if the given URL is in the site activations list.
     * Applies the same normalization the server uses: extract host+path, lowercase, strip www., untrail slash.
     */
    public function isActivatedFor(string $url): bool
    {
        $normalized = $this->normalizeDomain($url);
        if ($normalized === '') {
            return false;
        }

        foreach ($this->siteActivations as $activation) {
            if ($activation->domain === $normalized) {
                return true;
            }
        }

        return false;
    }

    private function normalizeDomain(string $url): string
    {
        $parts  = parse_url($url);
        $domain = strtolower(($parts['host'] ?? '') . ($parts['path'] ?? ''));

        if ($domain === '') {
            return '';
        }

        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        return rtrim($domain, '/');
    }
}
