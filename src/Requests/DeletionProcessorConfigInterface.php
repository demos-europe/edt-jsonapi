<?php

declare(strict_types=1);

namespace EDT\JsonApi\Requests;

use EDT\JsonApi\OutputHandling\PropertyReadableTypeProviderInterface;
use EDT\JsonApi\OutputHandling\ResponseFactory;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Instances must provide logic and implementations needed by {@link DeletionProcessor} instances.
 */
interface DeletionProcessorConfigInterface
{
    public function getEventDispatcher(): EventDispatcherInterface;

    public function getResponseFactory(PropertyReadableTypeProviderInterface $typeProvider): ResponseFactory;
}
