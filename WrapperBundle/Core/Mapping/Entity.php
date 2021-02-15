<?php

namespace Kaliop\eZObjectWrapperBundle\Core\Mapping;

use Doctrine\Common\Annotations\Annotation;

/**
 * @Annotation
 * @Target("CLASS")
 */
final class Entity
{
    /**
     * @Required
     *
     * @var string
     */
    public $contentType;

    /**
     * @var string
     */
    public $repositoryClass;
}
