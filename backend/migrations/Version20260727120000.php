<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dedicated activity_logs.import feature and stop gating the RiseUp activity log import behind exports.view';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT IGNORE INTO features (code, name, category, description) VALUES "
            . "('activity_logs.import', 'Import de journaux Rise Up', 'Administration', "
            . "'Importer un export Rise Up (XLSX/CSV) dans les journaux d’activité — droit d’écriture, distinct de la consultation des exports.')"
        );

        // Grant the new feature to every role that already has settings.users, since that is
        // the feature guarding the Sync Management page (the only frontend entry point to the
        // import endpoint). This preserves access for whoever could already reach the upload UI.
        $this->addSql(
            'INSERT IGNORE INTO role_feature (role_id, feature_id) '
            . 'SELECT rf.role_id, f_new.id '
            . 'FROM role_feature rf '
            . 'INNER JOIN features f_settings ON f_settings.id = rf.feature_id AND f_settings.code = \'settings.users\' '
            . 'INNER JOIN features f_new ON f_new.code = \'activity_logs.import\''
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE rf FROM role_feature rf '
            . 'INNER JOIN features f ON f.id = rf.feature_id AND f.code = \'activity_logs.import\''
        );
        $this->addSql("DELETE FROM features WHERE code = 'activity_logs.import'");
    }
}
