<?php

declare(strict_types=1);

namespace EDT\JsonApi\EntityAccess;

use Pagerfanta\Pagerfanta;

/**
 * @template TCondition of \EDT\Querying\Contracts\PathsBasedInterface
 * @template TSorting of \EDT\Querying\Contracts\PathsBasedInterface
 * @template TPagination of object
 * @template TEntity of object
 */
interface PaginatingEntityProviderInterface
{
    /**
     * @param list<TCondition> $conditions  the conditions to apply, the used paths are already mapped to the backing entity
     * @param list<TSorting> $sortMethods the sorting to apply, the used paths are already mapped to the backing entity
     * @param TPagination       $pagination
     *
     * @return Pagerfanta<TEntity>
     */
    public function getEntityPaginator(array $conditions, array $sortMethods, object $pagination): Pagerfanta;
}
