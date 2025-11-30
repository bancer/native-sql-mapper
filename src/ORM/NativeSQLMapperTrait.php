<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapper\ORM;

use Cake\Database\StatementInterface;

/**
 * NativeSQLMapperTrait
 *
 * Provides convenience functions for working with native SQL queries in
 * CakePHP Table classes. It allows preparing raw SQL statements using
 * the table's connection driver and wrapping executed statements in a
 * NativeQueryResultMapper object, enabling automatic entity and association
 * mapping based on CakePHP-style column aliases.
 */
trait NativeSQLMapperTrait
{
    /**
     * Wrap a prepared statement in a NativeQueryResultMapper, enabling the
     * mapping of native SQL result sets into fully hydrated entities.
     *
     * Typically used after calling prepareNativeStatement() and binding
     * the statement parameters.
     *
     * Example:
     * ```php
     * $stmt = $ArticlesTable->prepareNativeStatement("
     *     SELECT id AS Articles__id FROM articles
     * ");
     * $entities = $ArticlesTable->mapNativeStatement($stmt)->all();
     * ```
     *
     * @param \Cake\Database\StatementInterface $stmt Prepared statement.
     * @return \Bancer\NativeQueryMapper\ORM\NativeQueryResultMapper Wrapper for ORM-level mapping of native results.
     */
    public function mapNativeStatement(StatementInterface $stmt): NativeQueryResultMapper
    {
        return new NativeQueryResultMapper($this, $stmt);
    }

    /**
     * Prepare a native SQL statement using the table's database
     * connection driver. This provides direct access to low-level PDO-style
     * prepared statements while still using the CakePHP connection.
     *
     * Example:
     * ```php
     * $stmt = $ArticlesTable->prepareNativeStatement("
     *     SELECT id AS Articles__id FROM articles WHERE title = :title
     * ");
     * $stmt->bindValue('title', 'Example');
     * ```
     *
     * @param string $stmt Raw SQL string to prepare.
     * @return \Cake\Database\StatementInterface Prepared statement ready for parameter binding and execution.
     */
    public function prepareNativeStatement(string $stmt): StatementInterface
    {
        return $this->getConnection()->getDriver()->prepare($stmt);
    }
}
