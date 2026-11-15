<?php
declare(strict_types=1);
if (!defined('THP_APP')) { http_response_code(403); exit('forbidden'); }

// 取得全局 PDO 连接（单例）
function thp_db(array $cfg): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }
    $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . (int)$cfg['port'] . ';dbname=' . $cfg['name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)$cfg['user'], (string)$cfg['pass'], array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ));
    $pdo->exec("SET time_zone = '+08:00'");
    return $pdo;
}
