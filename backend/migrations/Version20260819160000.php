<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add soft delete (deleted_at) to companies/tutors/prospects, extract sector into its own entity, '
            . 'and drop hard unique constraints that would conflict with soft-deleted rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies ADD deleted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE tutors ADD deleted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE prospects ADD deleted_at DATETIME DEFAULT NULL');

        $this->addSql(
            'CREATE TABLE sectors ('
            . '  id INT AUTO_INCREMENT NOT NULL,'
            . '  name VARCHAR(120) NOT NULL,'
            . '  created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . '  updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . '  deleted_at DATETIME DEFAULT NULL,'
            . '  INDEX IDX_SECTORS_NAME (name),'
            . '  PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        // Convertit les valeurs distinctes déjà présentes dans companies.sector (texte libre) en
        // lignes sectors, avant de basculer companies vers une relation sector_id.
        $this->addSql(
            'INSERT INTO sectors (name, created_at, updated_at) '
            . 'SELECT DISTINCT sector, NOW(), NOW() FROM companies WHERE sector IS NOT NULL AND sector != \'\''
        );

        $this->addSql('ALTER TABLE companies ADD sector_id INT DEFAULT NULL');
        $this->addSql(
            'UPDATE companies c INNER JOIN sectors s ON s.name = c.sector SET c.sector_id = s.id'
        );
        $this->addSql('ALTER TABLE companies ADD CONSTRAINT FK_COMPANIES_SECTOR FOREIGN KEY (sector_id) REFERENCES sectors (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_COMPANIES_SECTOR ON companies (sector_id)');
        $this->addSql('ALTER TABLE companies DROP sector');

        // Le soft delete rend les contraintes uniques dures problématiques : une fiche "supprimée"
        // resterait invisible dans l'appli mais bloquerait toujours ce SIRET/email en base lors
        // d'une recréation. L'unicité continue d'être vérifiée en code, mais seulement parmi les
        // fiches non supprimées (cohérent avec le filtre ORM soft_deleteable).
        $this->addSql('DROP INDEX UNIQ_COMPANIES_SIRET ON companies');
        $this->addSql('CREATE INDEX IDX_COMPANIES_SIRET ON companies (siret)');
        $this->addSql('DROP INDEX UNIQ_PROSPECTS_EMAIL ON prospects');
        $this->addSql('CREATE INDEX IDX_PROSPECTS_EMAIL ON prospects (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_PROSPECTS_EMAIL ON prospects');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROSPECTS_EMAIL ON prospects (email)');
        $this->addSql('DROP INDEX IDX_COMPANIES_SIRET ON companies');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_COMPANIES_SIRET ON companies (siret)');

        $this->addSql('ALTER TABLE companies ADD sector VARCHAR(120) DEFAULT NULL');
        $this->addSql('UPDATE companies c INNER JOIN sectors s ON s.id = c.sector_id SET c.sector = s.name');
        $this->addSql('ALTER TABLE companies DROP FOREIGN KEY FK_COMPANIES_SECTOR');
        $this->addSql('DROP INDEX IDX_COMPANIES_SECTOR ON companies');
        $this->addSql('ALTER TABLE companies DROP sector_id');
        $this->addSql('DROP TABLE sectors');

        $this->addSql('ALTER TABLE companies DROP deleted_at');
        $this->addSql('ALTER TABLE tutors DROP deleted_at');
        $this->addSql('ALTER TABLE prospects DROP deleted_at');
    }
}
