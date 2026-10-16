<?php

namespace App\Tests\Infrastructure\Daemon\Cron;

use App\Infrastructure\Daemon\Cron\CronAction;
use App\Infrastructure\Daemon\Cron\CronActionId;
use Cron\CronExpression;
use PHPUnit\Framework\TestCase;

class CronActionTest extends TestCase
{
    public function testGetIdAndExpression(): void
    {
        $cronAction = CronAction::create(
            id: CronActionId::GEAR_MAINTENANCE_NOTIFICATION,
            expression: new CronExpression('0 2 * * *'),
        );

        $this->assertSame(CronActionId::GEAR_MAINTENANCE_NOTIFICATION, $cronAction->getId());
        $this->assertEquals(new CronExpression('0 2 * * *'), $cronAction->getExpression());
    }

    public function testItDelegatesToItsId(): void
    {
        $cronAction = CronAction::create(
            id: CronActionId::GEAR_MAINTENANCE_NOTIFICATION,
            expression: new CronExpression('* * * * *'),
        );

        $this->assertSame('bin/console app:notification:gear-maintenance', $cronAction->getCommand());
    }
}
