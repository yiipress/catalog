<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Catalog;

use YiiPress\Catalog\Entry\InvalidEntryException;
use YiiPress\Extension\ExtensionType;
use YiiPress\Extension\Manifest\FieldReader;
use YiiPress\Extension\Manifest\ManifestError;
use YiiPress\Extension\Manifest\YamlDocumentLoader;

use function array_map;
use function array_merge;
use function array_values;
use function in_array;
use function preg_match;
use function sprintf;

/**
 * The closed list of catalog categories per extension type, from `categories.yaml`.
 */
final readonly class Categories
{
    public const string FILE = 'categories.yaml';
    private const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';
    private const int MAX_PER_TYPE = 50;

    /**
     * @param array<string, list<string>> $byType Type value => categories.
     */
    private function __construct(private array $byType) {}

    /**
     * @throws InvalidEntryException
     */
    public static function fromYaml(string $yaml): self
    {
        $fields = new FieldReader();
        $root = new YamlDocumentLoader()->load($yaml, $fields);
        $types = array_map(static fn(ExtensionType $type): string => $type->value, ExtensionType::cases());
        $byType = [];
        if ($root !== null) {
            $fields->rejectUnknownKeys($root, $types, '');
            foreach ($types as $type) {
                $items = $fields->list($root, $type, $type, 0, self::MAX_PER_TYPE, required: false) ?? [];
                $byType[$type] = self::names($fields->strings($items, $type), $type, $fields);
            }
        }
        if ($root === null || $fields->hasErrors()) {
            throw new InvalidEntryException(array_map(
                static fn(ManifestError $error): ManifestError => new ManifestError(
                    $error->code,
                    $error->field,
                    self::FILE . ': ' . $error->message,
                ),
                $fields->errors(),
            ));
        }

        return new self($byType);
    }

    /**
     * @return list<string>
     */
    public function for(ExtensionType $type): array
    {
        return $this->byType[$type->value] ?? [];
    }

    public function isKnown(string $category): bool
    {
        return in_array($category, array_merge(...array_values($this->byType)), true);
    }

    /**
     * @param array<int, string> $items
     * @return list<string>
     */
    private static function names(array $items, string $type, FieldReader $fields): array
    {
        $names = [];
        foreach ($items as $index => $item) {
            if (preg_match(self::PATTERN, $item) !== 1) {
                $fields->error('field_invalid', FieldReader::path($type, $index), sprintf(
                    '"%s" must be lowercase letters and digits, words joined by "-"',
                    $item,
                ));
                continue;
            }
            $names[$index] = $item;
        }
        $fields->unique($names, $type);

        return array_values($names);
    }
}
