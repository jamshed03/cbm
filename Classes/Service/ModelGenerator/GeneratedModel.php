<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\ModelGenerator;

use TYPO3\CMS\Core\Utility\ArrayUtility;

/**
 * The files ModelGenerator produced, and the persistence mapping Extbase needs for them.
 */
final class GeneratedModel
{
    /**
     * @var array<string, string> PHP source by absolute file path
     */
    public array $files = [];

    /**
     * @var array<string, array<string, mixed>> Extbase persistence mapping by model class, only where conventions don't fit
     */
    public array $mappings = [];

    public function __construct(
        public readonly string $namespace,
        public readonly string $persistenceFile,
    ) {
    }

    /**
     * The fully qualified name of the model class $className, e.g. "Project" or "ProjectSection".
     */
    public function modelClass(string $className): string
    {
        return $this->namespace . '\\Domain\\Model\\' . $className;
    }

    /**
     * The entries for Configuration/Extbase/Persistence/Classes.php.
     */
    public function renderMappingEntries(): string
    {
        $entries = '';
        foreach ($this->mappings as $class => $mapping) {
            $entries .= '    \\' . $class . '::class => ' . ArrayUtility::arrayExport($mapping, 1);
        }
        return rtrim($entries);
    }

    public function renderPersistenceFile(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n" . $this->renderMappingEntries() . "\n];\n";
    }
}
