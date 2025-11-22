<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Cake\Database\Connection;
use Cake\Database\Driver\Sqlite;
use Cake\Datasource\ConnectionManager;

ConnectionManager::setConfig('test', [
    'className' => Connection::class,
    'driver' => Sqlite::class,
    'database' => ':memory:',
    'encoding' => 'utf8',
    'cacheMetadata' => false,
]);
ConnectionManager::alias('test', 'default');

$connection = ConnectionManager::get('test');

$connection->execute("
    CREATE TABLE countries (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL
    );
");

$connection->execute("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL,
        country_id INTEGER NOT NULL,
        FOREIGN KEY (country_id) REFERENCES countries(id)
    );
");

$connection->execute("
    CREATE TABLE profiles (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        bio TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id)
    );
");

$connection->execute("
    CREATE TABLE articles (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id)
    );
");

$connection->execute("
    CREATE TABLE comments (
        id INTEGER PRIMARY KEY,
        article_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        content TEXT,
        FOREIGN KEY (article_id) REFERENCES articles(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    );
");

$connection->execute("
    CREATE TABLE tags (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL
    );
");

$connection->execute("
    CREATE TABLE articles_tags (
        id INTEGER PRIMARY KEY,
        article_id INTEGER NOT NULL,
        tag_id INTEGER NOT NULL,
        FOREIGN KEY (article_id) REFERENCES articles(id),
        FOREIGN KEY (tag_id) REFERENCES tags(id)
    );
");

$connection->execute("
    INSERT INTO countries (id, name)
    VALUES
        (1,'USA'),
        (2,'UK'),
        (3,'France'),
        (4,'Germany'),
        (5,'Japan');
");

$connection->execute("
    INSERT INTO users (id, username, country_id)
    VALUES
        (1,'alice',1),
        (2,'bob',2),
        (3,'carol',3),
        (4,'dave',4),
        (5,'eve',5);
");

$connection->execute("
    INSERT INTO profiles (id, user_id, bio)
    VALUES
        (1,1,'Bio Alice'),
        (2,2,'Bio Bob'),
        (3,3,'Bio Carol'),
        (4,4,'Bio Dave'),
        (5,5,'Bio Eve');
");

$connection->execute("
    INSERT INTO articles (id, user_id, title)
    VALUES
        (1,1,'Article 1'),
        (2,2,'Article 2'),
        (3,3,'Article 3'),
        (4,4,'Article 4'),
        (5,5,'Article 5');
");

$connection->execute("
    INSERT INTO comments (id, article_id, user_id, content)
    VALUES
        (1,1,2,'Comment 1'),
        (2,1,3,'Comment 2'),
        (3,2,1,'Comment 3'),
        (4,3,4,'Comment 4'),
        (5,5,5,'Comment 5');
");

$connection->execute("
    INSERT INTO tags (id, name)
    VALUES
        (1,'Tech'),
        (2,'Food'),
        (3,'Travel'),
        (4,'Science'),
        (5,'Art');
");

$connection->execute("
    INSERT INTO articles_tags (id, article_id, tag_id)
    VALUES
        (1,1,1),
        (2,1,2),
        (3,2,3),
        (4,3,4),
        (5,4,5),
        (6,5,1),
        (7,5,2);
");
