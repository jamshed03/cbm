<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\ModelGenerator;

use AskoEducation\Cbm\Service\ContentBlockReloader;
use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentType;
use TYPO3\CMS\ContentBlocks\Definition\Factory\TableDefinitionCollectionFactory;
use TYPO3\CMS\ContentBlocks\Definition\TableDefinitionCollection;
use TYPO3\CMS\ContentBlocks\Definition\TcaFieldDefinition;
use TYPO3\CMS\ContentBlocks\FieldType\FieldTypeRegistry;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\ContentBlocks\Registry\ContentBlockRegistry;
use TYPO3\CMS\ContentBlocks\Schema\SimpleTcaSchemaFactory;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Domain\Model\Category;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * Generates Extbase models (properties, getters, setters) from a Content Block's fields, as Content Blocks compiles
 * them: one model for the Content Block, one for each of its Collections. Where table or columns differ from
 * Extbase's conventions (prefixed columns, tt_content, Collection tables), a persistence mapping is needed.
 */
final readonly class ModelGenerator
{
    public function __construct(
        private ContentBlockReloader $contentBlockReloader,
        private TableDefinitionCollectionFactory $tableDefinitionCollectionFactory,
        private FieldTypeRegistry $fieldTypeRegistry,
        private SimpleTcaSchemaFactory $simpleTcaSchemaFactory,
        private PackageManager $packageManager,
    ) {
    }

    /**
     * @param string $name "vendor/name", or just the name (case-insensitive), e.g. "Hero"
     */
    public function findContentBlock(ContentBlockRegistry $registry, string $name): LoadedContentBlock
    {
        if ($registry->hasContentBlock($name)) {
            return $registry->getContentBlock($name);
        }
        $matches = array_values(array_filter(
            $registry->getAll(),
            static fn(LoadedContentBlock $contentBlock): bool => strcasecmp($contentBlock->getPackage(), $name) === 0,
        ));
        return match (count($matches)) {
            1 => $matches[0],
            0 => throw new \InvalidArgumentException(sprintf('There is no Content Block "%s".', $name), 1758700050),
            default => throw new \InvalidArgumentException(sprintf(
                '"%s" is ambiguous, use one of: %s',
                $name,
                implode(', ', array_map(static fn(LoadedContentBlock $contentBlock): string => $contentBlock->getName(), $matches)),
            ), 1758700051),
        };
    }

    public function generate(string $contentBlockName, bool $withRepository): GeneratedModel
    {
        $registry = $this->contentBlockReloader->loadRegistry();
        $contentBlock = $this->findContentBlock($registry, $contentBlockName);
        if ($contentBlock->getContentType() === ContentType::FILE_TYPE) {
            throw new \InvalidArgumentException('File types extend sys_file_metadata, which has no Extbase model to generate.', 1758700052);
        }
        $tables = $this->tableDefinitionCollectionFactory->createUncached($registry, $this->fieldTypeRegistry, $this->simpleTcaSchemaFactory);
        $namespace = $this->getNamespace($contentBlock->getHostExtension());
        $basePath = $this->packageManager->getPackage($contentBlock->getHostExtension())->getPackagePath() . 'Classes/Domain/';
        $className = $this->toClassName($contentBlock->getPackage());
        $yaml = $contentBlock->getYaml();
        $table = (string) $yaml['table'];
        $columns = $tables->getTable($table)->contentTypeDefinitionCollection->getType($yaml['typeName'])->getOverrideColumns();

        $result = new GeneratedModel($basePath . 'Model/', $namespace, $basePath . '../../Configuration/Extbase/Persistence/Classes.php');
        $this->addModel($result, $contentBlock, $tables, $className, $table, $columns, sprintf('Model of the Content Block "%s"', $contentBlock->getName()));
        $mapping = &$result->mappings[$result->namespace . '\\Domain\\Model\\' . $className];
        // Content elements and pages share their table with all other types of it.
        if ($table === 'tt_content' || $table === 'pages') {
            $mapping['recordType'] = (string) $yaml['typeName'];
        }
        if ($withRepository) {
            $result->files[$basePath . 'Repository/' . $className . 'Repository.php'] = $this->renderRepository($namespace, $className);
        }
        return $result;
    }

    /**
     * @param iterable<TcaFieldDefinition> $columns
     */
    private function addModel(GeneratedModel $result, LoadedContentBlock $contentBlock, TableDefinitionCollection $tables, string $className, string $table, iterable $columns, string $description): void
    {
        $properties = [];
        foreach ($columns as $column) {
            $property = $this->createProperty($result, $contentBlock, $tables, $className, $column);
            if ($property !== null) {
                $properties[] = $property;
            }
        }
        $fqcn = $result->namespace . '\\Domain\\Model\\' . $className;
        $result->files[$result->modelPath . $className . '.php'] = $this->renderModel($result->namespace, $className, $description, $properties);

        $mapping = [];
        if ($table !== $this->getConventionalTable($fqcn)) {
            $mapping['tableName'] = $table;
        }
        foreach ($properties as $property) {
            if ($property->column !== GeneralUtility::camelCaseToLowerCaseUnderscored($property->name)) {
                $mapping['properties'][$property->name] = ['fieldName' => $property->column];
            }
        }
        $result->mappings[$fqcn] = $mapping;
    }

    private function createProperty(GeneratedModel $result, LoadedContentBlock $contentBlock, TableDefinitionCollection $tables, string $className, TcaFieldDefinition $column): ?ModelProperty
    {
        $name = lcfirst($this->toClassName($column->identifier));
        $config = $column->getTca()['config'] ?? [];
        $relationship = $config['relationship'] ?? null;
        $scalar = static fn(string $type, string $default, string $comment = ''): ModelProperty => new ModelProperty($name, $column->uniqueIdentifier, $type, $default, comment: $comment);
        $storage = static fn(string $class, string $comment = ''): ModelProperty => new ModelProperty($name, $column->uniqueIdentifier, 'ObjectStorage', '', self::shortName($class), [ObjectStorage::class, $class], $comment);

        return match ($column->fieldType->getName()) {
            'Number' => ($config['format'] ?? '') === 'decimal' ? $scalar('float', '0.0') : $scalar('int', '0'),
            'Checkbox' => count($config['items'] ?? []) > 1 ? $scalar('int', '0', 'bit mask of the checked items') : $scalar('bool', 'false'),
            'DateTime' => $scalar('?\\DateTime', 'null'),
            'SelectNumber', 'Language' => $scalar('int', '0'),
            'File' => $relationship === 'oneToOne'
                ? new ModelProperty($name, $column->uniqueIdentifier, '?FileReference', 'null', imports: [FileReference::class])
                : $storage(FileReference::class),
            'Category' => $storage(Category::class),
            'Collection' => $this->createCollectionProperty($result, $contentBlock, $tables, $className, $column, $name),
            'Relation' => $this->createRelationProperty($result, $tables, $column, $name),
            'Palette', 'Tab', 'Linebreak', 'Pass' => null,
            default => $scalar('string', "''"),
        };
    }

    /**
     * A Collection of this Content Block gets a model of its own, e.g. "ProjectSection" for the field "sections".
     */
    private function createCollectionProperty(GeneratedModel $result, LoadedContentBlock $contentBlock, TableDefinitionCollection $tables, string $className, TcaFieldDefinition $column, string $name): ModelProperty
    {
        $childTable = (string) ($column->getTca()['config']['foreign_table'] ?? '');
        if ($tables->hasTable($childTable)) {
            $childClassName = $className . $this->toSingular($this->toClassName($column->identifier));
            $description = sprintf('Model of the Collection "%s" of the Content Block "%s"', $column->identifier, $contentBlock->getName());
            $this->addModel($result, $contentBlock, $tables, $childClassName, $childTable, $tables->getTable($childTable)->getDefaultTypeDefinition()->getOverrideColumns(), $description);
            $childClass = $result->namespace . '\\Domain\\Model\\' . $childClassName;
            return new ModelProperty($name, $column->uniqueIdentifier, 'ObjectStorage', '', $childClassName, [ObjectStorage::class, $childClass]);
        }
        return new ModelProperty($name, $column->uniqueIdentifier, 'int', '0', comment: 'number of related ' . $childTable . ' records');
    }

    /**
     * A Relation to a Content Block of this extension points to its model; others keep the uid(s).
     */
    private function createRelationProperty(GeneratedModel $result, TableDefinitionCollection $tables, TcaFieldDefinition $column, string $name): ModelProperty
    {
        $config = $column->getTca()['config'] ?? [];
        $relatedTables = GeneralUtility::trimExplode(',', (string) ($config['allowed'] ?? $config['foreign_table'] ?? ''), true);
        $single = in_array($config['relationship'] ?? null, ['oneToOne', 'manyToOne'], true);
        $relatedClass = count($relatedTables) === 1 ? $this->findModelClass($result, $tables, $relatedTables[0]) : null;
        if ($relatedClass === null) {
            return $single
                ? new ModelProperty($name, $column->uniqueIdentifier, 'int', '0', comment: 'uid of a ' . implode('/', $relatedTables) . ' record')
                : new ModelProperty($name, $column->uniqueIdentifier, 'string', "''", comment: 'comma-separated uids of ' . implode('/', $relatedTables) . ' records');
        }
        return $single
            ? new ModelProperty($name, $column->uniqueIdentifier, '?' . self::shortName($relatedClass), 'null', imports: [$relatedClass])
            : new ModelProperty($name, $column->uniqueIdentifier, 'ObjectStorage', '', self::shortName($relatedClass), [ObjectStorage::class, $relatedClass]);
    }

    /**
     * The model a Content Block record type of the same extension would get with this generator.
     */
    private function findModelClass(GeneratedModel $result, TableDefinitionCollection $tables, string $table): ?string
    {
        if (!$tables->hasTable($table) || in_array($table, ['tt_content', 'pages'], true)) {
            return null;
        }
        $name = $tables->getTable($table)->getDefaultTypeDefinition()->getName();
        return $result->namespace . '\\Domain\\Model\\' . $this->toClassName(explode('/', $name)[1] ?? $name);
    }

    /**
     * @param list<ModelProperty> $properties
     */
    private function renderModel(string $namespace, string $className, string $description, array $properties): string
    {
        $imports = ['TYPO3\\CMS\\Extbase\\DomainObject\\AbstractEntity'];
        foreach ($properties as $property) {
            $imports = [...$imports, ...array_filter($property->imports, static fn(string $import): bool => !str_starts_with($import, $namespace . '\\Domain\\Model\\'))];
        }
        $imports = array_unique($imports);
        sort($imports);

        $declarations = [];
        $storages = [];
        $accessors = [];
        foreach ($properties as $property) {
            $doc = [];
            if ($property->comment !== '') {
                $doc[] = $property->comment;
            }
            if ($property->isObjectStorage()) {
                $doc[] = '@var ObjectStorage<' . $property->storageOf . '>';
                $storages[] = sprintf('        $this->%s = new ObjectStorage();', $property->name);
            }
            $declaration = $doc === [] ? '' : "    /**\n" . implode("\n", array_map(static fn(string $line): string => '     * ' . $line, $doc)) . "\n     */\n";
            $declarations[] = $declaration . sprintf('    protected %s $%s%s;', $property->type, $property->name, $property->default !== '' ? ' = ' . $property->default : '');
            $method = ucfirst($property->name);
            $accessors[] = <<<PHP
                public function get{$method}(): {$property->type}
                {
                    return \$this->{$property->name};
                }

                public function set{$method}({$property->type} \${$property->name}): void
                {
                    \$this->{$property->name} = \${$property->name};
                }
            PHP;
        }
        $constructor = $storages === [] ? '' : "\n    public function __construct()\n    {\n" . implode("\n", $storages) . "\n    }\n";
        $body = implode("\n", $declarations) . "\n" . $constructor . ($accessors === [] ? '' : "\n" . implode("\n\n", $accessors) . "\n");
        $uses = implode("\n", array_map(static fn(string $import): string => 'use ' . $import . ';', $imports));

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Domain\\Model;

            {$uses}

            /**
             * {$description}, generated by cbm:make:model.
             */
            class {$className} extends AbstractEntity
            {
            {$body}}

            PHP;
    }

    private function renderRepository(string $namespace, string $className): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Domain\\Repository;

            use {$namespace}\\Domain\\Model\\{$className};
            use TYPO3\\CMS\\Extbase\\Persistence\\Repository;

            /**
             * @extends Repository<{$className}>
             */
            class {$className}Repository extends Repository
            {
            }

            PHP;
    }

    /**
     * The namespace of the extension's "Classes/" folder, from the PSR-4 autoloading of its composer.json.
     */
    private function getNamespace(string $extension): string
    {
        $autoload = (array) ($this->packageManager->getPackage($extension)->getValueFromComposerManifest('autoload') ?? []);
        foreach ((array) ($autoload['psr-4'] ?? []) as $namespace => $path) {
            if (trim((string) $path, '/') === 'Classes') {
                return rtrim((string) $namespace, '\\');
            }
        }
        throw new \RuntimeException(sprintf('The composer.json of "%s" has no PSR-4 namespace for "Classes/".', $extension), 1758700053);
    }

    /**
     * The table Extbase assumes for a model class, e.g. "tx_portfolio_domain_model_project".
     */
    private function getConventionalTable(string $className): string
    {
        $parts = explode('\\', $className);
        return strtolower('tx_' . ($parts[1] ?? '') . '_domain_model_' . end($parts));
    }

    /**
     * "image-text" or "tech_stack"/"techStack" become "ImageText" and "TechStack".
     */
    private function toClassName(string $name): string
    {
        return GeneralUtility::underscoredToUpperCamelCase(str_replace('-', '_', GeneralUtility::camelCaseToLowerCaseUnderscored($name)));
    }

    /**
     * A simple English singular for the model of a Collection item: "sections" → "Section", "categories" → "Category".
     */
    private function toSingular(string $name): string
    {
        return match (true) {
            str_ends_with($name, 'ies') => substr($name, 0, -3) . 'y',
            str_ends_with($name, 'sses') => substr($name, 0, -2),
            str_ends_with($name, 's') && !str_ends_with($name, 'ss') => substr($name, 0, -1),
            default => $name,
        };
    }

    private static function shortName(string $className): string
    {
        $parts = explode('\\', $className);
        return end($parts);
    }
}
