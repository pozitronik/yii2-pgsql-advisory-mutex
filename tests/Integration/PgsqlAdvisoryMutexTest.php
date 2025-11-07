<?php /** @noinspection PhpRedundantOptionalArgumentInspection */
/** @noinspection PhpRedundantOptionalArgumentInspection */
/** @noinspection PhpRedundantOptionalArgumentInspection */
/** @noinspection PhpRedundantOptionalArgumentInspection */
/** @noinspection PhpRedundantOptionalArgumentInspection */
/** @noinspection PhpRedundantOptionalArgumentInspection */
/** @noinspection PhpRedundantOptionalArgumentInspection */
/** @noinspection PhpRedundantOptionalArgumentInspection */
declare(strict_types=1);

namespace Beeline\PgsqlAdvisoryMutex\Tests\Integration;

use Beeline\PgsqlAdvisoryMutex\PgsqlAdvisoryMutex;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\db\Connection;
use yii\db\Exception as DbException;

/**
 * Тесты для PgsqlAdvisoryMutex - распределённого mutex на основе PostgreSQL transaction-level advisory locks.
 *
 * PgsqlAdvisoryMutex использует функцию try_advisory_xact_lock_timeout() для обеспечения безопасной работы с connection pooling.
 *
 * Ключевые особенности:
 * - Блокировки привязаны к транзакции, не к сессии
 * - Автоматическое освобождение при COMMIT/ROLLBACK
 * - Безопасность при пулинге соединений
 * - Поддержка timeout в миллисекундах
 * - Поддержка shared/exclusive режимов
 *
 * ВАЖНО: Для работы мьютекса нужно применить миграцию m250202_000000_create_advisory_lock_timeout_function.php
 */
class PgsqlAdvisoryMutexTest extends TestCase
{
    private Connection $db;
    private static bool $functionChecked = false;
    private static bool $functionExists = false;

    /**
     * Подготовка к каждому тесту
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Yii::$app->db;

        // Проверяем существование функции один раз для всех тестов
        if (!self::$functionChecked) {
            self::$functionChecked = true;
            self::$functionExists = $this->checkFunctionExists();
        }

        if (!self::$functionExists) {
            static::markTestSkipped('Function try_advisory_xact_lock_timeout does not exist.');
        }
    }

    /**
     * Очистка после каждого теста
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        // Откатываем все незакоммиченные транзакции, это освободит все xact advisory locks
        if ($this->db->getTransaction()) {
            $this->db->getTransaction()->rollBack();
        }
    }

    // ========================================================================
    // БАЗОВАЯ ФУНКЦИОНАЛЬНОСТЬ
    // ========================================================================

    /**
     * Успешный захват эксклюзивной блокировки
     */
    public function testAcquireExclusiveLock(): void
    {
        $mutex = $this->createMutex();

        $acquired = $mutex->acquire('test_exclusive');
        self::assertTrue($acquired, 'Should acquire exclusive lock');

        // Проверяем что транзакция активна
        self::assertNotNull($this->db->getTransaction(), 'Transaction should be active');

        $mutex->release('test_exclusive');
    }

    /**
     * Захват нескольких блокировок одним mutex
     */
    public function testMultipleLocksInSeparateTransactions(): void
    {
        $mutex = $this->createMutex();

        // Первая блокировка
        $acquired1 = $mutex->acquire('lock_a');
        self::assertTrue($acquired1);

        // Вторая блокировка (должна быть в отдельной транзакции)
        $acquired2 = $mutex->acquire('lock_b');
        self::assertTrue($acquired2);

        // Освобождаем
        $mutex->release('lock_a');
        $mutex->release('lock_b');
    }

    /**
     * Timeout = 0 возвращает немедленно
     */
    public function testTimeoutZeroReturnsImmediately(): void
    {
        $db1 = $this->createConnection();
        $db2 = $this->createConnection();

        $mutex1 = new PgsqlAdvisoryMutex(['db' => $db1]);
        $mutex2 = new PgsqlAdvisoryMutex(['db' => $db2]);

        // Первый mutex захватывает
        $acquired1 = $mutex1->acquire('timeout_zero_test', 0);
        self::assertTrue($acquired1);

        $startTime = microtime(true);

        // Второй mutex НЕ должен ждать (timeout=0)
        $acquired2 = $mutex2->acquire('timeout_zero_test', 0);

        $elapsed = microtime(true) - $startTime;

        self::assertFalse($acquired2, 'Should NOT acquire locked resource');
        self::assertLessThan(0.5, $elapsed, 'Should return immediately with timeout=0');

        // Cleanup
        $mutex1->release('timeout_zero_test');
        $db1->close();
        $db2->close();
    }

    /**
     * Timeout > 0 ждет указанное время
     */
    public function testTimeoutWaitsSpecifiedDuration(): void
    {
        $db1 = $this->createConnection();
        $db2 = $this->createConnection();

        $mutex1 = new PgsqlAdvisoryMutex(['db' => $db1]);
        $mutex2 = new PgsqlAdvisoryMutex(['db' => $db2]);

        // Первый mutex захватывает
        $mutex1->acquire('timeout_wait_test', 0);

        $startTime = microtime(true);

        // Второй mutex должен ждать ~1 секунду
        $acquired2 = $mutex2->acquire('timeout_wait_test', 1);

        $elapsed = microtime(true) - $startTime;

        self::assertFalse($acquired2, 'Should timeout waiting for lock');
        self::assertGreaterThanOrEqual(0.9, $elapsed, 'Should wait at least timeout duration');
        self::assertLessThan(1.5, $elapsed, 'Should not wait much longer than timeout');

        // Cleanup
        $mutex1->release('timeout_wait_test');
        $db1->close();
        $db2->close();
    }

    /**
     * Разные блокировки не конфликтуют
     */
    public function testDifferentLocksDoNotConflict(): void
    {
        $db1 = $this->createConnection();
        $db2 = $this->createConnection();

        $mutex1 = new PgsqlAdvisoryMutex(['db' => $db1]);
        $mutex2 = new PgsqlAdvisoryMutex(['db' => $db2]);

        // Разные блокировки могут быть захвачены одновременно
        $acquiredA = $mutex1->acquire('lock_A');
        $acquiredB = $mutex2->acquire('lock_B');

        self::assertTrue($acquiredA, 'Should acquire lock A');
        self::assertTrue($acquiredB, 'Should acquire lock B');

        // Но mutex2 не может захватить lock_A
        $acquiredAFromMutex2 = $mutex2->acquire('lock_A', 0);
        self::assertFalse($acquiredAFromMutex2, 'Should NOT acquire lock A from second connection');

        // Cleanup
        $mutex1->release('lock_A');
        $mutex2->release('lock_B');
        $db1->close();
        $db2->close();
    }

    /**
     * ROLLBACK автоматически освобождает блокировку
     */
    public function testRollbackReleasesLock(): void
    {
        $db1 = $this->createConnection();
        $db2 = $this->createConnection();

        $mutex1 = new PgsqlAdvisoryMutex(['db' => $db1]);
        $mutex2 = new PgsqlAdvisoryMutex(['db' => $db2]);

        // Первое соединение захватывает
        $mutex1->acquire('rollback_test');

        // Второе не может захватить
        $acquired2 = $mutex2->acquire('rollback_test', 0);
        self::assertFalse($acquired2, 'Should be locked');

        // Откатываем транзакцию вручную (эмулируем сбой)
        $transaction = $db1->getTransaction();
        self::assertNotNull($transaction, 'Transaction should exist');
        $transaction->rollBack();

        // Теперь второе соединение МОЖЕТ захватить (блокировка освободилась)
        $acquired2AfterRollback = $mutex2->acquire('rollback_test', 0);
        self::assertTrue($acquired2AfterRollback, 'Lock should be released after rollback');

        // Cleanup
        $mutex2->release('rollback_test');
        $db1->close();
        $db2->close();
    }

    /**
     * Shared lock позволяет множественный захват
     */
    public function testSharedLockAllowsMultipleReaders(): void
    {
        $db1 = $this->createConnection();
        $db2 = $this->createConnection();
        $db3 = $this->createConnection();

        $mutex1 = new PgsqlAdvisoryMutex(['db' => $db1, 'sharedMode' => true]);
        $mutex2 = new PgsqlAdvisoryMutex(['db' => $db2, 'sharedMode' => true]);
        $mutex3 = new PgsqlAdvisoryMutex(['db' => $db3, 'sharedMode' => false]);

        // Два shared lock могут быть захвачены одновременно
        $acquired1 = $mutex1->acquire('shared_test');
        $acquired2 = $mutex2->acquire('shared_test');

        self::assertTrue($acquired1, 'First shared lock should be acquired');
        self::assertTrue($acquired2, 'Second shared lock should be acquired');

        // Но exclusive lock НЕ может быть захвачен
        $acquiredExclusive = $mutex3->acquire('shared_test', 0);
        self::assertFalse($acquiredExclusive, 'Exclusive lock should NOT be acquired while shared locks exist');

        // Cleanup
        $mutex1->release('shared_test');
        $mutex2->release('shared_test');
        $db1->close();
        $db2->close();
        $db3->close();
    }

    /**
     * Exclusive lock блокирует shared locks
     */
    public function testExclusiveLockBlocksSharedLocks(): void
    {
        $db1 = $this->createConnection();
        $db2 = $this->createConnection();

        $mutexExclusive = new PgsqlAdvisoryMutex(['db' => $db1, 'sharedMode' => false]);
        $mutexShared = new PgsqlAdvisoryMutex(['db' => $db2, 'sharedMode' => true]);

        // Exclusive lock захватывается
        $acquiredExclusive = $mutexExclusive->acquire('exclusive_blocks_shared');
        self::assertTrue($acquiredExclusive, 'Exclusive lock should be acquired');

        // Shared lock НЕ может быть захвачен
        $acquiredShared = $mutexShared->acquire('exclusive_blocks_shared', 0);
        self::assertFalse($acquiredShared, 'Shared lock should NOT be acquired while exclusive exists');

        // Cleanup
        $mutexExclusive->release('exclusive_blocks_shared');
        $db1->close();
        $db2->close();
    }

    /**
     * Отрицательный timeout вызывает исключение
     */
    public function testNegativeTimeoutThrowsException(): void
    {
        $mutex = $this->createMutex();

        $this->expectException(InvalidArgumentException::class);
        $mutex->acquire('negative_timeout_test', -1);
    }

    /**
     * Пустое имя блокировки
     */
    public function testEmptyLockName(): void
    {
        $mutex = $this->createMutex();

        $this->expectException(InvalidArgumentException::class);
        $mutex->acquire('');
    }

    /**
     * Очень длинное имя блокировки
     */
    public function testVeryLongLockName(): void
    {
        $mutex = $this->createMutex();

        // xxHash64 хэширует любую длину в int64
        $veryLongName = str_repeat('a', 10000);

        $acquired = $mutex->acquire($veryLongName);
        self::assertTrue($acquired, 'Should handle very long lock names via hashing');

        $mutex->release($veryLongName);
    }

    /**
     * Unicode в имени блокировки
     */
    public function testUnicodeLockName(): void
    {
        $mutex = $this->createMutex();

        $unicodeName = 'блокировка_测试_🔒';

        $acquired = $mutex->acquire($unicodeName);
        self::assertTrue($acquired, 'Should handle unicode lock names');

        $mutex->release($unicodeName);
    }

    /**
     * Создать экземпляр mutex с дефолтным соединением
     */
    private function createMutex(): PgsqlAdvisoryMutex
    {
        return new PgsqlAdvisoryMutex([
            'db' => $this->db,
            'sharedMode' => false,
        ]);
    }

    /**
     * Создать новое независимое соединение с БД
     */
    private function createConnection(): Connection
    {
        static $counter = 0;
        $counter++;

        $dsn = Yii::$app->db->dsn;
        $dsn .= ";application_name=test_advisory_mutex_{$counter}_" . uniqid('', true);

        $connection = new Connection([
            'dsn' => $dsn,
            'username' => Yii::$app->db->username,
            'password' => Yii::$app->db->password,
            'charset' => Yii::$app->db->charset,
        ]);

        $connection->open();

        return $connection;
    }

    /**
     * Проверить существование SQL функции
     */
    private function checkFunctionExists(): bool
    {
        try {
            $result = $this->db->createCommand("SELECT COUNT(*) FROM pg_proc WHERE proname = 'try_advisory_xact_lock_timeout'")->queryScalar();

            return (int)$result > 0;
        } catch (DbException) {
            return false;
        }
    }
}
