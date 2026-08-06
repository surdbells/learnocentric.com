<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Path-only file references: strip the stored absolute upload URL down to the
 * bare Flysystem path for every column that holds an uploaded file. External
 * links (e.g. a YouTube URL in content_resources.file_url) do not contain
 * "/uploads/" and are therefore left untouched.
 */
final class Version20260805100000 extends AbstractMigration
{
    /** @var array<string,string> table => column */
    private const TARGETS = [
        'institutions' => 'logo_url',
        'users' => 'profile_image_url',
        'content_resources' => 'file_url',
        'worksheets' => 'attachment_url',
        'worksheet_submissions' => 'attachment_url',
        'portfolio_entries' => 'evidence_url',
    ];

    public function getDescription(): string
    {
        return 'Convert stored upload URLs to bare Flysystem paths (path-only file serving)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TARGETS as $table => $col) {
            // Strip an optional "scheme://host" and a leading "/uploads/".
            $this->addSql(sprintf(
                "UPDATE %s SET %s = regexp_replace(%s, '^(https?://[^/]+)?/?uploads/', '') WHERE %s ~ '/uploads/'",
                $table,
                $col,
                $col,
                $col
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // One-way data normalisation; no automatic re-prefixing on rollback.
    }
}
