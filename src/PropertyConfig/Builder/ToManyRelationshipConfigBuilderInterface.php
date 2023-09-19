<?php

declare(strict_types=1);

namespace EDT\JsonApi\PropertyConfig\Builder;

use EDT\Querying\Contracts\PathsBasedInterface;

/**
 * @template TCondition of PathsBasedInterface
 * @template TSorting of PathsBasedInterface
 * @template TEntity of object
 * @template TRelationship of object
 *
 * @template-extends ReadablePropertyConfigBuilderInterface<TEntity, list<TRelationship>>
 * @template-extends InstantiablePropertyConfigBuilderInterface<TCondition, TEntity, list<TRelationship>>
 * @template-extends RelationshipConfigBuilderInterface<TCondition, TSorting, TRelationship>
 */
interface ToManyRelationshipConfigBuilderInterface extends
    PropertyConfigBuilderInterface,
    ReadablePropertyConfigBuilderInterface,
    InstantiablePropertyConfigBuilderInterface,
    RelationshipConfigBuilderInterface
{
    /**
     * @param list<TCondition> $entityConditions
     * @param list<TCondition> $relationshipConditions
     * @param null|callable(TEntity, list<TRelationship>): bool $updateCallback
     *
     * @return $this
     */
    public function updatable(array $entityConditions = [], array $relationshipConditions = [], callable $updateCallback = null): self;
}
