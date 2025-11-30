<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapper\ORM;

use Cake\ORM\Table;
use Cake\Datasource\EntityInterface;
use Cake\Utility\Hash;
use RuntimeException;

/**
 * Recursively hydrates nested CakePHP entities from a set of rows produced
 * by a native SQL query using Cake-style `{Alias}__{field}` column naming.
 *
 * This class constructs entity graphs according to a precomputed
 * `MappingStrategy`, supports deep associations and belongsToMany relations,
 * and caches hydrated entities to avoid duplication during recursion.
 */
class RecursiveEntityHydrator
{
    /**
     * The root Table instance initiating hydration.
     *
     * @var \Cake\ORM\Table
     */
    protected Table $rootTable;

    /**
     * Supported association types in the mapping strategy.
     *
     * @var string[]
     */
    protected array $associationTypes = [
        MappingStrategy::HAS_ONE,
        MappingStrategy::BELONGS_TO,
        MappingStrategy::HAS_MANY,
        MappingStrategy::BELONGS_TO_MANY,
    ];

    /**
     * Precomputed mapping strategy produced by MappingStrategy::build()->toArray().
     *
     * @var mixed[]
     */
    protected array $mappingStrategy = [];

    /**
     * Maps each alias to a map of hashed field sets to entity index.
     *
     * Example:
     * [
     *     'Articles' => [
     *         'a1b2c3' => 0,
     *         'd4e5f6' => 1,
     *     ],
     * ]
     *
     * @var array<string, array<string, int>>
     */
    protected array $entitiesMap = [];

    /**
     * A map of aliases to their corresponding Table objects.
     *
     * @var array<string, \Cake\ORM\Table|null>
     */
    protected array $aliasMap = [];

    /**
     * List of hydrated root-level entities.
     *
     * @var \Cake\Datasource\EntityInterface[]
     */
    protected array $entities = [];

    /**
     * Whether the presence of primary keys is mandatory for all entities,
     * inferred automatically based on the mapping strategy.
     *
     * @var bool
     */
    private bool $isPrimaryKeyRequired;

    /**
     * Constructor.
     *
     * @param \Cake\ORM\Table $rootTable The root Table instance.
     * @param mixed[] $mappingStrategy Precomputed mapping strategy.
     * @param array<string,\Cake\ORM\Table> $aliasMap Map of aliases to Table objects.
     */
    public function __construct(Table $rootTable, array $mappingStrategy, array $aliasMap)
    {
        $this->rootTable = $rootTable;
        $this->mappingStrategy = $mappingStrategy;
        $this->aliasMap = $aliasMap;
    }

    /**
     * Hydrate an array of rows into a list of fully mapped entities.
     *
     * @param mixed[][] $rows Flat rows from PDO::FETCH_ASSOC.
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
     * Recursively map aliases to entities and attach them to their parent entities.
     *
     * @param mixed[] $mappingStrategy Strategy node for the current level.
     * @param mixed[][] $row Parsed row grouped by alias.
     * @param \Cake\Datasource\EntityInterface|null $parent Parent entity, if any.
     * @param string|null $parentAssociation Association type joining child to parent.
     * @return void
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
     * Create an entity from raw field data using either:
     *  - Table marshaller (preferred), or
     *  - direct entity instantiation (fallback).
     *
     * Returns null when the row for the alias is "empty" (all NULL fields).
     *
     * @param class-string<\Cake\Datasource\EntityInterface> $className Entity class.
     * @param mixed[] $fields Raw database fields.
     * @param string $alias Alias of the entity.
     * @param string[]|string|null $primaryKey Primary key name(s).
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
        if (isset($this->aliasMap[$alias])) {
            /** @var \Cake\ORM\Table $Table */
            $Table = $this->aliasMap[$alias];
            $options = [
                'validate' => false,
            ];
            $entity = $Table->marshaller()->one($fields, $options);
            $entity->clean();
            $entity->setNew(false);
            return $entity;
        }
        $options = [
            'markClean' => true,
            'markNew' => false,
        ];
        return new $className($fields, $options);
    }

    /**
     * Compute a stable hash for an entity's field set,
     * optionally including the parent entity's hash for hasMany relations.
     *
     * @param mixed[] $fields Raw database fields.
     * @param string|null $parentEntityHash The hash of the parent entity object.
     * @return string
     */
    protected function computeFieldsHash(array $fields, ?string $parentEntityHash = null): string
    {
        $serialized = serialize($fields);
        return md5($serialized . $parentEntityHash);
    }

    /**
     * Determine if the current mapping-strategy node contains associations.
     *
     * @param mixed[] $node
     * @return bool
     */
    protected function hasAssociations(array $node): bool
    {
        $keys = array_keys($node);
        return array_intersect($this->associationTypes, $keys) !== [];
    }

    /**
     * Parse rows grouped by `{Alias}__{field}` format into:
     *
     *     [
     *         ['Articles' => [...], 'Comments' => [...]],
     *         ['Articles' => [...], 'Comments' => [...]],
     *     ]
     *
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
     * Check if the strategy requires primary keys for ALL mapped entities.
     *
     * Required when using hasMany or belongsToMany associations.
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
