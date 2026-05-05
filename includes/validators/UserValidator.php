<?php
/**
 * includes/validators/UserValidator.php
 *
 * Entity-level validation for users table.
 * Wraps Validator constants to produce a field-keyed error map.
 *
 * Usage:
 *   $errors = UserValidator::validateCreate($data);
 *   if ($errors) { jsonValidationError($errors); }
 */

require_once __DIR__ . '/../Validator.php';
require_once __DIR__ . '/../Input.php';

final class UserValidator
{
    /**
     * Validate data for creating a new user.
     *
     * @param  array $data  Associative array of field => value.
     * @return array<string, string>  Field-keyed error messages. Empty = valid.
     */
    public static function validateCreate(array $data): array
    {
        $errors = self::validateCommon($data);

        // ── username (required on create) ────────────────────────
        $username = isset($data['username']) ? trim((string) $data['username']) : '';
        if ($username === '') {
            $errors['username'] = 'Username is required.';
        } elseif (!Validator::isUsername($username)) {
            $errors['username'] = 'Username must be 3–' . Validator::maxLen('username') . ' characters, letters/digits/underscore/hyphen only.';
        }

        // ── password (required on create) ────────────────────────
        $password = $data['password'] ?? '';
        if ($password === '') {
            $errors['password'] = 'Password is required.';
        } elseif (mb_strlen((string) $password, 'UTF-8') > Validator::maxLen('password')) {
            $errors['password'] = 'Password must not exceed ' . Validator::maxLen('password') . ' characters.';
        }

        return $errors;
    }

    /**
     * Validate data for updating an existing user (all fields optional).
     *
     * @param  array $data  Associative array of field => value.
     * @return array<string, string>  Field-keyed error messages. Empty = valid.
     */
    public static function validateUpdate(array $data): array
    {
        $errors = self::validateCommon($data);

        // ── username (optional on update) ────────────────────────
        if (isset($data['username']) && $data['username'] !== '') {
            $username = trim((string) $data['username']);
            if (!Validator::isUsername($username)) {
                $errors['username'] = 'Username must be 3–' . Validator::maxLen('username') . ' characters, letters/digits/underscore/hyphen only.';
            }
        }

        // ── password (optional on update) ────────────────────────
        if (isset($data['password']) && $data['password'] !== '') {
            if (mb_strlen((string) $data['password'], 'UTF-8') > Validator::maxLen('password')) {
                $errors['password'] = 'Password must not exceed ' . Validator::maxLen('password') . ' characters.';
            }
        }

        return $errors;
    }

    /**
     * Shared validations applied for both create and update.
     *
     * @param  array $data
     * @return array<string, string>
     */
    private static function validateCommon(array $data): array
    {
        $errors = [];

        // ── email ─────────────────────────────────────────────────
        if (isset($data['email']) && $data['email'] !== '') {
            if (!Validator::email($data['email'])) {
                $errors['email'] = 'A valid email address is required (max ' . Validator::maxLen('email') . ' characters).';
            }
        }

        // ── full_name ─────────────────────────────────────────────
        if (isset($data['full_name'])) {
            $fullName = trim((string) $data['full_name']);
            if (mb_strlen($fullName, 'UTF-8') > Validator::maxLen('full_name')) {
                $errors['full_name'] = 'Full name must not exceed ' . Validator::maxLen('full_name') . ' characters.';
            }
        }

        // ── company_name ──────────────────────────────────────────
        if (isset($data['company_name'])) {
            $company = trim((string) $data['company_name']);
            if (mb_strlen($company, 'UTF-8') > Validator::maxLen('company_name')) {
                $errors['company_name'] = 'Company name must not exceed ' . Validator::maxLen('company_name') . ' characters.';
            }
        }

        // ── role ──────────────────────────────────────────────────
        if (isset($data['role'])) {
            $allowedRoles = ['admin', 'supplier', 'support', 'user', 'owner'];
            if (!in_array((string) $data['role'], $allowedRoles, true)) {
                $errors['role'] = 'Invalid role. Allowed roles: ' . implode(', ', $allowedRoles) . '.';
            }
        }

        // ── phone_number ──────────────────────────────────────────
        if (isset($data['phone_number'])) {
            $phone = trim((string) $data['phone_number']);
            if (mb_strlen($phone, 'UTF-8') > Validator::maxLen('phone_number')) {
                $errors['phone_number'] = 'Phone number must not exceed ' . Validator::maxLen('phone_number') . ' characters.';
            }
        }

        return $errors;
    }
}
