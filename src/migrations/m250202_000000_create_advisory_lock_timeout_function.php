<?php
declare(strict_types=1);

use yii\db\Migration;

/**
 * Создание функции try_advisory_xact_lock_timeout для безопасной работы
 * с PostgreSQL transaction-level advisory locks.
 *
 * Функция обеспечивает:
 * - Транзакционные advisory locks (автоматическое освобождение при COMMIT/ROLLBACK)
 * - Поддержку timeout в миллисекундах
 * - Поддержку shared/exclusive режимов
 * - Безопасность при connection pooling (PgBouncer)
 * - Корректное восстановление lock_timeout после выполнения
 *
 * Использование:
 * ```sql
 * -- Эксклюзивная блокировка с таймаутом 2 секунды
 * SELECT try_advisory_xact_lock_timeout(42, false, 2000);
 *
 * -- Разделяемая блокировка без ожидания
 * SELECT try_advisory_xact_lock_timeout(42, true, 0);
 * ```
 */
class m250202_000000_create_advisory_lock_timeout_function extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): void
    {
        /*
         * Функция try_advisory_xact_lock_timeout объединяет поведение
         * pg_try_advisory_xact_lock и pg_advisory_xact_lock.
         *
         * Параметры:
         * - key: идентификатор блокировки (bigint)
         * - shared: true = разделяемая, false = эксклюзивная
         * - timeout_ms: таймаут в миллисекундах (0 = без ожидания)
         *
         * Возвращает: true если блокировка получена, false при таймауте
         *
         * Особенности:
         * - Блокировка привязана к транзакции (auto-release при COMMIT/ROLLBACK)
         * - Безопасна при connection pooling
         * - Корректно восстанавливает lock_timeout после выполнения
         */
        $this->execute("
CREATE OR REPLACE FUNCTION try_advisory_xact_lock_timeout(
    key bigint,
    shared boolean DEFAULT false,
    timeout_ms integer DEFAULT 0
)
RETURNS boolean AS \$func\$
DECLARE
    ok boolean := false;
    old_timeout text;
BEGIN
    IF timeout_ms < 0 THEN
        RAISE EXCEPTION USING
            MESSAGE = format('timeout_ms должен быть неотрицательным (получено %s)', timeout_ms),
            ERRCODE = '22023';
    END IF;

    IF timeout_ms = 0 THEN
        IF shared THEN
            ok := pg_try_advisory_xact_lock_shared(key);
        ELSE
            ok := pg_try_advisory_xact_lock(key);
        END IF;
        RETURN ok;
    END IF;

    old_timeout := current_setting('lock_timeout');
    PERFORM set_config('lock_timeout', (timeout_ms::text || 'ms'), true);

    BEGIN
        IF shared THEN
            PERFORM pg_advisory_xact_lock_shared(key);
        ELSE
            PERFORM pg_advisory_xact_lock(key);
        END IF;
        ok := true;

    EXCEPTION
        WHEN SQLSTATE '57014' THEN
            ok := false;

        WHEN OTHERS THEN
            RAISE NOTICE
                'Неожиданная ошибка в try_advisory_xact_lock_timeout(%): SQLSTATE=%, SQLERRM=%',
                key, SQLSTATE, SQLERRM;
            ok := false;
    END;

    BEGIN
        PERFORM set_config('lock_timeout', old_timeout, true);
    EXCEPTION
        WHEN OTHERS THEN
            RAISE NOTICE
                'Не удалось восстановить lock_timeout до \"%\": %',
                old_timeout, SQLERRM;
    END;

    RETURN ok;
END;
\$func\$ LANGUAGE plpgsql
        ");

        // Добавляем комментарий к функции (отдельной командой)
        $this->execute("
COMMENT ON FUNCTION try_advisory_xact_lock_timeout(bigint, boolean, integer)
IS 'Получение транзакционной advisory lock с таймаутом в миллисекундах. Возвращает TRUE при успехе, FALSE при таймауте.'
        ");
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): void
    {
        $this->execute('DROP FUNCTION IF EXISTS try_advisory_xact_lock_timeout(bigint, boolean, integer);');
    }
}
