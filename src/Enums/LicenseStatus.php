<?php

namespace AshleyFae\SoftwareUpdater\Enums;

class LicenseStatus
{
    public const Active   = 'active';
    public const Expired  = 'expired';
    public const Disabled = 'disabled';

    private string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    public static function from(string $value): static
    {
        return new static($value);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function isActive(): bool
    {
        return $this->value === self::Active;
    }

    public function isExpired(): bool
    {
        return $this->value === self::Expired;
    }

    public function isDisabled(): bool
    {
        return $this->value === self::Disabled;
    }
}
