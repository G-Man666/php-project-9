<?php

namespace App;

class Connect
{
    private \PDO $connection;

    public function __construct()
    {
        $this->connect();
    }

    public function connect(): void
    {
        $databaseUrl = parse_url((string) getenv('DATABASE_URL'));
        $username = $databaseUrl['user'];
        $password = $databaseUrl['pass'];
        $host = $databaseUrl['host'];
        $port = $databaseUrl['port'];
        $dbName = ltrim($databaseUrl['path'], '/');
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbName";
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ];
        try {
            $pdo = new \PDO($dsn, $username, $password, $options);
        } catch (\PDOException $e) {
            echo $e->getMessage();
            die();
        }
        $this->connection = $pdo;
    }

    public function getConnection(): \PDO
    {
        return $this->connection;
    }
}