<?php

namespace AshleyFae\SoftwareUpdater\DataTransferObjects;

use DateTimeImmutable;

class ActivationResponse
{
    public function __construct(
        public string $domain,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            domain:    $data['domain'],
            createdAt: isset($data['created_at']) ? new DateTimeImmutable($data['created_at']) : null,
            updatedAt: isset($data['updated_at']) ? new DateTimeImmutable($data['updated_at']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'domain'     => $this->domain,
            'created_at' => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
