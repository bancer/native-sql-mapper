<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapper\ORM;

use Cake\ORM\Table;
use Cake\Database\Connection;
use Cake\Database\FieldTypeConverter;
use Cake\Database\TypeFactory;
use Cake\Database\TypeInterface;
use Cake\Database\TypeMap;
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
     * Resolved column type objects indexed by alias and column name.
     *
     * Structure:
     * [
     *     '{alias}' => [
     *         '{column}' => \Cake\Database\TypeInterface|null
     *     ]
     * ]
     *
     * A null value indicates that the column exists but has no resolvable type.
     *
     * @var array<string, array<string, \Cake\Database\TypeInterface|null>>
     */
    protected array $columnTypes = [];

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
     * Constructs an entity instance from raw database fields.
     *
     * This method:
     *  - Skips hydration when all fields are NULL (LEFT JOIN safety)
     *  - Enforces presence of primary keys when required by mapping strategy
     *  - Converts database values to PHP values using table schema types
     *  - Instantiates the entity in a "persisted & clean" state
     *
     * @param class-string<\Cake\Datasource\EntityInterface> $className Entity class.
     * @param mixed[] $fields Raw database fields (alias stripped).
     * @param string $alias Alias of the entity.
     * @param string[]|string|null $primaryKey Primary key column name(s), if required.
     * @throws \RuntimeException When primary keys are required but not configured.
     * @throws \Bancer\NativeQueryMapper\ORM\MissingColumnException When required primary key columns are missing
     *      from the result set.
     * @return \Cake\Datasource\EntityInterface|null Fully hydrated entity,
     *      or null when the row contains only NULL values.
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
        if (isset($this->aliasMap[$alias])) {
            /** @var \Cake\ORM\Table $Table */
            $Table = $this->aliasMap[$alias];
            $converted = $this->convertDatabaseTypesToPHP($alias, $fields);
            $options += [
                'source' => $Table->getRegistryAlias(),
            ];
            return new $className($converted, $options);
        }
        return new $className($fields, $options);
    }

    /**
     * Converts raw database values to PHP values using the table schema.
     *
     * Each column is converted using its corresponding database type.
     *
     * Column types are resolved lazily and cached per alias to avoid repeated
     * schema lookups and type instantiation.
     *
     * @param string $alias Query alias identifying the table schema.
     * @param mixed[] $fields Raw database field values indexed by column name.
     * @return mixed[] Converted field values suitable for entity construction.
     */
    protected function convertDatabaseTypesToPHP(string $alias, array $fields): array
    {
        /** @var \Cake\ORM\Table $Table */
        $Table = $this->aliasMap[$alias];
        $driver = $Table->getConnection()->getDriver();
        $converted = [];
        foreach ($fields as $field => $value) {
            if ($value === null) {
                $converted[$field] = $value;
                continue;
            }
            $type = $this->getColumnType($alias, $field);
            if ($type !== null) {
                $converted[$field] = $type->toPHP($value, $driver);
            } else {
                $converted[$field] = $value;
            }
        }
        return $converted;
    }

    /**
     * Resolves the database type for a given column.
     *
     * The column type is derived from the table schema associated with
     * the provided alias. The resolved type instance is cached to a class field to avoid
     * repeated schema access and object construction.
     *
     * @param string $alias Query alias used to resolve the table.
     * @param string $columnName Column name within the table.
     * @return \Cake\Database\TypeInterface|null
     *         Type instance when resolvable, or null if the column does not exist
     *         or has no associated type.
     */
    protected function getColumnType(string $alias, string $columnName): ?TypeInterface
    {
        if (
            !array_key_exists($alias, $this->columnTypes) ||
            !array_key_exists($columnName, $this->columnTypes[$alias])
        ) {
            $this->columnTypes[$alias][$columnName] = null;
            /** @var \Cake\ORM\Table $Table */
            $Table = $this->aliasMap[$alias];
            $schema = $Table->getSchema();
            if ($schema->hasColumn($columnName)) {
                $typeName = $schema->getColumnType($columnName);
                if ($typeName !== null) {
                    $this->columnTypes[$alias][$columnName] = TypeFactory::build($typeName);
                }
            }
        }
        return $this->columnTypes[$alias][$columnName];
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
