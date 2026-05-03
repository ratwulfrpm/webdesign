<?php
/**
 * includes/contract_validity_admin.php
 * Admin/Owner workflow helpers for contract validity requests.
 */

function cvrTableAvailable(PDO $pdo): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    try {
        // Lightweight probe: if table is missing, this throws and we hide the workflow safely.
        $pdo->query('SELECT 1 FROM supplier_contract_validity_requests LIMIT 1');
        $available = true;
    } catch (Throwable $e) {
        $available = false;
    }

    return $available;
}

function cvrListValidityRequests(PDO $pdo, ?int $orgId, ?string $status = null): array
{
    if (!cvrTableAvailable($pdo)) {
        return [];
    }

    $where = [];
    $params = [];

    if ($orgId !== null) {
        $where[] = 'r.org_id = ?';
        $params[] = $orgId;
    }
    if ($status !== null && in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
        $where[] = 'r.status = ?';
        $params[] = $status;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT r.id, r.supplier_id, r.org_id, r.requested_contract_id,
                   r.current_primary_contract_id, r.requested_by_user_id, r.reviewed_by_user_id,
                   r.status, r.review_comment, r.requested_at, r.reviewed_at,
                   s.username AS supplier_username,
                   o.name AS org_name,
                   req.original_filename AS requested_contract_file,
                   req.signed_date AS requested_signed_date,
                   req.effective_start_date AS requested_start_date,
                   req.effective_end_date AS requested_end_date,
                   req.created_at AS requested_uploaded_at,
                   cur.original_filename AS current_contract_file,
                   cur.signed_date AS current_signed_date,
                   cur.effective_start_date AS current_start_date,
                   cur.effective_end_date AS current_end_date,
                   cur.created_at AS current_uploaded_at,
                   ureq.username AS requested_by_username,
                   urev.username AS reviewed_by_username
              FROM supplier_contract_validity_requests r
              JOIN users s ON s.id = r.supplier_id
              JOIN organizations o ON o.id = r.org_id
              JOIN supplier_contracts req ON req.id = r.requested_contract_id
              LEFT JOIN supplier_contracts cur ON cur.id = r.current_primary_contract_id
              LEFT JOIN users ureq ON ureq.id = r.requested_by_user_id
              LEFT JOIN users urev ON urev.id = r.reviewed_by_user_id
              {$whereSql}
             ORDER BY CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END,
                      r.requested_at DESC,
                      r.id DESC";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function cvrLoadPendingRequest(PDO $pdo, int $requestId, ?int $orgId): ?array
{
    if (!cvrTableAvailable($pdo)) {
        return null;
    }

    $sql = 'SELECT r.id, r.supplier_id, r.org_id, r.requested_contract_id,
                   r.current_primary_contract_id, r.status
              FROM supplier_contract_validity_requests r
             WHERE r.id = ?
               AND r.status = "pending"';
    $params = [$requestId];

    if ($orgId !== null) {
        $sql .= ' AND r.org_id = ?';
        $params[] = $orgId;
    }

    $sql .= ' LIMIT 1';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function cvrApproveRequest(PDO $pdo, int $requestId, int $reviewerUserId, ?int $orgId): bool
{
    if (!cvrTableAvailable($pdo)) {
        return false;
    }

    $pending = cvrLoadPendingRequest($pdo, $requestId, $orgId);
    if (!$pending) {
        return false;
    }

    $supplierId = (int) $pending['supplier_id'];
    $targetOrgId = (int) $pending['org_id'];
    $requestedContractId = (int) $pending['requested_contract_id'];

    $contractSt = $pdo->prepare(
        'SELECT id FROM supplier_contracts
          WHERE id = ? AND supplier_id = ? AND org_id = ?
          LIMIT 1'
    );
    $contractSt->execute([$requestedContractId, $supplierId, $targetOrgId]);
    if (!$contractSt->fetchColumn()) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE supplier_contracts
                SET is_primary = 0
              WHERE supplier_id = ? AND org_id = ?'
        )->execute([$supplierId, $targetOrgId]);

        $pdo->prepare(
            'UPDATE supplier_contracts
                SET is_primary = 1
              WHERE id = ? AND supplier_id = ? AND org_id = ?'
        )->execute([$requestedContractId, $supplierId, $targetOrgId]);

        $pdo->prepare(
            'UPDATE supplier_contract_validity_requests
                SET status = "approved",
                    reviewed_by_user_id = ?,
                    reviewed_at = NOW(),
                    updated_at = NOW()
              WHERE id = ? AND status = "pending"'
        )->execute([$reviewerUserId, $requestId]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function cvrRejectRequest(PDO $pdo, int $requestId, int $reviewerUserId, ?int $orgId, ?string $comment): bool
{
    if (!cvrTableAvailable($pdo)) {
        return false;
    }

    $pending = cvrLoadPendingRequest($pdo, $requestId, $orgId);
    if (!$pending) {
        return false;
    }

    if ($comment !== null) {
        $comment = trim($comment);
        $safeComment = function_exists('mb_substr') ? mb_substr($comment, 0, 1000) : substr($comment, 0, 1000);
    } else {
        $safeComment = null;
    }

    $st = $pdo->prepare(
        'UPDATE supplier_contract_validity_requests
            SET status = "rejected",
                review_comment = ?,
                reviewed_by_user_id = ?,
                reviewed_at = NOW(),
                updated_at = NOW()
          WHERE id = ? AND status = "pending"'
    );

    $st->execute([$safeComment !== '' ? $safeComment : null, $reviewerUserId, $requestId]);
    return $st->rowCount() > 0;
}
