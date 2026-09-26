<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\ModelGenerator;

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
     * @var array<string, array<string, mixed>> Extbase persistence mapping by model class; empty where conventions fit
     */
    public array $mappings = [];

    public function __construct(
        public readonly string $modelPath,
        public readonly string $namespace,
        public readonly string $persistenceFile,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getNeededMappings(): array
    {
        return array_filter($this->mappings);
    }

    /**
     * The entries for Configuration/Extbase/Persistence/Classes.php.
     */
    public function renderMappingEntries(string $indent = '    '): string
    {
        $lines = [];
        foreach ($this->getNeededMappings() as $class => $mapping) {
            $lines[] = $indent . '\\' . $class . '::class => ' . $this->export($mapping, $indent) . ',';
        }
        return implode("\n", $lines);
    }

    public function renderPersistenceFile(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n" . $this->renderMappingEntries() . "\n];\n";
    }

    private function export(array $value, string $indent): string
    {
        $lines = [];
        foreach ($value as $key => $item) {
            $lines[] = $indent . '    ' . var_export($key, true) . ' => ' . (is_array($item) ? $this->export($item, $indent . '    ') : var_export($item, true)) . ',';
        }
        return "[\n" . implode("\n", $lines) . "\n" . $indent . ']';
    }
}
