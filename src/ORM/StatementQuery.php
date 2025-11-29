<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapper\ORM;

use Cake\Database\StatementInterface;
use Cake\ORM\Table;

class StatementQuery
{
    protected Table $rootTable;
    protected StatementInterface $stmt;
    protected bool $isExecuted;

    /**
     * @var mixed[]|null
     */
    protected $mapStrategy = null;

    public function __construct(Table $rootTable, StatementInterface $stmt)
    {
        $this->rootTable = $rootTable;
        $this->stmt = $stmt;
        $this->isExecuted = false;
    }

    /**
     * Provide a custom mapping strategy.
     *
     * @param mixed[] $strategy
     * @return $this
     */
    public function mapStrategy(array $strategy): self
    {
        $this->mapStrategy = $strategy;
        return $this;
    }

    /**
     * Execute and hydrate results.
     *
     * @return \Cake\Datasource\EntityInterface[]
     */
    public function all(): array
    {
        if (!$this->isExecuted) {
            $this->stmt->execute();
            $this->isExecuted = true;
        }
        $rows = $this->stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }
        $aliasMap = [];
        if ($this->mapStrategy === null) {
            $aliases = $this->extractAliases($rows);
            $strategy = new MappingStrategy($this->rootTable, $aliases);
            $this->mapStrategy = $strategy->build()->toArray();
            $aliasMap = $strategy->getAliasMap();
        }
        $hydrator = new AutoHydratorRecursive($this->rootTable, $this->mapStrategy, $aliasMap);
        return $hydrator->hydrateMany($rows);
    }

    /**
     * Extracts aliases of the columns from the query's result set.
     *
     * @param mixed[] $rows Result set rows.
     * @return string[]
     */
    protected function extractAliases(array $rows): array
    {
        $firstRow = $rows[0] ?? [];
        if (!is_array($firstRow)) {
            throw new \InvalidArgumentException('First element of the result set is not an array');
        }
        $keys = array_keys($firstRow);
        $aliases = [];
        foreach ($keys as $key) {
            if (!is_string($key) || !str_contains($key, '__')) {
                throw new UnknownAliasException("Column '$key' must use an alias in the format {Alias}__$key");
            }
            [$alias, $field] = explode('__', $key, 2);
            if (mb_strlen($alias) <= 0 || mb_strlen($field) <= 0) {
                $message = "Alias '$key' is invalid. Column alias must use {Alias}__{column_name} format";
                throw new UnknownAliasException($message);
            }
            $aliases[] = $alias;
        }
        sort($aliases);
        return $aliases;
    }
}
