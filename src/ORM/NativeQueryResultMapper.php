<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapper\ORM;

use Cake\Database\StatementInterface;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;

/**
 * Wrapper around a prepared SQL statement that executes it
 * and hydrates the result set into CakePHP entities using
 * a mapping strategy inferred from column aliases.
 *
 * This class is created via `prepareNativeStatement()` and
 * `mapNativeStatement()` in the `NativeSQLMapperTrait`.
 */
class NativeQueryResultMapper
{
    /**
     * The root table used to determine entity classes,
     * associations, and hydration rules.
     *
     * @var \Cake\ORM\Table
     */
    protected Table $rootTable;

    /**
     * The prepared PDO statement to be executed.
     *
     * @var \Cake\Database\StatementInterface
     */
    protected StatementInterface $stmt;

    /**
     * Whether the statement has already been executed.
     *
     * @var bool
     */
    protected bool $isExecuted;

    /**
     * Custom mapping strategy used to hydrate entities.
     * If null, a MappingStrategy will be automatically built
     * based on detected column aliases.
     *
     * @var array<string,mixed>|null
     */
    protected $mapStrategy = null;

    /**
     * Constructor.
     *
     * @param \Cake\ORM\Table $rootTable The root table instance.
     * @param \Cake\Database\StatementInterface $stmt The prepared statement.
     */
    public function __construct(Table $rootTable, StatementInterface $stmt)
    {
        $this->rootTable = $rootTable;
        $this->stmt = $stmt;
        $this->isExecuted = false;
    }

    /**
     * Provide a custom mapping strategy instead of relying
     * on automatic alias inference.
     *
     * The structure must match the output of MappingStrategy::toArray().
     *
     * @param array<string,mixed> $strategy Mapping configuration.
     * @return $this
     */
    public function setMappingStrategy(array $strategy): self
    {
        $this->mapStrategy = $strategy;
        return $this;
    }

    /**
     * Execute the SQL statement if not executed yet, fetch all rows,
     * build (or use) the mapping strategy, and hydrate the result set
     * into entities.
     *
     * @return \Cake\Datasource\EntityInterface[] Hydrated entity list.
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
        $hydrator = new RecursiveEntityHydrator($this->rootTable, $this->mapStrategy, $aliasMap);
        return $hydrator->hydrateMany($rows);
    }

    /**
     * Returns the first hydrated entity from the native query result.
     *
     * This executes the native SQL, hydrates entities using the mapping strategy,
     * and returns only the first entity (or null if no rows were returned).
     *
     * @return \Cake\Datasource\EntityInterface|null
     */
    public function first(): ?EntityInterface
    {
        $entities = $this->all();
        if ($entities === []) {
            return null;
        }
        return $entities[0];
    }

    /**
     * Extract column aliases used in the SQL result set.
     *
     * Each column must follow `{Alias}__{column}` format.
     * Throws UnknownAliasException if the alias format is invalid.
     *
     * @param array<int,array<string,mixed>|mixed> $rows Result set rows.
     * @return string[] Sorted list of unique aliases.
     *
     * @throws \InvalidArgumentException If the first row is not an array.
     * @throws \Bancer\NativeQueryMapper\ORM\UnknownAliasException
     *         If a column does not follow expected alias format.
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
