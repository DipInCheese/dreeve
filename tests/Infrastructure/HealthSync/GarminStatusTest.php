<?php

namespace App\Tests\Infrastructure\HealthSync;

use App\Infrastructure\HealthSync\GarminStatus;
use PHPUnit\Framework\TestCase;

class GarminStatusTest extends TestCase
{
    public function testFromArray(): void
    {
        $status = GarminStatus::fromArray([
            'connected' => true,
            'needsLogin' => false,
            'mfaPending' => false,
            'syncing' => true,
            'displayName' => 'Test Runner',
            'since' => '2026-07-18',
            'lastSyncAt' => '2026-10-16T10:00:00+00:00',
            'lastError' => null,
            'activitiesSynced' => 3,
            'backfilling' => true,
        ]);

        $this->assertTrue($status->isConnected());
        $this->assertTrue($status->isSyncing());
        $this->assertSame('Test Runner', $status->getDisplayName());
        $this->assertSame('2026-07-18', $status->getSince()?->format('Y-m-d'));
        $this->assertSame('2026-10-16 10:00', $status->getLastSyncAt()?->format('Y-m-d H:i'));
        $this->assertNull($status->getLastError());
        $this->assertSame(3, $status->getActivitiesSynced());
        $this->assertTrue($status->isBackfilling());
    }

    public function testItToleratesMissingAndInvalidValues(): void
    {
        $status = GarminStatus::fromArray(['connected' => 'yes', 'since' => 'not a date', 'displayName' => '  ', 'activitiesSynced' => '3']);

        $this->assertFalse($status->isConnected());
        $this->assertNull($status->getSince());
        $this->assertNull($status->getDisplayName());
        $this->assertSame(0, $status->getActivitiesSynced());
    }
}
