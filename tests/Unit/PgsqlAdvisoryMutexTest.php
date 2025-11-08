<?php
declare(strict_types=1);

namespace Beeline\PgsqlAdvisoryMutex\Tests\Unit;

use Beeline\PgsqlAdvisoryMutex\PgsqlAdvisoryMutex;
use PHPUnit\Framework\TestCase;
use stdClass;
use TypeError;

/**
 * Модульные тесты для PgsqlAdvisoryMutex (без реального подключения к БД)
 *
 * Тестируют:
 * - Валидацию конфигурации
 * - Валидацию входных параметров
 * - Обработку граничных случаев без взаимодействия с БД
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


}
