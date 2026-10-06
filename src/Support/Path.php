<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Support;

use InvalidArgumentException;

/** @internal Builds URL path segments from caller-supplied ids. */
final class Path
{
    /**
     * An empty id would turn `/objects/{id}` into `/objects/`, which is the
     * list endpoint, so it is rejected before any request is sent. Dot segments are
     * rejected too: HTTP clients normalise them away, changing the endpoint.
     */
    public static function segment(string $id, string $name): string
    {
        if (trim($id) === '') {
            throw new InvalidArgumentException("{$name} must not be empty");
        }

        if (trim($id) === '.' || trim($id) === '..') {
            throw new InvalidArgumentException("{$name} must not be '.' or '..'");
        }

        return '/'.rawurlencode($id);
    }
}
