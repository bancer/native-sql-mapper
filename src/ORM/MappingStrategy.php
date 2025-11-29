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
            throw new UnknownAliasException("The query must use root table alias '$rootAlias'");
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
            throw new UnknownAliasException('Failed to map some aliases: ' . $this->unknownAliasesToString());
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
                'propertyName' => $assoc->getProperty(),
            ];
            if ($assoc instanceof BelongsToMany) {
                $through = $assoc->getThrough() ?? $assoc->junction();
                if (is_object($through)) {
                    $through = $through->getAlias();
                }
                if (isset($this->unknownAliases[$through])) {
                    $firstLevelAssoc['hasOne'][$through] = [
                        'className' => $assoc->junction()->getEntityClass(),
                        'propertyName' => Inflector::underscore(Inflector::singularize($through)),
                    ];
                    unset($this->unknownAliases[$through]);
                }
            }
            $result[$type][$alias] = $firstLevelAssoc;
        }
        if ($unknownAliasesCount > 0 && $unknownAliasesCount === count($this->unknownAliases)) {
            throw new UnknownAliasException(
                'None of the root table associations match any remaining aliases: ' .
                $this->unknownAliasesToString()
            );
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
            $result[$type][$childAlias]['propertyName'] = $assoc->getProperty();
            if ($assoc instanceof BelongsToMany) {
                $through = $assoc->getThrough() ?? $assoc->junction();
                if (is_object($through)) {
                    $through = $through->getAlias();
                }
                $result[$type][$childAlias]['hasOne'][$through] = [
                    'className' => $assoc->junction()->getEntityClass(),
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
            HasOne::class        => 'hasOne',
            BelongsTo::class     => 'belongsTo',
            BelongsToMany::class => 'belongsToMany',
            HasMany::class       => 'hasMany',
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
        return implode(', ', array_keys($this->unknownAliases));
    }
}
