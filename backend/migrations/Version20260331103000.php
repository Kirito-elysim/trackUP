<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260331103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add local pathway model with pathway-trainings and pathway-enrollments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pathways (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(120) NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, state VARCHAR(80) DEFAULT NULL, target_duration INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_9B2017CC77153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE pathway_trainings (id INT AUTO_INCREMENT NOT NULL, pathway_id INT NOT NULL, training_id INT NOT NULL, position INT DEFAULT NULL, is_required TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_3322BD0C4B101C29 (pathway_id), INDEX IDX_3322BD0C5FB14BA7 (training_id), UNIQUE INDEX pathway_training_unique (pathway_id, training_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE pathway_enrollments (id INT AUTO_INCREMENT NOT NULL, pathway_id INT NOT NULL, learner_id INT NOT NULL, state VARCHAR(80) DEFAULT NULL, started_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ended_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', total_time INT DEFAULT NULL, average_progress DOUBLE PRECISION DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_E3A7D3544B101C29 (pathway_id), INDEX IDX_E3A7D354496E27F6 (learner_id), UNIQUE INDEX pathway_enrollment_unique (pathway_id, learner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE pathway_trainings ADD CONSTRAINT FK_3322BD0C4B101C29 FOREIGN KEY (pathway_id) REFERENCES pathways (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pathway_trainings ADD CONSTRAINT FK_3322BD0C5FB14BA7 FOREIGN KEY (training_id) REFERENCES trainings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pathway_enrollments ADD CONSTRAINT FK_E3A7D3544B101C29 FOREIGN KEY (pathway_id) REFERENCES pathways (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pathway_enrollments ADD CONSTRAINT FK_E3A7D354496E27F6 FOREIGN KEY (learner_id) REFERENCES learners (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pathway_trainings DROP FOREIGN KEY FK_3322BD0C4B101C29');
        $this->addSql('ALTER TABLE pathway_trainings DROP FOREIGN KEY FK_3322BD0C5FB14BA7');
        $this->addSql('ALTER TABLE pathway_enrollments DROP FOREIGN KEY FK_E3A7D3544B101C29');
        $this->addSql('ALTER TABLE pathway_enrollments DROP FOREIGN KEY FK_E3A7D354496E27F6');
        $this->addSql('DROP TABLE pathway_trainings');
        $this->addSql('DROP TABLE pathway_enrollments');
        $this->addSql('DROP TABLE pathways');
    }
}
