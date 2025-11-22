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
     * @var callable|null
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
     * @param callable $strategy
     * @return $this
     */
    public function mapStrategy(callable $strategy): self
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
        if ($this->mapStrategy !== null) {
            return array_map($this->mapStrategy, $rows);
        }
        $hydrator = new AutoHydratorRecursive($this->rootTable, $rows);
        return $hydrator->hydrateMany($rows);
    }
}
