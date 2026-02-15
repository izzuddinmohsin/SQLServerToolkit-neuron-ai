<?php

declare(strict_types=1);

namespace IzzuddinMohsin\NeuronSQLServer;

use NeuronAI\Tools\Tool;
use PDO;

use function array_merge;
use function array_values;
use function count;
use function implode;
use function in_array;
use function str_contains;
use function str_repeat;
use function strtolower;

/**
 * Discovers SQL Server database schema including tables, views, columns,
 * relationships, indexes, and constraints. Provides rich context for LLM
 * to generate accurate SQL Server queries.
 *
 * @method static static make(PDO $pdo, ?array $tables = null)
 */
class SQLServerSchemaTool extends Tool
{
    /**
     * @param PDO        $pdo    PDO connection to SQL Server database
     * @param array|null $tables Optional list of table/view names to limit discovery
     */
    public function __construct(
        protected PDO $pdo,
        protected ?array $tables = null,
    ) {
        parent::__construct(
            'analyze_sqlserver_database_schema',
            'Retrieves SQL Server database schema information including tables, views, columns, relationships, and indexes.
            Use this tool first to understand the database structure before writing any SQL queries.
            Essential for generating accurate queries with proper table/column names, JOIN conditions,
            and performance optimization. DO NOT call this tool if you already have database schema information in the context.'
        );
    }

    public function __invoke(): string
    {
        return $this->formatForLLM([
            'tables' => $this->getTables(),
            'relationships' => $this->getRelationships(),
            'indexes' => $this->getIndexes(),
            'constraints' => $this->getConstraints(),
        ]);
    }

    protected function formatForLLM(array $structure): string
    {
        $output = "# SQL Server Database Schema Analysis\n\n";
        $output .= "This database contains " . count($structure['tables']) . " tables/views with the following structure:\n\n";

        // Tables overview
        $output .= "## Tables Overview\n";
        foreach ($structure['tables'] as $table) {
            $pkColumns = empty($table['primary_key']) ? 'None' : implode(', ', $table['primary_key']);
            $typeLabel = $table['type'] === 'VIEW' ? ' (VIEW)' : '';
            $output .= "- **{$table['schema']}.{$table['name']}**{$typeLabel}: {$table['estimated_rows']} rows, Primary Key: {$pkColumns}";
            if ($table['comment']) {
                $output .= " - {$table['comment']}";
            }
            $output .= "\n";
        }
        $output .= "\n";

        // Detailed table structures
        $output .= "## Detailed Table Structures\n\n";
        foreach ($structure['tables'] as $table) {
            $typeLabel = $table['type'] === 'VIEW' ? ' (VIEW - read only)' : '';
            $output .= "### Table: `{$table['schema']}.{$table['name']}`{$typeLabel}\n";
            if ($table['comment']) {
                $output .= "**Description**: {$table['comment']}\n";
            }
            $output .= "**Estimated Rows**: {$table['estimated_rows']}\n\n";

            $output .= "**Columns**:\n";
            foreach ($table['columns'] as $column) {
                $nullable = $column['nullable'] ? 'NULL' : 'NOT NULL';
                $identity = $column['is_identity'] ? ' IDENTITY' : '';
                $default = $column['default'] !== null ? " DEFAULT {$column['default']}" : '';

                $output .= "- `{$column['name']}` {$column['full_type']} {$nullable}{$default}{$identity}";
                if ($column['comment']) {
                    $output .= " - {$column['comment']}";
                }
                $output .= "\n";
            }

            if (!empty($table['primary_key'])) {
                $output .= "\n**Primary Key**: " . implode(', ', $table['primary_key']) . "\n";
            }

            if (!empty($table['unique_keys'])) {
                $output .= "**Unique Keys**: " . implode(', ', $table['unique_keys']) . "\n";
            }

            $output .= "\n";
        }

        // Relationships
        if (!empty($structure['relationships'])) {
            $output .= "## Foreign Key Relationships\n\n";
            $output .= "Understanding these relationships is crucial for JOIN operations:\n\n";

            foreach ($structure['relationships'] as $rel) {
                $output .= "- `{$rel['source_schema']}.{$rel['source_table']}.{$rel['source_column']}` → `{$rel['target_schema']}.{$rel['target_table']}.{$rel['target_column']}`";
                $output .= " (ON DELETE {$rel['delete_rule']}, ON UPDATE {$rel['update_rule']})\n";
            }
            $output .= "\n";
        }

        // Indexes for query optimization
        if (!empty($structure['indexes'])) {
            $output .= "## Available Indexes (for Query Optimization)\n\n";
            $output .= "These indexes can significantly improve query performance:\n\n";

            foreach ($structure['indexes'] as $index) {
                $unique = $index['is_unique'] ? 'UNIQUE ' : '';
                $clustered = $index['type_desc'] === 'CLUSTERED' ? 'CLUSTERED ' : '';
                $columns = implode(', ', $index['columns']);
                $output .= "- {$unique}{$clustered}INDEX `{$index['name']}` on `{$index['schema']}.{$index['table']}` ({$columns})\n";
            }
            $output .= "\n";
        }

        // Query generation guidelines
        $output .= "## SQL Server Query Generation Guidelines\n\n";
        $output .= "**Best Practices for this database**:\n";
        $output .= "1. Always use schema-qualified table names (e.g., dbo.TableName)\n";
        $output .= "2. Use table aliases for better readability\n";
        $output .= "3. Prefer indexed columns in WHERE clauses for better performance\n";
        $output .= "4. Use appropriate JOINs based on the foreign key relationships listed above\n";
        $output .= "5. Consider the estimated row counts when writing queries - larger tables may need TOP clauses\n";
        $output .= "6. Pay attention to nullable columns when using comparison operators\n";
        $output .= "7. Use parameterized queries with positional placeholders (?) to prevent SQL injection\n";
        $output .= "8. Views are read-only — do not attempt INSERT, UPDATE, or DELETE on views\n\n";

        // Common patterns
        $output .= "**Common Query Patterns**:\n";
        $this->addCommonPatterns($output, $structure['tables']);

        return $output;
    }

    protected function getTables(): array
    {
        $whereClause = "WHERE t.TABLE_TYPE IN ('BASE TABLE', 'VIEW')";
        $params = [];

        // Add table filtering if specific tables are requested
        if ($this->tables !== null && $this->tables !== []) {
            $placeholders = str_repeat('?,', count($this->tables) - 1) . '?';
            $whereClause .= " AND t.TABLE_NAME IN ($placeholders)";
            $params = $this->tables;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                t.TABLE_SCHEMA,
                t.TABLE_NAME,
                t.TABLE_TYPE,
                c.COLUMN_NAME,
                c.ORDINAL_POSITION,
                c.COLUMN_DEFAULT,
                c.IS_NULLABLE,
                c.DATA_TYPE,
                c.CHARACTER_MAXIMUM_LENGTH,
                c.NUMERIC_PRECISION,
                c.NUMERIC_SCALE,
                COLUMNPROPERTY(OBJECT_ID(t.TABLE_SCHEMA + '.' + t.TABLE_NAME), c.COLUMN_NAME, 'IsIdentity') as IS_IDENTITY,
                CASE
                    WHEN pk.COLUMN_NAME IS NOT NULL THEN 'PRI'
                    WHEN uq.COLUMN_NAME IS NOT NULL THEN 'UNI'
                    WHEN idx.COLUMN_NAME IS NOT NULL THEN 'MUL'
                    ELSE NULL
                END as COLUMN_KEY,
                ep.value as COLUMN_COMMENT,
                tep.value as TABLE_COMMENT,
                p.total_rows as TABLE_ROWS
            FROM INFORMATION_SCHEMA.TABLES t
            LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
                ON t.TABLE_NAME = c.TABLE_NAME
                AND t.TABLE_SCHEMA = c.TABLE_SCHEMA
            LEFT JOIN (
                SELECT ku.TABLE_SCHEMA, ku.TABLE_NAME, ku.COLUMN_NAME
                FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE ku
                    ON tc.CONSTRAINT_NAME = ku.CONSTRAINT_NAME
                    AND tc.TABLE_SCHEMA = ku.TABLE_SCHEMA
                WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
            ) pk ON c.TABLE_SCHEMA = pk.TABLE_SCHEMA
                AND c.TABLE_NAME = pk.TABLE_NAME
                AND c.COLUMN_NAME = pk.COLUMN_NAME
            LEFT JOIN (
                SELECT ku.TABLE_SCHEMA, ku.TABLE_NAME, ku.COLUMN_NAME
                FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE ku
                    ON tc.CONSTRAINT_NAME = ku.CONSTRAINT_NAME
                    AND tc.TABLE_SCHEMA = ku.TABLE_SCHEMA
                WHERE tc.CONSTRAINT_TYPE = 'UNIQUE'
            ) uq ON c.TABLE_SCHEMA = uq.TABLE_SCHEMA
                AND c.TABLE_NAME = uq.TABLE_NAME
                AND c.COLUMN_NAME = uq.COLUMN_NAME
            LEFT JOIN (
                SELECT DISTINCT
                    SCHEMA_NAME(o.schema_id) as TABLE_SCHEMA,
                    o.name as TABLE_NAME,
                    col.name as COLUMN_NAME
                FROM sys.indexes i
                JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
                JOIN sys.columns col ON ic.object_id = col.object_id AND ic.column_id = col.column_id
                JOIN sys.objects o ON i.object_id = o.object_id
                WHERE i.is_primary_key = 0 AND i.is_unique_constraint = 0
            ) idx ON c.TABLE_SCHEMA = idx.TABLE_SCHEMA
                AND c.TABLE_NAME = idx.TABLE_NAME
                AND c.COLUMN_NAME = idx.COLUMN_NAME
            LEFT JOIN sys.extended_properties ep
                ON ep.major_id = OBJECT_ID(t.TABLE_SCHEMA + '.' + t.TABLE_NAME)
                AND ep.minor_id = COLUMNPROPERTY(OBJECT_ID(t.TABLE_SCHEMA + '.' + t.TABLE_NAME), c.COLUMN_NAME, 'ColumnId')
                AND ep.name = 'MS_Description'
            LEFT JOIN sys.extended_properties tep
                ON tep.major_id = OBJECT_ID(t.TABLE_SCHEMA + '.' + t.TABLE_NAME)
                AND tep.minor_id = 0
                AND tep.name = 'MS_Description'
            LEFT JOIN (
                SELECT object_id, SUM(rows) as total_rows
                FROM sys.partitions
                WHERE index_id IN (0, 1)
                GROUP BY object_id
            ) p ON p.object_id = OBJECT_ID(t.TABLE_SCHEMA + '.' + t.TABLE_NAME)
            $whereClause
            ORDER BY t.TABLE_SCHEMA, t.TABLE_NAME, c.ORDINAL_POSITION
        ");

        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tables = [];
        foreach ($results as $row) {
            $tableKey = $row['TABLE_SCHEMA'] . '.' . $row['TABLE_NAME'];

            if (!isset($tables[$tableKey])) {
                $tables[$tableKey] = [
                    'schema' => $row['TABLE_SCHEMA'],
                    'name' => $row['TABLE_NAME'],
                    'type' => $row['TABLE_TYPE'] === 'VIEW' ? 'VIEW' : 'TABLE',
                    'estimated_rows' => $row['TABLE_ROWS'] ?? 0,
                    'comment' => $row['TABLE_COMMENT'] ?? '',
                    'columns' => [],
                    'primary_key' => [],
                    'unique_keys' => [],
                    'indexes' => [],
                ];
            }

            if ($row['COLUMN_NAME']) {
                // Build full type string
                $fullType = $row['DATA_TYPE'];
                if ($row['CHARACTER_MAXIMUM_LENGTH']) {
                    $length = $row['CHARACTER_MAXIMUM_LENGTH'] == -1 ? 'MAX' : $row['CHARACTER_MAXIMUM_LENGTH'];
                    $fullType .= "($length)";
                } elseif ($row['NUMERIC_PRECISION']) {
                    $fullType .= "({$row['NUMERIC_PRECISION']}";
                    if ($row['NUMERIC_SCALE']) {
                        $fullType .= ",{$row['NUMERIC_SCALE']}";
                    }
                    $fullType .= ")";
                }

                $column = [
                    'name' => $row['COLUMN_NAME'],
                    'type' => $row['DATA_TYPE'],
                    'full_type' => $fullType,
                    'nullable' => $row['IS_NULLABLE'] === 'YES',
                    'default' => $row['COLUMN_DEFAULT'],
                    'is_identity' => $row['IS_IDENTITY'] == 1,
                    'comment' => $row['COLUMN_COMMENT'] ?? '',
                ];

                // Add length/precision info for better LLM understanding
                if ($row['CHARACTER_MAXIMUM_LENGTH']) {
                    $column['max_length'] = $row['CHARACTER_MAXIMUM_LENGTH'];
                }
                if ($row['NUMERIC_PRECISION']) {
                    $column['precision'] = $row['NUMERIC_PRECISION'];
                    $column['scale'] = $row['NUMERIC_SCALE'];
                }

                $tables[$tableKey]['columns'][] = $column;

                // Track key information
                if ($row['COLUMN_KEY'] === 'PRI') {
                    $tables[$tableKey]['primary_key'][] = $row['COLUMN_NAME'];
                } elseif ($row['COLUMN_KEY'] === 'UNI') {
                    $tables[$tableKey]['unique_keys'][] = $row['COLUMN_NAME'];
                } elseif ($row['COLUMN_KEY'] === 'MUL') {
                    $tables[$tableKey]['indexes'][] = $row['COLUMN_NAME'];
                }
            }
        }

        return $tables;
    }

    protected function getRelationships(): array
    {
        $whereClause = "WHERE 1=1";
        $params = [];

        // Add table filtering if specific tables are requested
        if ($this->tables !== null && $this->tables !== []) {
            $placeholders = str_repeat('?,', count($this->tables) - 1) . '?';
            $whereClause .= " AND (OBJECT_NAME(fk.parent_object_id) IN ($placeholders) OR OBJECT_NAME(fk.referenced_object_id) IN ($placeholders))";
            $params = array_merge($this->tables, $this->tables);
        }

        $stmt = $this->pdo->prepare("
            SELECT
                fk.name as constraint_name,
                OBJECT_SCHEMA_NAME(fk.parent_object_id) as source_schema,
                OBJECT_NAME(fk.parent_object_id) as source_table,
                COL_NAME(fkc.parent_object_id, fkc.parent_column_id) as source_column,
                OBJECT_SCHEMA_NAME(fk.referenced_object_id) as target_schema,
                OBJECT_NAME(fk.referenced_object_id) as target_table,
                COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) as target_column,
                CASE fk.update_referential_action
                    WHEN 0 THEN 'NO ACTION'
                    WHEN 1 THEN 'CASCADE'
                    WHEN 2 THEN 'SET NULL'
                    WHEN 3 THEN 'SET DEFAULT'
                END as update_rule,
                CASE fk.delete_referential_action
                    WHEN 0 THEN 'NO ACTION'
                    WHEN 1 THEN 'CASCADE'
                    WHEN 2 THEN 'SET NULL'
                    WHEN 3 THEN 'SET DEFAULT'
                END as delete_rule
            FROM sys.foreign_keys fk
            INNER JOIN sys.foreign_key_columns fkc
                ON fk.object_id = fkc.constraint_object_id
            $whereClause
            ORDER BY source_schema, source_table, fkc.constraint_column_id
        ");

        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function getIndexes(): array
    {
        $whereClause = "WHERE i.is_primary_key = 0
            AND i.is_unique_constraint = 0
            AND o.type = 'U'";
        $params = [];

        // Filter indexes by table list if specified
        if ($this->tables !== null && $this->tables !== []) {
            $placeholders = str_repeat('?,', count($this->tables) - 1) . '?';
            $whereClause .= " AND o.name IN ($placeholders)";
            $params = $this->tables;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                SCHEMA_NAME(o.schema_id) as TABLE_SCHEMA,
                o.name as TABLE_NAME,
                i.name as INDEX_NAME,
                i.type_desc,
                i.is_unique,
                col.name as COLUMN_NAME,
                ic.key_ordinal as SEQ_IN_INDEX
            FROM sys.indexes i
            INNER JOIN sys.index_columns ic
                ON i.object_id = ic.object_id
                AND i.index_id = ic.index_id
            INNER JOIN sys.columns col
                ON ic.object_id = col.object_id
                AND ic.column_id = col.column_id
            INNER JOIN sys.objects o
                ON i.object_id = o.object_id
            $whereClause
            ORDER BY o.name, i.name, ic.key_ordinal
        ");

        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $indexes = [];
        foreach ($results as $row) {
            $key = $row['TABLE_SCHEMA'] . '.' . $row['TABLE_NAME'] . '.' . $row['INDEX_NAME'];
            if (!isset($indexes[$key])) {
                $indexes[$key] = [
                    'schema' => $row['TABLE_SCHEMA'],
                    'table' => $row['TABLE_NAME'],
                    'name' => $row['INDEX_NAME'],
                    'is_unique' => $row['is_unique'] == 1,
                    'type_desc' => $row['type_desc'],
                    'columns' => [],
                ];
            }
            $indexes[$key]['columns'][] = $row['COLUMN_NAME'];
        }

        return array_values($indexes);
    }

    protected function getConstraints(): array
    {
        $whereClause = "WHERE CONSTRAINT_TYPE IN ('UNIQUE', 'CHECK')";
        $params = [];

        // Add table filtering if specific tables are requested
        if ($this->tables !== null && $this->tables !== []) {
            $placeholders = str_repeat('?,', count($this->tables) - 1) . '?';
            $whereClause .= " AND TABLE_NAME IN ($placeholders)";
            $params = $this->tables;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                TABLE_SCHEMA,
                CONSTRAINT_NAME,
                TABLE_NAME,
                CONSTRAINT_TYPE
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            $whereClause
        ");

        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function addCommonPatterns(string &$output, array $tables): void
    {
        // Find tables with timestamps for temporal queries
        foreach ($tables as $table) {
            foreach ($table['columns'] as $column) {
                if (in_array($column['type'], ['datetime', 'datetime2', 'smalldatetime', 'date', 'datetimeoffset']) &&
                    (str_contains(strtolower((string) $column['name']), 'created') ||
                        str_contains(strtolower((string) $column['name']), 'updated') ||
                        str_contains(strtolower((string) $column['name']), 'modified'))) {
                    $output .= "- For temporal queries on `{$table['schema']}.{$table['name']}`, use `{$column['name']}` column\n";
                    break;
                }
            }
        }

        // Find potential text search columns
        foreach ($tables as $table) {
            foreach ($table['columns'] as $column) {
                if (in_array($column['type'], ['varchar', 'nvarchar', 'text', 'ntext']) &&
                    (str_contains(strtolower((string) $column['name']), 'name') ||
                        str_contains(strtolower((string) $column['name']), 'title') ||
                        str_contains(strtolower((string) $column['name']), 'description'))) {
                    $output .= "- For text searches on `{$table['schema']}.{$table['name']}`, consider using `{$column['name']}` with LIKE or CONTAINS\n";
                    break;
                }
            }
        }

        $output .= "\n";
    }
}