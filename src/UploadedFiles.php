<?php

declare(strict_types=1);

namespace Richie314\SimpleVirtualFS;

use InvalidArgumentException;

/** Helpers for PHP's multipart request file entries. */
final class UploadedFiles
{
    /**
     * Normalize a single upload or flat multiple-file field into individual PHP upload entries.
     * Pass null for an absent field.
     * Entries retain PHP's upload error codes, including UPLOAD_ERR_NO_FILE.
     *
     * @param array<string, mixed>|null $field One field from $_FILES, such as $_FILES['attachments'] ?? null.
     * @return list<array{error: int, tmp_name: string, name?: string, type?: string, size?: int, full_path?: string}>
     * @throws InvalidArgumentException If upload columns are malformed or represent nested fields.
     */
    public static function fromField(?array $field): array
    {
        if ($field === null) {
            return [];
        }
        $errors = $field['error'] ?? null;
        if (!is_array($errors)) {
            return [self::validateEntry($field)];
        }

        if (!isset($field['tmp_name'])) {
            throw new InvalidArgumentException('Expected a PHP upload temporary name column.');
        }
        foreach ($field as $values) {
            if (!is_array($values) || array_diff_key($errors, $values) || array_diff_key($values, $errors)) {
                throw new InvalidArgumentException('PHP upload columns must have matching indices.');
            }
        }

        $files = [];
        foreach (array_keys($errors) as $index) {
            $entry = [];
            foreach ($field as $column => $values) {
                $entry[$column] = $values[$index];
            }
            $files[] = self::validateEntry($entry);
        }

        return $files;
    }

    /**
     * Validate a scalar PHP upload record while preserving its original fields.
     *
     * @return array{error: int, tmp_name: string, name?: string, type?: string, size?: int, full_path?: string}
     */
    private static function validateEntry(array $entry): array
    {
        if (!is_int($entry['error'] ?? null) || !is_string($entry['tmp_name'] ?? null)
            || (array_key_exists('size', $entry) && !is_int($entry['size']))
        ) {
            throw new InvalidArgumentException('Expected a single PHP upload entry.');
        }
        foreach (['name', 'type', 'full_path'] as $key) {
            if (array_key_exists($key, $entry) && !is_string($entry[$key])) {
                throw new InvalidArgumentException('Expected scalar PHP upload fields.');
            }
        }

        return $entry;
    }
}
