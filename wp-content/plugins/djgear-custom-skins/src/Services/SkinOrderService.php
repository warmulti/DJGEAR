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
 * Enforces plan upload rules, ownership isolation, DPI validation, proof approval locking,
 * dual-track production transitions, and WooCommerce order notes.
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
     * Upload customer artwork for a job. Enforces:
     * 1. Job ownership (`customer_user_id === $customerUserId`).
     * 2. Selected plan explicitly enables `allows_artwork_upload = true` (Standard plans are rejected HTTP 400).
     * 3. Job is in an artwork-uploadable status (`draft` or `revision_requested`).
     * 4. Private vault storage + DPI evaluation (`<180` reject, `180-299` warning, `>=300` pass).
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

        // If the job was waiting for a customer revision and uploaded acceptable artwork (pass), move back to design review.
        if ($status === SkinJobStateMachine::STATUS_REVISION_REQUESTED && $dpiStatus === ArtworkDpiValidator::STATUS_PASS) {
            $job = $this->jobs->transitionStatus(
                (int) $job['id'],
                SkinJobStateMachine::STATUS_IN_DESIGN_REVIEW,
                $customerUserId,
                'customer',
                true,
                sprintf('Customer uploaded revised artwork v%d (%.1f DPI).', (int) $artwork['version_number'], (float) ($dpiReport['computed_dpi'] ?? 0.0))
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
                'artwork_id'  => (int) $artwork['id'],
                'version'     => (int) $artwork['version_number'],
                'mime_type'   => $stored['mime_type'],
                'dpi_status'  => $dpiStatus,
                'computed_dpi' => $dpiReport['computed_dpi'] ?? null,
                'sha256_hash' => $stored['sha256_hash'],
            ],
        ));

        return [
            'job'        => $job,
            'artwork'    => $artwork,
            'dpi_report' => $dpiReport,
        ];
    }

    /**
     * Record explicit customer acknowledgment for a 180-299 DPI warning artwork.
     *
     * @return array<string, mixed>
     */
    public function acknowledgeLowDpi(string $jobUuid, int $customerUserId, ?int $artworkId = null): array
    {
        $job = $this->requireOwnedJob($jobUuid, $customerUserId);
        $targetArtworkId = $artworkId ?? (isset($job['current_artwork_id']) ? (int) $job['current_artwork_id'] : 0);

        if ($targetArtworkId <= 0) {
            throw SkinValidationException::badRequest('No uploaded artwork found on this job.');
        }

        $artwork = $this->jobs->findArtworkById($targetArtworkId);
        if ($artwork === null || (int) $artwork['job_id'] !== (int) $job['id'] || (string) $artwork['revision_type'] !== SkinJobRepository::REVISION_CUSTOMER_ARTWORK) {
            throw SkinValidationException::notFound('artwork', 'Artwork revision not found on this job.');
        }

        if ((string) $artwork['dpi_status'] !== ArtworkDpiValidator::STATUS_WARNING) {
            throw SkinValidationException::badRequest(
                'DPI warning acknowledgment applies only to artwork in the 180–299 DPI warning band.',
                'djgear_skins_dpi_ack_not_applicable',
                ['dpi_status' => (string) $artwork['dpi_status']]
            );
        }

        $this->jobs->acknowledgeLowDpi($targetArtworkId);

        if ((string) $job['status'] === SkinJobStateMachine::STATUS_REVISION_REQUESTED) {
            $job = $this->jobs->transitionStatus(
                (int) $job['id'],
                SkinJobStateMachine::STATUS_IN_DESIGN_REVIEW,
                $customerUserId,
                'customer',
                true,
                sprintf('Customer acknowledged low-DPI warning (%.1f DPI) on artwork v%d.', (float) ($artwork['computed_dpi'] ?? 0.0), (int) $artwork['version_number'])
            );
        } else {
            $reloaded = $this->jobs->findById((int) $job['id'], true);
            if ($reloaded !== null) {
                $job = $reloaded;
            }
        }

        $this->audit->record(new AuditEvent(
            action: 'skins.artwork.low_dpi_acknowledged',
            resourceType: 'skin_job',
            resourceId: $jobUuid,
            actorId: $customerUserId,
            context: [
                'artwork_id'   => $targetArtworkId,
                'computed_dpi' => $artwork['computed_dpi'],
                'sha256_hash'  => $artwork['sha256_hash'],
            ],
        ));

        return $job;
    }

    /**
     * Validate that a customer draft job is ready to be added to WooCommerce Cart / Checkout.
     *
     * - Standard plan: ready immediately without artwork.
     * - Custom Design plan (`requires_artwork = true`): requires current artwork with `dpi_status = pass`,
     *   OR `dpi_status = warning` with `low_dpi_acknowledged = true`. Rejected (`<180 DPI`) is strictly blocked!
     *
     * @return array<string, mixed> Validated job record.
     */
    public function assertReadyForCheckout(string $jobUuid, int $customerUserId): array
    {
        $job = $this->requireOwnedJob($jobUuid, $customerUserId);
        if ((string) $job['status'] !== SkinJobStateMachine::STATUS_DRAFT) {
            throw SkinValidationException::badRequest(
                sprintf('Job cannot be added to cart from "%s" status.', (string) $job['status']),
                'djgear_skins_job_not_draft'
            );
        }

        $snapshot = is_array($job['snapshot'] ?? null) ? $job['snapshot'] : [];
        $planSnap = is_array($snapshot['plan'] ?? null) ? $snapshot['plan'] : [];
        $requiresArtwork = (bool) ($planSnap['requires_artwork'] ?? false);

        if ($requiresArtwork) {
            $currentArtworkId = isset($job['current_artwork_id']) ? (int) $job['current_artwork_id'] : 0;
            if ($currentArtworkId <= 0) {
                throw SkinValidationException::badRequest(
                    'This Custom Design plan requires an artwork upload before adding to cart.',
                    'djgear_skins_artwork_required'
                );
            }

            $artwork = $this->jobs->findArtworkById($currentArtworkId);
            if ($artwork === null) {
                throw SkinValidationException::badRequest('Artwork record not found.', 'djgear_skins_artwork_required');
            }

            $dpiStatus = (string) $artwork['dpi_status'];
            if ($dpiStatus === ArtworkDpiValidator::STATUS_REJECTED) {
                throw SkinValidationException::badRequest(
                    'Artwork resolution is below 180 DPI and cannot proceed to checkout. Please upload a higher-resolution file.',
                    'djgear_skins_dpi_rejected',
                    ['computed_dpi' => $artwork['computed_dpi']]
                );
            }
            if ($dpiStatus === ArtworkDpiValidator::STATUS_WARNING && !((bool) $artwork['low_dpi_acknowledged'])) {
                throw SkinValidationException::badRequest(
                    'Artwork is in the 180–299 DPI warning range. Explicit customer acknowledgment is required before checkout.',
                    'djgear_skins_dpi_ack_required',
                    ['computed_dpi' => $artwork['computed_dpi']]
                );
            }
        }

        return $job;
    }

    /**
     * Staff uploads a designer proof preview for a Custom Design job and transitions to `proof_sent`.
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

        $currentStatus = (string) $job['status'];
        if ($currentStatus === SkinJobStateMachine::STATUS_SUBMITTED_FOR_REVIEW) {
            $job = $this->jobs->transitionStatus(
                $jobId,
                SkinJobStateMachine::STATUS_IN_DESIGN_REVIEW,
                $staffUserId,
                'staff',
                true,
                'Design review started.',
                ['assigned_staff_id' => $staffUserId]
            );
        } elseif ($currentStatus !== SkinJobStateMachine::STATUS_IN_DESIGN_REVIEW) {
            throw SkinValidationException::badRequest(
                sprintf('Proof cannot be uploaded while job is in "%s" status.', $currentStatus),
                'djgear_skins_invalid_proof_stage'
            );
        }

        $stored = $this->vault->storeFile($tmpFilePath, $originalFilename, (string) $job['job_uuid'], true);
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
            ['assigned_staff_id' => $staffUserId]
        );

        $this->audit->record(new AuditEvent(
            action: 'skins.proof.uploaded',
            resourceType: 'skin_job',
            resourceId: (string) $job['job_uuid'],
            actorId: $staffUserId,
            context: [
                'proof_id'    => (int) $proof['id'],
                'version'     => (int) $proof['version_number'],
                'sha256_hash' => $stored['sha256_hash'],
            ],
        ));

        return [
            'job'   => $updatedJob,
            'proof' => $proof,
        ];
    }

    /**
     * Customer approves current designer proof, locking proof ID, SHA-256 hash, and timestamp.
     *
     * @return array<string, mixed>
     */
    public function approveProof(string $jobUuid, int $customerUserId, string $comment = ''): array
    {
        $job = $this->requireOwnedJob($jobUuid, $customerUserId);
        if ((string) $job['status'] !== SkinJobStateMachine::STATUS_PROOF_SENT) {
            throw SkinValidationException::badRequest(
                'There is no pending designer proof awaiting your approval.',
                'djgear_skins_no_pending_proof'
            );
        }

        $proofId = isset($job['current_proof_id']) ? (int) $job['current_proof_id'] : 0;
        $proof = $this->jobs->findArtworkById($proofId);
        if ($proof === null || (int) $proof['job_id'] !== (int) $job['id']) {
            throw SkinValidationException::notFound('proof', 'Current designer proof not found.');
        }

        $approvedAt = $this->jobs->recordProofDecision((int) $job['id'], $proofId, 'approved');
        $cleanComment = SkinValidator::sanitizeNotes($comment, 1000);
        $historyNote = $cleanComment !== ''
            ? $cleanComment
            : sprintf('Customer approved designer proof v%d.', (int) $proof['version_number']);

        $updated = $this->jobs->transitionStatus(
            (int) $job['id'],
            SkinJobStateMachine::STATUS_APPROVED_FOR_PRODUCTION,
            $customerUserId,
            'customer',
            true,
            $historyNote
        );

        $this->audit->record(new AuditEvent(
            action: 'skins.proof.approved',
            resourceType: 'skin_job',
            resourceId: $jobUuid,
            actorId: $customerUserId,
            context: [
                'approved_proof_id' => $proofId,
                'proof_sha256'      => (string) $proof['sha256_hash'],
                'approved_at'       => $approvedAt,
            ],
        ));

        return $updated;
    }

    /**
     * Customer requests revisions on current designer proof, enforcing `plan.max_revisions`.
     *
     * @return array<string, mixed>
     */
    public function requestProofRevision(string $jobUuid, int $customerUserId, string $comment): array
    {
        $job = $this->requireOwnedJob($jobUuid, $customerUserId);
        if ((string) $job['status'] !== SkinJobStateMachine::STATUS_PROOF_SENT) {
            throw SkinValidationException::badRequest(
                'Revision requests are only available while a designer proof is awaiting review.',
                'djgear_skins_no_pending_proof'
            );
        }

        $cleanComment = SkinValidator::sanitizeNotes($comment, 1500);
        if ($cleanComment === '') {
            throw SkinValidationException::invalidField('comment', 'Please describe the requested changes.');
        }

        $snapshot = is_array($job['snapshot'] ?? null) ? $job['snapshot'] : [];
        $planSnap = is_array($snapshot['plan'] ?? null) ? $snapshot['plan'] : [];
        $maxRevisions = (int) ($planSnap['max_revisions'] ?? 0);
        $usedRevisions = $this->jobs->countProofRevisionRequests((int) $job['id']);

        if ($maxRevisions > 0 && $usedRevisions >= $maxRevisions) {
            throw SkinValidationException::badRequest(
                sprintf('Maximum included proof revisions (%d) reached for this plan.', $maxRevisions),
                'djgear_skins_max_revisions_reached',
                ['max_revisions' => $maxRevisions, 'used_revisions' => $usedRevisions]
            );
        }

        $proofId = isset($job['current_proof_id']) ? (int) $job['current_proof_id'] : 0;
        if ($proofId > 0) {
            $this->jobs->recordProofDecision((int) $job['id'], $proofId, 'changes_requested');
        }

        $updated = $this->jobs->transitionStatus(
            (int) $job['id'],
            SkinJobStateMachine::STATUS_REVISION_REQUESTED,
            $customerUserId,
            'customer',
            true,
            $cleanComment
        );

        $this->audit->record(new AuditEvent(
            action: 'skins.proof.revision_requested',
            resourceType: 'skin_job',
            resourceId: $jobUuid,
            actorId: $customerUserId,
            context: [
                'proof_id'       => $proofId,
                'used_revisions' => $usedRevisions + 1,
                'max_revisions'  => $maxRevisions,
            ],
        ));

        return $updated;
    }

    /**
     * Admin / shop_manager advances a job through production/shipping stages.
     * Appends HPOS-safe customer order note on WooCommerce order when marked `shipped`.
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

        $updated = $this->jobs->transitionStatus(
            $jobId,
            $toStatus,
            $staffUserId,
            $isAdmin ? 'admin' : 'staff',
            $isCustomerVisible,
            $comment !== '' ? $comment : null,
            $extra
        );

        // If shipped and linked to a WooCommerce HPOS order, add a customer-visible order note with tracking info.
        if ($toStatus === SkinJobStateMachine::STATUS_SHIPPED && !empty($updated['wc_order_id']) && function_exists('wc_get_order')) {
            $order = wc_get_order((int) $updated['wc_order_id']);
            if ($order instanceof \WC_Order) {
                $tracking = is_string($updated['tracking_code'] ?? null) ? $updated['tracking_code'] : '';
                $noteText = $tracking !== ''
                    ? sprintf('Your DJGear Custom Skin (%s) has shipped. Tracking code: %s', (string) $updated['job_uuid'], $tracking)
                    : sprintf('Your DJGear Custom Skin (%s) has shipped.', (string) $updated['job_uuid']);
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
     * Handle WooCommerce payment confirmation for all skin jobs attached to an order.
     */
    public function handleOrderPaid(int $wcOrderId): void
    {
        $jobs = $this->jobs->findByWcOrderId($wcOrderId);
        foreach ($jobs as $job) {
            if ((string) $job['status'] !== SkinJobStateMachine::STATUS_AWAITING_PAYMENT) {
                continue;
            }
            $snapshot = is_array($job['snapshot'] ?? null) ? $job['snapshot'] : [];
            $planSnap = is_array($snapshot['plan'] ?? null) ? $snapshot['plan'] : [];
            $requiresProof = (bool) ($planSnap['requires_proof_approval'] ?? false);
            $nextStatus = SkinJobStateMachine::postPaymentStatus($requiresProof);

            $this->jobs->transitionStatus(
                (int) $job['id'],
                $nextStatus,
                null,
                'system',
                true,
                $requiresProof
                    ? sprintf('WooCommerce order #%d payment confirmed. Submitted for design review.', $wcOrderId)
                    : sprintf('WooCommerce order #%d payment confirmed. Queued directly for production.', $wcOrderId)
            );
        }
    }

    /**
     * Handle WooCommerce order cancellation or refund for attached skin jobs.
     */
    public function handleOrderCancelledOrRefunded(int $wcOrderId, string $reason = 'cancelled'): void
    {
        $jobs = $this->jobs->findByWcOrderId($wcOrderId);
        foreach ($jobs as $job) {
            $current = (string) $job['status'];
            $target = SkinJobStateMachine::statusOnOrderCancel($current);
            if ($target === null) {
                continue;
            }

            $this->jobs->transitionStatus(
                (int) $job['id'],
                $target,
                null,
                'system',
                true,
                $target === SkinJobStateMachine::STATUS_ON_HOLD
                    ? sprintf('WooCommerce order #%d %s while already in production; placed on hold for staff review.', $wcOrderId, $reason)
                    : sprintf('WooCommerce order #%d %s; skin job cancelled.', $wcOrderId, $reason)
            );
        }
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
}
