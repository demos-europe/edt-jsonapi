<?php

declare(strict_types=1);

namespace EDT\JsonApi\ApiDocumentation;

use cebe\openapi\exceptions\TypeErrorException;
use cebe\openapi\spec\Components;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\PathItem;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema;
use cebe\openapi\spec\Tag;
use EDT\JsonApi\ResourceTypes\AbstractResourceType;
use EDT\JsonApi\ResourceTypes\ResourceTypeInterface;
use EDT\Wrapping\Contracts\TypeProviderInterface;
use EDT\Wrapping\Contracts\Types\TypeInterface;
use Throwable;
use function count;
use Psr\Log\LoggerInterface;
use ReflectionException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use UnexpectedValueException;

final class OpenAPISchemaGenerator
{
    private RouterInterface $router;

    private AttributeTypeResolver $typeResolver;

    private TypeProviderInterface $typeProvider;

    private TranslatorInterface $translator;

    private SchemaStore $schemaStore;

    private LoggerInterface $logger;

    private int $defaultPageSize;

    public function __construct(
        AttributeTypeResolver $typeResolver,
        LoggerInterface $logger,
        TypeProviderInterface $typeProvider,
        RouterInterface $router,
        SchemaStore $schemaStore,
        TranslatorInterface $translator,
        int $defaultPageSize
    ) {
        $this->typeResolver = $typeResolver;
        $this->typeProvider = $typeProvider;
        $this->router = $router;
        $this->translator = $translator;
        $this->schemaStore = $schemaStore;
        $this->logger = $logger;
        $this->defaultPageSize = $defaultPageSize;
    }

    /**
     * @throws TypeErrorException
     */
    public function getOpenAPISpecification(): OpenApi
    {
        $openApi = new OpenApi(
            [
                'openapi' => '3.0.2',
                'info'    => [
                    'title'       => $this->trans('title', []),
                    'description' => $this->trans('description'),
                    'version'     => '2.0',
                ],
                'paths'   => [],
                'tags'    => [],
            ]
        );

        $tags = array_map(
            fn (string $identifier): ?ResourceTypeInterface => $this->typeProvider->requestType($identifier)
                ->instanceOf(ResourceTypeInterface::class)
                ->getInstanceOrNull(),
            $this->typeProvider->getTypeIdentifiers()
        );
        $tags = array_filter(
            $tags,
            static fn (?ResourceTypeInterface $type): bool => null !== $type
        );

        $tags = array_map(function (ResourceTypeInterface $type): ResourceTypeInterface {
            // create schema information for all resource types

            $typeIdentifier = $type::getName();
            if (!$this->schemaStore->has($typeIdentifier)) {
                $schema = $this->createSchema($type);
                $this->schemaStore->set($typeIdentifier, $schema);
            }

            return $type;
        }, $tags);
        // remove non-directly accessible ones
        $tags = array_filter($tags, static fn (ResourceTypeInterface $type) => $type->isExposedAsPrimaryResource());
        $tags = array_map(function (ResourceTypeInterface $type) use ($openApi): Tag {
            // add routing information for directly accessible resource types
            $tag = $this->createTag($type);

            $listMethodPathItem = $this->createListMethodsPathItem($tag, $type);

            $entityMethodsPathItem = $this->createEntityMethodsPathItem($tag, $type);

            $baseUrl = $this->router->generate(
                'api_resource_list',
                ['resourceType' => $type::getName()]
            );

            $openApi->paths[$baseUrl] = $listMethodPathItem;

            $openApi->paths[$baseUrl.'/{resourceId}/'] = $entityMethodsPathItem;

            return $tag;
        }, $tags);

        $openApi->components = new Components(['schemas' => $this->schemaStore->all()]);
        $openApi->tags = array_values($tags);

        return $openApi;
    }

    /**
     * @throws TypeErrorException
     */
    private function createTag(ResourceTypeInterface $type): Tag
    {
        return new Tag(
            [
                'name'         => $this->trans(
                    'resource.section',
                    ['type' => $type::getName()]
                ),
            ]
        );
    }

    /**
     * @throws TypeErrorException
     */
    private function addListOperation(
        Tag $tag,
        ResourceTypeInterface $resource,
        PathItem $pathItem
    ): void {
        $okResponse = new Response(
            [
                'content' => [
                    'application/vnd.api+json' => [
                        'schema' => $this->wrapAsJsonApiResponseSchema(
                            $resource,
                            [
                                'type'  => 'array',
                                'items' => [
                                    '$ref' => $this->schemaStore->getSchemaReference($resource::getName()),
                                ],
                            ],
                            [],
                            true
                        ),
                    ],
                ],
            ]
        );

        $pathItem->get = new Operation(
            [
                'description' => $this->trans(
                    'method.list.description',
                    ['type' => $resource::getName()]
                ),
                'parameters'  => array_merge(
                    $this->getDefaultQueryParameters(),
                    $this->getPaginationParameters(),
                    $this->getFilterParameter()
                ),
                'responses'   => [
                    SymfonyResponse::HTTP_OK => $okResponse,
                ],
                'tags'        => [$tag->name],
            ]
        );
    }

    /**
     * @throws TypeErrorException
     */
    private function addGetOperation(
        Tag $tag,
        ResourceTypeInterface $resource,
        PathItem $pathItem
    ): void {
        $okResponse = new Response(
            [
                'content' => [
                    'application/vnd.api+json' => [
                        'schema' => $this->wrapAsJsonApiResponseSchema(
                            $resource,
                            [
                                '$ref' => $this->schemaStore->getSchemaReference($resource::getName()),
                            ],
                            [],
                            false
                        ),
                    ],
                ],
            ]
        );

        $pathItem->get = new Operation(
            [
                'description' => $this->trans(
                    'method.get.description',
                    ['type' => $resource::getName()]
                ),
                'responses'   => [
                    SymfonyResponse::HTTP_OK => $okResponse,
                ],
                'tags'        => [$tag->name],
            ]
        );
    }

    /**
     * @throws TypeErrorException
     */
    private function createEntityMethodsPathItem(Tag $tag, ResourceTypeInterface $resource): PathItem
    {
        $entityMethodsPathItem = new PathItem(
            [
                'parameters' => array_merge(
                    $this->getDefaultQueryParameters(),
                    [
                        new Parameter(
                            [
                                'in'          => 'path',
                                'name'        => 'resourceId',
                                'description' => $this->trans('resource.id'),
                            ]
                        ),
                    ]
                ),
            ]
        );

        $this->addGetOperation($tag, $resource, $entityMethodsPathItem);

        return $entityMethodsPathItem;
    }

    /**
     * @throws TypeErrorException
     */
    private function createListMethodsPathItem(Tag $tag, ResourceTypeInterface $resource): PathItem
    {
        $listMethodPathItem = new PathItem([]);
        $this->addListOperation($tag, $resource, $listMethodPathItem);

        return $listMethodPathItem;
    }

    /**
     * @throws TypeErrorException
     */
    private function createSchema(ResourceTypeInterface $type): Schema
    {
        $properties = $type->getReadableProperties();
        $properties = array_filter(
            $properties,
            fn (?TypeInterface $relationshipType): bool => null === $relationshipType
                || $relationshipType instanceof ResourceTypeInterface
        );
        $properties = array_map(static fn (?ResourceTypeInterface $type): ?string => null === $type ? null : $type::getName(), $properties);

        $properties = array_map(function (?string $typeIdentifier, string $propertyName) use ($type): array {
            // TODO: this is probably incorrect for all aliases with a path longer than 1 element
            $propertyName = $type->getAliases()[$propertyName][0] ?? $propertyName;

            return null === $typeIdentifier
                ? $this->resolveAttributeType($type, $propertyName)
                : ['$ref' => $this->schemaStore->getSchemaReference($typeIdentifier)];
        }, $properties, array_keys($properties));

        return new Schema(['type' => 'object', 'properties' => $properties]);
    }

    /**
     * @param array{type: non-empty-string, items: array<non-empty-string, non-empty-string>}|array<non-empty-string, non-empty-string> $dataObjects
     *
     * @throws TypeErrorException
     */
    private function wrapAsJsonApiResponseSchema(
        ResourceTypeInterface $resource,
        array $dataObjects,
        array $includedObjects,
        bool $isList
    ): Schema {
        $data = [
            'type'       => 'object',
            'properties' => [
                'type'       => ['type' => 'string', 'default' => $resource::getName()],
                'attributes' => $dataObjects,
            ],
        ];

        if ($isList) {
            $data = [
                'type'  => 'array',
                'items' => $data,
            ];
        }

        $selfLink = $this->router->generate(
            'api_resource_list',
            ['resourceType' => $resource::getName()]
        );

        if (!$isList) {
            $selfLink .= '{resourceId}/';
        }

        $jsonApiResponse = [
            'type'       => 'object',
            'properties' => [
                'jsonapi' => [
                    'type'       => 'object',
                    'properties' => [
                        'version' => ['type' => 'string', 'default' => '1.0'],
                    ],
                ],
                'data'    => $data,
                'meta'    => ['type' => 'object'],
                'links'   => [
                    'type'       => 'object',
                    'properties' => [
                        'self' => [
                            'type'    => 'string',
                            'default' => $selfLink,
                        ],
                    ],
                ],
            ],
        ];

        if (0 < count($includedObjects)) {
            $jsonApiResponse['properties']['included'] = [
                'type'  => 'array',
                'items' => $includedObjects,
            ];
        }

        return new Schema($jsonApiResponse);
    }

    /**
     * @param non-empty-string $propertyName
     *
     * @return array{type: string, format?: non-empty-string, description?: string}
     *
     * @throws ReflectionException
     * @throws Throwable
     */
    private function resolveAttributeType(
        ResourceTypeInterface $resource,
        string $propertyName
    ): array {
        try {
            if (!$resource instanceof AbstractResourceType) {
                throw new UnexpectedValueException("Cannot resolve attribute type of property {$resource::getName()}::$propertyName, resource type does not implement AbstractResourceType");
            }

            return $this->typeResolver->getPropertyType(
                $resource,
                $propertyName
            );
        } catch (UnexpectedValueException $e) {
            $this->logger->warning("Could not determine attribute type of resource property {$resource::getName()}::$propertyName", [$e]);

            return ['type' => 'undetermined'];
        }
    }

    /**
     * @return list<Parameter>
     *
     * @throws TypeErrorException
     */
    private function getDefaultQueryParameters(): array
    {
        $this->schemaStore->findOrCreate(
            'parameters:include',
            static fn (): Schema => new Schema(['type' => 'array'])
        );

        $this->schemaStore->findOrCreate(
            'parameters:exclude',
            static fn (): Schema => new Schema(['type' => 'array'])
        );

        return [
            new Parameter(
                [
                    'in'          => 'query',
                    'name'        => 'include',
                    'description' => $this->trans('parameter.query.include'),
                    'schema'      => [
                        '$ref' => $this->schemaStore->getSchemaReference('parameters:include'),
                    ],
                ]
            ),
            new Parameter(
                [
                    'in'          => 'query',
                    'name'        => 'exclude',
                    'description' => $this->trans('parameter.query.exclude'),
                    'schema'      => [
                        '$ref' => $this->schemaStore->getSchemaReference('parameters:exclude'),
                    ],
                ]
            ),
        ];
    }

    /**
     * @return list<Parameter>
     *
     * @throws TypeErrorException
     */
    private function getPaginationParameters(): array
    {
        $this->schemaStore->findOrCreate(
            'parameter:pagination_number',
            static fn (): Schema => new Schema([
                'type'    => 'number',
                'format'  => 'int64',
                'default' => 1,
            ])
        );

        $this->schemaStore->findOrCreate(
            'parameter:pagination_size',
            fn (): Schema => new Schema([
                'type'    => 'number',
                'format'  => 'int64',
                'default' => $this->defaultPageSize,
            ])
        );

        return [
            new Parameter(
                [
                    'in'          => 'query',
                    'name'        => 'page[number]',
                    'description' => $this->trans('parameter.query.page_number'),
                    'schema' => [
                        '$ref' => $this->schemaStore->getSchemaReference('parameter:pagination_number'),
                    ],
                ]
            ),
            new Parameter([
                'in'          => 'query',
                'name'        => 'page[size]',
                'description' => $this->trans('parameter.query.page_size'),
                'schema' => [
                    '$ref' => $this->schemaStore->getSchemaReference('parameter:pagination_size'),
                ],
            ]),
        ];
    }

    /**
     * @return list<Parameter>
     *
     * @throws TypeErrorException
     */
    private function getFilterParameter(): array
    {
        $this->schemaStore->findOrCreate(
            'parameter:filter',
            static fn (): Schema => new Schema([
                'type' => 'array',
            ])
        );

        return [
            new Parameter(
                [
                    'in'          => 'query',
                    'name'        => 'filter',
                    'description' => $this->trans('parameter.query.filter'),
                    'schema' => [
                        '$ref' => $this->schemaStore->getSchemaReference('parameter:filter'),
                    ],
                ]
            ),
        ];
    }

    /**
     * @param string               $id         #TranslationKey
     * @param array<string, mixed> $parameters
     */
    private function trans(string $id, array $parameters = []): string
    {
        return trim($this->translator->trans($id, $parameters, 'openapi', 'en'));
    }
}
