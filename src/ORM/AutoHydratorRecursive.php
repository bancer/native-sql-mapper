<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapper\ORM;

use Cake\ORM\Table;
use Cake\Datasource\EntityInterface;

class AutoHydratorRecursive
{
    protected Table $rootTable;

    /**
     * @var string[]
     */
    protected array $associationTypes = [
        'hasOne',
        'belongsTo',
        'hasMany',
        'belongsToMany',
    ];

    /**
     * Precomputed mapping strategy.
     *
     * @var mixed[]
     */
    protected array $mappingStrategy = [];

    /**
     * [
     *    '{alias}' => [
     *        '{hash}' => {index},
     *    ],
     * ]
     *
     * @var int[][]
     */
    protected array $entitiesMap = [];

    /**
     * @var \Cake\Datasource\EntityInterface[]
     */
    protected array $entities = [];

    /**
     * @param \Cake\ORM\Table $rootTable
     * @param mixed[] $mappingStrategy Mapping strategy.
     */
    public function __construct(Table $rootTable, array $mappingStrategy)
    {
        $this->rootTable = $rootTable;
        $this->mappingStrategy = $mappingStrategy;
    }

    /**
     * @param mixed[][] $rows
     * @return \Cake\Datasource\EntityInterface[]
     */
    public function hydrateMany(array $rows): array
    {
        $parsed = $this->parse($rows);
        foreach ($parsed as $row) {
            $this->map($this->mappingStrategy, $row);
        }
        return $this->entities;
    }

    /**
     *
     * @param mixed[] $mappingStrategy
     * @param mixed[][] $row
     * @param \Cake\Datasource\EntityInterface $parent
     * @param string $parentAssociation
     */
    protected function map(
        array $mappingStrategy,
        array $row,
        ?EntityInterface $parent = null,
        ?string $parentAssociation = null
    ): void {
        /** @var array{
         *      className?: class-string<\Cake\Datasource\EntityInterface>,
         *      propertyName?: string,
         *      hasOne?: array<string, mixed[]>,
         *      belongsTo?: array<string, mixed[]>,
         *      hasMany?: array<string, mixed[]>,
         *      belongsToMany?: array<string, mixed[]>
         *  } $node */
        foreach ($mappingStrategy as $alias => $node) {
            if (!isset($node['className'])) {
                throw new \RuntimeException("Unknown entity class name for alias $alias");
            }
            $className = $node['className'];
            if ($parent === null) {
                // root entity
                $hash = $this->computeFieldsHash($row[$alias]);
                if (!isset($this->entitiesMap[$alias][$hash])) {
                    // create new entity
                    $entity = $this->constructEntity($className, $row[$alias]);
                    if ($entity === null) {
                        throw new \RuntimeException('Failed to construct root entity');
                    }
                    $this->entities[] = $entity;
                    $this->entitiesMap[$alias][$hash] = array_key_last($this->entities);
                } else {
                    // edit already mapped entity
                    $entityIndex = $this->entitiesMap[$alias][$hash];
                    $entity = $this->entities[$entityIndex];
                }
            } else {
                // child entity
                if (!isset($node['propertyName'])) {
                    throw new \RuntimeException("Unknown property name for alias $alias");
                }
                if (in_array($parentAssociation, ['hasOne', 'belongsTo'])) {
                    if (!$parent->has($node['propertyName'])) {
                        // create new entity
                        $entity = $this->constructEntity($className, $row[$alias]);
                        $parent->set($node['propertyName'], $entity);
                        $parent->clean();
                    } else {
                        // edit already mapped entity
                        $entity = $parent->get($node['propertyName']);
                    }
                }
                if (in_array($parentAssociation, ['hasMany', 'belongsToMany'])) {
                    $siblings = $parent->get($node['propertyName']);
                    if (!is_array($siblings)) {
                        $siblings = [];
                    }
                    $parentHash = spl_object_hash($parent);
                    $hash = $this->computeFieldsHash($row[$alias], $parentHash);
                    if (!isset($this->entitiesMap[$alias][$hash])) {
                        // create new entity
                        $entity = $this->constructEntity($className, $row[$alias]);
                        if ($entity !== null) {
                            $siblings[] = $entity;
                            $this->entitiesMap[$alias][$hash] = array_key_last($siblings);
                        }
                    } else {
                        // edit already mapped entity
                        $entityIndex = $this->entitiesMap[$alias][$hash];
                        $entity = $siblings[$entityIndex];
                    }
                    $parent->set($node['propertyName'], $siblings);
                    $parent->clean();
                }
            }
            if ($this->hasAssociations($node)) {
                foreach ($this->associationTypes as $associationType) {
                    if (isset($node[$associationType])) {
                        if (!is_array($node[$associationType])) {
                            $message = "Association '$associationType' is not an array in mapping strategy";
                            throw new \RuntimeException($message);
                        }
                        if (!isset($entity) || !($entity instanceof EntityInterface)) {
                            throw new \RuntimeException('Parent entity must be an instance of EntityInterface');
                        }
                        $this->map($node[$associationType], $row, $entity, $associationType);
                    }
                }
            }
        }
    }

    /**
     * @param class-string<\Cake\Datasource\EntityInterface> $className
     * @param mixed[] $fields
     * @return \Cake\Datasource\EntityInterface|null
     */
    protected function constructEntity(string $className, array $fields): ?EntityInterface
    {
        $isEmpty = true;
        foreach ($fields as $value) {
            if ($value !== null) {
                $isEmpty = false;
                continue;
            }
        }
        if ($isEmpty) {
            return null;
        }
        $options = [
            'markClean' => true,
            'markNew' => false,
        ];
        return new $className($fields, $options);
    }

    /**
     * @param mixed[] $fields
     * @param string|null $parentEntityHash
     * @return string
     */
    protected function computeFieldsHash(array $fields, ?string $parentEntityHash = null): string
    {
        $serialized = serialize($fields);
        return md5($serialized . $parentEntityHash);
    }

    /**
     * @param mixed[] $node
     * @return bool
     */
    protected function hasAssociations(array $node): bool
    {
        $keys = array_keys($node);
        return array_intersect($this->associationTypes, $keys) !== [];
    }

    /**
     * @param mixed[][] $rows
     * @return mixed[][][]
     */
    protected function parse(array $rows): array
    {
        $results = [];
        foreach ($rows as $row) {
            $models = [];
            foreach ($row as $columnName => $columnValue) {
                [$alias, $field] = explode('__', $columnName, 2);
                $models[$alias][$field] = $columnValue;
            }
            $results[] = $models;
        }
        return $results;
    }
}
