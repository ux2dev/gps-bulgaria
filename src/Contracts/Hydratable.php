<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Contracts;

interface Hydratable
{
    /** @param array<mixed> $data Decoded JSON object from the API. */
    public static function fromArray(array $data): static;
}
