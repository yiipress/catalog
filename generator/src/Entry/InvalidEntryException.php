<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Entry;

use RuntimeException;
use YiiPress\Extension\Manifest\ManifestError;

use function array_map;
use function implode;

/**
 * One or more problems in catalog metadata, all reported at once.
 */
final class InvalidEntryException extends RuntimeException
{
    /**
     * @param list<ManifestError> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(implode("\n", array_map(static fn(ManifestError $error): string => $error->message, $errors)));
    }

    /**
     * @return list<ManifestError>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
