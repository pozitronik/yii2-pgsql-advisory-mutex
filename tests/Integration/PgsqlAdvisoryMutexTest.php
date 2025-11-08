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

    /**
     * Тест: getAcquiredLocks() возвращает список захваченных блокировок
     *
     * Сценарий: после захвата блокировок метод должен вернуть информацию
     * о каждой блокировке, включая lockKey и sharedMode.
     */
    public function testGetAcquiredLocks(): void
    {
        $mutex = $this->createMutex();

        // До захвата список пустой
        self::assertEmpty($mutex->getAcquiredLocks(), 'Should be empty before acquiring locks');

        // Захватываем блокировки
        $mutex->acquire('test_lock_1');
        $mutex->acquire('test_lock_2');

        $locks = $mutex->getAcquiredLocks();

        // Проверяем структуру
        self::assertCount(2, $locks, 'Should have 2 acquired locks');
        self::assertArrayHasKey('test_lock_1', $locks);
        self::assertArrayHasKey('test_lock_2', $locks);

        // Проверяем содержимое
        self::assertArrayHasKey('lockKey', $locks['test_lock_1']);
        self::assertArrayHasKey('sharedMode', $locks['test_lock_1']);
        self::assertIsInt($locks['test_lock_1']['lockKey']);
        self::assertFalse($locks['test_lock_1']['sharedMode'], 'Should be exclusive mode');

        // Cleanup
        $mutex->release('test_lock_1');
        $mutex->release('test_lock_2');
    }

    /**
     * Тест: getAcquiredLocks() для shared mode
     *
     * Сценарий: проверяем что информация о sharedMode корректно отражается в getAcquiredLocks().
     */
    public function testGetAcquiredLocksWithSharedMode(): void
    {
        $mutex = new PgsqlAdvisoryMutex(['db' => $this->db, 'sharedMode' => true]);

        $mutex->acquire('shared_mode_test');

        $locks = $mutex->getAcquiredLocks();

        self::assertArrayHasKey('shared_mode_test', $locks);
        self::assertTrue($locks['shared_mode_test']['sharedMode'], 'Should indicate shared mode');

        $mutex->release('shared_mode_test');
    }

    /**
     * Тест: getActiveLocks() возвращает информацию о всех advisory locks в системе
     *
     * Сценарий: после захвата блокировки метод должен вернуть информацию
     * о всех активных advisory locks в PostgreSQL, включая наши.
     */
    public function testGetActiveLocks(): void
    {
        $mutex = $this->createMutex();

        // Захватываем блокировку
        $mutex->acquire('monitor_test');

        // Получаем список активных блокировок
        $activeLocks = $mutex->getActiveLocks();

        // Должна быть хотя бы одна блокировка (наша)
        self::assertNotEmpty($activeLocks, 'Should have at least one active advisory lock');

        // Проверяем структуру первой блокировки
        self::assertArrayHasKey('pid', $activeLocks[0], 'Should have pid field');
        self::assertArrayHasKey('locktype', $activeLocks[0], 'Should have locktype field');
        self::assertArrayHasKey('mode', $activeLocks[0], 'Should have mode field');
        self::assertArrayHasKey('granted', $activeLocks[0], 'Should have granted field');
        self::assertArrayHasKey('lock_key', $activeLocks[0], 'Should have lock_key field');
        self::assertArrayHasKey('is_current_connection', $activeLocks[0], 'Should have is_current_connection field');

        // Должна быть advisory блокировка
        $hasAdvisoryLock = false;
        foreach ($activeLocks as $lock) {
            if ('advisory' === $lock['locktype']) {
                $hasAdvisoryLock = true;
                break;
            }
        }
        self::assertTrue($hasAdvisoryLock, 'Should have at least one advisory lock');

        // Cleanup
        $mutex->release('monitor_test');
    }

    /**
     * Тест: getActiveLocks() не выбрасывает исключение
     *
     * Сценарий: метод должен корректно работать даже при отсутствии блокировок и не выбрасывать исключений.
     */
    public function testGetActiveLocksDoesNotThrowException(): void
    {
        $mutex = $this->createMutex();

        // Метод не должен выбросить исключение даже при отсутствии блокировок
        $locks = $mutex->getActiveLocks();

        self::assertIsArray($locks, 'Should return array even when empty');
    }

    /**
     * Тест: releaseAll() освобождает все захваченные блокировки
     *
     * Сценарий: после захвата нескольких блокировок, releaseAll()
     * должен освободить их все и вернуть корректное количество.
     */
    public function testReleaseAll(): void
    {
        $mutex = $this->createMutex();

        // Захватываем несколько блокировок
        $mutex->acquire('bulk_lock_1');
        $mutex->acquire('bulk_lock_2');
        $mutex->acquire('bulk_lock_3');

        // Проверяем что блокировки захвачены
        self::assertCount(3, $mutex->getAcquiredLocks());

        // Освобождаем все
        $count = $mutex->releaseAll();

        // Проверяем результат
        self::assertEquals(3, $count, 'Should release 3 locks');
        self::assertEmpty($mutex->getAcquiredLocks(), 'Should have no acquired locks after releaseAll');
    }

    /**
     * Тест: releaseAll() возвращает 0 если нет захваченных блокировок
     *
     * Сценарий: вызов releaseAll() без захваченных блокировок должен корректно вернуть 0.
     */
    public function testReleaseAllWhenNoLocks(): void
    {
        $mutex = $this->createMutex();

        // Нет захваченных блокировок
        self::assertEmpty($mutex->getAcquiredLocks());

        $count = $mutex->releaseAll();

        self::assertEquals(0, $count, 'Should release 0 locks');
    }

    /**
     * Тест: инициализация с db как строкой (component ID)
     *
     * Сценарий: когда db передан как строка, mutex должен получить
     * компонент из Yii::$app и корректно инициализироваться.
     */
    public function testInitWithStringDbComponent(): void
    {
        // Создаем mutex с db как строкой
        $mutex = new PgsqlAdvisoryMutex(['db' => 'db']);

        // Проверяем что инициализация прошла успешно и блокировка работает
        $acquired = $mutex->acquire('component_id_test');
        self::assertTrue($acquired, 'Should acquire lock with db component ID');

        $mutex->release('component_id_test');
    }

    /**
     * Тест: попытка освободить не захваченную блокировку
     *
     * Сценарий: release() на блокировке, которую мы не захватывали, должен вернуть false и залогировать предупреждение.
     */
    public function testReleaseNonAcquiredLock(): void
    {
        $mutex = $this->createMutex();

        // Пытаемся освободить блокировку, которую не захватывали
        $result = $mutex->release('never_acquired');

        self::assertFalse($result, 'Should return false for non-acquired lock');
        self::assertEmpty($mutex->getAcquiredLocks(), 'Should have no locks');
    }

    /**
     * Тест: освобождение блокировки после внешнего закрытия транзакции
     *
     * Сценарий: если транзакция была закоммичена/откачена вне mutex,
     * release() должен корректно обработать это и вернуть false.
     */
    public function testReleaseAfterExternalTransactionClose(): void
    {
        $mutex = $this->createMutex();

        // Захватываем блокировку
        $mutex->acquire('external_commit_test');

        // Получаем транзакцию и коммитим её вручную (эмулируем внешнее закрытие)
        $transaction = $this->db->getTransaction();
        self::assertNotNull($transaction, 'Transaction should exist');
        $transaction->commit();

        // Попытка освободить - транзакция уже закрыта
        $result = $mutex->release('external_commit_test');

        self::assertFalse($result, 'Should return false when transaction already closed');
        self::assertEmpty($mutex->getAcquiredLocks(), 'Lock should be removed from tracking');
    }
}
