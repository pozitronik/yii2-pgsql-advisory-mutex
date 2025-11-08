<?php
declare(strict_types=1);

namespace Beeline\PgsqlAdvisoryMutex;

use InvalidArgumentException;
use UnexpectedValueException;
use Yii;
use yii\base\InvalidConfigException;
use yii\db\Connection;
use yii\db\Exception as DbException;
use yii\db\Transaction;
use yii\mutex\Mutex;

/**
 * Распределённый mutex на основе PostgreSQL transaction-level advisory locks.
 *
 * Использует функцию try_advisory_xact_lock_timeout() для получения блокировок,
 * привязанных к транзакции, что обеспечивает безопасность при connection pooling.
 *
 * Ключевые особенности:
 * - Блокировки привязаны к транзакции, не к сессии
 * - Автоматическое освобождение при COMMIT/ROLLBACK
 * - Работает через PgBouncer в любом режиме (session/transaction/statement)
 * - Поддержка timeout в миллисекундах
 * - Поддержка shared (читатели) / exclusive (писатели) режимов
 * - Низкие накладные расходы (виртуальные блокировки без физического хранения)
 *
 * Ограничения:
 * - НЕ поддерживает reentrant режим (нельзя захватить одну блокировку дважды в одной транзакции)
 * - Требует PostgreSQL 9.1+ (для pg_advisory_xact_lock)
 * - Имена блокировок хэшируются в int64 через CRC32 (возможны коллизии)
 * - **ВАЖНО**: В среде с вложенными транзакциями (например, Codeception TransactionForcer)
 *   блокировка НЕ будет освобождена при release(), а только при коммите внешней транзакции.
 *   Это корректное поведение transaction-level advisory locks, но оно не совместимо с Codeception
 *
 * @property Connection $db Компонент соединения с БД
 */
class PgsqlAdvisoryMutex extends Mutex
{
    /**
     * Компонент БД или его ID
     *
     * @var Connection|string
     */
    public Connection|string $db = 'db';

    /**
     * Режим блокировки:
     * - false (по умолчанию) = эксклюзивная блокировка (exclusive lock)
     * - true = разделяемая блокировка (shared lock)
     *
     * Shared locks позволяют множественный захват (читатели), но блокируют exclusive locks (писатели).
     */
    public bool $sharedMode = false;

    /**
     * Название SQL функции для получения блокировки
     *
     * Функция должна иметь сигнатуру:
     * function_name(key bigint, shared boolean, timeout_ms integer) RETURNS boolean
     */
    public string $functionName = 'try_advisory_xact_lock_timeout';

    /**
     * Отслеживание захваченных блокировок и их транзакций
     *
     * Карта: lock_name => [
     *     'transaction' => Transaction,  // Транзакция, в которой захвачена блокировка
     *     'lockKey' => int,              // CRC32 хэш имени блокировки
     * ]
     *
     * @var array<string, array{transaction: Transaction, lockKey: int}>
     */
    private array $acquiredLocks = [];

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        if (is_string($this->db)) {
            $this->db = Yii::$app->get($this->db);
        }
    }

    /**
     * @inheritdoc
     */
    protected function acquireLock($name, $timeout = 0): bool
    {
        // Валидация входных параметров
        if ('' === $name) {
            throw new InvalidArgumentException('Lock name cannot be empty.');
        }

        if ($timeout < 0) {
            throw new InvalidArgumentException('Timeout must be non-negative.');
        }

        // Конвертируем имя блокировки в bigint ключ через MD5
        $lockKey = $this->generateLockKey($name);

        // Конвертируем timeout из секунд в миллисекунды: max int32 миллисекунд = ~24 дня
        $timeoutMs = min(($timeout * 1000), 2147483647);

        $transaction = null;

        try {
            /**
             * Начинаем транзакцию для этой блокировки
             * ВАЖНО: Каждая блокировка должна быть в отдельной транзакции, так как xact lock освобождается при COMMIT транзакции
             *
             * ПРИМЕЧАНИЕ: В тестовой среде (Codeception) может уже существовать  внешняя транзакция.
             * В этом случае beginTransaction() создаст SAVEPOINT.
             * Xact advisory locks НЕ освобождаются при SAVEPOINT commit, только при commit внешней транзакции.
             */
            $transaction = $this->db->beginTransaction();
            $transactionLevel = $transaction->getLevel();

            // Вызываем SQL функцию для получения блокировки
            $acquired = $this->db->createCommand(
                "SELECT {$this->functionName}(:key, :shared, :timeout)",
            )->bindValues([
                ':key' => $lockKey,
                ':shared' => $this->sharedMode,
                ':timeout' => $timeoutMs,
            ])->queryScalar();

            if ($acquired) {
                // Сохраняем транзакцию - она должна остаться открытой!
                $this->acquiredLocks[$name] = compact('transaction', 'lockKey', 'transactionLevel');

                if ($transactionLevel > 1) {
                    Yii::warning(
                        "Advisory lock '$name' (key=$lockKey) acquired in NESTED transaction (level=$transactionLevel). " .
                        "Lock will NOT be released until outer transaction commits. " .
                        "This may indicate test environment (Codeception TransactionForcer) or application-level transaction wrapping.",
                        __METHOD__,
                    );
                }

                Yii::debug(
                    "Advisory lock '$name' (key=$lockKey) acquired in " .
                    ($this->sharedMode ? 'SHARED' : 'EXCLUSIVE') . " mode (tx level=$transactionLevel)",
                    __METHOD__,
                );

                return true;
            }

            // Не удалось получить блокировку - откатываем транзакцию
            $transaction->rollBack();

            Yii::debug("Advisory lock '$name' (key=$lockKey) NOT acquired (timeout={$timeout}s)", __METHOD__);

            return false;
        } catch (DbException $e) {
            // При ошибке откатываем транзакцию, если она существует
            if (isset($transaction) && $transaction->getIsActive()) {
                $transaction->rollBack();
            }

            Yii::error("Failed to acquire advisory lock '$name': {$e->getMessage()}", __METHOD__);
            throw $e;
        }
    }

    /**
     * @inheritdoc
     */
    protected function releaseLock($name): bool
    {
        // Проверяем, захватывали ли мы эту блокировку
        if (!isset($this->acquiredLocks[$name])) {
            Yii::warning("Attempted to release advisory lock '$name' that was not acquired by this mutex instance", __METHOD__);
            return false;
        }

        $lockInfo = $this->acquiredLocks[$name];
        $transaction = $lockInfo['transaction'];
        $lockKey = $lockInfo['lockKey'];

        // Удаляем из tracking
        unset($this->acquiredLocks[$name]);

        // Проверяем, что транзакция все еще активна (пользователь мог вручную закоммитить/откатить)
        if (!$transaction->getIsActive()) {
            Yii::warning("Advisory lock '$name' (key=$lockKey) transaction already closed externally", __METHOD__);
            return false;
        }

        try {
            // COMMIT автоматически освобождает xact advisory lock, но может выбросить DbException при ошибке БД
            $transaction->commit();

            Yii::debug("Advisory lock '$name' (key=$lockKey) released via COMMIT", __METHOD__);

            return true;
        } catch (DbException $e) {
            Yii::error("Failed to release advisory lock '$name': {$e->getMessage()}", __METHOD__);

            // Пытаемся откатить при ошибке
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }

            return false;
        }
    }

    /**
     * Получить информацию о текущих advisory locks в системе
     *
     * Полезно для отладки и мониторинга.
     *
     * @return array Список активных advisory locks
     */
    public function getActiveLocks(): array
    {
        try {
            return $this->db->createCommand(
                "SELECT pid, locktype, mode, granted, objid as lock_key, pg_backend_pid() = pid as is_current_connection
                 FROM pg_locks
                 WHERE locktype = 'advisory'
                 ORDER BY pid, objid",
            )->queryAll();
        } catch (DbException $e) {
            Yii::error("Failed to fetch active advisory locks: {$e->getMessage()}", __METHOD__);
            return [];
        }
    }

    /**
     * Получить список блокировок, захваченных этим mutex
     *
     * @return array<string, array{lockKey: int, sharedMode: bool}>
     */
    public function getAcquiredLocks(): array
    {
        $result = [];

        foreach ($this->acquiredLocks as $name => $info) {
            $result[$name] = [
                'lockKey' => $info['lockKey'],
                'sharedMode' => $this->sharedMode,
            ];
        }

        return $result;
    }

    /**
     * Принудительное освобождение всех захваченных блокировок
     *
     * ВНИМАНИЕ: Использовать только для очистки при shutdown или в тестах!
     * Откатывает все активные транзакции.
     *
     * @return int Количество освобожденных блокировок
     */
    public function releaseAll(): int
    {
        $count = 0;

        foreach (array_keys($this->acquiredLocks) as $name) {
            if ($this->release($name)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Генерация lock key из строкового имени блокировки
     *
     * @param string $name Имя блокировки
     *
     * @return int Lock key для использования в PostgreSQL
     */
    private function generateLockKey(string $name): int
    {
        $hash = unpack('q', hash('xxh64', $name, binary: true));
        if (false === $hash) {
            throw new UnexpectedValueException('Failed to generate lock key');
        }
        /** @noinspection OffsetOperationsInspection Функция гарантирует нам результат */
        return $hash[1];
    }
}
