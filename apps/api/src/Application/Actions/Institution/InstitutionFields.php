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

    /** First usable package id from packageIds / package_ids (the entity stores one). */
    public static function firstPackageId(array $body): ?int
    {
        $ids = $body['packageIds'] ?? $body['package_ids'] ?? null;
        if (!is_array($ids)) {
            return null;
        }
        foreach ($ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                return $n;
            }
        }
        return null;
    }
}
