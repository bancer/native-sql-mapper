<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapperTest\TestCase;

use PHPUnit\Framework\TestCase;
use Bancer\NativeQueryMapperTest\TestApp\Model\Entity\Article;
use Bancer\NativeQueryMapperTest\TestApp\Model\Entity\Comment;
use Bancer\NativeQueryMapperTest\TestApp\Model\Entity\Profile;
use Bancer\NativeQueryMapperTest\TestApp\Model\Entity\Tag;
use Bancer\NativeQueryMapperTest\TestApp\Model\Entity\User;
use Bancer\NativeQueryMapperTest\TestApp\Model\Table\ArticlesTable;
use Bancer\NativeQueryMapperTest\TestApp\Model\Table\CommentsTable;
use Cake\ORM\Locator\LocatorAwareTrait;
use Bancer\NativeQueryMapper\ORM\UnknownAliasException;
use Bancer\NativeQueryMapperTest\TestApp\Model\Table\UsersTable;

class NativeQueryMapperTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @param \Cake\Datasource\EntityInterface[] $expected
     * @param \Cake\Datasource\EntityInterface[] $actual
     */
    private function assertEqualsEntities(array $expected, array $actual): void
    {
        $expectedCount = count($expected);
        $actualCount = count($actual);
        static::assertSame($expectedCount, $actualCount);
        for ($i = 0; $i < $actualCount; $i++) {
            $message = "Entities at index $i are not equal";
            //static::assertEquals($expected[$i], $actual[$i], $message);
            static::assertEquals($expected[$i]->toArray(), $actual[$i]->toArray(), $message);
        }
    }

    public function testInvalidAlias(): void
    {
        $this->expectException(UnknownAliasException::class);
        $this->expectExceptionMessage("The query must use root table alias 'Articles'");
        /** @var \Bancer\NativeQueryMapperTest\TestApp\Model\Table\ArticlesTable $ArticlesTable */
        $ArticlesTable = $this->fetchTable(ArticlesTable::class);
        $stmt = $ArticlesTable->prepareSQL("
            SELECT
                a.id AS a__id,
                a.title AS a__title
            FROM articles AS a
        ");
        $ArticlesTable->fromNativeQuery($stmt)->all();
    }

    public function testEmptyResultSet(): void
    {
        /** @var \Bancer\NativeQueryMapperTest\TestApp\Model\Table\ArticlesTable $ArticlesTable */
        $ArticlesTable = $this->fetchTable(ArticlesTable::class);
        $stmt = $ArticlesTable->prepareSQL("
            SELECT
                Articles.id AS Articles__id,
                Articles.title AS Articles__title
            FROM articles AS Articles
            WHERE Articles.title = :title
        ");
        $stmt->bindValue('title', 'Non-existing-title');
        $actual = $ArticlesTable->fromNativeQuery($stmt)->all();
        static::assertSame([], $actual);
    }

    public function testSimplestSelect(): void
    {
        /** @var \Bancer\NativeQueryMapperTest\TestApp\Model\Table\ArticlesTable $ArticlesTable */
        $ArticlesTable = $this->fetchTable(ArticlesTable::class);
        $stmt = $ArticlesTable->prepareSQL("
            SELECT
                Articles.id AS Articles__id,
                Articles.title AS Articles__title
            FROM articles AS Articles
        ");
        $actual = $ArticlesTable->fromNativeQuery($stmt)->all();
        static::assertCount(5, $actual);
        static::assertInstanceOf(Article::class, $actual[0]);
        $expected = [
            'id' => 1,
            'title' => 'Article 1',
        ];
        static::assertSame($expected, $actual[0]->toArray());
        $cakeEntities = $ArticlesTable->find()
            ->select(['id', 'title'])
            ->toArray();
        $this->assertEqualsEntities($cakeEntities, $actual);
        //static::assertEquals($cakeEntities, $actual);
    }

    public function testSelectHasMany(): void
    {
        /** @var \Bancer\NativeQueryMapperTest\TestApp\Model\Table\ArticlesTable $ArticlesTable */
        $ArticlesTable = $this->fetchTable(ArticlesTable::class);
        $stmt = $ArticlesTable->prepareSQL("
            SELECT
                Articles.id AS Articles__id,
                Articles.title AS Articles__title,
                Comments.id AS Comments__id,
                Comments.article_id AS Comments__article_id,
                Comments.content AS Comments__content
            FROM articles AS Articles
            LEFT JOIN comments AS Comments
                ON Articles.id=Comments.article_id
        ");
        $actual = $ArticlesTable->fromNativeQuery($stmt)->all();
        static::assertCount(5, $actual);
        static::assertInstanceOf(Article::class, $actual[0]);
        $actualComments = $actual[0]->get('comments');
        static::assertIsArray($actualComments);
        static::assertCount(2, $actualComments);
        static::assertInstanceOf(Comment::class, $actualComments[0]);
        $expected = [
            'id' => 1,
            'title' => 'Article 1',
            'comments' => [
                [
                    'id' => 1,
                    'article_id' => 1,
                    'content' => 'Comment 1',
                ],
                [
                    'id' => 2,
                    'article_id' => 1,
                    'content' => 'Comment 2',
                ],
            ],
        ];
        static::assertSame($expected, $actual[0]->toArray());
        $cakeEntities = $ArticlesTable->find()
            ->select(['Articles.id', 'Articles.title'])
            ->contain([
                'Comments' => [
                    'fields' => ['Comments.id', 'Comments.article_id', 'Comments.content'],
                ],
            ])
            ->toArray();
        $this->assertEqualsEntities($cakeEntities, $actual);
        //static::assertEquals($cakeEntities, $actual);
    }

    public function testSelectBelongsTo(): void
    {
        /** @var \Bancer\NativeQueryMapperTest\TestApp\Model\Table\CommentsTable $CommentsTable */
        $CommentsTable = $this->fetchTable(CommentsTable::class);
        $stmt = $CommentsTable->prepareSQL("
            SELECT
                Comments.id AS Comments__id,
                Comments.article_id AS Comments__article_id,
                Comments.content AS Comments__content,
                Articles.id AS Articles__id,
                Articles.title AS Articles__title
            FROM comments AS Comments
            LEFT JOIN articles AS Articles
                ON Articles.id=Comments.article_id
        ");
        $actual = $CommentsTable->fromNativeQuery($stmt)->all();
        static::assertCount(5, $actual);
        static::assertInstanceOf(Comment::class, $actual[0]);
        static::assertInstanceOf(Article::class, $actual[0]->get('article'));
        $expected = [
            'id' => 1,
            'article_id' => 1,
            'content' => 'Comment 1',
            'article' => [
                'id' => 1,
                'title' => 'Article 1',
            ],
        ];
        $cakeEntities = $CommentsTable->find()
            ->select(['Comments.id', 'Comments.article_id', 'Comments.content'])
            ->contain([
                'Articles' => [
                    'fields' => ['Articles.id', 'Articles.title'],
                ],
            ])
            ->toArray();
        static::assertSame($expected, $actual[0]->toArray());
        $this->assertEqualsEntities($cakeEntities, $actual);
        //static::assertEquals($cakeEntities, $actual);
    }

    public function testHasOne(): void
    {
        /** @var \Bancer\NativeQueryMapperTest\TestApp\Model\Table\UsersTable $UsersTable */
        $UsersTable = $this->fetchTable(UsersTable::class);
        $stmt = $UsersTable->prepareSQL("
            SELECT
                Users.id AS Users__id,
                Users.username AS Users__username,
                Profiles.id AS Profiles__id,
                Profiles.user_id AS Profiles__user_id,
                Profiles.bio AS Profiles__bio
            FROM users AS Users
            LEFT JOIN profiles AS Profiles
                ON Users.id=Profiles.user_id
        ");
        $actual = $UsersTable->fromNativeQuery($stmt)->all();
        static::assertCount(5, $actual);
        static::assertInstanceOf(User::class, $actual[0]);
        static::assertInstanceOf(Profile::class, $actual[0]->get('profile'));
        $expected = [
            'id' => 1,
            'username' => 'alice',
            'profile' => [
                'id' => 1,
                'user_id' => 1,
                'bio' => 'Bio Alice',
            ],
        ];
        static::assertSame($expected, $actual[0]->toArray());
        $cakeEntities = $UsersTable->find()
            ->select(['Users.id', 'Users.username'])
            ->contain([
                'Profiles' => [
                    'fields' => ['Profiles.id', 'Profiles.user_id', 'Profiles.bio'],
                ],
            ])
            ->toArray();
        static::assertSame($expected, $actual[0]->toArray());
        $this->assertEqualsEntities($cakeEntities, $actual);
        //static::assertEquals($cakeEntities, $actual);
    }

    public function testBelongsToManySimple(): void
    {
        /** @var \Bancer\NativeQueryMapperTest\TestApp\Model\Table\ArticlesTable $ArticlesTable */
        $ArticlesTable = $this->fetchTable(ArticlesTable::class);
        $stmt = $ArticlesTable->prepareSQL("
            SELECT
                Articles.id AS Articles__id,
                Articles.title AS Articles__title,
                Tags.id AS Tags__id,
                Tags.name AS Tags__name
            FROM articles AS Articles
            LEFT JOIN articles_tags AS ArticlesTags
                ON Articles.id=ArticlesTags.article_id
            LEFT JOIN tags AS Tags
                ON Tags.id=ArticlesTags.tag_id
        ");
        $actual = $ArticlesTable->fromNativeQuery($stmt)->all();
        static::assertCount(5, $actual);
        static::assertInstanceOf(Article::class, $actual[0]);
        $actualTags = $actual[0]->get('tags');
        static::assertIsArray($actualTags);
        static::assertCount(2, $actualTags);
        static::assertInstanceOf(Tag::class, $actualTags[0]);
        $expected = [
            'id' => 1,
            'title' => 'Article 1',
            'tags' => [
                [
                    'id' => 1,
                    'name' => 'Tech',
                ],
                [
                    'id' => 2,
                    'name' => 'Food',
                ],
            ],
        ];
        static::assertSame($expected, $actual[0]->toArray());
        /*$cakeEntities = $ArticlesTable->find()
            ->select(['Articles.id', 'Articles.title'])
            ->contain([
                'Tags' => [
                    'fields' => ['Tags.id', 'Tags.name'],
                ],
            ])
            ->toArray();
        static::assertSame($expected, $actual[0]->toArray());
        $this->assertEqualsEntities($cakeEntities, $actual);
        static::assertEquals($cakeEntities, $actual);*/
    }

    public function testBelongsToManyFetchJoinTable(): void
    {
        /** @var \Bancer\NativeQueryMapperTest\TestApp\Model\Table\ArticlesTable $ArticlesTable */
        $ArticlesTable = $this->fetchTable(ArticlesTable::class);
        $stmt = $ArticlesTable->prepareSQL("
            SELECT
                Articles.id AS Articles__id,
                Articles.title AS Articles__title,
                Tags.id AS Tags__id,
                Tags.name AS Tags__name,
                ArticlesTags.id AS ArticlesTags__id,
                ArticlesTags.article_id AS ArticlesTags__article_id,
                ArticlesTags.tag_id AS ArticlesTags__tag_id
            FROM articles AS Articles
            LEFT JOIN articles_tags AS ArticlesTags
                ON Articles.id=ArticlesTags.article_id
            LEFT JOIN tags AS Tags
                ON Tags.id=ArticlesTags.tag_id
        ");
        $actual = $ArticlesTable->fromNativeQuery($stmt)->all();
        static::assertCount(5, $actual);
        static::assertInstanceOf(Article::class, $actual[0]);
        $actualTags = $actual[0]->get('tags');
        static::assertIsArray($actualTags);
        static::assertCount(2, $actualTags);
        static::assertInstanceOf(Tag::class, $actualTags[0]);
        $expected = [
            'id' => 1,
            'title' => 'Article 1',
            'tags' => [
                [
                    'id' => 1,
                    'name' => 'Tech',
                    'articles_tag' => [
                        'id' => 1,
                        'article_id' => 1,
                        'tag_id' => 1,
                    ],
                ],
                [
                    'id' => 2,
                    'name' => 'Food',
                    'articles_tag' => [
                        'id' => 2,
                        'article_id' => 1,
                        'tag_id' => 2,
                    ],
                ],
            ],
        ];
        static::assertSame($expected, $actual[0]->toArray());
        /*$cakeEntities = $ArticlesTable->find()
            ->select(['Articles.id', 'Articles.title'])
            ->contain([
                'Tags' => [
                    'fields' => ['Tags.id', 'Tags.name'],
                    'ArticlesTags' => [
                        'fields' => ['ArticlesTags.id', 'ArticlesTags.article_id', 'ArticlesTags.tag_id'],
                    ],
                ],
            ])
            ->toArray();
        static::assertSame($cakeEntities[0]->toArray(), $actual[0]->toArray());
        $this->assertEqualsEntities($cakeEntities, $actual);
        static::assertEquals($cakeEntities, $actual);*/
    }
}
