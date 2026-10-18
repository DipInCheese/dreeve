<?php

declare(strict_types=1);

namespace App\Domain\Insight\Overview;

/**
 * The three moments of the day an AI overview is written, each with its own focus.
 */
enum OverviewSlot: string
{
    case MORNING = 'morning';
    case AFTERNOON = 'afternoon';
    case EVENING = 'evening';

    public function hour(): int
    {
        return match ($this) {
            self::MORNING => 8,
            self::AFTERNOON => 13,
            self::EVENING => 20,
        };
    }

    /**
     * The latest slot that has started today, or null before the first one. A slot stays due until the
     * next one starts, so when the model can not be reached at 08:00, it is tried again until 13:00.
     */
    public static function dueAt(\DateTimeImmutable $now): ?self
    {
        $hour = (int) $now->format('G');
        $due = null;
        foreach (self::cases() as $slot) {
            if ($hour >= $slot->hour()) {
                $due = $slot;
            }
        }

        return $due;
    }

    /**
     * What the overview of this moment is about.
     */
    public function focus(): string
    {
        return match ($this) {
            self::MORNING => 'It is morning. Focus on last night: how the user slept, how recovered they are (HRV, resting heart rate, Body Battery, training readiness), and what that means for today, for example whether to train hard or take it easy.',
            self::AFTERNOON => 'It is early afternoon. Focus on the day so far: Body Battery now against how far it charged, stress, steps and activity, and what would help for the rest of the day.',
            self::EVENING => 'It is evening. Look back on the day: activity, stress and how much Body Battery is left, and give advice for a good night: when to wind down and go to bed, given the usual bedtime.',
        };
    }
}
