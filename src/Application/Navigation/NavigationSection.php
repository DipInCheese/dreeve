<?php

declare(strict_types=1);

namespace App\Application\Navigation;

enum NavigationSection: string
{
    case TODAY = 'today';
    case HEALTH_SLEEP = 'health-sleep';
    case HEALTH_HEART = 'health-heart';
    case HEALTH_STRESS = 'health-stress';
    case HEALTH_BODY = 'health-body';
    case HEALTH_FITNESS = 'health-fitness';
    case DASHBOARD = 'dashboard';
    case TRAINING_LOAD = 'training-load';
    case INSIGHTS = 'insights';
    case ACTIVITIES = 'activities';
    case GEAR = 'gear';
    case SEGMENTS = 'segments';
    case MONTHLY_STATS = 'monthly-stats';
    case EDDINGTON = 'eddington';
    case HEATMAP = 'heatmap';
    case BEST_EFFORTS = 'best-efforts';
    case MILESTONES = 'milestones';
    case REWIND = 'rewind';
    case PHOTOS = 'photos';
    case GARMIN = 'garmin';
    case UPLOAD = 'upload';
    case FILE_IMPORTS = 'file-imports';
    case MANAGE_ACTIVITIES = 'manage-activities';
    case MANAGE_GEAR = 'manage-gear';
    case MANAGE_SEGMENTS = 'manage-segments';
    case AUTOMATION_RULES = 'automation-rules';
    case USERS = 'users';
    case SETTINGS = 'settings';

    /**
     * The "Manage" section a page under /admin belongs to.
     */
    public static function forManagePath(string $path): ?self
    {
        $prefixes = [
            '/admin/garmin' => self::GARMIN,
            '/admin/upload' => self::UPLOAD,
            '/admin/file-imports' => self::FILE_IMPORTS,
            '/admin/activities' => self::MANAGE_ACTIVITIES,
            '/admin/gear' => self::MANAGE_GEAR,
            '/admin/segments' => self::MANAGE_SEGMENTS,
            '/admin/automation-rules' => self::AUTOMATION_RULES,
            '/admin/users' => self::USERS,
            '/admin/settings' => self::SETTINGS,
        ];
        foreach ($prefixes as $prefix => $section) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $section;
            }
        }

        return null;
    }
}
