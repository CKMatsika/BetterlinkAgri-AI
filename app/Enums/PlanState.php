<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Section 3: Authoritative Farm Plan Lifecycle State Machine Enum.
 */
enum PlanState: string
{
    case INTERVIEW = 'INTERVIEW';
    case PLAN_DRAFTED = 'PLAN_DRAFTED';
    case PROJECTION_READY = 'PROJECTION_READY';
    case REVIEW_REQUESTED = 'REVIEW_REQUESTED';
    case ACCEPTED = 'ACCEPTED';
    case ABANDONED = 'ABANDONED';
    case ACTIVATED = 'ACTIVATED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case CLOSED = 'CLOSED';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::INTERVIEW => [
                self::INTERVIEW,
                self::PLAN_DRAFTED,
                self::ABANDONED,
            ],
            self::PLAN_DRAFTED => [
                self::PROJECTION_READY,
                self::INTERVIEW,
            ],
            self::PROJECTION_READY => [
                self::INTERVIEW,
                self::REVIEW_REQUESTED,
                self::ACCEPTED,
                self::ABANDONED,
            ],
            self::REVIEW_REQUESTED => [
                self::PROJECTION_READY,
                self::ABANDONED,
            ],
            self::ACCEPTED => [
                self::ACTIVATED,
                self::INTERVIEW, // creates new version
            ],
            self::ACTIVATED => [
                self::IN_PROGRESS,
                self::INTERVIEW,
            ],
            self::IN_PROGRESS => [
                self::IN_PROGRESS,
                self::CLOSED,
                self::INTERVIEW,
            ],
            self::CLOSED, self::ABANDONED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
