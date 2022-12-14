<?php

declare(strict_types=1);

namespace EDT\JsonApi\RequestHandling;

use EDT\JsonApi\ResourceTypes\ResourceTypeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use EDT\JsonApi\Schema\RelationshipObject;
use EDT\JsonApi\Schema\ResourceIdentifierObject;
use EDT\JsonApi\Schema\ToManyResourceLinkage;
use EDT\JsonApi\Schema\ToOneResourceLinkage;
use EDT\Wrapping\Contracts\TypeProviderInterface;
use InvalidArgumentException;
use function count;

/**
 * @template TCondition of \EDT\Querying\Contracts\PathsBasedInterface
 * @template TSorting of \EDT\Querying\Contracts\PathsBasedInterface
 *
 * @phpstan-import-type JsonApiRelationship from RelationshipObject
 * @phpstan-import-type JsonApiRelationships from RelationshipObject
 */
class PropertyValuesGenerator
{
    /**
     * @var TypeProviderInterface<TCondition, TSorting>
     */
    private TypeProviderInterface $typeProvider;

    /**
     * @var EntityFetcherInterface<TCondition, TSorting>
     */
    private EntityFetcherInterface $entityFetcher;

    /**
     * @param EntityFetcherInterface<TCondition, TSorting> $entityFetcher
     * @param TypeProviderInterface<TCondition, TSorting>  $typeProvider
     */
    public function __construct(EntityFetcherInterface $entityFetcher, TypeProviderInterface $typeProvider)
    {
        $this->typeProvider = $typeProvider;
        $this->entityFetcher = $entityFetcher;
    }

    /**
     * Converts the attributes and relationships from the JSON:API request format into
     * a single list, mapping the property names to the actual values to set.
     *
     * @param array<non-empty-string, mixed|null> $attributes
     * @param JsonApiRelationships                $relationships
     *
     * @return array<non-empty-string, mixed>
     */
    public function generatePropertyValues(array $attributes, array $relationships): array
    {
        $relationships = array_map(
            [RelationshipObject::class, 'createWithDataRequired'],
            $relationships
        );

        $relationships = array_map(function (RelationshipObject $relationshipObject) {
            $resourceLinkage = $relationshipObject->getData();

            if ($resourceLinkage instanceof ToManyResourceLinkage && $resourceLinkage->getCardinality()->isToMany()) {
                return new ArrayCollection($this->getRelationshipEntities($resourceLinkage));
            }

            if ($resourceLinkage instanceof ToOneResourceLinkage && $resourceLinkage->getCardinality()->isToOne()) {
                return $this->getEntityForResourceLinkage($resourceLinkage);
            }

            throw new InvalidArgumentException('Resource linkage not supported');
        }, $relationships);

        return $this->preventDuplicatedFieldNames($attributes, $relationships);
    }

    /**
     * @param array<non-empty-string, mixed> $attributes
     * @param array<non-empty-string, mixed> $relationships
     *
     * @return array<non-empty-string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function preventDuplicatedFieldNames(array $attributes, array $relationships): array
    {
        $fieldKeys = array_unique(array_merge(array_keys($attributes), array_keys($relationships)));
        if (count($fieldKeys) !== count($attributes) + count($relationships)) {
            throw new InvalidArgumentException('Attribute and relationship keys must be distinct');
        }

        return array_merge($attributes, $relationships);
    }

    private function getEntityForResourceLinkage(ToOneResourceLinkage $resourceLinkage): ?object
    {
        $resourceIdentifierObject = $resourceLinkage->getResourceIdentifierObject();
        if (null === $resourceIdentifierObject) {
            return null;
        }

        return $this->getRelationshipEntity($resourceIdentifierObject);
    }

    /**
     * @return list<object>
     */
    private function getRelationshipEntities(ToManyResourceLinkage $resourceLinkage): array
    {
        return array_map(
            [$this, 'getRelationshipEntity'],
            $resourceLinkage->getResourceIdentifierObjects()
        );
    }

    private function getRelationshipEntity(ResourceIdentifierObject $resourceIdentifierObject): object
    {
        $typeIdentifier = $resourceIdentifierObject->getType();

        $type = $this->typeProvider->requestType($typeIdentifier)
            ->instanceOf(ResourceTypeInterface::class)
            ->exposedAsRelationship()
            ->getInstanceOrThrow();
        $id = $resourceIdentifierObject->getId();

        return $this->entityFetcher->getEntityByTypeIdentifier($type, $id);
    }
}
