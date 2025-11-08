<?php
declare(strict_types=1);

namespace Beeline\PgsqlAdvisoryMutex\Tests\Unit;

use Beeline\PgsqlAdvisoryMutex\PgsqlAdvisoryMutex;
use PHPUnit\Framework\TestCase;
use stdClass;
use TypeError;
use yii\db\Command;
use yii\db\Connection;
use yii\db\Exception as DbException;
use yii\db\Transaction;

/**
 * Модульные тесты для PgsqlAdvisoryMutex (без реального подключения к БД)
 *
 * Тестируют:
 * - Валидацию конфигурации
 * - Валидацию входных параметров
 * - Обработку граничных случаев без взаимодействия с БД
 * - Обработку исключений при работе с БД
 */
class PgsqlAdvisoryMutexTest extends TestCase
{
    /**
     * Тест: инициализация с некорректным db вызывает исключение
     *
     * Сценарий: если db не является Connection|string, PHP 8.4 выбросит TypeError
     * из-за строгой типизации свойств класса.
     */
    public function testInitWithInvalidDbThrowsException(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('Cannot assign stdClass to property');

        // Передаем некорректный объект
        new PgsqlAdvisoryMutex(['db' => new stdClass()]);
    }

    /**
     * Тест: DbException при захвате блокировки
     *
     * Сценарий: если при выполнении SQL-запроса происходит DbException,
     * она должна быть проброшена наружу после отката транзакции.
     */
    public function testAcquireLockThrowsDbException(): void
    {
        $this->expectException(DbException::class);
        $this->expectExceptionMessage('Database error during lock acquisition');

        // Создаем моки с поддержкой method chaining
        $command = $this->createMock(Command::class);
        $command->expects($this->once())
            ->method('bindValues')
            ->willReturnSelf(); // Поддержка цепочки вызовов
        $command->expects($this->once())
            ->method('queryScalar')
            ->willThrowException(new DbException('Database error during lock acquisition'));

        $transaction = $this->createMock(Transaction::class);
        $transaction->expects($this->once())
            ->method('rollBack');
        $transaction->expects($this->once())
            ->method('getIsActive')
            ->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('createCommand')
            ->with(static::stringContains('SELECT try_advisory_xact_lock_timeout'))
            ->willReturn($command);
        $connection->expects($this->once())
            ->method('beginTransaction')
            ->willReturn($transaction);

        $mutex = new PgsqlAdvisoryMutex(['db' => $connection]);
        $mutex->acquire('test_lock');
    }

    /**
     * Тест: DbException при освобождении блокировки (commit)
     *
     * Сценарий: если при коммите транзакции происходит DbException,
     * метод должен вернуть false после отката транзакции.
     */
    public function testReleaseLockHandlesDbException(): void
    {
        // Создаем мок команды для acquire с поддержкой method chaining
        $acquireCommand = $this->createMock(Command::class);
        $acquireCommand->expects($this->once())
            ->method('bindValues')
            ->willReturnSelf();
        $acquireCommand->expects($this->once())
            ->method('queryScalar')
            ->willReturn(true);

        // Создаем ОДНУ транзакцию, которая используется и в acquire, и в release
        $transaction = $this->createMock(Transaction::class);
        $transaction->method('getLevel')
            ->willReturn(1);
        // getIsActive() вызывается дважды: перед commit() и в catch блоке перед rollBack()
        $transaction->expects($this->exactly(2))
            ->method('getIsActive')
            ->willReturn(true);
        $transaction->expects($this->once())
            ->method('commit')
            ->willThrowException(new DbException('Database error during commit'));
        $transaction->expects($this->once())
            ->method('rollBack');

        $connection = $this->createMock(Connection::class);

        // Настраиваем createCommand
        $connection->expects($this->once())
            ->method('createCommand')
            ->willReturn($acquireCommand);

        // beginTransaction вызывается только один раз - в acquire
        // release использует транзакцию, сохраненную в acquiredLocks
        $connection->expects($this->once())
            ->method('beginTransaction')
            ->willReturn($transaction);

        $mutex = new PgsqlAdvisoryMutex(['db' => $connection]);

        // Захватываем блокировку
        $acquired = $mutex->acquire('test_lock');
        self::assertTrue($acquired);

        // Пытаемся освободить - должно вернуть false из-за ошибки
        $released = $mutex->release('test_lock');
        self::assertFalse($released);
    }

    /**
     * Тест: DbException в getActiveLocks() возвращает пустой массив
     *
     * Сценарий: если при получении списка активных блокировок происходит
     * DbException, метод должен вернуть пустой массив вместо исключения.
     */
    public function testGetActiveLocksHandlesDbException(): void
    {
        $command = $this->createMock(Command::class);
        $command->method('queryAll')
            ->willThrowException(new DbException('Database error during query'));

        $connection = $this->createMock(Connection::class);
        $connection->method('createCommand')
            ->willReturn($command);

        $mutex = new PgsqlAdvisoryMutex(['db' => $connection]);

        $result = $mutex->getActiveLocks();
        self::assertIsArray($result);
        self::assertEmpty($result);
    }
}
