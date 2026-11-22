<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Rest;

use DJGear\Core\Rest\Response;
use DJGear\Core\Rest\RouteInterface;
use DJGear\Core\Security\Authorizer;
use DJGear\DeviceCatalog\Domain\ValidationException;
use DJGear\DeviceCatalog\Security\CatalogCapabilities;
use DJGear\DeviceCatalog\Services\ImportExportService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Administrator-only Catalog Import/Export controller (`djgear_import_export_catalog`).
 */
final class ImportExportController implements RouteInterface
{
    public const MAX_PAYLOAD_BYTES = 2097152; // 2 MiB hard limit

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ImportExportService $importExport,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/catalog/import/validate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'validateImport'],
            'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::IMPORT_EXPORT_CATALOG),
        ]);

        register_rest_route($namespace, '/catalog/import/execute', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'executeImport'],
            'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::IMPORT_EXPORT_CATALOG),
        ]);

        register_rest_route($namespace, '/catalog/export', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'exportCatalog'],
            'permission_callback' => $this->authorizer->restPermissionCallback(CatalogCapabilities::IMPORT_EXPORT_CATALOG),
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function validateImport(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $body = $this->body($request);
            $policy = is_string($body['conflict_policy'] ?? null) ? (string) $body['conflict_policy'] : 'skip';

            return Response::ok($this->importExport->validateImport($body, $policy));
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function executeImport(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $body = $this->body($request);
            $policy = is_string($body['conflict_policy'] ?? null) ? (string) $body['conflict_policy'] : 'skip';

            return Response::ok($this->importExport->executeImport($body, $policy));
        } catch (ValidationException $e) {
            return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fieldErrors);
        }
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function exportCatalog(WP_REST_Request $request): WP_REST_Response
    {
        $format = is_string($request->get_param('format')) ? strtolower((string) $request->get_param('format')) : 'json';

        if ($format === 'csv') {
            return Response::ok([
                'format'  => 'csv',
                'content' => $this->importExport->exportCsv(),
            ]);
        }

        return Response::ok([
            'format'  => 'json',
            'payload' => $this->importExport->exportJson(),
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     * @return array<string, mixed>
     */
    private function body(WP_REST_Request $request): array
    {
        $rawBody = $request->get_body();
        if (strlen($rawBody) > self::MAX_PAYLOAD_BYTES) {
            throw new ValidationException(
                'Import payload exceeds the maximum allowed size of 2 MiB.',
                413,
                'djgear_catalog_payload_too_large'
            );
        }

        $json = $request->get_json_params();

        return $json !== [] ? $json : $request->get_params();
    }
}
