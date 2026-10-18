<?php

declare(strict_types=1);

namespace App\Domain\Insight\Overview;

use App\Domain\Insight\Insight;

/**
 * An AI overview as shown on Today: a headline, a few sentences and some tips. The model answers in
 * this shape (JSON), so nothing it writes is ever shown as HTML.
 */
final readonly class Overview
{
    public const string INSIGHT_TYPE = 'overview';
    private const int MAX_TIPS = 3;

    /**
     * @param list<string> $tips
     */
    private function __construct(
        public string $headline,
        public string $summary,
        public array $tips,
        public OverviewSlot $slot,
        public \DateTimeImmutable $writtenOn,
        public string $model,
    ) {
    }

    /**
     * One overview per moment of the day, e.g. "overview:2026-10-18:morning".
     */
    public static function insightId(OverviewSlot $slot, string $day): string
    {
        return sprintf('%s:%s:%s', self::INSIGHT_TYPE, $day, $slot->value);
    }

    /**
     * The model's answer as content to store: only the fields that are shown, cleaned up.
     * Null when the answer is not the asked shape.
     */
    public static function contentFromAnswer(string $answer): ?string
    {
        // Some models wrap JSON in a code block or add a sentence around it: take the object itself.
        if (false === ($start = strpos($answer, '{')) || false === ($end = strrpos($answer, '}'))) {
            return null;
        }
        $data = json_decode(substr($answer, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            return null;
        }

        $headline = self::text($data['headline'] ?? null);
        $summary = self::text($data['summary'] ?? null);
        if (null === $headline || null === $summary) {
            return null;
        }
        $tips = array_values(array_filter(array_map(self::text(...), is_array($data['tips'] ?? null) ? $data['tips'] : [])));

        return json_encode([
            'headline' => $headline,
            'summary' => $summary,
            'tips' => array_slice($tips, 0, self::MAX_TIPS),
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromInsight(Insight $insight): ?self
    {
        $parts = explode(':', $insight->id);
        $slot = OverviewSlot::tryFrom(end($parts));
        $data = json_decode($insight->content, true);
        if (!$slot instanceof OverviewSlot || !is_array($data) || !is_string($data['headline'] ?? null) || !is_string($data['summary'] ?? null)) {
            return null;
        }

        return new self(
            headline: $data['headline'],
            summary: $data['summary'],
            tips: array_values(array_filter(is_array($data['tips'] ?? null) ? $data['tips'] : [], is_string(...))),
            slot: $slot,
            writtenOn: new \DateTimeImmutable($insight->createdOn),
            model: $insight->model,
        );
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim(strip_tags($value));

        return '' === $value ? null : $value;
    }
}
