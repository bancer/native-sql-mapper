<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapperTest\TestApp\Model\Table;

use Cake\ORM\Table;
use Bancer\NativeQueryMapper\ORM\NativeSQLMapperTrait;

class CountriesTable extends Table
{
    use NativeSQLMapperTrait;

    /**
     * {@inheritDoc}
     *
     * @see \Cake\ORM\Table::initialize()
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->hasMany('Users', ['className' => UsersTable::class]);
    }
}
