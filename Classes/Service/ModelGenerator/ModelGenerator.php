<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\ModelGenerator;

use AskoEducation\Cbm\Service\ContentBlockReloader;
use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentType;
use TYPO3\CMS\ContentBlocks\Definition\Factory\TableDefinitionCollectionFactory;
use TYPO3\CMS\ContentBlocks\Definition\TableDefinitionCollection;
use TYPO3\CMS\ContentBlocks\Definition\TcaFieldDefinition;
use TYPO3\CMS\ContentBlocks\FieldType\FieldTypeRegistry;
use TYPO3\CMS\ContentBlocks\Schema\SimpleTcaSchemaFactory;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Schema\RelationshipType;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Domain\Model\Category;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;

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
     * @param string $contentBlockName "vendor/name", or just the name (case-insensitive), e.g. "Hero"
     */
    public function generate(string $contentBlockName, bool $withRepository): GeneratedModel
    {
        $registry = $this->contentBlockReloader->loadRegistry();
        $contentBlock = $this->contentBlockReloader->find($registry, $contentBlockName);
        if ($contentBlock->getContentType() === ContentType::FILE_TYPE) {
            throw new \InvalidArgumentException('File types extend sys_file_metadata, which has no Extbase model to generate.', 1758700052);
        }
        $tables = $this->tableDefinitionCollectionFactory->createUncached($registry, $this->fieldTypeRegistry, $this->simpleTcaSchemaFactory);
        $package = $this->packageManager->getPackage($contentBlock->getHostExtension());
        $packagePath = $package->getPackagePath();
        $className = $this->toClassName($contentBlock->getPackage());
        $yaml = $contentBlock->getYaml();
        $table = (string) $yaml['table'];
        $columns = $tables->getTable($table)->contentTypeDefinitionCollection->getType($yaml['typeName'])->getOverrideColumns();
        // A table with a type field (tt_content, pages, ...) is shared with its other types.
        $recordType = $tables->getTable($table)->hasTypeField() ? (string) $yaml['typeName'] : null;

        $result = new GeneratedModel($this->getNamespace($package), $packagePath . 'Configuration/Extbase/Persistence/Classes.php');
        $this->addModel($result, $contentBlock->getName(), $tables, $packagePath . 'Classes/Domain/Model/', $className, $table, $columns, sprintf('Model of the Content Block "%s"', $contentBlock->getName()), $recordType);
        if ($withRepository) {
            $result->files[$packagePath . 'Classes/Domain/Repository/' . $className . 'Repository.php'] = $this->renderRepository($result->namespace, $className);
        }
        return $result;
    }

    /**
     * @param iterable<TcaFieldDefinition> $columns
     */
    private function addModel(GeneratedModel $result, string $contentBlockName, TableDefinitionCollection $tables, string $modelPath, string $className, string $table, iterable $columns, string $description, ?string $recordType = null): void
    {
        $properties = [];
        foreach ($columns as $column) {
            $property = $this->createProperty($result, $contentBlockName, $tables, $modelPath, $className, $column);
            if ($property !== null) {
                $properties[] = $property;
            }
        }
        $fqcn = $result->modelClass($className);
        $result->files[$modelPath . $className . '.php'] = $this->renderModel($result, $className, $description, $properties);

        $mapping = [];
        if ($table !== $this->getConventionalTable($fqcn)) {
            $mapping['tableName'] = $table;
        }
        if ($recordType !== null) {
            $mapping['recordType'] = $recordType;
        }
        foreach ($properties as $property) {
            if ($property->column !== GeneralUtility::camelCaseToLowerCaseUnderscored($property->name)) {
                $mapping['properties'][$property->name] = ['fieldName' => $property->column];
            }
        }
        if ($mapping !== []) {
            $result->mappings[$fqcn] = $mapping;
        }
    }

    private function createProperty(GeneratedModel $result, string $contentBlockName, TableDefinitionCollection $tables, string $modelPath, string $className, TcaFieldDefinition $column): ?ModelProperty
    {
        $name = lcfirst($this->toClassName($column->identifier));
        $config = $column->getTca()['config'] ?? [];
        $scalar = static fn(string $type, string $default, string $comment = ''): ModelProperty => ModelProperty::scalar($name, $column->uniqueIdentifier, $type, $default, $comment);

        return match ($column->fieldType->getName()) {
            'Number' => ($config['format'] ?? '') === 'decimal' ? $scalar('float', '0.0') : $scalar('int', '0'),
            'Checkbox' => count($config['items'] ?? []) > 1 ? $scalar('int', '0', 'bit mask of the checked items') : $scalar('bool', 'false'),
            'DateTime' => $scalar('?\\DateTime', 'null'),
            'SelectNumber', 'Language' => $scalar('int', '0'),
            'File' => RelationshipType::fromTcaConfiguration($config)->hasOne()
                ? ModelProperty::object($name, $column->uniqueIdentifier, FileReference::class)
                : ModelProperty::storage($name, $column->uniqueIdentifier, FileReference::class),
            'Category' => ModelProperty::storage($name, $column->uniqueIdentifier, Category::class),
            'Collection' => $this->createCollectionProperty($result, $contentBlockName, $tables, $modelPath, $className, $column, $config, $name),
            'Relation' => $this->createRelationProperty($result, $tables, $column, $config, $name),
            'Palette', 'Tab', 'Linebreak', 'Pass' => null,
            default => $scalar('string', "''"),
        };
    }

    /**
     * A Collection of this Content Block gets a model of its own, e.g. "ProjectSection" for the field "sections".
     */
    private function createCollectionProperty(GeneratedModel $result, string $contentBlockName, TableDefinitionCollection $tables, string $modelPath, string $className, TcaFieldDefinition $column, array $config, string $name): ModelProperty
    {
        $childTable = (string) ($config['foreign_table'] ?? '');
        if (!$tables->hasTable($childTable)) {
            return ModelProperty::scalar($name, $column->uniqueIdentifier, 'int', '0', 'number of related ' . $childTable . ' records');
        }
        $childClassName = $className . $this->toSingular($this->toClassName($column->identifier));
        $description = sprintf('Model of the Collection "%s" of the Content Block "%s"', $column->identifier, $contentBlockName);
        $this->addModel($result, $contentBlockName, $tables, $modelPath, $childClassName, $childTable, $tables->getTable($childTable)->getDefaultTypeDefinition()->getOverrideColumns(), $description);
        return ModelProperty::storage($name, $column->uniqueIdentifier, $result->modelClass($childClassName));
    }

    /**
     * A Relation to a Content Block of this extension points to its model; others keep the uid(s).
     */
    private function createRelationProperty(GeneratedModel $result, TableDefinitionCollection $tables, TcaFieldDefinition $column, array $config, string $name): ModelProperty
    {
        $relatedTables = GeneralUtility::trimExplode(',', (string) ($config['allowed'] ?? $config['foreign_table'] ?? ''), true);
        $single = RelationshipType::fromTcaConfiguration($config)->hasOne();
        $relatedClass = count($relatedTables) === 1 ? $this->findModelClass($result, $tables, $relatedTables[0]) : null;
        if ($relatedClass === null) {
            return $single
                ? ModelProperty::scalar($name, $column->uniqueIdentifier, 'int', '0', 'uid of a ' . implode('/', $relatedTables) . ' record')
                : ModelProperty::scalar($name, $column->uniqueIdentifier, 'string', "''", 'comma-separated uids of ' . implode('/', $relatedTables) . ' records');
        }
        return $single
            ? ModelProperty::object($name, $column->uniqueIdentifier, $relatedClass)
            : ModelProperty::storage($name, $column->uniqueIdentifier, $relatedClass);
    }

    /**
     * The model a Content Block record type of the same extension would get with this generator; none for tables
     * shared by several types.
     */
    private function findModelClass(GeneratedModel $result, TableDefinitionCollection $tables, string $table): ?string
    {
        if (!$tables->hasTable($table) || $tables->getTable($table)->hasTypeField()) {
            return null;
        }
        return $result->modelClass($this->toClassName($tables->getTable($table)->getDefaultTypeDefinition()->getPackage()));
    }

    /**
     * @param list<ModelProperty> $properties
     */
    private function renderModel(GeneratedModel $result, string $className, string $description, array $properties): string
    {
        $imports = array_filter(
            array_merge(['TYPO3\\CMS\\Extbase\\DomainObject\\AbstractEntity'], ...array_map(static fn(ModelProperty $property): array => $property->getImports(), $properties)),
            static fn(string $import): bool => !str_starts_with($import, $result->modelClass('')),
        );
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
            if ($property->isObjectStorage) {
                $doc[] = '@var ObjectStorage<' . $property->getStorageOf() . '>';
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
        $sections = [implode("\n", $declarations)];
        if ($storages !== []) {
            $sections[] = "    public function __construct()\n    {\n" . implode("\n", $storages) . "\n    }";
        }
        if ($accessors !== []) {
            $sections[] = implode("\n\n", $accessors);
        }
        $body = implode("\n\n", $sections) . "\n";
        $uses = implode("\n", array_map(static fn(string $import): string => 'use ' . $import . ';', $imports));

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$result->namespace}\\Domain\\Model;

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
    private function getNamespace(PackageInterface $package): string
    {
        $autoload = (array) ($package->getValueFromComposerManifest('autoload') ?? []);
        foreach ((array) ($autoload['psr-4'] ?? []) as $namespace => $path) {
            if (trim((string) $path, '/') === 'Classes') {
                return rtrim((string) $namespace, '\\');
            }
        }
        throw new \RuntimeException(sprintf('The composer.json of "%s" has no PSR-4 namespace for "Classes/".', $package->getPackageKey()), 1758700053);
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
}
