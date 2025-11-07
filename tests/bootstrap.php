<?php
declare(strict_types=1);

// Composer autoloader
use yii\console\Application;
use yii\db\Connection;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Yii2 environment
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

// Получаем параметры подключения из переменных окружения
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '5432';
$dbName = getenv('DB_NAME') ?: 'test_mutex';
$dbUser = getenv('DB_USER') ?: 'postgres';
$dbPassword = getenv('DB_PASSWORD') ?: 'postgres';

// Создаем тестовое приложение с подключением к PostgreSQL
$app = new Application([
    'id' => 'pgsql-advisory-mutex-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => [
            'class' => Connection::class,
            'dsn' => "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}",
            'username' => $dbUser,
            'password' => $dbPassword,
            'charset' => 'utf8',
            'enableSchemaCache' => false,
        ],
    ],
]);

// Применяем миграцию для создания функции try_advisory_xact_lock_timeout
try {
    $db = $app->db;
    $db->open();

    // Проверяем существование функции
    $functionExists = (int)$db->createCommand(
        "SELECT COUNT(*) FROM pg_proc WHERE proname = 'try_advisory_xact_lock_timeout'"
    )->queryScalar() > 0;

    if (!$functionExists) {
        echo "Applying migration to create try_advisory_xact_lock_timeout function...\n";

        // Запускаем миграцию
        require_once dirname(__DIR__) . '/src/migrations/m250202_000000_create_advisory_lock_timeout_function.php';
        $migration = new m250202_000000_create_advisory_lock_timeout_function();
        $migration->db = $db;
        $migration->up();

        echo "Migration applied successfully.\n";
    }
} catch (Exception $e) {
    echo "Warning: Could not apply migration: " . $e->getMessage() . "\n";
    echo "Tests requiring the function will be skipped.\n";
}
