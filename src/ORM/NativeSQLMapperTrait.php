<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapper\ORM;

use Cake\Database\StatementInterface;

trait NativeSQLMapperTrait
{
    /**
     * Create a StatementQuery wrapper for a prepared statement.
     *
     * @param \Cake\Database\StatementInterface $stmt
     * @return \Bancer\NativeQueryMapper\ORM\StatementQuery
     */
    public function fromNativeQuery(StatementInterface $stmt): StatementQuery
    {
        return new StatementQuery($this, $stmt);
    }

    /**
     * @param string $stmt
     * @return \Cake\Database\StatementInterface
     */
    public function prepareSQL(string $stmt): StatementInterface
    {
        return $this->getConnection()->getDriver()->prepare($stmt);
    }
}
