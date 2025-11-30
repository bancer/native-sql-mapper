# native-sql-mapper

A lightweight extension for the CakePHP ORM that converts **native SQL queries** (executed through prepared PDO statements) into **fully hydrated CakePHP entity graphs**.

This library allows you to execute raw SQL while still benefiting from CakePHP’s entity system, associations, nested structures, and conventions. It supports **deep associations**, **belongsToMany relations**, **junction data**, **nested mapping**, and **strict alias validation**.

`native-sql-mapper` is ideal when:

- You need SQL performance or features that exceed the ORM’s query builder  
- You want complex joins, window functions, CTEs, subqueries, aggregates
- You do not want to spend time on converting your SQL statements to query objects using CakePHP's query builder
- But still want **CakePHP entities**, **patch-like hydration**, and **nested association graphs** automatically built from the result set  

Aliases such as:

```
Articles__id,
Articles__title,
Comments__id,
Comments__article_id,
Comments__content
```

will be converted into a fully hydrated entity objects.

---

## 🚀 Features

- **Native SQL → real CakePHP entities**  
- **Deep association support** (belongsTo, hasMany, hasOne, belongsToMany)
- **Automatic nested entity graph building**
- **Strict alias validation** based on your ORM associations
- **No configuration required** — conventions are inferred
- **Works with any SQL** (CTEs, window functions, unions, etc.)

---

## 📦 Installation

Install via Composer:

```bash
composer require bancer/native-sql-mapper
```
---

## 🔧 Setup & Usage

### 1. Import the trait in your Table class

```php
use Bancer\NativeQueryMapper\ORM\NativeSQLMapperTrait;
```

### 2. Use the trait

```php
use NativeSQLMapperTrait;
```

### 3. Example usage

```php
$ArticlesTable = $this->fetchTable(ArticlesTable::class);
$stmt = $ArticlesTable->prepareNativeStatement("
    SELECT
        id     AS Articles__id,
        title  AS Articles__title
    FROM articles
    WHERE title = :title
");
$stmt->bindValue('title', 'My Article Title');
/** @var \App\Model\Entity\Article[] $entities */
$entities = $ArticlesTable->mapNativeStatement($stmt)->all();
```

`$entities` now contains hydrated `Article` entities based on the SQL result.

---

## 🔁 hasMany Example Using Minimalistic SQL

```php
$stmt = $ArticlesTable->prepareNativeStatement("
    SELECT
        a.id        AS Articles__id,
        title       AS Articles__title,
        c.id        AS Comments__id,
        article_id  AS Comments__article_id,
        content     AS Comments__content
    FROM articles AS a
    LEFT JOIN comments AS c
        ON a.id=c.article_id
");
$entities = $ArticlesTable->mapNativeStatement($stmt)->all();
```
`$entities` now contains an array of Article objects with Comment objects as children.

Same as the result of reqular `->find()...->toArray()`:
```php
$entities = $ArticlesTable->find()
    ->select(['Articles.id', 'Articles.title'])
    ->contain([
        'Comments' => [
            'fields' => ['Comments.id', 'Comments.article_id', 'Comments.content'],
        ],
    ])
    ->toArray();
```
Notice that `FROM` and `JOIN` clauses may use short or long aliases or no aliases at all (if the query does not use 'hasMany' or 'belongsToMany' associations) but all fields in `SELECT` clause must use aliases according to CakePHP naming convention `{Alias}__{field_name}`.

## 🔁 belongsToMany Example

```php
$ArticlesTable = $this->fetchTable(ArticlesTable::class);
$stmt = $ArticlesTable->prepareNativeStatement("
    SELECT
        Articles.id     AS Articles__id,
        Articles.title  AS Articles__title,
        Tags.id         AS Tags__id,
        Tags.name       AS Tags__name
    FROM articles AS Articles
    LEFT JOIN articles_tags AS ArticlesTags
        ON Articles.id=ArticlesTags.article_id
    LEFT JOIN tags AS Tags
        ON Tags.id=ArticlesTags.tag_id
");
$entities = $ArticlesTable->mapNativeStatement($stmt)->all();
```
You can find more examples in tests - https://github.com/bancer/native-sql-mapper/tree/develop/tests/TestCase/ORM.

### Mapping
---

## 🧠 How It Works

- Aliases are parsed using CakePHP’s `Alias__field` naming convention  
- Mapping is validated against real your ORM associations  
- Deep nested associations are built recursively      
- Only entities and associations that exist in your ORM are allowed  

---

## ⚠️ Requirements

- Cake ORM **4.x** or **5.x** (or CakePHP **4.x** or **5.x**)
- PHP **7.4+** or **8.0+**
- PDO database driver  

---

## 📝 Notes & Limitations

- Aliases **must** follow CakePHP-style naming: `Model__field`.
- If SQL retrieves data from 'hasMany' or 'belongsToMany' associations then all primary columns must be present in `SELECT` clause
- Fields without valid aliases throw exceptions
- Associations must exist in the Table class, incorrect aliases throw exceptions  
- Pagination must be handled manually
- This library is not a replacement of CakePHP query builder but a useful addition to it.

---

## ✔️ Summary

`native-sql-mapper` gives you the **freedom** of native SQL with the **structure** of CakePHP entities.  
It fills the gap between raw PDO statements and the ORM — allowing complex SQL while preserving the integrity of your entity graphs.

---
```

