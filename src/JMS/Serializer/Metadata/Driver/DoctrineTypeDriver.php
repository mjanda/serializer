<?php

/*
 * Copyright 2011 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\Serializer\Metadata\Driver;

use Doctrine\Common\Persistence\ManagerRegistry;
use Metadata\Driver\DriverInterface;

/**
 * This class decorates any other driver. If the inner driver does not provide a
 * a property type, the decorator will guess based on Doctrine 2 metadata.
 */
class DoctrineTypeDriver implements DriverInterface
{
    /**
     * Map of doctrine 2 field types to JMS\Serializer types
     * @var array
     */
    protected $fieldMapping = array(
        'string'       => 'string',
        'text'         => 'string',
        'blob'         => 'string',

        'integer'      => 'integer',
        'smallint'     => 'integer',
        'bigint'       => 'integer',

        'datetime'     => 'DateTime',
        'datetimetz'   => 'DateTime',
        'time'         => 'DateTime',

        'float'        => 'float',
        'decimal'      => 'float',

        'boolean'      => 'boolean',

        'array'        => 'array',
        'json_array'   => 'array',
        'simple_array' => 'array<string>',
    );

    /**
     * @var DriverInterface
     */
    protected $delegate;

    /**
     * @var ManagerRegistry
     */
    protected $registry;

    public function __construct(DriverInterface $delegate, ManagerRegistry $registry)
    {
        $this->delegate = $delegate;
        $this->registry = $registry;
    }

    public function loadMetadataForClass(\ReflectionClass $class)
    {
        $classMetadata = $this->delegate->loadMetadataForClass($class);

        // Abort if the given class is not a mapped entity
        if (!$doctrineMetadata = $this->tryLoadingDoctrineMetadata($class->name)) {
            return $classMetadata;
        }

        // We base our scan on the internal driver's property list so that we
        // respect any internal white/blacklisting like in the AnnotationDriver
        foreach ($classMetadata->propertyMetadata as $propertyMetadata) {
            // If the inner driver provides a type, don't guess anymore.
            if ($propertyMetadata->type) {
                continue;
            }

            $propertyName = $propertyMetadata->name;
            if ($doctrineMetadata->hasField($propertyName) && $fieldType = $this->normalizeFieldType($doctrineMetadata->getTypeOfField($propertyName))) {
                $propertyMetadata->setType($fieldType);
            } elseif ($doctrineMetadata->hasAssociation($propertyName)) {
                $targetEntity = $doctrineMetadata->getAssociationTargetClass($propertyName);
                if ($doctrineMetadata->isSingleValuedAssociation($propertyName)) {
                    $propertyMetadata->setType($targetEntity);
                } else {
                    $propertyMetadata->setType("ArrayCollection<{$targetEntity}>");
                }
            }
        }

        return $classMetadata;
    }

    private function tryLoadingDoctrineMetadata($className) {
        if (!$manager = $this->registry->getManagerForClass($className)) {
            return null;
        }

        if ($manager->getMetadataFactory()->isTransient($className)) {
            return null;
        }

        return $manager->getClassMetadata($className);
    }

    private function normalizeFieldType($type)
    {
        if (!isset($this->fieldMapping[$type])) {
            return;
        }

        return $this->fieldMapping[$type];
    }
}
