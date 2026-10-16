<?php

declare(strict_types=1);

namespace App\Infrastructure\Daemon\Cron;

use App\Console\Notification\AppUpdateAvailableNotificationConsoleCommand;
use App\Console\Notification\GearMaintenanceNotificationConsoleCommand;
use App\Infrastructure\Localisation\TranslatableWithDescription;
use Symfony\Contracts\Translation\TranslatorInterface;

enum CronActionId: string implements TranslatableWithDescription
{
    // The backing values are persisted in the daemon settings, renaming one would reset the user's configuration.
    case GEAR_MAINTENANCE_NOTIFICATION = 'gearMaintenanceNotification';
    case APP_UPDATE_AVAILABLE_NOTIFICATION = 'appUpdateAvailableNotification';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::GEAR_MAINTENANCE_NOTIFICATION => $translator->trans('Gear maintenance notification', domain: 'admin', locale: $locale),
            self::APP_UPDATE_AVAILABLE_NOTIFICATION => $translator->trans('App update available notification', domain: 'admin', locale: $locale),
        };
    }

    public function transDescription(TranslatorInterface $translator, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return match ($this) {
            self::GEAR_MAINTENANCE_NOTIFICATION => $translator->trans('Sends a notification when gear maintenance is due. Requires a configured notification service.', domain: 'admin', locale: $locale),
            self::APP_UPDATE_AVAILABLE_NOTIFICATION => $translator->trans('Sends a notification when a new app version is available. Requires a configured notification service.', domain: 'admin', locale: $locale),
        };
    }

    public function command(): string
    {
        return match ($this) {
            self::GEAR_MAINTENANCE_NOTIFICATION => sprintf('bin/console %s', GearMaintenanceNotificationConsoleCommand::NAME),
            self::APP_UPDATE_AVAILABLE_NOTIFICATION => sprintf('bin/console %s', AppUpdateAvailableNotificationConsoleCommand::NAME),
        };
    }

    public function defaultCronExpression(): string
    {
        return match ($this) {
            self::GEAR_MAINTENANCE_NOTIFICATION, self::APP_UPDATE_AVAILABLE_NOTIFICATION => '0 4 * * *',
        };
    }
}
