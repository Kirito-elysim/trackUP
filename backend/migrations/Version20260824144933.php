<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824144933 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the absences.view/absences.manage features for the absence tracking module';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT IGNORE INTO features (code, name, category, description) VALUES "
            . "('absences.view', 'Absences', 'Conformité', "
            . "'Consulter le tableau de bord des absences aux masterclass et sessions présentielles.'), "
            . "('absences.manage', 'Gestion des absences', 'Conformité', "
            . "'Valider ou rejeter les justificatifs d’absence, changer le statut, ajouter une note interne.')"
        );

        // Même raisonnement que pour companies.view (Version20260818180000) : accorder les deux
        // nouvelles features à tout rôle ayant déjà learners.view, pour une adoption immédiate sans
        // reconfiguration manuelle des rôles.
        $this->addSql(
            'INSERT IGNORE INTO role_feature (role_id, feature_id) '
            . 'SELECT rf.role_id, f_new.id '
            . 'FROM role_feature rf '
            . 'INNER JOIN features f_existing ON f_existing.id = rf.feature_id AND f_existing.code = \'learners.view\' '
            . 'INNER JOIN features f_new ON f_new.code IN (\'absences.view\', \'absences.manage\')'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE rf FROM role_feature rf '
            . 'INNER JOIN features f ON f.id = rf.feature_id AND f.code IN (\'absences.view\', \'absences.manage\')'
        );
        $this->addSql("DELETE FROM features WHERE code IN ('absences.view', 'absences.manage')");
    }
}
