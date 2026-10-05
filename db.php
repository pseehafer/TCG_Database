<?php
// ---- Edit these to match your XAMPP / phpMyAdmin setup ----
const DB_HOST = 'localhost';
const DB_NAME = 'tcg database';        // the database that holds your two tables
const DB_USER = 'root';       // XAMPP default
const DB_PASS = '';           // XAMPP default is empty

// Game value (from the UI) => table name in MySQL
const GAME_TABLES = [
    'pokemon' => 'pokemon_inv',
    'mtg'     => 'mtg_inv', 
];

// Name of the column that stores how many copies you own
const QTY_COLUMN = 'quantity';
// -----------------------------------------------------------

function db(): PDO {
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function norm(string $s): string {
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $s));
}

/** Importable columns of a table, as normalized-name => real name. Skips auto-increment and generated columns. */
function table_columns(PDO $pdo, string $table): array {
    $st = $pdo->prepare(
        'SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
    );
    $st->execute([$table]);
    $cols = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (stripos($c['EXTRA'], 'auto_increment') !== false || stripos($c['EXTRA'], 'generated') !== false) continue;
        $cols[norm($c['COLUMN_NAME'])] = $c['COLUMN_NAME'];
    }
    return $cols;
}
