<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RBAC enforcement (audit P0 #5): the super-admin "Enforce RBAC" switch used to
 * be ignored, so any saved value is meaningless. Switch it on once so custom
 * role matrices take effect; the super admin can turn it off afterwards.
 */
final class Version20261113000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Turn on security.rbac_enforce in saved platform settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE platform_settings
               SET data = jsonb_set(
                       COALESCE(data::jsonb, '{}'::jsonb),
                       '{security}',
                       COALESCE(data::jsonb -> 'security', '{}'::jsonb) || '{"rbac_enforce": true}'::jsonb
                   )::json
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Irreversible in meaning: the previous value was never honoured.
    }
}
