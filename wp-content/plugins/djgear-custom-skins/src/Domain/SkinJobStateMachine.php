<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Domain;

/**
 * Enforces the server-side dual-track lifecycle state machine for Standard vs. Custom-Design skin jobs.
 *
 * Security & Business Invariants:
 * - Standard plans (`requiresProofApproval = false`) can NEVER enter design-review or proof states.
 * - Custom-Design plans (`requiresProofApproval = true`) can NEVER enter production (`queued_for_production`,
 *   `in_production`, `quality_check`, `packed`, `shipped`, `completed`) without a customer-approved proof
 *   (`hasApprovedProof = true`) — including from `on_hold`.
 * - `approved_for_production` is strictly a customer-controlled transition (staff cannot impersonate approval).
 */
final class SkinJobStateMachine
{
    public const STATUS_DRAFT                   = 'draft';
    public const STATUS_AWAITING_PAYMENT        = 'awaiting_payment';
    public const STATUS_SUBMITTED_FOR_REVIEW    = 'submitted_for_review';
    public const STATUS_IN_DESIGN_REVIEW        = 'in_design_review';
    public const STATUS_REVISION_REQUESTED      = 'revision_requested';
    public const STATUS_PROOF_SENT              = 'proof_sent';
    public const STATUS_APPROVED_FOR_PRODUCTION = 'approved_for_production';
    public const STATUS_QUEUED_FOR_PRODUCTION   = 'queued_for_production';
    public const STATUS_IN_PRODUCTION           = 'in_production';
    public const STATUS_QUALITY_CHECK           = 'quality_check';
    public const STATUS_PACKED                  = 'packed';
    public const STATUS_SHIPPED                 = 'shipped';
    public const STATUS_COMPLETED               = 'completed';
    public const STATUS_ON_HOLD                 = 'on_hold';
    public const STATUS_CANCELLED               = 'cancelled';

    /** @var list<string> Statuses requiring prior customer proof approval on Custom-Design plans */
    public const PRODUCTION_AND_FULFILLMENT_STATUSES = [
        self::STATUS_QUEUED_FOR_PRODUCTION,
        self::STATUS_IN_PRODUCTION,
        self::STATUS_QUALITY_CHECK,
        self::STATUS_PACKED,
        self::STATUS_SHIPPED,
        self::STATUS_COMPLETED,
    ];

    /** @var list<string> Statuses that may ONLY be entered by the owning customer */
    public const CUSTOMER_ONLY_TARGET_STATUSES = [
        self::STATUS_APPROVED_FOR_PRODUCTION,
    ];

    /**
     * @return list<string>
     */
    public static function allStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_AWAITING_PAYMENT,
            self::STATUS_SUBMITTED_FOR_REVIEW,
            self::STATUS_IN_DESIGN_REVIEW,
            self::STATUS_REVISION_REQUESTED,
            self::STATUS_PROOF_SENT,
            self::STATUS_APPROVED_FOR_PRODUCTION,
            self::STATUS_QUEUED_FOR_PRODUCTION,
            self::STATUS_IN_PRODUCTION,
            self::STATUS_QUALITY_CHECK,
            self::STATUS_PACKED,
            self::STATUS_SHIPPED,
            self::STATUS_COMPLETED,
            self::STATUS_ON_HOLD,
            self::STATUS_CANCELLED,
        ];
    }

    /**
     * Base transition graph. Track-specific and proof-approval guards are enforced in `canTransition`.
     *
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::STATUS_DRAFT => [
                self::STATUS_AWAITING_PAYMENT,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_AWAITING_PAYMENT => [
                self::STATUS_QUEUED_FOR_PRODUCTION,
                self::STATUS_SUBMITTED_FOR_REVIEW,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_SUBMITTED_FOR_REVIEW => [
                self::STATUS_IN_DESIGN_REVIEW,
                self::STATUS_ON_HOLD,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_IN_DESIGN_REVIEW => [
                self::STATUS_REVISION_REQUESTED,
                self::STATUS_PROOF_SENT,
                self::STATUS_ON_HOLD,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_REVISION_REQUESTED => [
                self::STATUS_IN_DESIGN_REVIEW,
                self::STATUS_ON_HOLD,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_PROOF_SENT => [
                self::STATUS_REVISION_REQUESTED,
                self::STATUS_APPROVED_FOR_PRODUCTION,
                self::STATUS_ON_HOLD,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_APPROVED_FOR_PRODUCTION => [
                self::STATUS_QUEUED_FOR_PRODUCTION,
                self::STATUS_ON_HOLD,
            ],
            self::STATUS_QUEUED_FOR_PRODUCTION => [
                self::STATUS_IN_PRODUCTION,
                self::STATUS_ON_HOLD,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_IN_PRODUCTION => [
                self::STATUS_QUALITY_CHECK,
                self::STATUS_ON_HOLD,
            ],
            self::STATUS_QUALITY_CHECK => [
                self::STATUS_IN_PRODUCTION,
                self::STATUS_PACKED,
                self::STATUS_ON_HOLD,
            ],
            self::STATUS_PACKED => [
                self::STATUS_SHIPPED,
                self::STATUS_ON_HOLD,
            ],
            self::STATUS_SHIPPED => [
                self::STATUS_COMPLETED,
            ],
            self::STATUS_ON_HOLD => [
                self::STATUS_SUBMITTED_FOR_REVIEW,
                self::STATUS_IN_DESIGN_REVIEW,
                self::STATUS_QUEUED_FOR_PRODUCTION,
                self::STATUS_IN_PRODUCTION,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_COMPLETED => [],
            self::STATUS_CANCELLED => [],
        ];
    }

    /**
     * Determine whether transitioning from `$from` to `$to` is valid given plan track & proof-approval state.
     */
    public static function canTransition(
        string $from,
        string $to,
        bool $requiresProofApproval,
        bool $hasApprovedProof = false,
    ): bool {
        if (!in_array($from, self::allStatuses(), true) || !in_array($to, self::allStatuses(), true)) {
            return false;
        }

        $allowed = self::transitions()[$from] ?? [];
        if (!in_array($to, $allowed, true)) {
            return false;
        }

        // Enforce strict dual-track payment routing:
        if ($from === self::STATUS_AWAITING_PAYMENT) {
            if ($requiresProofApproval && $to === self::STATUS_QUEUED_FOR_PRODUCTION) {
                return false;
            }
            if (!$requiresProofApproval && $to === self::STATUS_SUBMITTED_FOR_REVIEW) {
                return false;
            }
        }

        // Standard plans can NEVER enter design-review / proof states (even from on_hold).
        if (!$requiresProofApproval && in_array($to, [
            self::STATUS_SUBMITTED_FOR_REVIEW,
            self::STATUS_IN_DESIGN_REVIEW,
            self::STATUS_REVISION_REQUESTED,
            self::STATUS_PROOF_SENT,
            self::STATUS_APPROVED_FOR_PRODUCTION,
        ], true)) {
            return false;
        }

        // Custom-Design plans can NEVER enter production/fulfillment without a locked customer-approved proof
        // (blocks bypassing proof approval via `on_hold -> queued_for_production` or `on_hold -> in_production`).
        if ($requiresProofApproval && in_array($to, self::PRODUCTION_AND_FULFILLMENT_STATUSES, true) && !$hasApprovedProof) {
            return false;
        }

        return true;
    }

    /**
     * Assert a transition is valid or throw SkinValidationException (HTTP 400).
     */
    public static function assertTransition(
        string $from,
        string $to,
        bool $requiresProofApproval,
        bool $hasApprovedProof = false,
    ): void {
        if (!self::canTransition($from, $to, $requiresProofApproval, $hasApprovedProof)) {
            throw SkinValidationException::badRequest(
                sprintf('Invalid skin job status transition from "%s" to "%s".', $from, $to),
                'djgear_skins_invalid_transition',
                [
                    'from_status'             => $from,
                    'to_status'               => $to,
                    'requires_proof_approval' => $requiresProofApproval,
                    'has_approved_proof'      => $hasApprovedProof,
                ]
            );
        }
    }

    /**
     * Determine target status when WooCommerce payment is confirmed.
     */
    public static function postPaymentStatus(bool $requiresProofApproval): string
    {
        return $requiresProofApproval
            ? self::STATUS_SUBMITTED_FOR_REVIEW
            : self::STATUS_QUEUED_FOR_PRODUCTION;
    }

    /**
     * Determine target status when a WooCommerce order is cancelled or refunded.
     * Pre-production jobs cancel automatically; active production jobs move to `on_hold` for staff review.
     */
    public static function statusOnOrderCancel(string $currentStatus): ?string
    {
        if (in_array($currentStatus, [
            self::STATUS_DRAFT,
            self::STATUS_AWAITING_PAYMENT,
            self::STATUS_SUBMITTED_FOR_REVIEW,
            self::STATUS_IN_DESIGN_REVIEW,
            self::STATUS_REVISION_REQUESTED,
            self::STATUS_PROOF_SENT,
            self::STATUS_QUEUED_FOR_PRODUCTION,
        ], true)) {
            return self::STATUS_CANCELLED;
        }

        if (in_array($currentStatus, [
            self::STATUS_APPROVED_FOR_PRODUCTION,
            self::STATUS_IN_PRODUCTION,
            self::STATUS_QUALITY_CHECK,
            self::STATUS_PACKED,
        ], true)) {
            return self::STATUS_ON_HOLD;
        }

        return null;
    }

    /**
     * Allowed next statuses for staff/admin UI (excludes customer-only transitions such as `approved_for_production`).
     *
     * @return list<string>
     */
    public static function allowedNextStatuses(
        string $from,
        bool $requiresProofApproval,
        bool $hasApprovedProof = false,
        bool $forStaff = true,
    ): array {
        $candidates = self::transitions()[$from] ?? [];
        $valid = [];
        foreach ($candidates as $next) {
            if ($forStaff && in_array($next, self::CUSTOMER_ONLY_TARGET_STATUSES, true)) {
                continue;
            }
            if (self::canTransition($from, $next, $requiresProofApproval, $hasApprovedProof)) {
                $valid[] = $next;
            }
        }

        return $valid;
    }
}
