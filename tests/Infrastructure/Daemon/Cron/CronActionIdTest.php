<?php

namespace App\Tests\Infrastructure\Daemon\Cron;

use App\Infrastructure\Daemon\Cron\CronActionId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CronActionIdTest extends TestCase
{
    #[DataProvider('provideCommands')]
    public function testCommand(CronActionId $id, string $expectedCommand): void
    {
        $this->assertSame($expectedCommand, $id->command());
    }

    public static function provideCommands(): iterable
    {
        yield [CronActionId::GEAR_MAINTENANCE_NOTIFICATION, 'bin/console app:notification:gear-maintenance'];
        yield [CronActionId::APP_UPDATE_AVAILABLE_NOTIFICATION, 'bin/console app:notification:app-update-available'];
    }

    #[DataProvider('provideDefaultCronExpressions')]
    public function testDefaultCronExpression(CronActionId $id, string $expected): void
    {
        $this->assertSame($expected, $id->defaultCronExpression());
    }

    public static function provideDefaultCronExpressions(): iterable
    {
        yield [CronActionId::GEAR_MAINTENANCE_NOTIFICATION, '0 4 * * *'];
        yield [CronActionId::APP_UPDATE_AVAILABLE_NOTIFICATION, '0 4 * * *'];
    }
}
