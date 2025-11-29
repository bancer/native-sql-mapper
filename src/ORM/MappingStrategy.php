<?php

declare(strict_types=1);

namespace Bancer\NativeQueryMapper\ORM;

use Cake\ORM\Association;
use Cake\ORM\Table;
use Cake\ORM\Association\HasOne;
use Cake\ORM\Association\HasMany;
use Cake\ORM\Association\BelongsTo;
use Cake\ORM\Association\BelongsToMany;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;

class MappingStrategy
{
    use LocatorAwareTrait;

    public const BELONGS_TO = 'belongsTo';

    public const BELONGS_TO_MANY = 'belongsToMany';

    public const HAS_ONE = 'hasOne';

    public const HAS_MANY = 'hasMany';

    protected Table $rootTable;

    /**
     * A list of model aliases.
     *
     * @var string[]
     */
    protected array $aliasList;

    /**
     * A list of aliases to be mapped.
     *
     * @var string[]
     */
    protected array $unknownAliases = [];

    /**
     * A tree-like array describing Alias-Entity mappings.
     *
     * @var mixed[]
     */
    protected array $mappings = [];

    /**
     * Constructor.
     *
     * @param \Cake\ORM\Table $rootTable Table from which SELECT query is executed.
     * @param string[] $aliases A list of model aliases.
     */
    public function __construct(Table $rootTable, array $aliases)
    {
        $this->rootTable = $rootTable;
        if ($aliases === []) {
            throw new UnknownAliasException('Every column of the query must use aliases');
        }
        $this->aliasList = $aliases;
        $this->unknownAliases = array_combine($aliases, $aliases);
        $rootAlias = $rootTable->getAlias();
        if (!isset($this->unknownAliases[$rootAlias])) {
            $message = "The query must select at least one column from the root table.";
            $message .= " The column alias must use {$rootAlias}__{column_name} format";
            throw new UnknownAliasException($message);
        }
        unset($this->unknownAliases[$rootAlias]);
    }

    public function build(): self
    {
        $rootAlias = $this->rootTable->getAlias();
        // --- Process root table non-recursively ---
        $firstLevelChildren = $this->scanRootLevel($this->rootTable);
        $this->mappings[$rootAlias] = $firstLevelChildren;
        // --- Recursively process all remaining unknown aliases ---
        foreach ($firstLevelChildren as $assocType => $children) {
            if (is_array($children)) {
                foreach ($children as $childAlias => $childValue) {
                    $childMappings = $this->scanTableRecursive($childAlias);
                    $mappings = Hash::merge($this->mappings[$rootAlias][$assocType][$childAlias], $childMappings);
                    $this->mappings[$rootAlias][$assocType][$childAlias] = $mappings;
                }
            }
        }
        if ($this->unknownAliases !== []) {
            $message = sprintf("None of the table associations match alias '%s'", $this->unknownAliasesToString());
            throw new UnknownAliasException($message);
        }
        return $this;
    }

    /**
     * Process a table associations one level only (non-recursively).
     *
     * @param \Cake\ORM\Table $table Query's root table.
     * @return mixed[]
     */
    private function scanRootLevel(Table $table): array
    {
        $unknownAliasesCount = count($this->unknownAliases);
        /** @var mixed[] $result */
        $result = [
            'className' => $table->getEntityClass(),
            'primaryKey' => $table->getPrimaryKey(),
        ];
        /** @var \Cake\ORM\Association $assoc */
        foreach ($table->associations() as $assoc) {
            $type = $this->assocType($assoc);
            if ($type === null) {
                continue;
            }
            $target = $assoc->getTarget();
            $alias = $target->getAlias();
            if (!isset($this->unknownAliases[$alias])) {
                continue;
            }
            unset($this->unknownAliases[$alias]);
            $firstLevelAssoc = [
                'className' => $target->getEntityClass(),
                'primaryKey' => $target->getPrimaryKey(),
                'propertyName' => $assoc->getProperty(),
            ];
            if ($assoc instanceof BelongsToMany) {
                $through = $assoc->getThrough() ?? $assoc->junction();
                if (is_object($through)) {
                    $through = $through->getAlias();
                }
                if (isset($this->unknownAliases[$through])) {
                    $firstLevelAssoc[self::HAS_ONE][$through] = [
                        'className' => $assoc->junction()->getEntityClass(),
                        'primaryKey' => $assoc->junction()->getPrimaryKey(),
                        'propertyName' => Inflector::underscore(Inflector::singularize($through)),
                    ];
                    unset($this->unknownAliases[$through]);
                }
            }
            $result[$type][$alias] = $firstLevelAssoc;
        }
        if ($unknownAliasesCount > 0 && $unknownAliasesCount === count($this->unknownAliases)) {
            $message = sprintf(
                "None of the root table associations match alias '%s'",
                $this->unknownAliasesToString(),
            );
            throw new UnknownAliasException($message);
        }
        return $result;
    }

    /**
     * Recursively process associations starting from non-root tables.
     *
     * @param string $alias Model alias.
     * @return mixed[]
     */
    private function scanTableRecursive(string $alias): array
    {
        if (!in_array($alias, $this->aliasList)) {
            return [];
        }
        $table = $this->fetchTable($alias);
        /** @var mixed[] $result */
        $result = [
            'className' => $table->getEntityClass(),
            'primaryKey' => $table->getPrimaryKey(),
        ];
        foreach ($table->associations() as $assoc) {
            $type = $this->assocType($assoc);
            if (!$type) {
                continue;
            }
            $target = $assoc->getTarget();
            $childAlias = $target->getAlias();
            if (!isset($this->unknownAliases[$childAlias])) {
                continue;
            }
            unset($this->unknownAliases[$childAlias]);
            $result[$type][$childAlias]['className'] = $target->getEntityClass();
            $result[$type][$childAlias]['primaryKey'] = $target->getPrimaryKey();
            $result[$type][$childAlias]['propertyName'] = $assoc->getProperty();
            if ($assoc instanceof BelongsToMany) {
                $through = $assoc->getThrough() ?? $assoc->junction();
                if (is_object($through)) {
                    $through = $through->getAlias();
                }
                $result[$type][$childAlias][self::HAS_ONE][$through] = [
                    'className' => $assoc->junction()->getEntityClass(),
                    'primaryKey' => $assoc->junction()->getPrimaryKey(),
                    'propertyName' => Inflector::underscore(Inflector::singularize($through)),
                ];
                if (isset($this->unknownAliases[$through])) {
                    unset($this->unknownAliases[$through]);
                }
            } else {
                $childChildren = $this->scanTableRecursive($childAlias);
                $childChildren['propertyName'] = $assoc->getProperty();
                $result[$type][$childAlias] = $childChildren;
            }
        }
        return $result;
    }

    /**
     * Returns the name of association type.
     *
     * @param \Cake\ORM\Association $assoc Model association.
     * @return string|null
     */
    private function assocType(Association $assoc): ?string
    {
        $map = [
            HasOne::class        => self::HAS_ONE,
            BelongsTo::class     => self::BELONGS_TO,
            BelongsToMany::class => self::BELONGS_TO_MANY,
            HasMany::class       => self::HAS_MANY,
        ];
        foreach ($map as $class => $type) {
            if ($assoc instanceof $class) {
                return $type;
            }
        }
        return null;
    }

    /**
     * Returns a tree-like array describing Alias-Entity mappings.
     *
     * @return mixed[]
     */
    public function toArray(): array
    {
        return $this->mappings;
    }

    private function unknownAliasesToString(): string
    {
        return implode("', '", array_keys($this->unknownAliases));
    }
}
