<?php

declare(strict_types=1);

namespace IzzuddinMohsin\NeuronSQLServer;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PDO;
use ReflectionException;

use function implode;
use function preg_match;
use function preg_quote;

/**
 * Write operation tool for SQL Server databases.
 *
 * Allows AI agents to execute INSERT, UPDATE, DELETE, and MERGE operations
 * with positional parameters. Includes security validation to prevent
 * dangerous DDL and system operations.
 *
 * @method static static make(PDO $pdo)
 */
class SQLServerWriteTool extends Tool
{
    public function __construct(
        protected PDO $pdo,
        protected array $forbiddenStatements = [
            'DROP', 'CREATE', 'ALTER', 'GRANT', 'REVOKE', 'TRUNCATE',
            'EXEC', 'EXECUTE', 'sp_executesql', 'xp_cmdshell',
            'OPENROWSET', 'OPENDATASOURCE', 'BULK',
        ]
    ) {
        parent::__construct(
            'sqlserver_write_query',
            'Use this tool to perform write operations against the SQL Server database (e.g. INSERT, UPDATE, DELETE, MERGE).
Note: Use positional placeholders (?) for parameters instead of named parameters due to SQL Server PDO driver limitations.
For identity columns, use SCOPE_IDENTITY() or @@IDENTITY to get the last inserted ID.
Do NOT use this tool on views — views are read-only.'
        );
    }

    /**
     * @throws ReflectionException
     * @throws ArrayPropertyException
     * @throws ToolException
     */
    protected function properties(): array
    {
        return [
            new ToolProperty(
                'query',
                PropertyType::STRING,
                'The parameterized SQL write query using positional placeholders (?). Examples:
                - INSERT: "INSERT INTO dbo.users (name, email) VALUES (?, ?)"
                - UPDATE: "UPDATE dbo.users SET name = ?, email = ? WHERE id = ?"
                - DELETE: "DELETE FROM dbo.users WHERE id = ?"
                - MERGE: "MERGE INTO dbo.target USING dbo.source ON ..."
                Use positional parameters (?) for all dynamic values in the order they appear.',
                true
            ),
            new ArrayProperty(
                'parameters',
                'Array of parameter values in the exact order they appear in the query. Example: ["John Doe", "john@example.com", 123]. The order must match the ? placeholders. Leave empty if no parameters are needed.',
                false,
                items: new ToolProperty(
                    name: 'value',
                    type: PropertyType::STRING,
                    description: 'Parameter value',
                    required: true
                )
            ),
        ];
    }

    /**
     * Execute a write operation against SQL Server.
     *
     * @param string       $query      The parameterized write query
     * @param array<string>|null $parameters Positional parameter values
     * @return string Result message or error
     */
    public function __invoke(string $query, ?array $parameters = []): string
    {
        if (!$this->validate($query)) {
            return "The query was rejected for security reasons: it contains forbidden statements ("
                . implode(', ', $this->forbiddenStatements) . ").";
        }

        try {
            $statement = $this->pdo->prepare($query);

            // Bind positional parameters
            $parameters ??= [];
            foreach ($parameters as $index => $value) {
                // PDO uses 1-based indexing for positional parameters
                $statement->bindValue($index + 1, $value);
            }

            $result = $statement->execute();

            if (!$result) {
                $errorInfo = $statement->errorInfo();
                return "Error executing query: " . ($errorInfo[2] ?? 'Unknown database error');
            }

            // Get the number of affected rows for feedback
            $rowCount = $statement->rowCount();

            // For INSERT operations, also return the last insert ID if available
            if (preg_match('/^\s*INSERT/i', $query)) {
                try {
                    // SQL Server uses SCOPE_IDENTITY() for the last inserted identity value
                    $idStmt = $this->pdo->query('SELECT SCOPE_IDENTITY() as last_id');
                    $lastId = $idStmt->fetchColumn();

                    if ($lastId && $lastId > 0) {
                        return "Query executed successfully. {$rowCount} row(s) affected. Last insert ID: {$lastId}";
                    }
                } catch (\PDOException $e) {
                    // If we can't get the ID (e.g., table has no IDENTITY column), just report success
                }
            }

            return "Query executed successfully. {$rowCount} row(s) affected.";
        } catch (\PDOException $e) {
            return "Error executing query: " . $e->getMessage();
        }
    }

    protected function validate(string $query): bool
    {
        // Check for forbidden keywords
        foreach ($this->forbiddenStatements as $forbidden) {
            if ($this->containsKeyword($query, $forbidden)) {
                return false;
            }
        }

        // Ensure it's a write operation (INSERT, UPDATE, DELETE, MERGE)
        if (!preg_match('/^\s*(INSERT|UPDATE|DELETE|MERGE)\b/i', $query)) {
            return false;
        }

        return true;
    }

    protected function containsKeyword(string $query, string $keyword): bool
    {
        // Use word boundaries to avoid false positives
        return preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $query) === 1;
    }
}