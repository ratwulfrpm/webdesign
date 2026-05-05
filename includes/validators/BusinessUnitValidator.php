<?php
/**
 * includes/validators/BusinessUnitValidator.php
 *
 * Entity-level validation for organizations (business units) table.
 * Wraps Validator constants to produce a field-keyed error map.
 *
 * Usage:
 *   $errors = BusinessUnitValidator::validate($data);
 *   if ($errors) { jsonValidationError($errors); }
 */

require_once __DIR__ . '/../Validator.php';
require_once __DIR__ . '/../Input.php';

final class BusinessUnitValidator
{
    /**
     * Validate business unit (organization) create/update data.
     *
     * @param  array $data         Associative array of field => value.
     * @param  bool  $requireName  True on create; false on patch (optional update).
     * @return array<string, string>  Field-keyed error messages. Empty = valid.
     */
    public static function validate(array $data, bool $requireName = true): array
    {
        $errors = [];

        // ── name ──────────────────────────────────────────────────
        $name = isset($data['name']) ? trim((string) $data['name']) : null;
        if ($requireName && ($name === null || $name === '')) {
            $errors['name'] = 'Business unit name is required.';
        } elseif ($name !== null && $name !== '') {
            if (mb_strlen($name, 'UTF-8') > Validator::maxLen('org_name')) {
                $errors['name'] = 'Business unit name must not exceed ' . Validator::maxLen('org_name') . ' characters.';
            }
        }

        // ── slug ──────────────────────────────────────────────────
        if (isset($data['slug']) && $data['slug'] !== '') {
            $slug = trim((string) $data['slug']);
            if (!Validator::isSlug($slug)) {
                $errors['slug'] = 'Slug must be lowercase alphanumeric with hyphens only, max ' . Validator::maxLen('org_slug') . ' characters.';
            }
        }

        // ── description ───────────────────────────────────────────
        if (isset($data['description'])) {
            $desc = (string) $data['description'];
            if (mb_strlen($desc, 'UTF-8') > Validator::maxLen('org_description')) {
                $errors['description'] = 'Description must not exceed ' . Validator::maxLen('org_description') . ' characters.';
            }
        }

        // ── tax_id ────────────────────────────────────────────────
        if (isset($data['tax_id'])) {
            $taxId = trim((string) $data['tax_id']);
            if (mb_strlen($taxId, 'UTF-8') > Validator::maxLen('tax_id')) {
                $errors['tax_id'] = 'Tax ID must not exceed ' . Validator::maxLen('tax_id') . ' characters.';
            }
        }

        // ── legal_rep_name ────────────────────────────────────────
        if (isset($data['legal_rep_name'])) {
            $lrn = trim((string) $data['legal_rep_name']);
            if (mb_strlen($lrn, 'UTF-8') > Validator::maxLen('legal_rep_name')) {
                $errors['legal_rep_name'] = 'Legal representative name must not exceed ' . Validator::maxLen('legal_rep_name') . ' characters.';
            }
        }

        // ── legal_rep_id ──────────────────────────────────────────
        if (isset($data['legal_rep_id'])) {
            $lri = trim((string) $data['legal_rep_id']);
            if (mb_strlen($lri, 'UTF-8') > Validator::maxLen('legal_rep_id')) {
                $errors['legal_rep_id'] = 'Legal representative ID must not exceed ' . Validator::maxLen('legal_rep_id') . ' characters.';
            }
        }

        // ── contact email ─────────────────────────────────────────
        if (isset($data['contact_email']) && $data['contact_email'] !== '') {
            if (!Validator::email($data['contact_email'])) {
                $errors['contact_email'] = 'A valid contact email address is required.';
            }
        }

        // ── country ───────────────────────────────────────────────
        if (isset($data['country'])) {
            $country = trim((string) $data['country']);
            if (mb_strlen($country, 'UTF-8') > Validator::maxLen('country')) {
                $errors['country'] = 'Country must not exceed ' . Validator::maxLen('country') . ' characters.';
            }
        }

        return $errors;
    }
}
