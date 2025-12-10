<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapper\Test\TestCase\Database;

use Bancer\NativeQueryMapperTest\TestApp\Model\Entity\Article;
use Bancer\NativeQueryMapperTest\TestApp\Model\Entity\User;
use Bancer\NativeQueryMapperTest\TestApp\Model\Table\ArticlesTable;
use Bancer\NativeQueryMapperTest\TestApp\Model\Table\UsersTable;
use Bancer\NativeQueryMapper\Database\InPlaceholders;
use Cake\ORM\Locator\LocatorAwareTrait;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class InPlaceholdersTest extends TestCase
{
    use LocatorAwareTrait;

    public function testConstructorEmptyPrefix(): void
    {
        static::expectException(InvalidArgumentException::class);
        static::expectExceptionMessage('IN() placeholders cannot be constructed with an empty prefix');
        new InPlaceholders('', []);
    }

    public function testConstructorEmptyValues(): void
    {
        static::expectException(InvalidArgumentException::class);
        static::expectExceptionMessage('IN() placeholders cannot be constructed with an empty value list');
        new InPlaceholders('status', []);
    }

    public function testToString(): void
    {
        $InPlaceholders = new InPlaceholders('id', [3, 5]);
        $expected = ':id_0, :id_1';
        $actual = $InPlaceholders->__toString();
        static::assertSame($expected, $actual);
    }

    public function testBindValuesToStatementInt(): void
    {
        $userIds = [2, 5];
        $inPlaceholders = new InPlaceholders('user', $userIds);
        /** @var \Bancer\NativeQueryMapperTest\TestApp\Model\Table\ArticlesTable $ArticlesTable */
        $ArticlesTable = $this->fetchTable(ArticlesTable::class);
        $stmt = $ArticlesTable->prepareNativeStatement("
            SELECT
                id     AS Articles__id,
                title  AS Articles__title
            FROM articles AS a
            WHERE a.user_id IN($inPlaceholders)
        ");
        $inPlaceholders->bindValuesToStatement($stmt);
        $actual = $ArticlesTable->mapNativeStatement($stmt)->all();
        static::assertCount(2, $actual);
        static::assertInstanceOf(Article::class, $actual[0]);
        $expected = [
            'id' => 2,
            'title' => 'Article 2',
        ];
        static::assertEquals($expected, $actual[0]->toArray());
        $cakeEntities = $ArticlesTable->find()
            ->select(['id', 'title'])
            ->where(['user_id IN' => $userIds])
            ->toArray();
        static::assertEquals($cakeEntities, $actual);
    }

    public function testBindValuesToStatementStrings(): void
    {
        $users = ['bob', 'eve'];
        $inPlaceholders = new InPlaceholders('user', $users);
        /** @var \Bancer\NativeQueryMapperTest\TestApp\Model\Table\UsersTable $UsersTable */
        $UsersTable = $this->fetchTable(UsersTable::class);
        $stmt = $UsersTable->prepareNativeStatement("
            SELECT
                id          AS Users__id,
                username    AS Users__username
            FROM users AS u
            WHERE u.username IN($inPlaceholders)
        ");
        $inPlaceholders->bindValuesToStatement($stmt);
        $actual = $UsersTable->mapNativeStatement($stmt)->all();
        static::assertCount(2, $actual);
        static::assertInstanceOf(User::class, $actual[0]);
        $expected = [
            'id' => 2,
            'username' => 'bob',
        ];
        static::assertEquals($expected, $actual[0]->toArray());
        $cakeEntities = $UsersTable->find()
            ->select(['id', 'username'])
            ->where(['username IN' => $users])
            ->toArray();
        static::assertEquals($cakeEntities, $actual);
    }
}
