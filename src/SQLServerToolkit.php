<?php

declare(strict_types=1);

namespace IzzuddinMohsin\NeuronSQLServer;

use NeuronAI\Tools\Toolkits\AbstractToolkit;
use PDO;

/**
 * SQL Server / Azure SQL Database Toolkit for Neuron AI.
 *
 * Provides schema discovery, read queries, and write operations
 * for SQL Server databases through AI agent tool calls.
 *
 * @method static static make(PDO $pdo, ?array $tables = null)
 */
class SQLServerToolkit extends AbstractToolkit
{
    /**
     * @param PDO        $pdo    PDO connection to SQL Server database
     * @param array|null $tables Optional list of table/view names to limit schema discovery.
     *                           Pass null to discover all tables and views.
     *                           Example: ['users', 'orders'] or ['view_active_users']
     */
    public function __construct(
        protected PDO $pdo,
        protected ?array $tables = null,
    ) {
    }

    public function guidelines(): ?string
    {
        return "These tools allow you to learn the SQL Server database structure,
        getting detailed information about tables, views, columns, relationships, and constraints
        to generate and execute precise and efficient SQL queries for SQL Server / Azure SQL database.

        CRITICAL QUERY EXECUTION GUIDELINES:
        - The SELECT tool returns an array of results (can be empty array if no matches)
        - An empty array [] is a VALID result meaning no matching records exist
        - DO NOT retry queries when you get an empty array - accept it as the final answer
        - Only retry if you get an actual error message in the result
        - If a table genuinely has no data matching your criteria, inform the user directly

        Important notes for SQL Server:
        - Always use schema-qualified table names (e.g., dbo.TableName)
        - Use TOP instead of LIMIT for row limiting (e.g., SELECT TOP 10 * FROM table)
        - Use positional parameters (?) instead of named parameters (:name) due to PDO driver limitations
        - Be aware of IDENTITY columns (SQL Server's equivalent of AUTO_INCREMENT)
        - Use SCOPE_IDENTITY() to get the last inserted identity value
        - String comparisons are case-insensitive by default (depends on collation)
        - Use GETDATE() for current datetime, not NOW()
        - Use LEN() instead of LENGTH() for string length
        - Use + for string concatenation or CONCAT() function
        - Views are read-only — do not attempt INSERT, UPDATE, or DELETE on views";
    }

    public function provide(): array
    {
        return [
            SQLServerSchemaTool::make($this->pdo, $this->tables),
            SQLServerSelectTool::make($this->pdo),
            SQLServerWriteTool::make($this->pdo),
        ];
    }
}