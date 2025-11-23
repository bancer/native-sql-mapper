<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapper\ORM;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\ORM\Entity;
use Cake\ORM\TableRegistry;
use Cake\ORM\Association\HasMany;
use Cake\ORM\Association\HasOne;
use Cake\ORM\Association\BelongsTo;
use Cake\ORM\Association\BelongsToMany;

class AutoHydratorRecursive
{
    /**
     * A list of uknown aliases.
     *
     * @var string[]
     */
    private array $unknownAliases = [];

    protected Table $rootTable;

    /** @var array<string,string[]> SQL alias => fields */
    protected array $aliasMap = [];

    /** @var array<string,Table> SQL alias => Table instance */
    protected array $tableByAlias = [];

    /**
     * Precomputed mapping strategy.
     *
     * @var array<string, mixed[]>
     */
    protected array $mappingStrategy = [];

    /**
     * @param Table $rootTable
     * @param mixed[] $rows
     */
    public function __construct(Table $rootTable, array $rows)
    {
        $this->rootTable = $rootTable;
        $first = $rows[0] ?? [];
        if (!is_array($first)) {
            throw new \InvalidArgumentException('First element of the result set is not an array');
        }
        $keys = array_keys($first);
        $this->aliasMap = $this->buildAliasMapFromRowKeys($keys);
        $allAliases = array_keys($this->aliasMap);
        $this->unknownAliases = array_combine($allAliases, $allAliases);
        $this->buildMappingStrategy();
    }

    /**
     * @param (int|string)[] $keys
     * @return string[][]
     */
    protected function buildAliasMapFromRowKeys(array $keys): array
    {
        $map = [];
        foreach ($keys as $k) {
            if (!is_string($k)) {
                throw new UnknownAliasException('SQL alias is not a string');
            }
            if (!str_contains($k, '__')) {
                continue;
            }
            [$alias, $field] = explode('__', $k, 2);
            $map[$alias][] = $field;
        }
        return $map;
    }

    /**
     * Precompute mapping strategy and resolve table aliases.
     */
    protected function buildMappingStrategy(): void
    {
        $rootAlias = $this->rootTable->getAlias();
        $this->tableByAlias[$rootAlias] = $this->rootTable;
        $this->mappingStrategy = [];
        foreach ($this->aliasMap as $alias => $_fields) {
            if ($alias === $rootAlias) {
                continue;
            }
            $table = $this->resolveTableByAliasRecursive($alias);
            if ($table === null) {
                throw new UnknownAliasException(
                    "SQL alias '$alias' does not match any reachable Table from '$rootAlias'."
                );
            }
            $this->tableByAlias[$alias] = $table;
        }
        $allAliases = array_keys($this->aliasMap);
        $aliasesToMap = array_combine($allAliases, $allAliases);
        foreach ($this->tableByAlias as $alias => $table) {
            if (isset($aliasesToMap[$alias])) {
                $this->mappingStrategy[$alias] = [];
                foreach ($table->associations() as $assoc) {
                    $type = null;
                    if ($assoc instanceof HasOne) {
                        $type = 'hasOne';
                    } elseif ($assoc instanceof BelongsTo) {
                        $type = 'belongsTo';
                    } elseif ($assoc instanceof BelongsToMany) {
                        $type = 'belongsToMany';
                    } elseif ($assoc instanceof HasMany) {
                        $type = 'hasMany';
                    }
                    if ($type === null) {
                        continue;
                    }
                    $childAlias = $assoc->getTarget()->getAlias();
                    if (!isset($aliasesToMap[$childAlias])) {
                        continue;
                    }
                    $entry = [];
                    if ($assoc instanceof BelongsToMany) {
                        $through = $assoc->getThrough();
                        if ($through === null) {
                            $through = $assoc->junction();
                        }
                        if (is_object($through)) {
                            $through = $through->getAlias();
                        }
                        $entry['through'] = $through;
                        if (isset($aliasesToMap[$through])) {
                            unset($aliasesToMap[$through]);
                        }
                    }
                    $entry['property'] = $assoc->getProperty();
                    $this->mappingStrategy[$alias][$type][$childAlias] = $entry;
                    unset($aliasesToMap[$childAlias]);
                }
            }
        }
    }

    protected function resolveTableByAlias(string $alias): ?Table
    {
        return $this->tableByAlias[$alias] ?? null;
    }

    /**
     * Resolves a table instance based on a given SQL alias.
     *
     * This method performs a breadth-first search (BFS) starting from the root table
     * to find a table that matches the provided alias. It traverses all associations
     * (HasOne, HasMany, BelongsTo, BelongsToMany) recursively and also considers
     * junction tables for BelongsToMany associations.
     *
     * For BelongsToMany associations, the junction table is only enqueued if it exists
     * and is not already visited. If the alias matches a junction table, the method
     * retrieves it from the TableLocator.
     *
     * @param string $alias The SQL alias to resolve.
     * @return \Cake\ORM\Table|null The Table instance corresponding to the alias, or null if not found.
     */
    protected function resolveTableByAliasRecursive(string $alias): ?Table
    {
        $visited = [];
        $queue = [$this->rootTable];
        while ($queue && !empty($this->unknownAliases)) {
            /** @var Table $table */
            $table = array_shift($queue);
            $visited[$table->getAlias()] = true;
            foreach ($table->associations() as $assoc) {
                $target = $assoc->getTarget();
                $ta = $target->getAlias();
                if (isset($this->unknownAliases[$ta])) {
                    unset($this->unknownAliases[$ta]);
                    if ($ta === $alias) {
                        return $target;
                    }
                }
                if (!isset($visited[$ta])) {
                    $queue[] = $target;
                }
                if ($assoc instanceof BelongsToMany) {
                    $through = $assoc->getThrough();
                    if ($through !== null) {
                        if (is_object($through)) {
                            $through = $through->getAlias();
                        }
                        if (isset($this->unknownAliases[$through])) {
                            unset($this->unknownAliases[$through]);
                            if ($through === $alias) {
                                return TableRegistry::getTableLocator()->get($through);
                            }
                        }
                        if (!isset($visited[$through])) {
                            $queue[] = TableRegistry::getTableLocator()->get($through);
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * @param mixed[][] $rows
     * @return \Cake\Datasource\EntityInterface[]
     */
    public function hydrateMany(array $rows): array
    {
        $results = [];
        $rootAlias = $this->rootTable->getAlias();
        foreach ($rows as $row) {
            $tree = $this->buildEntityRecursive($this->rootTable, $row);
            $root = $tree[$rootAlias];
            $key = $this->entityKey($root, $this->rootTable);
            if (!isset($results[$key])) {
                $results[$key] = $root;
            } else {
                $this->mergeEntityCollections($results[$key], $root);
            }
        }
        return array_values($results);
    }

    /**
     * @param Table $table
     * @param mixed[] $row
     * @param mixed[] $visited
     * @return \Cake\Datasource\EntityInterface[]
     */
    protected function buildEntityRecursive(
        Table $table,
        array $row,
        array &$visited = []
    ): array {
        $alias = $table->getAlias();
        // prevent infinite recursion
        if (isset($visited[$alias])) {
            return [];
        }
        $visited[$alias] = true;
        $out = [];
        if (!isset($this->aliasMap[$alias])) {
            unset($visited[$alias]);
            return [];
        }
        $data = [];
        foreach ($this->aliasMap[$alias] as $field) {
            $data[$field] = $row["{$alias}__{$field}"] ?? null;
        }
        $entity = $table->newEntity(
            $data,
            [
                'associated' => [],
                'markNew' => false,
                'accessibleFields' => ['*' => true],
            ]
        );
        $out[$alias] = $entity;
        foreach ($this->mappingStrategy[$alias] ?? [] as $type => $children) {
            if (is_array($children)) {
                foreach ($children as $childAlias => $assocData) {
                    if (!isset($this->aliasMap[$childAlias])) {
                        continue;
                    }
                    $childTable = $this->tableByAlias[$childAlias];
                    $tree = $this->buildEntityRecursive($childTable, $row, $visited);
                    if (!$tree) {
                        continue;
                    }
                    $childEntity = $tree[$childAlias];
                    if ($type === 'belongsToMany') {
                        $throughAlias = null;
                        if (is_array($assocData) && isset($assocData['through'])) {
                            $throughAlias = $assocData['through'];
                        }
                        if (is_string($throughAlias) && isset($this->aliasMap[$throughAlias])) {
                            $throughTable = $this->tableByAlias[$throughAlias];
                            $jTree = $this->buildEntityRecursive($throughTable, $row, $visited);
                            if ($jTree) {
                                $childEntity->set('_joinData', [$jTree[$throughAlias]]);
                                $out += $jTree;
                            }
                        }
                    }
                    $prop = null;
                    if (is_array($assocData) && isset($assocData['property'])) {
                        $prop = $assocData['property'];
                    }
                    if ($type === 'hasMany' || $type === 'belongsToMany') {
                        if (!is_string($prop)) {
                            $prop = $childAlias;
                        }
                        $list = $entity->get($prop);
                        if (!is_array($list)) {
                            $list = [];
                        }
                        $list[] = $childEntity;
                        $entity->set($prop, $list);
                    } else {
                        if (is_string($prop)) {
                            $entity->set($prop, $childEntity);
                        }
                    }
                    $out += $tree;
                }
            }
        }
        unset($visited[$alias]);
        return $out;
    }

    protected function mergeEntityCollections(EntityInterface $into, EntityInterface $from): void
    {
        foreach ($from->toArray() as $prop => $val) {
            if (!is_array($val)) {
                continue;
            }
            $existing = $into->get($prop);
            if (!is_array($existing)) {
                $existing = [];
            }
            $merged = array_merge($existing, $val);
            $unique = [];
            $seen = [];
            foreach ($merged as $child) {
                if (!$child instanceof Entity) {
                    $unique[] = $child;
                    continue;
                }
                $alias = (string)$child->getSource();
                $table = $this->resolveTableByAlias($alias);
                if (!$table) {
                    $oid = spl_object_id($child);
                    if (!isset($seen[$oid])) {
                        $seen[$oid] = true;
                        $unique[] = $child;
                    }
                    continue;
                }
                $pk = $table->getPrimaryKey();
                if (is_array($pk)) {
                    $complexPrimaryKey = function ($p) use ($child) {
                        /** @var string|null $primaryKeyValue */
                        $primaryKeyValue = $child->get($p);
                        return (string)$primaryKeyValue;
                    };
                    $value = implode('|', array_map($complexPrimaryKey, $pk));
                } else {
                    /** @var string|null $primaryKeyValue */
                    $primaryKeyValue = $child->get($pk);
                    $value = (string)$primaryKeyValue;
                }
                if (!isset($seen[$value])) {
                    $seen[$value] = true;
                    $unique[] = $child;
                }
            }
            $into->set($prop, $unique);
        }
    }

    protected function entityKey(EntityInterface $e, Table $table): string
    {
        $pk = $table->getPrimaryKey();
        if (is_array($pk)) {
            $complexPrimaryKey = function ($p) use ($e) {
                /** @var string|null $primaryKeyValue */
                $primaryKeyValue = $e->get($p);
                return (string)($primaryKeyValue ?? '');
            };
            return implode('|', array_map($complexPrimaryKey, $pk));
        }
        /** @var string|null $primaryKeyValue */
        $primaryKeyValue = $e->get($pk);
        return (string)($primaryKeyValue ?? spl_object_id($e));
    }
}
