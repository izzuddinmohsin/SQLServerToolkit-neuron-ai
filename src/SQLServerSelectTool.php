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

use function preg_match;
use function preg_quote;
use function preg_replace;
use function strtoupper;
use function trim;

/**
 * Read-only query tool for SQL Server databases.
 *
 * Allows AI agents to execute SELECT queries with positional parameters.
 * Includes security validation to prevent write operations through the read tool.
 *
 * @method static static make(PDO $pdo)
 */
class SQLServerSelectTool extends Tool
{
    protected array $forbiddenStatements = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
        'TRUNCATE', 'MERGE', 'EXEC', 'EXECUTE', 'sp_executesql',
        'xp_cmdshell', 'OPENROWSET', 'OPENDATASOURCE', 'BULK',
        'INTO',
    ];

    public function __construct(protected PDO $pdo)
    {
        parent::__construct(
            'sqlserver_select_query',
            'Use this tool only to run SELECT queries against the SQL Server database.
This is the tool to use only to gather information from the SQL Server database.
Note: SQL Server uses TOP instead of LIMIT for row limiting (e.g., SELECT TOP 10 * FROM table).

IMPORTANT: This tool returns an array of results. An empty array [] means no matching records were found,
which is a valid result - DO NOT retry the query if you get an empty array.'
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
                name: 'query',
                type: PropertyType::STRING,
                description: 'The parameterized SELECT query. Use positional placeholders (?) for parameters due to SQL Server PDO driver limitations. Example: "SELECT name, email FROM dbo.users WHERE id = ? AND status = ?". All dynamic values must use positional parameters.',
                required: true
            ),
            new ArrayProperty(
                name: 'parameters',
                description: 'Array of parameter values in the exact order they appear in the query. Example: [123, "active"]. The order must match the ? placeholders in the query. Leave empty if no parameters are needed.',
                required: false,
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
     * Execute a read-only SELECT query against SQL Server.
     *
     * @param string       $query      The parameterized SELECT query
     * @param array<string>|null $parameters Positional parameter values
     * @return string|array Query results or error message
     */
    public function __invoke(string $query, ?array $parameters = []): string|array
    {
        if (!$this->validateReadOnly($query)) {
            return "The query was rejected for security reasons. "
                . "It looks like you are trying to run a write query or dangerous operation using the read-only query tool.";
        }

        try {
            $statement = $this->pdo->prepare($query);

            // Bind positional parameters
            $parameters ??= [];
            foreach ($parameters as $index => $value) {
                // PDO uses 1-based indexing for positional parameters
                $statement->bindValue($index + 1, $value);
            }

            $statement->execute();

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return "Error executing query: " . $e->getMessage();
        }
    }

    protected function validateReadOnly(string $query): bool
    {
        // Remove comments and normalize whitespace
        $cleanQuery = $this->sanitizeQuery($query);

        // Check if it starts with allowed statements (SELECT or WITH for CTEs)
        $firstKeyword = $this->getFirstKeyword($cleanQuery);
        if ($firstKeyword !== 'SELECT' && $firstKeyword !== 'WITH') {
            return false;
        }

        // Check for forbidden keywords that might be in subqueries or main query
        foreach ($this->forbiddenStatements as $forbidden) {
            if ($this->containsKeyword($cleanQuery, $forbidden)) {
                return false;
            }
        }

        return true;
    }

    protected function sanitizeQuery(string $query): string
    {
        // Remove SQL comments (both -- and /* */ style)
        $query = preg_replace('/--.*$/m', '', $query);
        $query = preg_replace('/\/\*.*?\*\//s', '', (string) $query);

        // Normalize whitespace
        return preg_replace('/\s+/', ' ', trim((string) $query));
    }

    protected function getFirstKeyword(string $query): string
    {
        if (preg_match('/^\s*(\w+)/i', $query, $matches)) {
            return strtoupper($matches[1]);
        }
        return '';
    }

    protected function containsKeyword(string $query, string $keyword): bool
    {
        // Use word boundaries to avoid false positives
        return preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $query) === 1;
    }
}