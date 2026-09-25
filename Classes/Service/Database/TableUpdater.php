<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\Database;

use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;

/**
 * Creates a table or adds its missing columns, like extension:setup does for the whole database – limited to one
 * table and to additions, so that no pending change of another extension is applied on the way.
 */
final readonly class TableUpdater
{
    public function __construct(
        private SqlReader $sqlReader,
        private SchemaMigrator $schemaMigrator,
    ) {
    }

    public function addMissing(string $table): void
    {
        $statements = $this->sqlReader->getCreateTableStatementArray($this->sqlReader->getTablesDefinitionString());
        $suggestions = array_merge_recursive(...array_values($this->schemaMigrator->getUpdateSuggestions($statements)));
        $selected = [];
        foreach (['create_table', 'add'] as $action) {
            foreach ($suggestions[$action] ?? [] as $hash => $statement) {
                if (preg_match('/^(CREATE|ALTER) TABLE `?' . preg_quote($table, '/') . '`?\s/i', (string) $statement)) {
                    $selected[$hash] = true;
                }
            }
        }
        $errors = $this->schemaMigrator->migrate($statements, $selected);
        if ($errors !== []) {
            throw new \RuntimeException(implode(' ', $errors), 1758700020);
        }
    }
}
