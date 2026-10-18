<?php

declare(strict_types=1);

namespace App\Infrastructure\HealthSync;

use App\Domain\Health\HealthRepository;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Garmin connection as the header shows it on every page. Asked from health-sync at most once a
 * minute: a page never waits on it for long, and an unreachable service simply shows nothing.
 */
final readonly class SyncStatus
{
    private const string CACHE_KEY = 'health-sync.status';
    private const int CACHE_SECONDS = 60;
    private const int STALE_WATCH_HOURS = 6;

    public function __construct(
        private HealthSync $healthSync,
        private HealthRepository $healthRepository,
        private CacheInterface $cache,
        private Clock $clock,
        private TranslatorInterface $translator,
    ) {
    }

    public function current(): ?GarminStatus
    {
        if (!$this->healthSync->isAvailable()) {
            return null;
        }

        /** @var array<string, mixed>|null $status */
        $status = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): ?array {
            $item->expiresAfter(self::CACHE_SECONDS);
            try {
                return $this->healthSync->status()->toArray();
            } catch (HealthSyncFailed) {
                return null;
            }
        });

        return null === $status ? null : GarminStatus::fromArray($status);
    }

    public function forget(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }

    /**
     * "Synced 12 min ago", for the header.
     */
    public function describe(GarminStatus $status): string
    {
        if ($status->isSyncing()) {
            return $this->translator->trans('Syncing…');
        }
        $lastSyncAt = $status->getLastSyncAt();
        if (!$lastSyncAt instanceof SerializableDateTime) {
            return $this->translator->trans('Not synced yet');
        }

        $minutes = (int) floor(($this->clock->getCurrentDateTimeImmutable()->getTimestamp() - $lastSyncAt->getTimestamp()) / 60);

        return match (true) {
            $minutes < 1 => $this->translator->trans('Synced just now'),
            $minutes < 60 => $this->translator->trans('Synced {minutes} min ago', ['{minutes}' => $minutes]),
            $minutes < 24 * 60 => $this->translator->trans('Synced {hours} h ago', ['{hours}' => intdiv($minutes, 60)]),
            default => $this->translator->trans('Synced on {date}', ['{date}' => $lastSyncAt->format('d-m-Y')]),
        };
    }

    /**
     * When the watch has not uploaded to Garmin for a while, syncing on time brings nothing new: says
     * so, so a stuck upload is not taken for a sync problem. Nothing when it is recent or unknown.
     */
    public function describeStaleWatch(GarminStatus $status): ?string
    {
        if (!$status->isConnected() || $status->needsLogin()) {
            return null;
        }
        $uploadedAt = $this->healthRepository->findLatestWatchUpload();
        if (!$uploadedAt instanceof SerializableDateTime) {
            return null;
        }

        $now = $this->clock->getCurrentDateTimeImmutable();
        if ($now->getTimestamp() - $uploadedAt->getTimestamp() <= self::STALE_WATCH_HOURS * 3600) {
            return null;
        }
        $uploadedAt = $uploadedAt->setTimezone($now->getTimezone());

        return $this->translator->trans("Garmin hasn't received data from your watch since {time}. Open the Garmin Connect app on your phone to sync it.", [
            '{time}' => $uploadedAt->format($uploadedAt->format('Y-m-d') === $now->format('Y-m-d') ? 'H:i' : 'd-m-Y H:i'),
        ]);
    }
}
