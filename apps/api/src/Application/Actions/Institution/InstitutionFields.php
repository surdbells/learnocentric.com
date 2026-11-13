<?php

declare(strict_types=1);

namespace App\Application\Actions\Institution;

/**
 * Shared mapping between the SPA's institution payload (camelCase, with a few
 * convenience fields the entity stores differently) and the Institution entity.
 * Used by onboard (create) and update so they stay in lockstep.
 */
final class InstitutionFields
{
    /** Normalise the free-text type to the entity's canonical values. */
    public static function normalizeType(mixed $raw): string
    {
        $t = strtolower(trim((string) $raw));
        if ($t === 'tutoring_academy' || $t === 'academy' || str_contains($t, 'tutor')) {
            return 'tutoring_academy';
        }
        return 'school';
    }

    /**
     * Build the admin_contact blob, preserving any already-stored values.
     *
     * @param array<string,mixed>|null $existing
     * @return array<string,string>
     */
    public static function adminContact(string $email, string $phone, string $firstName, string $lastName, ?array $existing = null): array
    {
        $out = is_array($existing) ? $existing : [];
        if ($email !== '') { $out['email'] = $email; }
        if ($phone !== '') { $out['phone'] = $phone; }
        if ($firstName !== '') { $out['first_name'] = $firstName; }
        if ($lastName !== '') { $out['last_name'] = $lastName; }
        return $out;
    }

    /**
     * The package the form chose. An institution holds one package; the form
     * sends packageIds as a single id, '' (none), or an array (first id wins).
     *
     * @return array{0:bool,1:?int} [was the field sent at all, chosen id or null to unassign]
     */
    public static function packageChoice(array $body): array
    {
        $key = array_key_exists('packageIds', $body) ? 'packageIds' : (array_key_exists('package_ids', $body) ? 'package_ids' : null);
        if ($key === null) {
            return [false, null];
        }
        $raw = $body[$key];
        foreach (is_array($raw) ? $raw : [$raw] as $id) {
            $n = (int) $id;
            if ($n > 0) {
                return [true, $n];
            }
        }
        return [true, null];
    }
}
