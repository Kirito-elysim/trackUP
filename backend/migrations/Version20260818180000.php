<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add companies/tutors entities for the alternance module, link learners to a tutor/company, and seed the companies.view feature';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE companies ('
            . '  id INT AUTO_INCREMENT NOT NULL,'
            . '  name VARCHAR(255) NOT NULL,'
            . '  siret VARCHAR(20) DEFAULT NULL,'
            . '  address LONGTEXT DEFAULT NULL,'
            . '  sector VARCHAR(120) DEFAULT NULL,'
            . '  contact_name VARCHAR(180) DEFAULT NULL,'
            . '  contact_email VARCHAR(180) DEFAULT NULL,'
            . '  contact_phone VARCHAR(40) DEFAULT NULL,'
            . '  created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . '  updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . '  UNIQUE INDEX UNIQ_COMPANIES_SIRET (siret),'
            . '  PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'CREATE TABLE tutors ('
            . '  id INT AUTO_INCREMENT NOT NULL,'
            . '  first_name VARCHAR(120) NOT NULL,'
            . '  last_name VARCHAR(120) NOT NULL,'
            . '  email VARCHAR(180) DEFAULT NULL,'
            . '  phone VARCHAR(40) DEFAULT NULL,'
            . '  created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . '  updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . '  PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'CREATE TABLE company_tutor ('
            . '  tutor_id INT NOT NULL,'
            . '  company_id INT NOT NULL,'
            . '  INDEX IDX_COMPANY_TUTOR_TUTOR (tutor_id),'
            . '  INDEX IDX_COMPANY_TUTOR_COMPANY (company_id),'
            . '  PRIMARY KEY(tutor_id, company_id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
        $this->addSql('ALTER TABLE company_tutor ADD CONSTRAINT FK_COMPANY_TUTOR_TUTOR FOREIGN KEY (tutor_id) REFERENCES tutors (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE company_tutor ADD CONSTRAINT FK_COMPANY_TUTOR_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE learners ADD tutor_id INT DEFAULT NULL, ADD company_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE learners ADD CONSTRAINT FK_LEARNERS_TUTOR FOREIGN KEY (tutor_id) REFERENCES tutors (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE learners ADD CONSTRAINT FK_LEARNERS_COMPANY FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_LEARNERS_TUTOR ON learners (tutor_id)');
        $this->addSql('CREATE INDEX IDX_LEARNERS_COMPANY ON learners (company_id)');

        $this->addSql(
            "INSERT IGNORE INTO features (code, name, category, description) VALUES "
            . "('companies.view', 'Entreprises & tuteurs', 'Pilotage', "
            . "'Gérer les entreprises, les tuteurs et le rattachement des apprenants pour le suivi de l’alternance.')"
        );

        // Grant the new feature to every role that already has learners.view, matching the
        // pattern used for activity_logs.import — this module is learner-adjacent pilotage
        // data, so whoever already manages apprenants should see it immediately.
        $this->addSql(
            'INSERT IGNORE INTO role_feature (role_id, feature_id) '
            . 'SELECT rf.role_id, f_new.id '
            . 'FROM role_feature rf '
            . 'INNER JOIN features f_existing ON f_existing.id = rf.feature_id AND f_existing.code = \'learners.view\' '
            . 'INNER JOIN features f_new ON f_new.code = \'companies.view\''
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE rf FROM role_feature rf '
            . 'INNER JOIN features f ON f.id = rf.feature_id AND f.code = \'companies.view\''
        );
        $this->addSql("DELETE FROM features WHERE code = 'companies.view'");

        $this->addSql('ALTER TABLE learners DROP FOREIGN KEY FK_LEARNERS_TUTOR');
        $this->addSql('ALTER TABLE learners DROP FOREIGN KEY FK_LEARNERS_COMPANY');
        $this->addSql('DROP INDEX IDX_LEARNERS_TUTOR ON learners');
        $this->addSql('DROP INDEX IDX_LEARNERS_COMPANY ON learners');
        $this->addSql('ALTER TABLE learners DROP tutor_id, DROP company_id');

        $this->addSql('ALTER TABLE company_tutor DROP FOREIGN KEY FK_COMPANY_TUTOR_TUTOR');
        $this->addSql('ALTER TABLE company_tutor DROP FOREIGN KEY FK_COMPANY_TUTOR_COMPANY');
        $this->addSql('DROP TABLE company_tutor');
        $this->addSql('DROP TABLE tutors');
        $this->addSql('DROP TABLE companies');
    }
}
