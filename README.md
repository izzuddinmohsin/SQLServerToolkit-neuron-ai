# SQL Server Toolkit for Neuron AI

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)

A SQL Server / Azure SQL Database toolkit for [Neuron AI](https://github.com/neuron-core/neuron-ai) — the PHP Agentic Framework. Gives your AI agents the ability to discover database schema, execute read queries, and perform write operations on SQL Server databases.

> Adapted from Neuron AI's built-in MySQL Toolkit with full SQL Server compatibility, including schema-awareness, views discovery, positional parameters, and SQL Server-specific security hardening.

## Features

- **Schema Discovery** — Automatically discovers tables, views, columns, relationships, indexes, and constraints
- **Views Support** — Discovers and distinguishes between tables and views (read-only indicator for LLM)
- **Read Queries** — Safe SELECT query execution with security validation
- **Write Operations** — INSERT, UPDATE, DELETE, and MERGE support with SCOPE_IDENTITY() for identity columns
- **Security Hardened** — Blocks dangerous operations like `xp_cmdshell`, `OPENROWSET`, `SELECT INTO`, DDL statements
- **Positional Parameters** — Uses `?` placeholders (required for SQL Server PDO driver compatibility)
- **Azure SQL Compatible** — Works with both on-premise SQL Server and Azure SQL Database
- **LLM-Optimized Output** — Schema output formatted with SQL Server-specific guidelines (TOP, GETDATE, LEN, etc.)

## Requirements

- PHP 8.1+
- PDO SQL Server driver (`pdo_sqlsrv`)
- [Neuron AI](https://github.com/neuron-core/neuron-ai) ^2.0

## Installation

```bash
composer require izzuddinmohsin/neuron-sqlserver-toolkit
```

### PDO SQL Server Driver Setup

**Windows:**
Download [Microsoft Drivers for PHP for SQL Server](https://learn.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server) and enable in `php.ini`:
```ini
extension=php_pdo_sqlsrv_83_ts.dll
extension=php_sqlsrv_83_ts.dll
```

**Linux (Ubuntu/Debian):**
```bash
# Install Microsoft ODBC Driver
curl https://packages.microsoft.com/keys/microsoft.asc | apt-key add -
curl https://packages.microsoft.com/config/ubuntu/$(lsb_release -rs)/prod.list > /etc/apt/sources.list.d/mssql-release.list
apt-get update
ACCEPT_EULA=Y apt-get install -y msodbcsql18

# Install PHP extensions
pecl install sqlsrv pdo_sqlsrv
echo "extension=pdo_sqlsrv.so" >> $(php -i | grep "Loaded Configuration" | sed -e "s|.*:\s*||")
```

## Quick Start

```php
use IzzuddinMohsin\NeuronSQLServer\SQLServerToolkit;
use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\SystemPrompt;
use PDO;

class DataAnalystAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            key: env('ANTHROPIC_API_KEY'),
            model: 'claude-sonnet-4-5-20250929',
        );
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ['You are a data analyst expert working with SQL Server databases.'],
        );
    }

    protected function tools(): array
    {
        $pdo = new PDO(
            'sqlsrv:Server=localhost;Database=mydb',
            'username',
            'password',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        return [
            ...SQLServerToolkit::make($pdo)->provide(),
        ];
    }
}
```

### Laravel Integration

```php
use IzzuddinMohsin\NeuronSQLServer\SQLServerToolkit;
use Illuminate\Support\Facades\DB;

// In your Agent's tools() method:
protected function tools(): array
{
    return [
        ...SQLServerToolkit::make(
            DB::connection('sqlsrv')->getPdo()
        )->provide(),
    ];
}
```

## Usage

### Discover All Tables and Views

```php
// Discover everything in the database
SQLServerToolkit::make($pdo);
```

### Discover Specific Tables/Views Only

```php
// Only discover these specific tables and views
SQLServerToolkit::make($pdo, [
    'users',
    'orders',
    'view_active_customers',
]);
```

### Exclude the Write Tool

If you want read-only access, use the toolkit's `exclude()` method:

```php
use IzzuddinMohsin\NeuronSQLServer\SQLServerWriteTool;

// Read-only: exclude the write tool
...SQLServerToolkit::make($pdo)
    ->exclude([SQLServerWriteTool::class])
    ->provide(),
```

### Azure SQL Connection

```php
$pdo = new PDO(
    'sqlsrv:Server=myserver.database.windows.net;Database=mydb;Encrypt=yes;TrustServerCertificate=no',
    'username@myserver',
    'password',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

SQLServerToolkit::make($pdo);
```

## SQL Server vs MySQL Differences

This toolkit handles the key differences between SQL Server and MySQL automatically. The LLM receives SQL Server-specific guidelines, but here's a reference:

| Feature | MySQL | SQL Server |
|---------|-------|-----------|
| Limit rows | `LIMIT 10` | `TOP 10` |
| Auto increment | `AUTO_INCREMENT` | `IDENTITY(1,1)` |
| Current datetime | `NOW()` | `GETDATE()` |
| String length | `LENGTH()` | `LEN()` |
| String concat | `CONCAT()` or `\|\|` | `CONCAT()` or `+` |
| Table quoting | `` `table` `` | `[table]` |
| Boolean type | `BOOLEAN` / `TINYINT(1)` | `BIT` |
| Schema prefix | Not required | Required (e.g., `dbo.table`) |
| Parameters (PDO) | Named (`:name`) | Positional (`?`) |
| Last insert ID | `lastInsertId()` | `SCOPE_IDENTITY()` |

## Security

### Read Tool (SQLServerSelectTool)

| Status | Statements |
|--------|-----------|
| ✅ Allowed | `SELECT`, `WITH` (CTE) |
| ❌ Blocked | `INSERT`, `UPDATE`, `DELETE`, `DROP`, `CREATE`, `ALTER`, `TRUNCATE`, `MERGE`, `EXEC`, `EXECUTE`, `sp_executesql`, `xp_cmdshell`, `OPENROWSET`, `OPENDATASOURCE`, `BULK`, `INTO` |

### Write Tool (SQLServerWriteTool)

| Status | Statements |
|--------|-----------|
| ✅ Allowed | `INSERT`, `UPDATE`, `DELETE`, `MERGE` |
| ❌ Blocked | `DROP`, `CREATE`, `ALTER`, `GRANT`, `REVOKE`, `TRUNCATE`, `EXEC`, `EXECUTE`, `sp_executesql`, `xp_cmdshell`, `OPENROWSET`, `OPENDATASOURCE`, `BULK` |

### Additional Security Measures

- All queries use **parameterized statements** to prevent SQL injection
- SQL comments are stripped before validation
- The PDO instance can use **dedicated credentials** with limited database permissions
- `SELECT INTO` is blocked to prevent table creation through the read tool

## Toolkit Components

| Tool | Description |
|------|-------------|
| `SQLServerSchemaTool` | Discovers database structure (tables, views, columns, relationships, indexes, constraints) |
| `SQLServerSelectTool` | Executes read-only SELECT queries with positional parameters |
| `SQLServerWriteTool` | Executes write operations (INSERT, UPDATE, DELETE, MERGE) with positional parameters |

## Troubleshooting

### Error: "could not find driver"
Install the PDO SQL Server driver. See the [Installation](#pdo-sql-server-driver-setup) section.

### Error: "Invalid object name"
Use schema-qualified table names: `dbo.TableName` instead of just `TableName`.

### Error: "Login failed for user"
Verify your connection string and credentials. For Azure SQL, ensure your IP is allowed in the firewall rules.

### Empty results from queries
An empty array `[]` is a valid result meaning no records matched. The toolkit instructs the LLM not to retry on empty results.

### Connection timeout
Add timeout to your connection string:
```php
$pdo = new PDO(
    'sqlsrv:Server=localhost;Database=mydb;LoginTimeout=30',
    $user,
    $pass
);
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Credits

- Adapted from [Neuron AI](https://github.com/neuron-core/neuron-ai) MySQL Toolkit by [Inspector.dev](https://inspector.dev)
- SQL Server adaptation by [Izzuddin Mohsin](https://github.com/izzuddinmohsin)

## License

MIT License. See [LICENSE](LICENSE) for details.