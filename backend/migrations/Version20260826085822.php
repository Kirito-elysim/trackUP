<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826085822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the learners.manage feature (bulk e-learning reminder emails from the group members table)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT IGNORE INTO features (code, name, category, description) VALUES "
            . "('learners.manage', 'Gestion des apprenants', 'Pilotage', "
            . "'Envoyer des relances e-learning (avancement, horaires de connexion) depuis la fiche groupe/parcours.')"
        );

        // Même raisonnement que pour absences.view/absences.manage (Version20260824144933) : accorder
        // la nouvelle feature à tout rôle ayant déjà learners.view, pour une adoption immédiate sans
        // reconfiguration manuelle des rôles.
        $this->addSql(
            'INSERT IGNORE INTO role_feature (role_id, feature_id) '
            . 'SELECT rf.role_id, f_new.id '
            . 'FROM role_feature rf '
            . 'INNER JOIN features f_existing ON f_existing.id = rf.feature_id AND f_existing.code = \'learners.view\' '
            . 'INNER JOIN features f_new ON f_new.code = \'learners.manage\''
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE rf FROM role_feature rf '
            . 'INNER JOIN features f ON f.id = rf.feature_id AND f.code = \'learners.manage\''
        );
        $this->addSql("DELETE FROM features WHERE code = 'learners.manage'");
    }
}
