<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Services;

use DJGear\Core\Logging\AuditEvent;
use DJGear\Core\Logging\AuditLoggerInterface;
use DJGear\CustomSkins\Domain\SkinJobStateMachine;
use DJGear\CustomSkins\Domain\SkinValidationException;
use DJGear\CustomSkins\Domain\SkinValidator;
use DJGear\CustomSkins\Repositories\SkinJobRepository;
use DJGear\CustomSkins\Repositories\SkinPlanRepository;
use DJGear\CustomSkins\Repositories\SkinVariantRepository;
use DJGear\DeviceCatalog\Repositories\DeviceRepository;

/**
 * Commercial custom-skin orchestration service.
 * Enforces plan upload rules, ownership isolation, DPI validation, revision-bound acknowledgments,
 * on-disk SHA-256 proof integrity verification, atomic state transitions, and WooCommerce notes.
 */
final class SkinOrderService
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly SkinVariantRepository $variants,
        private readonly SkinPlanRepository $plans,
        private readonly SkinJobRepository $jobs,
        private readonly CustomerArtworkVault $vault,
        private readonly ArtworkDpiValidator $dpiValidator,
        private readonly AuditLoggerInterface $audit,
    ) {
    }

    /**
     * Create a customer draft skin job and freeze the commercial configuration snapshot.
     *
     * @return array<string, mixed>
     */
    public function createCustomerDraft(
        int $customerUserId,
        int $deviceId,
        int $skinVariantId,
        int $planId,
        string $customerNotes = '',
    ): array {
        if ($customerUserId <= 0) {
            throw SkinValidationException::forbidden('Authentication is required to configure a custom skin order.');
        }

        $device = $this->devices->findById($deviceId);
        if ($device === null || (string) ($device['lifecycle_status'] ?? '') !== 'active') {
            throw SkinValidationException::invalidField('device_id', 'Selected DJ device is unavailable.');
        }

        $variant = $this->variants->findById($skinVariantId);
        if ($variant === null || (string) ($variant['status'] ?? '') !== 'active') {
            throw SkinValidationException::invalidField('skin_variant_id', 'Selected skin model/color is unavailable.');
        }

        if ((int) $variant['device_id'] !== $deviceId) {
            throw SkinValidationException::invalidField(
                'skin_variant_id',
                'Selected skin variant does not belong to the chosen DJ device.'
            );
        }

        $plan = $this->plans->findById($planId);
        if ($plan === null || !((bool) ($plan['is_active'] ?? false))) {
            throw SkinValidationException::invalidField('plan_id', 'Selected ordering plan is unavailable.');
        }

        $snapshot = [
            'device' => [
                'device_id'   => (int) ($device['id'] ?? $deviceId),
                'slug'        => (string) ($device['slug'] ?? ''),
                'model_code'  => (string) ($device['model_code'] ?? ''),
                'name_fa'     => (string) ($device['name_fa'] ?? ''),
                'name_en'     => (string) ($device['name_en'] ?? ''),
                'brand_fa'    => is_array($device['brand'] ?? null) ? (string) ($device['brand']['name_fa'] ?? '') : '',
                'brand_en'    => is_array($device['brand'] ?? null) ? (string) ($device['brand']['name_en'] ?? '') : '',
            ],
            'variant' => [
                'id'              => (int) $variant['id'],
                'wc_product_id'   => (int) $variant['wc_product_id'],
                'wc_variation_id' => $variant['wc_variation_id'],
                'model_code'      => (string) $variant['model_code'],
                'model_name_fa'   => (string) $variant['model_name_fa'],
                'model_name_en'   => (string) $variant['model_name_en'],
                'color_code'      => (string) $variant['color_code'],
                'color_name_fa'   => (string) $variant['color_name_fa'],
                'color_name_en'   => (string) $variant['color_name_en'],
                'color_hex'       => (string) $variant['color_hex'],
                'finish'          => (string) $variant['finish'],
                'print_width_mm'  => $variant['print_width_mm'],
                'print_height_mm' => $variant['print_height_mm'],
                'base_price'      => $variant['base_price'],
                'currency'        => (string) ($variant['currency'] ?? 'IRT'),
            ],
            'plan' => [
                'id'                      => (int) $plan['id'],
                'slug'                    => (string) $plan['slug'],
                'name_fa'                 => (string) $plan['name_fa'],
                'name_en'                 => (string) $plan['name_en'],
                'plan_type'               => (string) $plan['plan_type'],
                'allows_artwork_upload'   => (bool) $plan['allows_artwork_upload'],
                'requires_artwork'        => (bool) $plan['requires_artwork'],
                'requires_proof_approval' => (bool) $plan['requires_proof_approval'],
                'max_revisions'           => (int) $plan['max_revisions'],
                'price_surcharge'         => (float) $plan['price_surcharge'],
                'estimated_lead_days'     => (int) $plan['estimated_lead_days'],
            ],
        ];

        $jobUuid = $this->vault->generateUuidV4();
        $cleanNotes = SkinValidator::sanitizeNotes($customerNotes, 1500);

        $job = $this->jobs->createDraft(
            $jobUuid,
            $customerUserId,
            $deviceId,
            $skinVariantId,
            $planId,
            $snapshot,
            $cleanNotes
        );

        $this->audit->record(new AuditEvent(
            action: 'skins.job.created',
            resourceType: 'skin_job',
            resourceId: $jobUuid,
            actorId: $customerUserId,
            context: [
                'device_id'       => $deviceId,
                'skin_variant_id' => $skinVariantId,
                'plan_slug'       => (string) $plan['slug'],
                'plan_type'       => (string) $plan['plan_type'],
            ],
        ));

        return $job;
    }

    /**
     * Upload customer artwork for a job. Enforces ownership, plan upload allowance, uploadable status,
     * private vault storage, DPI evaluation, and invalidation of prior acknowledgments.
     *
     * @return array{job: array<string, mixed>, artwork: array<string, mixed>, dpi_report: array<string, mixed>}
     */
    public function uploadCustomerArtwork(
        string $jobUuid,
        int $customerUserId,
        string $tmpFilePath,
        string $originalFilename,
        string $customerNote = '',
    ): array {
        $job = $this->requireOwnedJob($jobUuid, $customerUserId);
        $snapshot = is_array($job['snapshot'] ?? null) ? $job['snapshot'] : [];
        $planSnap = is_array($snapshot['plan'] ?? null) ? $snapshot['plan'] : [];
        $variantSnap = is_array($snapshot['variant'] ?? null) ? $snapshot['variant'] : [];

        if (!((bool) ($planSnap['allows_artwork_upload'] ?? false))) {
            throw SkinValidationException::badRequest(
                'The selected Standard ordering plan does not allow artwork uploads. Choose a Custom Design plan to upload custom artwork.',
                'djgear_skins_plan_disallows_artwork',
                ['plan_slug' => (string) ($planSnap['slug'] ?? '')]
            );
        }

        $status = (string) ($job['status'] ?? '');
        if (!in_array($status, [SkinJobStateMachine::STATUS_DRAFT, SkinJobStateMachine::STATUS_REVISION_REQUESTED], true)) {
            throw SkinValidationException::badRequest(
                sprintf('Artwork cannot be uploaded while job is in "%s" status.', $status),
                'djgear_skins_upload_not_allowed_in_status'
            );
        }

        $stored = $this->vault->storeFile($tmpFilePath, $originalFilename, $jobUuid, false);

        $printW = isset($variantSnap['print_width_mm']) && is_numeric($variantSnap['print_width_mm'])
            ? (float) $variantSnap['print_width_mm']
            : null;
        $printH = isset($variantSnap['print_height_mm']) && is_numeric($variantSnap['print_height_mm'])
            ? (float) $variantSnap['print_height_mm']
            : null;

        $dpiReport = $this->dpiValidator->evaluateFile($stored['absolute_path'], $stored['mime_type'], $printW, $printH);
        $dpiStatus = (string) ($dpiReport['dpi_status'] ?? ArtworkDpiValidator::STATUS_REJECTED);

        $noteClean = SkinValidator::sanitizeNotes($customerNote, 1000);
        $artwork = $this->jobs->addArtworkRevision((int) $job['id'], SkinJobRepository::REVISION_CUSTOMER_ARTWORK, [
            'uploaded_by_user_id'  => $customerUserId,
            'storage_rel_path'     => $stored['storage_rel_path'],
            'original_filename'    => $stored['original_filename'],
            'mime_type'            => $stored['mime_type'],
            'file_size_bytes'      => $stored['file_size_bytes'],
            'sha256_hash'          => $stored['sha256_hash'],
            'pixel_width'          => $stored['pixel_width'],
            'pixel_height'         => $stored['pixel_height'],
            'computed_dpi'         => $dpiReport['computed_dpi'] ?? null,
            'dpi_status'           => $dpiStatus,
            'preflight_report'     => $dpiReport,
            'low_dpi_acknowledged' => false,
            'customer_decision'    => 'not_applicable',
            'note_en'              => $noteClean !== '' ? $noteClean : null,
        ]);

        if ($status === SkinJobStateMachine::STATUS_REVISION_REQUESTED && $dpiStatus === ArtworkDpiValidator::STATUS_PASS) {
            $job = $this->jobs->transitionStatus(
                (int) $job['id'],
                SkinJobStateMachine::STATUS_IN_DESIGN_REVIEW,
                $customerUserId,
                'customer',
                true,
                sprintf('Customer uploaded revised artwork v%d (%.1f DPI).', (int) $artwork['version_number'], (float) ($dpiReport['computed_dpi'] ?? 0.0)),
                [],
                SkinJobStateMachine::STATUS_REVISION_REQUESTED
            );
        } else {
            $reloaded = $this->jobs->findById((int) $job['id'], true);
            if ($reloaded !== null) {
                $job = $reloaded;
            }
        }

        $this->audit->record(new AuditEvent(
            action: 'skins.artwork.uploaded',
            resourceType: 'skin_job',
            resourceId: $jobUuid,
            actorId: $customerUserId,
            context: [
                'artwork_id'   => (int) $artwork['id'],
                'version'      => (int) $artwork['version_number'],
                'mime_type'    => $stored['mime_type'],
                'dpi_status'   => $dpiStatus,
                'computed_dpi' => $dpiReport['computed_dpi'] ?? null,
                'sha256_hash'  => $stored['sha256_hash'],
            ],
        ));

        return [
            'job'        => $job,
            'artwork'    => $artwork,
            'dpi_report' => $dpiReport,
        ];
    }

    /**
     * Record explicit customer acknowledgment strictly bound to the job's CURRENT artwork revision ID & SHA-256 digest.
     * Supports `warning` (180–299 DPI) and `manual_review_required` (unmeasurable PDF on proof-reviewed plans).
     * Rejects stale revision IDs or mismatched SHA-256 digests.
     *
     * @return array<string, mixed>
     */
    public function acknowledgeLowDpi(
        string $jobUuid,
        int $customerUserId,
        ?int $artworkId = null,
        ?string $expectedSha256 = null,
    ): array {
        $job = $this->requireOwnedJob($jobUuid, $customerUserId);
        $currentArtworkId = isset($job['current_artwork_id']) ? (int) $job['current_artwork_id'] : 0;

        if ($currentArtworkId <= 0) {
            throw SkinValidationException::badRequest('No uploaded artwork found on this job.');
        }

        if ($artworkId !== null && $artworkId !== $currentArtworkId) {
            throw SkinValidationException::badRequest(
                'DPI acknowledgment must match the job\'s latest active artwork revision.',
                'djgear_skins_stale_artwork_revision',
                ['current_artwork_id' => $currentArtworkId, 'provided_artwork_id' => $artworkId]
            );
        }

        $artwork = $this->jobs->findArtworkById($currentArtworkId);
        if ($artwork === null || (int) $artwork['job_id'] !== (int) $job['id'] || (string) $artwork['revision_type'] !== SkinJobRepository::REVISION_CUSTOMER_ARTWORK) {
            throw SkinValidationException::notFound('artwork', 'Current artwork revision not found on this job.');
        }

        $actualSha256 = (string) $artwork['sha256_hash'];
        if ($expectedSha256 !== null && trim($expectedSha256) !== '' && !hash_equals($actualSha256, strtolower(trim($expectedSha256)))) {
            throw SkinValidationException::badRequest(
                'Provided SHA-256 digest does not match the current artwork revision.',
                'djgear_skins_artwork_digest_mismatch'
            );
        }

        $dpiStatus = (string) $artwork['dpi_status'];
        $snapshot = is_array($job['snapshot'] ?? null) ? $job['snapshot'] : [];
        $planSnap = is_array($snapshot['plan'] ?? null) ? $snapshot['plan'] : [];
        $requiresProof = (bool) ($planSnap['requires_proof_approval'] ?? false);

        $isWarning = ($dpiStatus === ArtworkDpiValidator::STATUS_WARNING);
        $isManualReviewAllowed = ($dpiStatus === ArtworkDpiValidator::STATUS_MANUAL_REVIEW_REQUIRED && $requiresProof);

        if (!$isWarning && !$isManualReviewAllowed) {
            throw SkinValidationException::badRequest(
                'Acknowledgment applies only to active 180–299 DPI warnings or manual-review PDFs on proof-approved plans.',
                'djgear_skins_dpi_ack_not_applicable',
                ['dpi_status' => $dpiStatus]
            );
        }

        $job = $this->jobs->transaction(function () use ($job, $currentArtworkId, $actualSha256, $customerUserId, $artwork, $dpiStatus): array {
            $this->jobs->acknowledgeLowDpi($currentArtworkId, $actualSha256);

            if ((string) $job['status'] === SkinJobStateMachine::STATUS_REVISION_REQUESTED) {
                $note = $dpiStatus === ArtworkDpiValidator::STATUS_MANUAL_REVIEW_REQUIRED
                    ? sprintf('Customer acknowledged manual PDF preflight review on artwork v%d.', (int) $artwork['version_number'])
                    : sprintf('Customer acknowledged low-DPI warning (%.1f DPI) on artwork v%d.', (float) ($artwork['computed_dpi'] ?? 0.0), (int) $artwork['version_number']);

                return $this->jobs->transitionStatus(
                    (int) $job['id'],
                    SkinJobStateMachine::STATUS_IN_DESIGN_REVIEW,
                    $customerUserId,
                    'customer',
                    true,
                    $note,
                    [],
                    SkinJobStateMachine::STATUS_REVISION_REQUESTED
                );
            }

            $reloaded = $this->jobs->findById((int) $job['id'], true);
            return $reloaded ?? $job;
        });

        $this->audit->record(new AuditEvent(
            action: 'skins.artwork.low_dpi_acknowledged',
            resourceType: 'skin_job',
            resourceId: $jobUuid,
            actorId: $customerUserId,
            context: [
                'artwork_id'   => $currentArtworkId,
                'dpi_status'   => $dpiStatus,
                'computed_dpi' => $artwork['computed_dpi'],
                'sha256_hash'  => $actualSha256,
            ],
        ));

        return $job;
    }

    /**
     * Validate that a customer draft job is ready for WooCommerce Cart AND final Checkout.
     * Re-validates draft state, unbound order state, active plan rules, current artwork revision,
     * DPI status, and exact SHA-256-bound acknowledgment.
     *
     * @return array<string, mixed> Validated job record.
     */
    public function assertReadyForCheckout(string $jobUuid, int $customerUserId): array
    {
        $job = $this->requireOwnedJob($jobUuid, $customerUserId);
        if ((string) $job['status'] !== SkinJobStateMachine::STATUS_DRAFT || $job['wc_order_id'] !== null) {
            throw SkinValidationException::badRequest(
                sprintf('Job cannot proceed to checkout from "%s" status.', (string) $job['status']),
                'djgear_skins_job_not_draft'
            );
        }

        $snapshot = is_array($job['snapshot'] ?? null) ? $job['snapshot'] : [];
        $planSnap = is_array($snapshot['plan'] ?? null) ? $snapshot['plan'] : [];
        $requiresArtwork = (bool) ($planSnap['requires_artwork'] ?? false);
        $requiresProof = (bool) ($planSnap['requires_proof_approval'] ?? false);

        if ($requiresArtwork) {
            $currentArtworkId = isset($job['current_artwork_id']) ? (int) $job['current_artwork_id'] : 0;
            if ($currentArtworkId <= 0) {
                throw SkinValidationException::badRequest(
                    'This Custom Design plan requires an artwork upload before checkout.',
                    'djgear_skins_artwork_required'
                );
            }

            $artwork = $this->jobs->findArtworkById($currentArtworkId);
            if ($artwork === null || (int) $artwork['job_id'] !== (int) $job['id']) {
                throw SkinValidationException::badRequest('Artwork record not found.', 'djgear_skins_artwork_required');
            }

            $dpiStatus = (string) $artwork['dpi_status'];
            if ($dpiStatus === ArtworkDpiValidator::STATUS_REJECTED) {
                throw SkinValidationException::badRequest(
                    'Artwork failed DPI or format validation (<180 DPI or unsupported multi-page file) and cannot proceed to checkout.',
                    'djgear_skins_dpi_rejected',
                    ['computed_dpi' => $artwork['computed_dpi']]
                );
            }

            if ($dpiStatus === ArtworkDpiValidator::STATUS_WARNING) {
                $this->assertValidRevisionBoundAck($artwork, 'Artwork is in the 180–299 DPI warning range. Explicit customer acknowledgment for this exact revision is required before checkout.', 'djgear_skins_dpi_ack_required');
            } elseif ($dpiStatus === ArtworkDpiValidator::STATUS_MANUAL_REVIEW_REQUIRED) {
                if (!$requiresProof) {
                    throw SkinValidationException::badRequest(
                        'This PDF requires manual designer preflight review, which is not available on the selected plan. Please upload a 300+ DPI PNG/JPEG.',
                        'djgear_skins_pdf_manual_review_unsupported'
                    );
                }
                $this->assertValidRevisionBoundAck($artwork, 'This PDF cannot be auto-certified for raster DPI. Explicit customer acknowledgment for manual designer review is required before checkout.', 'djgear_skins_manual_review_ack_required');
            } elseif ($dpiStatus !== ArtworkDpiValidator::STATUS_PASS) {
                throw SkinValidationException::badRequest(
                    'Artwork has not passed preflight validation.',
                    'djgear_skins_dpi_unverified'
                );
            }
        }

        return $job;
    }

    /**
     * Staff uploads a designer proof preview for a Custom Design job and transitions atomically to `proof_sent`.
     *
     * @return array{job: array<string, mixed>, proof: array<string, mixed>}
     */
    public function uploadDesignerProof(
        int $jobId,
        int $staffUserId,
        string $tmpFilePath,
        string $originalFilename,
        string $noteFa = '',
        string $noteEn = '',
    ): array {
        $job = $this->jobs->findById($jobId, false);
        if ($job === null) {
            throw SkinValidationException::notFound('skin_job', 'Custom skin job not found.');
        }

        $snapshot = is_array($job['snapshot'] ?? null) ? $job['snapshot'] : [];
        $planSnap = is_array($snapshot['plan'] ?? null) ? $snapshot['plan'] : [];
        if (!((bool) ($planSnap['requires_proof_approval'] ?? false))) {
            throw SkinValidationException::badRequest(
                'Designer proofs apply only to Custom Design ordering plans.',
                'djgear_skins_proof_not_applicable'
            );
        }

        $stored = $this->vault->storeFile($tmpFilePath, $originalFilename, (string) $job['job_uuid'], true);

        try {
            $result = $this->jobs->transaction(function () use ($jobId, $staffUserId, $stored, $noteFa, $noteEn): array {
                $locked = $this->jobs->findByIdForUpdate($jobId);
                if ($locked === null) {
                    throw SkinValidationException::notFound('skin_job', 'Custom skin job not found.');
                }

                $currentStatus = (string) $locked['status'];
                if ($currentStatus === SkinJobStateMachine::STATUS_SUBMITTED_FOR_REVIEW) {
                    $this->jobs->transitionStatus(
                        $jobId,
                        SkinJobStateMachine::STATUS_IN_DESIGN_REVIEW,
                        $staffUserId,
                        'staff',
                        true,
                        'Design review started.',
                        ['assigned_staff_id' => $staffUserId],
                        SkinJobStateMachine::STATUS_SUBMITTED_FOR_REVIEW
                    );
                } elseif ($currentStatus !== SkinJobStateMachine::STATUS_IN_DESIGN_REVIEW) {
                    throw SkinValidationException::badRequest(
                        sprintf('Proof cannot be uploaded while job is in "%s" status.', $currentStatus),
                        'djgear_skins_invalid_proof_stage'
                    );
                }

                $proof = $this->jobs->addArtworkRevision($jobId, SkinJobRepository::REVISION_DESIGNER_PROOF, [
                    'uploaded_by_user_id'  => $staffUserId,
                    'storage_rel_path'     => $stored['storage_rel_path'],
                    'original_filename'    => $stored['original_filename'],
                    'mime_type'            => $stored['mime_type'],
                    'file_size_bytes'      => $stored['file_size_bytes'],
                    'sha256_hash'          => $stored['sha256_hash'],
                    'pixel_width'          => $stored['pixel_width'],
                    'pixel_height'         => $stored['pixel_height'],
                    'dpi_status'           => ArtworkDpiValidator::STATUS_NOT_APPLICABLE,
                    'customer_decision'    => 'pending',
                    'note_fa'              => SkinValidator::sanitizeNotes($noteFa, 1000) ?: null,
                    'note_en'              => SkinValidator::sanitizeNotes($noteEn, 1000) ?: null,
                ]);

                $updatedJob = $this->jobs->transitionStatus(
                    $jobId,
                    SkinJobStateMachine::STATUS_PROOF_SENT,
                    $staffUserId,
                    'staff',
                    true,
                    sprintf('Designer proof v%d sent for customer approval.', (int) $proof['version_number']),
                    ['assigned_staff_id' => $staffUserId],
                    SkinJobStateMachine::STATUS_IN_DESIGN_REVIEW
                );

                return ['job' => $updatedJob, 'proof' => $proof];
            });
        } catch (\Throwable $e) {
            $this->vault->deleteFile($stored['storage_rel_path']);
            throw $e;
        }

        $this->audit->record(new AuditEvent(
            action: 'skins.proof.uploaded',
            resourceType: 'skin_job',
            resourceId: (string) $job['job_uuid'],
            actorId: $staffUserId,
            context: [
                'proof_id'    => (int) $result['proof']['id'],
                'version'     => (int) $result['proof']['version_number'],
                'sha256_hash' => $stored['sha256_hash'],
            ],
        ));

        return $result;
    }

    /**
     * Customer approves current designer proof atomically:
     * 1. Verifies stored proof file exists on disk and its SHA-256 matches the immutable DB digest.
     * 2. Locks `approved_proof_id`, `approved_at`, `customer_decision = 'approved'`, and transitions
     *    `proof_sent -> approved_for_production` inside one atomic InnoDB transaction.
     *
     * @return array<string, mixed>
     */
    public function approveProof(string $jobUuid, int $customerUserId, string $comment = ''): array
    {
        $owned = $this->requireOwnedJob($jobUuid, $customerUserId);
        $jobId = (int) $owned['id'];

        $approvalData = $this->jobs->transaction(function () use ($jobId, $customerUserId, $comment): array {
            $locked = $this->jobs->findByIdForUpdate($jobId);
            if ($locked === null) {
                throw SkinValidationException::notFound('skin_job', 'Custom skin job not found.');
            }
            if ((string) $locked['status'] !== SkinJobStateMachine::STATUS_PROOF_SENT) {
                throw SkinValidationException::badRequest(
                    'There is no pending designer proof awaiting your approval.',
                    'djgear_skins_no_pending_proof'
                );
            }

            $proofId = isset($locked['current_proof_id']) ? (int) $locked['current_proof_id'] : 0;
            $proof = $this->verifyProofFileIntegrity($jobId, (string) $locked['job_uuid'], $proofId, $customerUserId);

            $approvedAt = $this->jobs->recordProofDecision($jobId, $proofId, 'approved');
            $cleanComment = SkinValidator::sanitizeNotes($comment, 1000);
            $historyNote = $cleanComment !== ''
                ? $cleanComment
                : sprintf('Customer approved designer proof v%d.', (int) $proof['version_number']);

            $updated = $this->jobs->transitionStatus(
                $jobId,
                SkinJobStateMachine::STATUS_APPROVED_FOR_PRODUCTION,
                $customerUserId,
                'customer',
                true,
                $historyNote,
                [],
                SkinJobStateMachine::STATUS_PROOF_SENT
            );

            return [
                'job'         => $updated,
                'proof_id'    => $proofId,
                'sha256'      => (string) $proof['sha256_hash'],
                'approved_at' => $approvedAt,
            ];
        });

        $this->audit->record(new AuditEvent(
            action: 'skins.proof.approved',
            resourceType: 'skin_job',
            resourceId: $jobUuid,
            actorId: $customerUserId,
            context: [
                'approved_proof_id' => $approvalData['proof_id'],
                'proof_sha256'      => $approvalData['sha256'],
                'approved_at'       => $approvalData['approved_at'],
            ],
        ));

        return $approvalData['job'];
    }

    /**
     * Customer requests revisions on current designer proof atomically, enforcing `plan.max_revisions`
     * strictly (including `max_revisions = 0` meaning ZERO additional revisions!).
     *
     * @return array<string, mixed>
     */
    public function requestProofRevision(string $jobUuid, int $customerUserId, string $comment): array
    {
        $owned = $this->requireOwnedJob($jobUuid, $customerUserId);
        $jobId = (int) $owned['id'];

        $cleanComment = SkinValidator::sanitizeNotes($comment, 1500);
        if ($cleanComment === '') {
            throw SkinValidationException::invalidField('comment', 'Please describe the requested changes.');
        }

        $revResult = $this->jobs->transaction(function () use ($jobId, $customerUserId, $cleanComment): array {
            $locked = $this->jobs->findByIdForUpdate($jobId);
            if ($locked === null) {
                throw SkinValidationException::notFound('skin_job', 'Custom skin job not found.');
            }
            if ((string) $locked['status'] !== SkinJobStateMachine::STATUS_PROOF_SENT) {
                throw SkinValidationException::badRequest(
                    'Revision requests are only available while a designer proof is awaiting review.',
                    'djgear_skins_no_pending_proof'
                );
            }

            $snapshot = is_array($locked['snapshot'] ?? null) ? $locked['snapshot'] : [];
            $planSnap = is_array($snapshot['plan'] ?? null) ? $snapshot['plan'] : [];
            $maxRevisions = max(0, (int) ($planSnap['max_revisions'] ?? 0));
            $usedRevisions = $this->jobs->countProofRevisionRequests($jobId);

            // Strictly enforce `max_revisions = 0` as ZERO revisions (never unlimited!)
            if ($usedRevisions >= $maxRevisions) {
                throw SkinValidationException::badRequest(
                    sprintf('Maximum included proof revisions (%d) reached for this plan.', $maxRevisions),
                    'djgear_skins_max_revisions_reached',
                    ['max_revisions' => $maxRevisions, 'used_revisions' => $usedRevisions]
                );
            }

            $proofId = isset($locked['current_proof_id']) ? (int) $locked['current_proof_id'] : 0;
            if ($proofId > 0) {
                $this->jobs->recordProofDecision($jobId, $proofId, 'changes_requested');
            }

            $updated = $this->jobs->transitionStatus(
                $jobId,
                SkinJobStateMachine::STATUS_REVISION_REQUESTED,
                $customerUserId,
                'customer',
                true,
                $cleanComment,
                [],
                SkinJobStateMachine::STATUS_PROOF_SENT
            );

            return [
                'job'            => $updated,
                'proof_id'       => $proofId,
                'used_revisions' => $usedRevisions + 1,
                'max_revisions'  => $maxRevisions,
            ];
        });

        $this->audit->record(new AuditEvent(
            action: 'skins.proof.revision_requested',
            resourceType: 'skin_job',
            resourceId: $jobUuid,
            actorId: $customerUserId,
            context: [
                'proof_id'       => $revResult['proof_id'],
                'used_revisions' => $revResult['used_revisions'],
                'max_revisions'  => $revResult['max_revisions'],
            ],
        ));

        return $revResult['job'];
    }

    /**
     * Admin / shop_manager advances a job through production/shipping stages atomically.
     *
     * Security Guarantees:
     * - Staff/admin can NEVER impersonate customer proof approval (`approved_for_production` blocked HTTP 403).
     * - Custom-Design jobs entering `queued_for_production` or `in_production` MUST have a locked
     *   `approved_proof_id` AND its file on disk MUST pass live SHA-256 integrity re-verification.
     * - Shipping appends an HPOS-safe customer order note without auto-completing the WooCommerce order.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function adminTransition(int $jobId, int $staffUserId, bool $isAdmin, array $payload): array
    {
        $toStatus = is_string($payload['to_status'] ?? null) ? trim($payload['to_status']) : '';
        if ($toStatus === '') {
            throw SkinValidationException::invalidField('to_status', 'to_status is required.');
        }

        // Staff/admin may NEVER impersonate customer proof approval
        if (in_array($toStatus, SkinJobStateMachine::CUSTOMER_ONLY_TARGET_STATUSES, true)) {
            throw SkinValidationException::forbidden(
                'Only the owning customer may approve a designer proof for production.'
            );
        }

        $extra = [];
        if (array_key_exists('tracking_code', $payload)) {
            $extra['tracking_code'] = SkinValidator::sanitizeTrackingCode($payload['tracking_code']);
        }
        if (array_key_exists('internal_notes', $payload)) {
            $extra['internal_notes'] = SkinValidator::sanitizeNotes($payload['internal_notes'], 2500);
        }
        if (isset($payload['assign_to_me']) && $payload['assign_to_me']) {
            $extra['assigned_staff_id'] = $staffUserId;
        }

        $comment = SkinValidator::sanitizeNotes($payload['comment'] ?? '', 1500);
        $isCustomerVisible = array_key_exists('is_customer_visible', $payload)
            ? (bool) $payload['is_customer_visible']
            : true;

        $updated = $this->jobs->transaction(function () use (
            $jobId,
            $toStatus,
            $staffUserId,
            $isAdmin,
            $isCustomerVisible,
            $comment,
            $extra
        ): array {
            $locked = $this->jobs->findByIdForUpdate($jobId);
            if ($locked === null) {
                throw SkinValidationException::notFound('skin_job', 'Custom skin job not found.');
            }

            $snapshot = is_array($locked['snapshot'] ?? null) ? $locked['snapshot'] : [];
            $planSnap = is_array($snapshot['plan'] ?? null) ? $snapshot['plan'] : [];
            $requiresProof = (bool) ($planSnap['requires_proof_approval'] ?? false);

            // Re-verify approved proof SHA-256 on disk immediately before entering production!
            if ($requiresProof && in_array($toStatus, [
                SkinJobStateMachine::STATUS_QUEUED_FOR_PRODUCTION,
                SkinJobStateMachine::STATUS_IN_PRODUCTION,
            ], true)) {
                $approvedProofId = isset($locked['approved_proof_id']) ? (int) $locked['approved_proof_id'] : 0;
                if ($approvedProofId <= 0) {
                    throw SkinValidationException::badRequest(
                        'Customer proof approval is required before this Custom Design job can enter production.',
                        'djgear_skins_proof_approval_required'
                    );
                }
                $this->verifyProofFileIntegrity($jobId, (string) $locked['job_uuid'], $approvedProofId, $staffUserId);
            }

            return $this->jobs->transitionStatus(
                $jobId,
                $toStatus,
                $staffUserId,
                $isAdmin ? 'admin' : 'staff',
                $isCustomerVisible,
                $comment !== '' ? $comment : null,
                $extra,
                (string) $locked['status']
            );
        });

        // If shipped and linked to a WooCommerce HPOS order, add a customer-visible order note (NO auto-complete).
        if ($toStatus === SkinJobStateMachine::STATUS_SHIPPED && !empty($updated['wc_order_id']) && function_exists('wc_get_order')) {
            $order = wc_get_order((int) $updated['wc_order_id']);
            if ($order instanceof \WC_Order) {
                $tracking = is_string($updated['tracking_code'] ?? null) ? $updated['tracking_code'] : '';
                $snap = is_array($updated['snapshot'] ?? null) ? $updated['snapshot'] : [];
                $devSnap = is_array($snap['device'] ?? null) ? $snap['device'] : [];
                $devTitle = (string) ($devSnap['name_en'] ?? $updated['job_uuid']);
                $noteText = $tracking !== ''
                    ? sprintf(
                        __('Your DJGear Custom Skin (%1$s, ref %2$s) has shipped. Tracking code: %3$s', 'djgear-custom-skins'),
                        $devTitle,
                        (string) $updated['job_uuid'],
                        $tracking
                    )
                    : sprintf(
                        __('Your DJGear Custom Skin (%1$s, ref %2$s) has shipped.', 'djgear-custom-skins'),
                        $devTitle,
                        (string) $updated['job_uuid']
                    );
                $order->add_order_note($noteText, 1, true);
                $order->save();
            }
        }

        $this->audit->record(new AuditEvent(
            action: 'skins.job.transitioned',
            resourceType: 'skin_job',
            resourceId: (string) $updated['job_uuid'],
            actorId: $staffUserId,
            context: [
                'to_status'     => $toStatus,
                'tracking_code' => $updated['tracking_code'] ?? null,
            ],
        ));

        return $updated;
    }

    /**
     * Handle WooCommerce payment confirmation idempotently & atomically for all skin jobs attached to an order.
     */
    public function handleOrderPaid(int $wcOrderId): void
    {
        $jobs = $this->jobs->findByWcOrderId($wcOrderId);
        foreach ($jobs as $job) {
            $jobId = (int) $job['id'];
            $this->jobs->transaction(function () use ($jobId, $wcOrderId): void {
                $locked = $this->jobs->findByIdForUpdate($jobId);
                if ($locked === null || (string) $locked['status'] !== SkinJobStateMachine::STATUS_AWAITING_PAYMENT) {
                    return;
                }

                $snapshot = is_array($locked['snapshot'] ?? null) ? $locked['snapshot'] : [];
                $planSnap = is_array($snapshot['plan'] ?? null) ? $snapshot['plan'] : [];
                $requiresProof = (bool) ($planSnap['requires_proof_approval'] ?? false);
                $nextStatus = SkinJobStateMachine::postPaymentStatus($requiresProof);

                $this->jobs->transitionStatus(
                    $jobId,
                    $nextStatus,
                    null,
                    'system',
                    true,
                    $requiresProof
                        ? sprintf('WooCommerce order #%d payment confirmed. Submitted for design review.', $wcOrderId)
                        : sprintf('WooCommerce order #%d payment confirmed. Queued directly for production.', $wcOrderId),
                    [],
                    SkinJobStateMachine::STATUS_AWAITING_PAYMENT
                );
            });
        }
    }

    /**
     * Handle WooCommerce order cancellation or refund idempotently & atomically for attached skin jobs.
     */
    public function handleOrderCancelledOrRefunded(int $wcOrderId, string $reason = 'cancelled'): void
    {
        $jobs = $this->jobs->findByWcOrderId($wcOrderId);
        foreach ($jobs as $job) {
            $jobId = (int) $job['id'];
            $this->jobs->transaction(function () use ($jobId, $wcOrderId, $reason): void {
                $locked = $this->jobs->findByIdForUpdate($jobId);
                if ($locked === null) {
                    return;
                }
                $current = (string) $locked['status'];
                $target = SkinJobStateMachine::statusOnOrderCancel($current);
                if ($target === null) {
                    return;
                }

                $this->jobs->transitionStatus(
                    $jobId,
                    $target,
                    null,
                    'system',
                    true,
                    $target === SkinJobStateMachine::STATUS_ON_HOLD
                        ? sprintf('WooCommerce order #%d %s while already in production; placed on hold for staff review.', $wcOrderId, $reason)
                        : sprintf('WooCommerce order #%d %s; skin job cancelled.', $wcOrderId, $reason),
                    [],
                    $current
                );
            });
        }
    }

    /**
     * Verify a designer proof exists in DB, exists safely in the private vault, and its live on-disk
     * SHA-256 digest matches the immutable recorded digest.
     *
     * @return array<string, mixed>
     */
    public function verifyProofFileIntegrity(int $jobId, string $jobUuid, int $proofId, int $actorUserId): array
    {
        if ($proofId <= 0) {
            throw SkinValidationException::notFound('proof', 'Designer proof record not found.');
        }

        $proof = $this->jobs->findArtworkById($proofId);
        if ($proof === null || (int) $proof['job_id'] !== $jobId || (string) $proof['revision_type'] !== SkinJobRepository::REVISION_DESIGNER_PROOF) {
            throw SkinValidationException::notFound('proof', 'Designer proof record does not match this job.');
        }

        try {
            $safePath = $this->vault->resolveSafePath((string) $proof['storage_rel_path']);
        } catch (SkinValidationException) {
            $this->audit->record(new AuditEvent(
                action: 'skins.proof.integrity_failed',
                resourceType: 'skin_job',
                resourceId: $jobUuid,
                actorId: $actorUserId,
                context: ['proof_id' => $proofId, 'reason' => 'missing_or_inaccessible_file'],
            ));
            throw SkinValidationException::conflict(
                'approved_proof_id',
                'Approved proof file is missing or inaccessible in private storage. Production is blocked until re-verified.'
            );
        }

        $diskHash = hash_file('sha256', $safePath);
        $expectedHash = (string) $proof['sha256_hash'];

        if (!is_string($diskHash) || $expectedHash === '' || !hash_equals($expectedHash, $diskHash)) {
            $this->audit->record(new AuditEvent(
                action: 'skins.proof.integrity_failed',
                resourceType: 'skin_job',
                resourceId: $jobUuid,
                actorId: $actorUserId,
                context: [
                    'proof_id'      => $proofId,
                    'reason'        => 'sha256_mismatch',
                    'expected_hash' => $expectedHash,
                    'actual_hash'   => is_string($diskHash) ? $diskHash : null,
                ],
            ));
            throw SkinValidationException::conflict(
                'approved_proof_id',
                'Approved proof SHA-256 integrity check failed (file modified or corrupted on disk). Production is blocked.'
            );
        }

        return $proof;
    }

    /**
     * @return array<string, mixed>
     */
    public function requireOwnedJob(string $jobUuid, int $customerUserId): array
    {
        if (!SkinValidator::isValidUuid($jobUuid)) {
            throw SkinValidationException::notFound('skin_job', 'Invalid job identifier.');
        }

        $job = $this->jobs->findByUuid($jobUuid, true);
        if ($job === null) {
            throw SkinValidationException::notFound('skin_job', 'Custom skin job not found.');
        }

        if ((int) $job['customer_user_id'] !== $customerUserId) {
            throw SkinValidationException::forbidden('You do not have access to this custom skin job.');
        }

        return $job;
    }

    /**
     * Verify `low_dpi_acknowledged === true` AND preflight JSON binds exact revision ID & SHA-256 digest.
     *
     * @param array<string, mixed> $artwork
     */
    private function assertValidRevisionBoundAck(array $artwork, string $message, string $errorCode): void
    {
        $ackFlag = (bool) ($artwork['low_dpi_acknowledged'] ?? false);
        $report = is_array($artwork['preflight_report'] ?? null) ? $artwork['preflight_report'] : [];
        $ackArtId = isset($report['acknowledged_artwork_id']) ? (int) $report['acknowledged_artwork_id'] : 0;
        $ackSha = is_string($report['acknowledged_sha256'] ?? null) ? $report['acknowledged_sha256'] : '';
        $actualSha = (string) ($artwork['sha256_hash'] ?? '');

        if (!$ackFlag || $ackArtId !== (int) $artwork['id'] || $actualSha === '' || !hash_equals($actualSha, $ackSha)) {
            throw SkinValidationException::badRequest(
                $message,
                $errorCode,
                [
                    'artwork_id'   => (int) $artwork['id'],
                    'computed_dpi' => $artwork['computed_dpi'] ?? null,
                    'sha256_hash'  => $actualSha,
                ]
            );
        }
    }
}
