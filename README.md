# PostgreSQL Advisory Mutex для Yii2


[![Tests](https://github.com/pozitronik/yii2-pgsql-advisory-mutex/actions/workflows/tests.yml/badge.svg)](https://github.com/pozitronik/yii2-pgsql-advisory-mutex/actions/workflows/tests.yml)
[![Codecov](https://codecov.io/gh/pozitronik/yii2-pgsql-advisory-mutex/branch/master/graph/badge.svg)](https://codecov.io/gh/pozitronik/yii2-pgsql-advisory-mutex)
[![Packagist Version](https://img.shields.io/packagist/v/beeline/yii2-pgsql-advisory-mutex)](https://packagist.org/packages/beeline/yii2-pgsql-advisory-mutex)
[![Packagist License](https://img.shields.io/packagist/l/beeline/yii2-pgsql-advisory-mutex)](https://packagist.org/packages/beeline/yii2-pgsql-advisory-mutex)
[![Packagist Downloads](https://img.shields.io/packagist/dt/beeline/yii2-pgsql-advisory-mutex)](https://packagist.org/packages/beeline/yii2-pgsql-advisory-mutex)

Реализация распределённого mutex для Yii2 на основе транзакционных advisory locks PostgreSQL. Обеспечивает безопасную, быструю и надёжную распределённую блокировку без физического хранения в таблицах.

## Возможности

- **Транзакционные блокировки**: Автоматическое освобождение при COMMIT/ROLLBACK
- **Совместимость с PgBouncer**: Работает с пулингом соединений в любом режиме (session/transaction/statement)
- **Поддержка таймаутов**: Таймаут захвата блокировки с миллисекундной точностью
- **Разделяемые/эксклюзивные режимы**: Поддержка паттернов читатель-писатель
- **Нулевые накладные расходы на хранение**: Виртуальные блокировки без физических строк в таблицах
- **Генерация ключей через xxHash64**: Быстрое, устойчивое к коллизиям хеширование ключей блокировок
- **PHP 8.4+**: Современный PHP со строгой типизацией

## Установка

```bash
composer require beeline/yii2-pgsql-advisory-mutex
```

## Требования

- PHP >= 8.4
- PostgreSQL >= 9.1
- Yii2 >= 2.0.45
- ext-pgsql

## Настройка

### 1. Применение миграции

Mutex требует наличия PostgreSQL функции `try_advisory_xact_lock_timeout`. Примените миграцию:

```bash
# Используя команду миграции Yii2
php yii migrate --migrationPath=@vendor/beeline/yii2-pgsql-advisory-mutex/src/migrations
```

Или вручную выполните SQL из файла `src/migrations/m250202_000000_create_advisory_lock_timeout_function.php`.

### 2. Конфигурация приложения

```php
return [
    'components' => [
        'mutex' => [
            'class' => \beeline\PgsqlAdvisoryMutex\PgsqlAdvisoryMutex::class,
            'db' => 'db', // ID компонента базы данных
        ],
    ],
];
```

## Использование

### Базовая блокировка

```php
use Yii;

$mutex = Yii::$app->mutex;

// Захват блокировки (ожидание бесконечно)
if ($mutex->acquire('my_lock')) {
    try {
        // Критическая секция - только один процесс выполняет это одновременно
        performCriticalOperation();
    } finally {
        $mutex->release('my_lock');
    }
} else {
    // Не удалось захватить блокировку
}
```

### Поддержка таймаутов

```php
// Попытка захватить блокировку с таймаутом 5 секунд
if ($mutex->acquire('my_lock', 5)) {
    try {
        performCriticalOperation();
    } finally {
        $mutex->release('my_lock');
    }
} else {
    // Истёк таймаут или блокировка удерживается другим процессом
    echo "Не удалось захватить блокировку в течение 5 секунд\n";
}

// Без ожидания (timeout = 0)
if ($mutex->acquire('my_lock', 0)) {
    // Получили блокировку немедленно
} else {
    // Блокировка занята
}
```

### Разделяемые блокировки (паттерн читатель-писатель)

```php
// Несколько читателей могут одновременно захватить разделяемые блокировки
$readerMutex = new \beeline\PgsqlAdvisoryMutex\PgsqlAdvisoryMutex([
    'db' => Yii::$app->db,
    'sharedMode' => true,
]);

if ($readerMutex->acquire('resource')) {
    // Несколько читателей могут находиться здесь одновременно
    $data = readResource();
    $readerMutex->release('resource');
}

// Писатель использует эксклюзивную блокировку (блокирует и читателей, и писателей)
$writerMutex = new \beeline\PgsqlAdvisoryMutex\PgsqlAdvisoryMutex([
    'db' => Yii::$app->db,
    'sharedMode' => false, // по умолчанию
]);

if ($writerMutex->acquire('resource')) {
    // Эксклюзивный доступ
    writeResource($data);
    $writerMutex->release('resource');
}
```

### Расширенное использование

```php
$mutex = Yii::$app->mutex;

// Получение информации о текущих advisory locks в базе данных
$activeLocks = $mutex->getActiveLocks();
// Возвращает: [['pid' => 12345, 'locktype' => 'advisory', 'mode' => 'ExclusiveLock', ...], ...]

// Получение блокировок, захваченных этим экземпляром mutex
$acquired = $mutex->getAcquiredLocks();
// Возвращает: ['my_lock' => ['lockKey' => -1234567890, 'sharedMode' => false], ...]

// Освобождение всех блокировок, захваченных этим экземпляром
$count = $mutex->releaseAll();
echo "Освобождено {$count} блокировок\n";
```

## Принцип работы

### Транзакционные Advisory Locks

В отличие от session-level advisory locks, транзакционные блокировки автоматически освобождаются при коммите или откате транзакции. Это делает их безопасными для использования с пулингом соединений:

```php
// Блокировка захватывается внутри транзакции
$mutex->acquire('my_lock');

// Если приложение упадёт или соединение потеряется,
// PostgreSQL автоматически освободит блокировку при откате транзакции
```

### Генерация ключей блокировок

Имена блокировок хешируются в int64 с использованием xxHash64:

```php
$mutex->acquire('user_123_profile');
// Внутренне: xxHash64('user_123_profile') -> -8234567890123456789
```

Вероятность коллизии с xxHash64 чрезвычайно мала (~10⁻¹⁹ для 1 миллиарда блокировок).

### Реализация таймаута

Mutex использует настройку PostgreSQL `lock_timeout` для поддержки таймаутов:

```sql
-- Внутренне для timeout=2000ms
SET LOCAL lock_timeout = '2000ms';
SELECT pg_advisory_xact_lock(key);
-- lock_timeout восстанавливается после возврата функции
```

## Важные ограничения

### Не реентерабельные

Транзакционные advisory locks НЕ являются реентерабельными. Попытка захватить одну и ту же блокировку дважды в одной транзакции приведёт к блокировке:

```php
$mutex->acquire('lock1'); // OK
$mutex->acquire('lock1'); // DEADLOCK - зависнет!
```

### Вложенные транзакции

В окружениях с вложенными транзакциями (например, Codeception с TransactionForcer) блокировки НЕ будут освобождены до коммита самой внешней транзакции:

```php
// В тесте Codeception с TransactionForcer
$mutex->acquire('lock1'); // Создаётся SAVEPOINT, не новая транзакция
$mutex->release('lock1'); // SAVEPOINT закоммичен, но xact lock НЕ освобождён
// Блокировка освобождается только при коммите/откате внешней тестовой транзакции
```

### Совместимость с PgBouncer

Работает во **всех** режимах PgBouncer:
- **Session mode**: ✅ Полная поддержка
- **Transaction mode**: ✅ Полная поддержка (транзакционные блокировки)
- **Statement mode**: ✅ Полная поддержка (каждый запрос в своей транзакции)

## Параметры конфигурации

| Свойство       | Тип                | По умолчанию                     | Описание                                                                      |
|----------------|--------------------|----------------------------------|-------------------------------------------------------------------------------|
| `db`           | Connection\|string | 'db'                             | Компонент базы данных или его ID                                              |
| `sharedMode`   | bool               | false                            | Использовать разделяемые блокировки (читатели) вместо эксклюзивных (писатель) |
| `functionName` | string             | 'try_advisory_xact_lock_timeout' | Имя функции PostgreSQL                                                        |

## Тестирование

### Локальное тестирование с Docker

```bash
# Запуск PostgreSQL
docker-compose up -d

# Установка зависимостей
composer install

# Запуск тестов
vendor/bin/phpunit

# Запуск с покрытием
vendor/bin/phpunit --coverage-html coverage/

# Остановка PostgreSQL
docker-compose down
```

### Переменные окружения

Настройте подключение к базе данных через переменные окружения:

```bash
export DB_HOST=localhost
export DB_PORT=5432
export DB_NAME=test_mutex
export DB_USER=postgres
export DB_PASSWORD=postgres

vendor/bin/phpunit
```

## Производительность

Advisory locks обладают высокой производительностью:
- **Без дискового I/O**: Блокировки хранятся только в памяти
- **Быстрый захват**: Время захвата блокировки менее миллисекунды
- **Низкие накладные расходы**: Минимальное использование CPU и памяти
- **Масштабируемость**: Тысячи одновременных блокировок

Бенчмарк (PostgreSQL 16, одно ядро):
- Захват/освобождение блокировки: ~0.1мс
- Пропускная способность: ~10,000 операций/сек на соединение

## Примеры использования

### Распределённая обработка задач

```php
// Гарантируем, что только один воркер обрабатывает каждую задачу
if ($mutex->acquire("task:{$taskId}", 0)) {
    try {
        processTask($taskId);
    } finally {
        $mutex->release("task:{$taskId}");
    }
}
```

### Предотвращение cache stampede

```php
$cacheKey = 'expensive_data';
$data = Cache::get($cacheKey);

if ($data === null) {
    if ($mutex->acquire($cacheKey, 5)) {
        try {
            // Двойная проверка после захвата блокировки
            $data = Cache::get($cacheKey);
            if ($data === null) {
                $data = computeExpensiveData();
                Cache::set($cacheKey, $data);
            }
        } finally {
            $mutex->release($cacheKey);
        }
    } else {
        // Запасной вариант, если не удалось захватить блокировку
        $data = computeExpensiveData();
    }
}
```

### Координация миграций базы данных

```php
// Гарантируем, что только один экземпляр запускает миграции
if ($mutex->acquire('schema_migration', 0)) {
    try {
        runMigrations();
    } finally {
        $mutex->release('schema_migration');
    }
}
```

### Атомарные операции с внешними ресурсами

```php
use beeline\PgsqlAdvisoryMutex\PgsqlAdvisoryMutex;

class FileProcessor
{
    private PgsqlAdvisoryMutex $mutex;

    public function __construct()
    {
        $this->mutex = new PgsqlAdvisoryMutex(['db' => Yii::$app->db]);
    }

    public function processFile(string $filename): void
    {
        // Используем имя файла как ключ блокировки
        if (!$this->mutex->acquire("file:{$filename}", 10)) {
            throw new \RuntimeException("Файл {$filename} уже обрабатывается");
        }

        try {
            // Только один процесс обрабатывает этот файл
            $content = file_get_contents($filename);
            $processed = $this->process($content);
            file_put_contents($filename, $processed);
        } finally {
            $this->mutex->release("file:{$filename}");
        }
    }
}
```

### Распределённый счётчик с блокировкой

```php
use beeline\PgsqlAdvisoryMutex\PgsqlAdvisoryMutex;

class DistributedCounter
{
    private PgsqlAdvisoryMutex $mutex;

    public function __construct()
    {
        $this->mutex = new PgsqlAdvisoryMutex(['db' => Yii::$app->db]);
    }

    public function increment(string $counterName): int
    {
        if (!$this->mutex->acquire("counter:{$counterName}", 5)) {
            throw new \RuntimeException('Не удалось захватить блокировку счётчика');
        }

        try {
            $current = (int)Cache::get($counterName, 0);
            $new = $current + 1;
            Cache::set($counterName, $new);
            return $new;
        } finally {
            $this->mutex->release("counter:{$counterName}");
        }
    }
}
```

## Отладка

### Просмотр активных блокировок

```php
$mutex = Yii::$app->mutex;
$locks = $mutex->getActiveLocks();

foreach ($locks as $lock) {
    echo "PID: {$lock['pid']}, ";
    echo "Lock Key: {$lock['lock_key']}, ";
    echo "Mode: {$lock['mode']}, ";
    echo "Granted: " . ($lock['granted'] ? 'Yes' : 'No') . "\n";
}
```

### Мониторинг блокировок через SQL

```sql
-- Просмотр всех advisory locks
SELECT
    pid,
    locktype,
    mode,
    granted,
    objid as lock_key
FROM pg_locks
WHERE locktype = 'advisory'
ORDER BY pid, objid;

-- Поиск заблокированных процессов
SELECT
    blocked_locks.pid AS blocked_pid,
    blocking_locks.pid AS blocking_pid,
    blocked_activity.usename AS blocked_user,
    blocking_activity.usename AS blocking_user,
    blocked_activity.query AS blocked_statement,
    blocking_activity.query AS blocking_statement
FROM pg_catalog.pg_locks blocked_locks
JOIN pg_catalog.pg_stat_activity blocked_activity ON blocked_activity.pid = blocked_locks.pid
JOIN pg_catalog.pg_locks blocking_locks
    ON blocking_locks.locktype = blocked_locks.locktype
    AND blocking_locks.objid = blocked_locks.objid
    AND blocking_locks.pid != blocked_locks.pid
JOIN pg_catalog.pg_stat_activity blocking_activity ON blocking_activity.pid = blocking_locks.pid
WHERE NOT blocked_locks.granted
AND blocked_locks.locktype = 'advisory';
```

## Лицензия

GNU Lesser General Public License 3.0