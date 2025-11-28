<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapper\Test\TestCase\ORM;

use Bancer\NativeQueryMapperTest\TestApp\Model\Entity\Article;
use Bancer\NativeQueryMapperTest\TestApp\Model\Entity\Comment;
use Bancer\NativeQueryMapperTest\TestApp\Model\Entity\Country;
use Bancer\NativeQueryMapperTest\TestApp\Model\Entity\Profile;
use Bancer\NativeQueryMapperTest\TestApp\Model\Entity\Tag;
use Bancer\NativeQueryMapperTest\TestApp\Model\Entity\User;
use Bancer\NativeQueryMapper\ORM\MappingStrategy;
use Bancer\NativeQueryMapperTest\TestApp\Model\Table\ArticlesTable;
use Cake\ORM\Entity;
use Cake\ORM\Locator\LocatorAwareTrait;
use PHPUnit\Framework\TestCase;

class MappingStrategyTest extends TestCase
{
    use LocatorAwareTrait;

    public function testDeepAssociations(): void
    {
        $ArticlesTable = $this->fetchTable(ArticlesTable::class);
        $aliases = [
            'Articles',
            'ArticlesTags',
            'Comments',
            'Countries',
            'Profiles',
            'Tags',
            'Users',
        ];
        $strategy = new MappingStrategy($ArticlesTable, $aliases);
        $actual = $strategy->build()->toArray();
        $expected = [
            'Articles' => [
                'className' => Article::class,
                'belongsTo' => [
                    'Users' => [
                        'className' => User::class,
                        'propertyName' => 'user',
                        'belongsTo' => [
                            'Countries' => [
                                'className' => Country::class,
                                'propertyName' => 'country',
                            ],
                        ],
                        'hasOne' => [
                            'Profiles' => [
                                'className' => Profile::class,
                                'propertyName' => 'profile',
                            ],
                        ],
                    ],
                ],
                'belongsToMany' => [
                    'Tags' => [
                        'className' => Tag::class,
                        'propertyName' => 'tags',
                        'hasOne' => [
                            'ArticlesTags' => [
                                'className' => Entity::class,
                                'propertyName' => 'articles_tag',
                            ],
                        ],
                    ],
                ],
                'hasMany' => [
                    'Comments' => [
                        'className' => Comment::class,
                        'propertyName' => 'comments',
                    ],
                ],
            ],
        ];
        $this->assertSame($expected, $actual);
    }

    public function testBelongsToMany(): void
    {
        $articles = $this->fetchTable(ArticlesTable::class);
        $aliases = [
            'Articles',
            'Tags',
        ];
        $strategy = new MappingStrategy($articles, $aliases);
        $actual = $strategy->build()->toArray();
        $expected = [
            'Articles' => [
                'className' => Article::class,
                'belongsToMany' => [
                    'Tags' => [
                        'className' => Tag::class,
                        'propertyName' => 'tags',
                    ],
                ],
            ],
        ];
        $this->assertSame($expected, $actual);
    }

    public function testBelongsToManyFetchJoinTable(): void
    {
        $articles = $this->fetchTable(ArticlesTable::class);
        $aliases = [
            'Articles',
            'Tags',
            'ArticlesTags',
        ];
        $strategy = new MappingStrategy($articles, $aliases);
        $actual = $strategy->build()->toArray();
        $expected = [
            'Articles' => [
                'className' => Article::class,
                'belongsToMany' => [
                    'Tags' => [
                        'className' => Tag::class,
                        'propertyName' => 'tags',
                        'hasOne' => [
                            'ArticlesTags' => [
                                'className' => Entity::class,
                                'propertyName' => 'articles_tag',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $this->assertSame($expected, $actual);
    }
}
