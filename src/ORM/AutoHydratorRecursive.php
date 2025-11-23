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
    protected Table $rootTable;

    /** @var array<string,string[]> */
    protected array $aliasMap = [];

    /** @var array<string,Table> */
    protected array $tableByAlias = [];

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
        $this->validateAndResolveAliases();
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

    protected function validateAndResolveAliases(): void
    {
        $rootAlias = $this->rootTable->getAlias();
        $this->tableByAlias[$rootAlias] = $this->rootTable;
        foreach ($this->aliasMap as $alias => $_fields) {
            if ($alias === $rootAlias) {
                continue;
            }
            $table = $this->resolveTableByAlias($alias);
            if ($table === null) {
                throw new UnknownAliasException(
                    "SQL alias '$alias' does not match any reachable Table from '$rootAlias'."
                );
            }
            $this->tableByAlias[$alias] = $table;
        }
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
    protected function resolveTableByAlias(string $alias): ?Table
    {
        if (isset($this->tableByAlias[$alias])) {
            return $this->tableByAlias[$alias];
        }
        $visited = [];
        $queue = [$this->rootTable];
        while ($queue) {
            /** @var Table $table */
            $table = array_shift($queue);
            $visited[$table->getAlias()] = true;
            foreach ($table->associations() as $assoc) {
                $target = $assoc->getTarget();
                $ta = $target->getAlias();
                if ($ta === $alias) {
                    return $target;
                }
                if (!isset($visited[$ta])) {
                    $queue[] = $target;
                }
                if ($assoc instanceof BelongsToMany) {
                    $junctionAlias = $assoc->getThrough();
                    if ($junctionAlias) {
                        if (is_object($junctionAlias)) {
                            $junctionAlias = $junctionAlias->getAlias();
                        }
                        if ($junctionAlias === $alias) {
                            return TableRegistry::getTableLocator()->get($junctionAlias);
                        }
                        if (!isset($visited[$junctionAlias])) {
                            $queue[] = TableRegistry::getTableLocator()->get($junctionAlias);
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
        foreach ($table->associations() as $assoc) {
            $target = $assoc->getTarget();
            $childAlias = $target->getAlias();
            if (
                !isset($this->aliasMap[$childAlias]) &&
                !(
                    $assoc instanceof BelongsToMany &&
                    isset($this->aliasMap[$assoc->junction()->getAlias()])
                )
            ) {
                continue;
            }
            if ($assoc instanceof HasMany) {
                $tree = $this->buildEntityRecursive($target, $row, $visited);
                if ($tree) {
                    $list = $entity->get($assoc->getProperty());
                    if (!is_array($list)) {
                        $list = [];
                    }
                    $list[] = $tree[$childAlias];
                    $entity->set($assoc->getProperty(), $list);
                    $out += $tree;
                }
                continue;
            }
            if ($assoc instanceof BelongsTo || $assoc instanceof HasOne) {
                $tree = $this->buildEntityRecursive($target, $row, $visited);
                if ($tree) {
                    $entity->set($assoc->getProperty(), $tree[$childAlias]);
                    $out += $tree;
                }
                continue;
            }
            if ($assoc instanceof BelongsToMany) {
                $tree = $this->buildEntityRecursive($target, $row, $visited);
                if ($tree) {
                    $child = $tree[$childAlias];
                    $junctionAlias = $assoc->getThrough();
                    if (is_object($junctionAlias)) {
                        $junctionAlias = $junctionAlias->getAlias();
                    }
                    // hydrate join data only if the row contains it
                    if ($junctionAlias !== null && isset($this->aliasMap[$junctionAlias])) {
                        $junctionTable = TableRegistry::getTableLocator()->get($junctionAlias);
                        $jTree = $this->buildEntityRecursive($junctionTable, $row, $visited);
                        if ($jTree) {
                            $child->set('_joinData', $jTree[$junctionAlias]);
                            $out += $jTree;
                        }
                    }
                    $list = $entity->get($assoc->getProperty());
                    if (!is_array($list)) {
                        $list = [];
                    }
                    $list[] = $child;
                    $entity->set($assoc->getProperty(), $list);
                    $out += $tree;
                }
                continue;
            }
            // fallback
            $tree = $this->buildEntityRecursive($target, $row, $visited);
            if ($tree) {
                $entity->set($assoc->getProperty(), $tree[$childAlias]);
                $out += $tree;
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
