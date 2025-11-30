<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapperTest\TestApp\Model\Table;

use Bancer\NativeQueryMapper\ORM\NativeSQLMapperTrait;
use Cake\ORM\Table;

class CommentsTable extends Table
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
        $this->belongsTo('Articles', ['className' => ArticlesTable::class]);
        $this->belongsTo('Users', ['className' => UsersTable::class]);
        $this->addBehavior('Timestamp');
    }
}
