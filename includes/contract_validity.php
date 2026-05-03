<?php
/**
 * includes/contract_validity.php
 * Shared helpers for contract validity review workflow.
 */

function cvrLoadSupplierContract(PDO $pdo, int $contractId, int $supplierId, int $orgId): ?array
{
    $st = $pdo->prepare(
        'SELECT id, supplier_id, org_id, is_primary, effective_end_date, created_at
           FROM supplier_contracts
          WHERE id = ? AND supplier_id = ? AND org_id = ?
          LIMIT 1'
    );
    $st->execute([$contractId, $supplierId, $orgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function cvrLoadLatestSupplierContract(PDO $pdo, int $supplierId, int $orgId): ?array
{
    $st = $pdo->prepare(
        'SELECT id, supplier_id, org_id, is_primary, effective_end_date, created_at
           FROM supplier_contracts
          WHERE supplier_id = ? AND org_id = ?
          ORDER BY created_at DESC, id DESC
          LIMIT 1'
    );
    $st->execute([$supplierId, $orgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function cvrLoadCurrentPrimaryContractId(PDO $pdo, int $supplierId, int $orgId): ?int
{
    $st = $pdo->prepare(
        'SELECT id
           FROM supplier_contracts
          WHERE supplier_id = ? AND org_id = ? AND is_primary = 1
          ORDER BY created_at DESC, id DESC
          LIMIT 1'
    );
    $st->execute([$supplierId, $orgId]);
    $id = $st->fetchColumn();
    return $id !== false ? (int) $id : null;
}

function cvrIsContractExpired(?string $effectiveEndDate): bool
{
    if (!$effectiveEndDate) {
        return false;
    }
    $today = date('Y-m-d');
    return $effectiveEndDate < $today;
}

function cvrHasPendingRequest(PDO $pdo, int $supplierId, int $orgId, int $requestedContractId): bool
{
    $st = $pdo->prepare(
        'SELECT id
           FROM supplier_contract_validity_requests
          WHERE supplier_id = ?
            AND org_id = ?
            AND requested_contract_id = ?
            AND status = "pending"
          LIMIT 1'
    );
    $st->execute([$supplierId, $orgId, $requestedContractId]);
    return (bool) $st->fetchColumn();
}

function cvrCreateRequest(
    PDO $pdo,
    int $supplierId,
    int $orgId,
    int $requestedContractId,
    ?int $currentPrimaryContractId,
    int $requestedByUserId
): array {
    if (cvrHasPendingRequest($pdo, $supplierId, $orgId, $requestedContractId)) {
        return ['created' => false, 'duplicate' => true, 'id' => null];
    }

    $pdo->prepare(
        'INSERT INTO supplier_contract_validity_requests
            (supplier_id, org_id, requested_contract_id, current_primary_contract_id,
             requested_by_user_id, status, requested_at)
         VALUES (?, ?, ?, ?, ?, "pending", NOW())'
    )->execute([
        $supplierId,
        $orgId,
        $requestedContractId,
        $currentPrimaryContractId,
        $requestedByUserId,
    ]);

    return ['created' => true, 'duplicate' => false, 'id' => (int) $pdo->lastInsertId()];
}
