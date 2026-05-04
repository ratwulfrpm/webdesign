<?php
/**
 * api/v1/resources/contract_validity_requests.php
 *
 * Supplier endpoint:
 *   POST /api/v1/supplier/contracts/{contractId}/request-validity-review
 *
 * Admin/Owner endpoints:
 *   GET  /api/v1/admin/contract-validity-requests
 *   POST /api/v1/admin/contract-validity-requests/{requestId}/approve
 *   POST /api/v1/admin/contract-validity-requests/{requestId}/reject
 */

require_once __DIR__ . '/../../../includes/contract_validity.php';
require_once __DIR__ . '/../../../includes/contract_validity_admin.php';
require_once __DIR__ . '/../../../includes/audit.php';

function handleSupplierContractValidityRoutes(string $method, array $segments): void
{
    if (($segments[1] ?? '') !== 'contracts') {
        jsonError('Not found', 404);
    }

    $contractIdRaw = $segments[2] ?? '';
    $action = $segments[3] ?? '';

    if (!ctype_digit((string) $contractIdRaw) || (int) $contractIdRaw <= 0) {
        jsonError('Invalid contract ID', 422);
    }
    $contractId = (int) $contractIdRaw;

    if ($method !== 'POST' || $action !== 'request-validity-review') {
        jsonError('Method Not Allowed', 405);
    }

    $auth = requireApiAuth(['supplier']);
    $pdo = getDB();
    $supplierId = (int) $auth['user_id'];
    $orgId = (int) $auth['org_id'];

    $target = cvrLoadSupplierContract($pdo, $contractId, $supplierId, $orgId);
    if (!$target) {
        jsonError('Contract not found for this supplier/business unit', 404);
    }

    $latest = cvrLoadLatestSupplierContract($pdo, $supplierId, $orgId);
    if (!$latest) {
        jsonError('No contracts found for this supplier', 422);
    }

    if ((int) $target['id'] === (int) $latest['id']) {
        jsonError('Latest contract does not require review request. Mark directly as current in supplier UI.', 422);
    }

    $currentPrimaryId = cvrLoadCurrentPrimaryContractId($pdo, $supplierId, $orgId);
    $req = cvrCreateRequest($pdo, $supplierId, $orgId, (int) $target['id'], $currentPrimaryId, $supplierId);

    if ($req['created']) {
        auditLog('supplier_contract_validity_review_requested', 'info', null, $supplierId, [
            'request_id' => $req['id'],
            'supplier_id' => $supplierId,
            'org_id' => $orgId,
            'requested_contract_id' => (int) $target['id'],
            'current_primary_contract_id' => $currentPrimaryId,
            'historical_expired' => cvrIsContractExpired($target['effective_end_date'] ?? null) ? 1 : 0,
            'source' => 'api',
        ]);
    }

    jsonOk([
        'created' => (bool) $req['created'],
        'pending_duplicate' => (bool) $req['duplicate'],
        'request_id' => $req['id'],
        'message' => $req['created']
            ? 'Review request sent. Current contract remains unchanged until approved.'
            : 'There is already a pending review request for this contract.',
    ], $req['created'] ? 201 : 200);
}

function handleAdminContractValidityRoutes(string $method, array $segments): void
{
    if (($segments[1] ?? '') !== 'contract-validity-requests') {
        jsonError('Not found', 404);
    }

    $auth = requireApiAuth(['admin', 'owner']);
    $pdo = getDB();
    $scopeOrgId = $auth['role'] === 'admin' ? (int) $auth['org_id'] : null;

    // GET /api/v1/admin/contract-validity-requests
    if ($method === 'GET' && count($segments) === 2) {
        $status = trim((string) ($_GET['status'] ?? ''));
        $statusFilter = in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)
            ? $status
            : null;

        $rows = cvrListValidityRequests($pdo, $scopeOrgId, $statusFilter);
        $items = array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'supplier_id' => (int) $r['supplier_id'],
                'supplier' => (string) $r['supplier_username'],
                'business_id' => (int) $r['org_id'],
                'business_unit' => (string) $r['org_name'],
                'requested_contract_id' => (int) $r['requested_contract_id'],
                'requested_contract_file' => (string) $r['requested_contract_file'],
                'current_primary_contract_id' => $r['current_primary_contract_id'] !== null ? (int) $r['current_primary_contract_id'] : null,
                'current_primary_contract_file' => $r['current_contract_file'] !== null ? (string) $r['current_contract_file'] : null,
                'signed_date' => $r['requested_signed_date'],
                'effective_start_date' => $r['requested_start_date'],
                'effective_end_date' => $r['requested_end_date'],
                'uploaded_at' => $r['requested_uploaded_at'],
                'requested_by_user_id' => (int) $r['requested_by_user_id'],
                'requested_by' => (string) ($r['requested_by_username'] ?? ''),
                'reviewed_by_user_id' => $r['reviewed_by_user_id'] !== null ? (int) $r['reviewed_by_user_id'] : null,
                'reviewed_by' => $r['reviewed_by_username'] !== null ? (string) $r['reviewed_by_username'] : null,
                'status' => (string) $r['status'],
                'review_comment' => $r['review_comment'],
                'requested_at' => $r['requested_at'],
                'reviewed_at' => $r['reviewed_at'],
            ];
        }, $rows);

        jsonOk([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    // POST /api/v1/admin/contract-validity-requests/{id}/approve|reject
    if ($method === 'POST' && count($segments) === 4) {
        $requestIdRaw = $segments[2] ?? '';
        $action = $segments[3] ?? '';

        if (!ctype_digit((string) $requestIdRaw) || (int) $requestIdRaw <= 0) {
            jsonError('Invalid request ID', 422);
        }
        $requestId = (int) $requestIdRaw;

        if ($action === 'approve') {
            $ok = cvrApproveRequest($pdo, $requestId, (int) $auth['user_id'], $scopeOrgId);
            if (!$ok) {
                jsonError('Pending request not found or invalid contract scope', 404);
            }

            auditLog('admin_contract_validity_request_approved', 'info', null, (int) $auth['user_id'], [
                'request_id' => $requestId,
                'org_scope' => $scopeOrgId,
                'source' => 'api',
            ]);
            auditLog('contract_primary_changed_by_review', 'info', null, (int) $auth['user_id'], [
                'request_id' => $requestId,
                'org_scope' => $scopeOrgId,
                'source' => 'api',
            ]);

            jsonOk(['id' => $requestId, 'status' => 'approved']);
        }

        if ($action === 'reject') {
            $body = parseBody();
            $comment = isset($body['review_comment']) ? (string) $body['review_comment'] : null;
            $ok = cvrRejectRequest($pdo, $requestId, (int) $auth['user_id'], $scopeOrgId, $comment);
            if (!$ok) {
                jsonError('Pending request not found in scope', 404);
            }

            auditLog('admin_contract_validity_request_rejected', 'warning', null, (int) $auth['user_id'], [
                'request_id' => $requestId,
                'org_scope' => $scopeOrgId,
                'source' => 'api',
            ]);

            jsonOk(['id' => $requestId, 'status' => 'rejected']);
        }

        jsonError('Not found', 404);
    }

    jsonError('Method Not Allowed', 405);
}
