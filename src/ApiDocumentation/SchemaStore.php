<?php

declare(strict_types=1);

namespace EDT\JsonApi\ApiDocumentation;

use function array_key_exists;
use cebe\openapi\spec\Schema;
use function Safe\sprintf;

/**
 * Store OpenAPI Schema Definitions for reuse.
 */
class SchemaStore
{
    /**
     * Schema definitions keyed by type name.
     *
     * @var array<string,Schema>
     */
    private $schemas = [];

    public function has(string $schemaName): bool
    {
        return array_key_exists($schemaName, $this->schemas);
    }

    /**
     * @param callable(): Schema $schemaBuilder
     */
    public function findOrCreate(string $schemaName, callable $schemaBuilder): Schema
    {
        if (!$this->has($schemaName)) {
            $value = $schemaBuilder();
            $this->set($schemaName, $value);

            return $value;
        }

        return $this->get($schemaName);
    }

    public function getSchemaReference(string $schemaName): string
    {
        return sprintf('#/components/schemas/%s', $schemaName);
    }

    public function get(string $schemaName): Schema
    {
        return $this->schemas[$schemaName];
    }

    public function set(string $offset, Schema $value): void
    {
        $this->schemas[$offset] = $value;
    }

    /**
     * @return array<string, Schema>
     */
    public function all(): array
    {
        return $this->schemas;
    }
}