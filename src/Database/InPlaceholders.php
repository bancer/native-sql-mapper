<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapper\Database;

use Cake\Database\StatementInterface;
use InvalidArgumentException;
use PDO;

/**
 * Value object representing a set of named placeholders for use in SQL IN() clauses.
 *
 * This class encapsulates:
 *  - Placeholder generation (via __toString())
 *  - Safe binding of multiple scalar values to a prepared statement
 *  - Lazy inference of the appropriate PDO parameter type
 *
 * Example:
 * ```php
 * $statuses = new InPlaceholders('status', [1, 5, 9]);
 * $sql = "SELECT email AS Users_email FROM users WHERE status_id IN ($statuses)";
 * $stmt = $this->prepareNativeStatement($sql);
 * $statuses->bindValuesToStatement($stmt);
 * ```
 *
 * Resulting SQL:
 * ```sql
 * SELECT email AS Users_email FROM users WHERE status_id status_id IN (:status_0, :status_1, :status_2)
 * ```
 */
class InPlaceholders
{
    /**
     * Placeholder name prefix (without colon).
     *
     * Example: "status" -> :status_0, :status_1, ...
     *
     * @var string
     */
    private string $prefix;

    /**
     * Scalar values to be bound to the placeholders.
     *
     * @var list<scalar>
     */
    private array $values;

    /**
     * PDO parameter type used when binding values.
     *
     * If not provided, the type is inferred from the first value.
     *
     * @var string|int|null
     */
    private $pdoType;

    /**
     *  Constructor.
     *
     * @param string $prefix Placeholder prefix (eg. "status").
     * @param list<scalar> $values Values for the IN() clause
     * @param string|int|null $pdoType PDO::PARAM_* constant or name of configured Type class.
     *      Same as `$type` parameter of \Cake\Database\StatementInterface::bindValue()
     */
    public function __construct(string $prefix, array $values, $pdoType = null)
    {
        if ($prefix === '') {
            throw new InvalidArgumentException('IN() placeholders cannot be constructed with an empty prefix');
        }
        if ($values === []) {
            throw new InvalidArgumentException('IN() placeholders cannot be constructed with an empty value list');
        }
        $this->prefix = $prefix;
        $this->values = $values;
        $this->pdoType = $pdoType;
    }

    /**
     * Bind all placeholder values to the prepared statement.
     *
     * Placeholders are bound using the pattern:
     *     :{prefix}_{index}
     *
     * @param \Cake\Database\StatementInterface $stmt Prepared statement.
     * @return void
     */
    public function bindValuesToStatement(StatementInterface $stmt): void
    {
        foreach ($this->values as $index => $value) {
            $stmt->bindValue($this->prefix . '_' . $index, $value, $this->getPdoType());
        }
    }

    /**
     * Resolve the PDO parameter type.
     *
     * If the type was not provided in the constructor, it is inferred lazily
     * from the first value in the list.
     *
     * @return string|int PDO::PARAM_* constant or name of configured Type class.
     */
    private function getPdoType()
    {
        if (!isset($this->pdoType)) {
            $this->pdoType = $this->inferPdoType();
        }
        return $this->pdoType;
    }

    /**
     * Infer the PDO parameter type from the first value.
     *
     * @return int PDO::PARAM_* constant
     */
    private function inferPdoType(): int
    {
        $first = $this->values[0];
        if (is_int($first)) {
            return PDO::PARAM_INT;
        }
        if (is_bool($first)) {
            return PDO::PARAM_BOOL;
        }
        return PDO::PARAM_STR;
    }

    /**
     * Generate the SQL placeholder list for use inside an IN() clause.
     *
     * Example output:
     * ```sql
     * :status_0, :status_1, :status_2
     * ```
     *
     * @return string
     */
    public function __toString(): string
    {
        $placeholders = [];
        foreach ($this->values as $index => $_) {
            $placeholders[] = ':' . $this->prefix . '_' . $index;
        }
        return implode(', ', $placeholders);
    }
}
