<?php
/**
 * includes/validators/ProductValidator.php
 *
 * Entity-level validation for supplier_products.
 * Wraps the low-level Validator constants to produce a field-keyed error map
 * that can be passed directly to jsonValidationError() or displayed in forms.
 *
 * Usage:
 *   $errors = ProductValidator::validate($data);
 *   if ($errors) { jsonValidationError($errors); }
 */

require_once __DIR__ . '/../Validator.php';
require_once __DIR__ . '/../Input.php';

final class ProductValidator
{
    /**
     * Validate product create/update data.
     *
     * @param  array $data  Associative array of field => value (from parsed request body).
     * @param  bool  $requireName  True on create; false on patch (optional update).
     * @return array<string, string>  Field-keyed error messages. Empty = valid.
     */
    public static function validate(array $data, bool $requireName = true): array
    {
        $errors = [];

        // ── product_name ─────────────────────────────────────────
        $name = isset($data['product_name']) ? trim((string) $data['product_name']) : null;
        if ($requireName && ($name === null || $name === '')) {
            $errors['product_name'] = 'Product name is required.';
        } elseif ($name !== null && $name !== '') {
            if (mb_strlen($name, 'UTF-8') > Validator::maxLen('product_name')) {
                $errors['product_name'] = 'Product name must not exceed ' . Validator::maxLen('product_name') . ' characters.';
            }
        }

        // ── supplier_product_code ────────────────────────────────
        if (isset($data['supplier_product_code'])) {
            $code = trim((string) $data['supplier_product_code']);
            if (mb_strlen($code, 'UTF-8') > Validator::maxLen('product_code')) {
                $errors['supplier_product_code'] = 'Product code must not exceed ' . Validator::maxLen('product_code') . ' characters.';
            }
        }

        // ── internal_product_code ────────────────────────────────
        if (isset($data['internal_product_code'])) {
            $icode = trim((string) $data['internal_product_code']);
            if (mb_strlen($icode, 'UTF-8') > Validator::maxLen('product_code')) {
                $errors['internal_product_code'] = 'Internal code must not exceed ' . Validator::maxLen('product_code') . ' characters.';
            }
        }

        // ── technical_description ────────────────────────────────
        if (isset($data['technical_description'])) {
            $desc = (string) $data['technical_description'];
            if (mb_strlen($desc, 'UTF-8') > Validator::maxLen('technical_description')) {
                $errors['technical_description'] = 'Description must not exceed ' . Validator::maxLen('technical_description') . ' characters.';
            }
        }

        // ── price_fob ────────────────────────────────────────────
        if (isset($data['price_fob']) && $data['price_fob'] !== '' && $data['price_fob'] !== null) {
            if (!Validator::price($data['price_fob'])) {
                $errors['price_fob'] = 'FOB price must be between ' . Validator::PRICE_MIN . ' and ' . Validator::PRICE_MAX . '.';
            }
        }

        // ── price_cif ────────────────────────────────────────────
        if (isset($data['price_cif']) && $data['price_cif'] !== '' && $data['price_cif'] !== null) {
            if (!Validator::price($data['price_cif'])) {
                $errors['price_cif'] = 'CIF price must be between ' . Validator::PRICE_MIN . ' and ' . Validator::PRICE_MAX . '.';
            }
        }

        // ── keywords ─────────────────────────────────────────────
        if (isset($data['keywords']) && is_array($data['keywords'])) {
            if (count($data['keywords']) > Validator::MAX_KEYWORDS) {
                $errors['keywords'] = 'A product may have at most ' . Validator::MAX_KEYWORDS . ' keywords.';
            } else {
                foreach ($data['keywords'] as $i => $kw) {
                    if (!Validator::isKeyword((string) $kw)) {
                        $errors['keywords[' . $i . ']'] = 'Keyword "' . htmlspecialchars((string) $kw, ENT_QUOTES, 'UTF-8') . '" is invalid (max ' . Validator::maxLen('keyword') . ' chars, letters/digits/dash/underscore only).';
                        break; // report first offending keyword only
                    }
                }
            }
        }

        return $errors;
    }
}
