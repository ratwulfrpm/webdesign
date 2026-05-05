<?php
/**
 * includes/validators/QuoteValidator.php
 *
 * Entity-level validation for quote assignments (quote_assignments table).
 * Wraps Validator constants to produce a field-keyed error map.
 *
 * Usage:
 *   $errors = QuoteValidator::validate($data);
 *   if ($errors) { jsonValidationError($errors); }
 */

require_once __DIR__ . '/../Validator.php';
require_once __DIR__ . '/../Input.php';

final class QuoteValidator
{
    /**
     * Validate quote assignment data.
     *
     * @param  array $data  Associative array of field => value.
     * @return array<string, string>  Field-keyed error messages. Empty = valid.
     */
    public static function validate(array $data): array
    {
        $errors = [];

        // ── customer_name ────────────────────────────────────────
        $custName = isset($data['customer_name']) ? trim((string) $data['customer_name']) : '';
        if ($custName === '') {
            $errors['customer_name'] = 'Customer name is required.';
        } elseif (mb_strlen($custName, 'UTF-8') > Validator::maxLen('customer_name')) {
            $errors['customer_name'] = 'Customer name must not exceed ' . Validator::maxLen('customer_name') . ' characters.';
        }

        // ── profit_calculation_type ───────────────────────────────
        $profitType = isset($data['profit_calculation_type']) ? (string) $data['profit_calculation_type'] : '';
        $allowedProfitTypes = ['percentage', 'fixed_amount', 'none'];
        if ($profitType !== '' && !in_array($profitType, $allowedProfitTypes, true)) {
            $errors['profit_calculation_type'] = 'Invalid profit calculation type.';
        }

        // ── profit_percentage ────────────────────────────────────
        if (isset($data['profit_percentage']) && $data['profit_percentage'] !== '' && $data['profit_percentage'] !== null) {
            if (!Validator::percentage($data['profit_percentage'])) {
                $errors['profit_percentage'] = 'Profit percentage must be between ' . Validator::PERCENTAGE_MIN . ' and ' . Validator::PERCENTAGE_MAX . '.';
            }
        }

        // ── profit_fixed_amount ───────────────────────────────────
        if (isset($data['profit_fixed_amount']) && $data['profit_fixed_amount'] !== '' && $data['profit_fixed_amount'] !== null) {
            if (!Validator::price($data['profit_fixed_amount'])) {
                $errors['profit_fixed_amount'] = 'Profit fixed amount must be between ' . Validator::PRICE_MIN . ' and ' . Validator::PRICE_MAX . '.';
            }
        }

        // ── discount_pct ─────────────────────────────────────────
        if (isset($data['discount_pct']) && $data['discount_pct'] !== '' && $data['discount_pct'] !== null) {
            if (!Validator::discountPct($data['discount_pct'])) {
                $errors['discount_pct'] = 'Discount must be between ' . Validator::DISCOUNT_MIN . '% and ' . Validator::DISCOUNT_MAX . '%.';
            }
        }

        // ── transport_pct ─────────────────────────────────────────
        if (isset($data['transport_pct']) && $data['transport_pct'] !== '' && $data['transport_pct'] !== null) {
            if (!Validator::inRange($data['transport_pct'], 0, Validator::TRANSPORT_MAX)) {
                $errors['transport_pct'] = 'Transport percentage must be between 0 and ' . Validator::TRANSPORT_MAX . '.';
            }
        }

        // ── tax_pct ───────────────────────────────────────────────
        if (isset($data['tax_pct']) && $data['tax_pct'] !== '' && $data['tax_pct'] !== null) {
            if (!Validator::inRange($data['tax_pct'], 0, Validator::TAX_MAX)) {
                $errors['tax_pct'] = 'Tax percentage must be between 0 and ' . Validator::TAX_MAX . '.';
            }
        }

        // ── validity_days ─────────────────────────────────────────
        if (isset($data['validity_days']) && $data['validity_days'] !== '' && $data['validity_days'] !== null) {
            if (!Validator::validityDays($data['validity_days'])) {
                $errors['validity_days'] = 'Validity must be an integer between ' . Validator::VALIDITY_DAYS_MIN . ' and ' . Validator::VALIDITY_DAYS_MAX . ' days.';
            }
        }

        // ── special_conditions ────────────────────────────────────
        if (isset($data['special_conditions'])) {
            $sc = (string) $data['special_conditions'];
            if (mb_strlen($sc, 'UTF-8') > Validator::maxLen('special_conditions')) {
                $errors['special_conditions'] = 'Special conditions must not exceed ' . Validator::maxLen('special_conditions') . ' characters.';
            }
        }

        // ── notes ─────────────────────────────────────────────────
        if (isset($data['notes'])) {
            $notes = (string) $data['notes'];
            if (mb_strlen($notes, 'UTF-8') > Validator::maxLen('notes')) {
                $errors['notes'] = 'Notes must not exceed ' . Validator::maxLen('notes') . ' characters.';
            }
        }

        return $errors;
    }
}
