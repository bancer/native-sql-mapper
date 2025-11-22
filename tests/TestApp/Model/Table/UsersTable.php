<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapperTest\TestApp\Model\Table;

use Bancer\NativeQueryMapper\ORM\NativeSQLMapperTrait;
use Cake\ORM\Table;

class UsersTable extends Table
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
        $this->hasOne('Profiles', ['className' => ProfilesTable::class]);
    }
}
