<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Json;
use App\Domain\Entity\Institution;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /backend/school/settings — the institution's grading policy and safeguarding
 * configuration. Readable by any member of the institution; a school/tutor admin
 * may update it.
 */
final class SchoolSettingsAction
{
    private const DEFAULTS = [
        'grading' => [
            'pass_mark' => 50,
            'bands' => [
                ['grade' => 'A', 'min' => 70],
                ['grade' => 'B', 'min' => 60],
                ['grade' => 'C', 'min' => 50],
                ['grade' => 'D', 'min' => 40],
                ['grade' => 'F', 'min' => 0],
            ],
            // Score separation is the default: portfolio/competency never inflates the
            // academic grade unless a school deliberately opts in here (spec §14, §18).
            'weighting' => [
                'portfolio_into_academic' => false,
                'portfolio_percent' => 0, // 0–100 share of a blended grade when enabled
            ],
        ],
        'safeguarding' => [
            'lead_name' => '',
            'lead_email' => '',
            'lead_phone' => '',
            'policy_note' => '',
        ],
        // Institution-level configuration surfaced by the School-Admin Settings hub.
        'academic' => [
            'naming_convention' => 'first_last',   // first_last | last_first
            'class_structure' => 'grade_based',    // grade_based | form_based | year_based
        ],
        'localization' => [
            'timezone' => 'Africa/Lagos',
            'date_format' => 'DD MMM YYYY',
            'language' => 'en',
        ],
        'branding' => [
            'portal_name' => '',
        ],
        'notifications' => [
            'email_alerts' => true,
            'report_notifications' => true,
            'parent_communication' => true,
        ],
        'data' => [
            'auto_save' => true,
            'archive_policy' => 'after_3_years',   // never | after_1_year | after_3_years
            'approval_workflow' => 'admin_final',   // admin_final | single | none
        ],
    ];

    /** Flat scalar sections editable by a straight typed whitelist merge. */
    private const FLAT_SECTIONS = ['academic', 'localization', 'branding', 'notifications', 'data'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        return strtoupper($request->getMethod()) === 'PUT'
            ? $this->update($request, $response)
            : $this->show($request, $response);
    }

    private function show(Request $request, Response $response): Response
    {
        $institution = $this->institution($request);
        if ($institution === null) {
            return Json::error($response, 'No institution is linked to this account.', 404);
        }
        return Json::write($response, $this->merged($institution));
    }

    private function update(Request $request, Response $response): Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if (!in_array($user?->getRole()->getCode(), ['school_admin', 'tutor_admin'], true)) {
            return Json::error($response, 'Only a school administrator can change settings.', 403);
        }
        $institution = $this->institution($request);
        if ($institution === null) {
            return Json::error($response, 'No institution is linked to this account.', 404);
        }
        $before = $this->merged($institution);
        $body = (array) $request->getParsedBody();
        $current = $this->merged($institution);

        // Grading.
        if (is_array($body['grading'] ?? null)) {
            $g = $body['grading'];
            if (isset($g['pass_mark'])) {
                $current['grading']['pass_mark'] = max(0, min(100, (int) $g['pass_mark']));
            }
            if (is_array($g['bands'] ?? null)) {
                $bands = [];
                foreach ($g['bands'] as $b) {
                    $grade = trim((string) ($b['grade'] ?? ''));
                    if ($grade === '') {
                        continue;
                    }
                    $bands[] = ['grade' => $grade, 'min' => max(0, min(100, (int) ($b['min'] ?? 0)))];
                }
                if ($bands !== []) {
                    usort($bands, static fn ($a, $b) => $b['min'] <=> $a['min']);
                    $current['grading']['bands'] = $bands;
                }
            }
        }

        // Safeguarding.
        if (is_array($body['safeguarding'] ?? null)) {
            $s = $body['safeguarding'];
            foreach (['lead_name', 'lead_email', 'lead_phone', 'policy_note'] as $k) {
                if (array_key_exists($k, $s)) {
                    $current['safeguarding'][$k] = trim((string) $s[$k]);
                }
            }
        }

        // Flat scalar sections — merge each supplied key, cast to the default's type.
        foreach (self::FLAT_SECTIONS as $section) {
            if (!is_array($body[$section] ?? null)) {
                continue;
            }
            foreach (self::DEFAULTS[$section] as $key => $default) {
                if (!array_key_exists($key, $body[$section])) {
                    continue;
                }
                $value = $body[$section][$key];
                if (is_bool($default)) {
                    $current[$section][$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
                } elseif (is_int($default)) {
                    $current[$section][$key] = is_numeric($value) ? (int) $value : $default;
                } else {
                    $current[$section][$key] = mb_substr(trim((string) $value), 0, 200);
                }
            }
        }

        $institution->setSettings($current);
        $this->em->flush();
        $this->audit->log('institution.settings', $user, 'Institution', (string) $institution->getId(), $before, $current);

        return Json::write($response, $current);
    }

    /** Stored settings merged over defaults so the client always gets a full shape. */
    private function merged(Institution $institution): array
    {
        $stored = $institution->getSettings() ?? [];
        $out = [
            'grading' => array_merge(self::DEFAULTS['grading'], $stored['grading'] ?? []),
            'safeguarding' => array_merge(self::DEFAULTS['safeguarding'], $stored['safeguarding'] ?? []),
        ];
        foreach (self::FLAT_SECTIONS as $section) {
            $out[$section] = array_merge(self::DEFAULTS[$section], is_array($stored[$section] ?? null) ? $stored[$section] : []);
        }
        return $out;
    }

    private function institution(Request $request): ?Institution
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        return $user?->getInstitution();
    }
}
