<?php
/**
 * includes/product_code.php — Internal product code generator.
 *
 * Generates application-assigned internal codes that:
 *  - Are globally unique across all products.
 *  - Are NOT derived from supplier_product_code, supplier_id, or product_id.
 *  - Use a human-readable alphanumeric format (no ambiguous chars).
 *  - Are cryptographically random (CSPRNG via random_int).
 *
 * Format:  APP-PRD-XXXXXXXX
 *          where XXXXXXXX is 8 characters from a base-32 alphabet
 *          that excludes visually ambiguous characters: O, 0, I, 1
 *
 * Alphabet size: 32 chars → 32^8 ≈ 1.1 trillion combinations.
 *
 * Usage:
 *   require_once 'includes/product_code.php';
 *   $code = generateInternalProductCode($pdo);
 *   // Returns e.g.  "APP-PRD-8F3K2Q9L"
 */

/**
 * Generate a unique internal product code and verify it does not already
 * exist in the database before returning it.
 *
 * Retries up to $maxAttempts times on collision (practically impossible
 * with a trillion-combination space, but handled defensively).
 *
 * @param  PDO $pdo
 * @param  int $maxAttempts
 * @return string  e.g. "APP-PRD-8F3K2Q9L"
 * @throws RuntimeException if a unique code cannot be generated.
 */
function generateInternalProductCode(PDO $pdo, int $maxAttempts = 10): string
{
    $check = $pdo->prepare(
        'SELECT 1 FROM supplier_products
          WHERE internal_product_code = ?
          LIMIT 1'
    );

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $code = _buildInternalCode();
        $check->execute([$code]);
        if (!$check->fetch()) {
            return $code;
        }
        // Collision — extremely unlikely, but retry.
        error_log('generateInternalProductCode: collision on attempt ' . $attempt . ' for code ' . $code);
    }

    throw new RuntimeException(
        'generateInternalProductCode: could not generate a unique code after ' . $maxAttempts . ' attempts.'
    );
}

/**
 * Build one candidate code string.
 * Uses random_int (CSPRNG) — NOT mt_rand, NOT uniqid.
 *
 * Alphabet: ABCDEFGHJKLMNPQRSTUVWXYZ23456789  (32 chars)
 * Excludes: O (looks like 0), 0 (looks like O), I (looks like 1), 1 (looks like I)
 */
function _buildInternalCode(): string
{
    // 32-char alphabet, no ambiguous characters
    static $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $len  = 8;          // 8 random chars → 32^8 ≈ 1.1T combinations
    $max  = strlen($alphabet) - 1;
    $rand = '';
    for ($i = 0; $i < $len; $i++) {
        $rand .= $alphabet[random_int(0, $max)];
    }
    return 'APP-PRD-' . $rand;
}
