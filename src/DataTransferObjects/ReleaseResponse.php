<?php

namespace AshleyFae\SoftwareUpdater\DataTransferObjects;

use AshleyFae\SoftwareUpdater\Enums\LicenseStatus;
use DateTimeImmutable;

class ReleaseResponse
{
    public function __construct(
        public string $licenseKey,
        public LicenseStatus $licenseStatus,
        public ?DateTimeImmutable $expiresAt,
        public string $productSlug,
        public string $version,
        public ?string $description,
        public bool $preRelease,
        public ?array $requires,
        public bool $requirementsMet,
        public ?array $unmetRequirements,
        public ?DateTimeImmutable $updatedAt,
        public ?string $downloadUrl,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            licenseKey:        $data['license_key'],
            licenseStatus:     LicenseStatus::from($data['license_status']),
            expiresAt:         isset($data['expires_at']) ? new DateTimeImmutable($data['expires_at']) : null,
            productSlug:       $data['product_slug'],
            version:           $data['version'],
            description:       $data['description'] ?? null,
            preRelease:        (bool) ($data['pre_release'] ?? false),
            requires:          $data['requires'] ?? null,
            requirementsMet:   (bool) ($data['requirements_met'] ?? false),
            unmetRequirements: $data['unmet_requirements'] ?? null,
            updatedAt:         isset($data['updated_at']) ? new DateTimeImmutable($data['updated_at']) : null,
            downloadUrl:       $data['download_url'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'license_key'       => $this->licenseKey,
            'license_status'    => $this->licenseStatus->getValue(),
            'expires_at'        => $this->expiresAt?->format(\DateTimeInterface::ATOM),
            'product_slug'      => $this->productSlug,
            'version'           => $this->version,
            'description'       => $this->description,
            'pre_release'       => $this->preRelease,
            'requires'          => $this->requires,
            'requirements_met'  => $this->requirementsMet,
            'unmet_requirements' => $this->unmetRequirements,
            'updated_at'        => $this->updatedAt?->format(\DateTimeInterface::ATOM),
            'download_url'      => $this->downloadUrl,
        ];
    }
}
