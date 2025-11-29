<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapper\ORM;

use Cake\ORM\Table;
use Cake\Datasource\EntityInterface;
use Cake\Utility\Hash;
use RuntimeException;

class AutoHydratorRecursive
{
    protected Table $rootTable;

    /**
     * @var string[]
     */
    protected array $associationTypes = [
        MappingStrategy::HAS_ONE,
        MappingStrategy::BELONGS_TO,
        MappingStrategy::HAS_MANY,
        MappingStrategy::BELONGS_TO_MANY,
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
     * If mapping strategy contains hasMany or belongsToMany association then all mapped models must have primary keys.
     *
     * @var boolean
     */
    protected bool $isPrimaryKeyRequired;

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
         *      primaryKey?: string[]|string,
         *      hasOne?: array<string, mixed[]>,
         *      belongsTo?: array<string, mixed[]>,
         *      hasMany?: array<string, mixed[]>,
         *      belongsToMany?: array<string, mixed[]>
         *  } $node */
        foreach ($mappingStrategy as $alias => $node) {
            if (!isset($node['className'])) {
                throw new RuntimeException("Unknown entity class name for alias $alias");
            }
            $className = $node['className'];
            if ($parent === null) {
                // root entity
                $hash = $this->computeFieldsHash($row[$alias]);
                if (!isset($this->entitiesMap[$alias][$hash])) {
                    // create new entity
                    $entity = $this->constructEntity($className, $row[$alias], $alias, $node['primaryKey'] ?? null);
                    if ($entity === null) {
                        throw new RuntimeException('Failed to construct root entity');
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
                    throw new RuntimeException("Unknown property name for alias $alias");
                }
                if (in_array($parentAssociation, [MappingStrategy::HAS_ONE, MappingStrategy::BELONGS_TO])) {
                    if (!$parent->has($node['propertyName'])) {
                        // create new entity
                        $entity = $this->constructEntity($className, $row[$alias], $alias, $node['primaryKey'] ?? null);
                        $parent->set($node['propertyName'], $entity);
                        $parent->clean();
                    } else {
                        // edit already mapped entity
                        $entity = $parent->get($node['propertyName']);
                    }
                }
                if (in_array($parentAssociation, [MappingStrategy::HAS_MANY, MappingStrategy::BELONGS_TO_MANY])) {
                    $siblings = $parent->get($node['propertyName']);
                    if (!is_array($siblings)) {
                        $siblings = [];
                    }
                    $parentHash = spl_object_hash($parent);
                    $hash = $this->computeFieldsHash($row[$alias], $parentHash);
                    if (!isset($this->entitiesMap[$alias][$hash])) {
                        // create new entity
                        $entity = $this->constructEntity($className, $row[$alias], $alias, $node['primaryKey'] ?? null);
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
                            throw new RuntimeException($message);
                        }
                        if (!isset($entity) || !($entity instanceof EntityInterface)) {
                            throw new RuntimeException('Parent entity must be an instance of EntityInterface');
                        }
                        $this->map($node[$associationType], $row, $entity, $associationType);
                    }
                }
            }
        }
    }

    /**
     * @param class-string<\Cake\Datasource\EntityInterface> $className Entity class name.
     * @param mixed[] $fields Entity fields with values.
     * @param string $alias Entity alias.
     * @param string[]|string|null $primaryKey The name(s) of the primary key column(s).
     * @return \Cake\Datasource\EntityInterface|null
     */
    protected function constructEntity(
        string $className,
        array $fields,
        string $alias,
        $primaryKey
    ): ?EntityInterface {
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
        if ($this->isPrimaryKeyRequired()) {
            if ($primaryKey === null) {
                $message = "Mapping factory must have 'primaryKey' value for each of the mapped models";
                $message .= " in order to be able to map 'hasMany' and 'belongsToMany' associations.";
                throw new RuntimeException($message);
            }
            if (is_string($primaryKey)) {
                $primaryKey = [$primaryKey];
            }
            foreach ($primaryKey as $name) {
                if (!isset($fields[$name])) {
                    $primaryKeyString = implode("', '{$alias}__", $primaryKey);
                    $message = "'{$alias}__{$primaryKeyString}' column must be present in the query's SELECT clause";
                    throw new MissingColumnException($message);
                }
            }
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

    /**
     * Checks whether the mapping strategy requires all primary keys to be present.
     * If mapping strategy contains hasMany or belongsToMany association then all mapped models must have primary keys.
     *
     * @return bool
     */
    protected function isPrimaryKeyRequired(): bool
    {
        if (!isset($this->isPrimaryKeyRequired)) {
            $this->isPrimaryKeyRequired = false;
            $flatMap = Hash::flatten($this->mappingStrategy);
            $keys = array_keys($flatMap);
            foreach ($keys as $name) {
                if (
                    str_contains($name, MappingStrategy::HAS_MANY) ||
                    str_contains($name, MappingStrategy::BELONGS_TO_MANY)
                ) {
                    $this->isPrimaryKeyRequired = true;
                }
            }
        }
        return $this->isPrimaryKeyRequired;
    }
}
