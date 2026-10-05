<?php
/**
 * Read-only view over config/app.php with dotted-key access.
 * Typed getters fail loudly on a missing or mistyped key: a silently wrong
 * timeout or path is worse than a crash at boot.
 */
declare(strict_types=1);

namespace ManorLedger\Support;

use InvalidArgumentException;
use RuntimeException;

final class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new RuntimeException("Config file not found: {$file}");
        }
        $values = require $file;
        if (!is_array($values)) {
            throw new RuntimeException("Config file must return an array: {$file}");
        }
        return new self($values);
    }

    /** @return array<string, mixed> the whole tree, for code that takes the plain array */
    public function all(): array
    {
        return $this->values;
    }

    public function has(string $key): bool
    {
        return $this->lookup($key) !== null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->lookup($key) ?? $default;
    }

    public function string(string $key): string
    {
        $value = $this->require($key);
        if (!is_string($value)) {
            throw new InvalidArgumentException("Config key {$key} is not a string");
        }
        return $value;
    }

    public function int(string $key): int
    {
        $value = $this->require($key);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int)$value;
        }
        throw new InvalidArgumentException("Config key {$key} is not an integer");
    }

    public function bool(string $key): bool
    {
        $value = $this->require($key);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_string($value)) {
            return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
        }
        throw new InvalidArgumentException("Config key {$key} is not a boolean");
    }

    /** @return array<mixed> */
    public function array(string $key): array
    {
        $value = $this->require($key);
        if (!is_array($value)) {
            throw new InvalidArgumentException("Config key {$key} is not an array");
        }
        return $value;
    }

    private function require(string $key): mixed
    {
        $value = $this->lookup($key);
        if ($value === null) {
            throw new InvalidArgumentException("Missing config key: {$key}");
        }
        return $value;
    }

    private function lookup(string $key): mixed
    {
        $node = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }
        return $node;
    }
}
