<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260331143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace local pathway schema with API-aligned learning paths schema';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE features SET code = 'learningpaths.view' WHERE code = 'pathways.view'");
        $this->addSql("UPDATE features SET code = 'settings.learningpaths' WHERE code = 'settings.pathways'");

        $this->addSql('SET FOREIGN_KEY_CHECKS=0');
        $this->addSql('DROP TABLE IF EXISTS pathway_enrollments');
        $this->addSql('DROP TABLE IF EXISTS pathway_trainings');
        $this->addSql('DROP TABLE IF EXISTS pathways');
        $this->addSql('SET FOREIGN_KEY_CHECKS=1');

        $this->addSql("CREATE TABLE learning_paths (id INT AUTO_INCREMENT NOT NULL, external_id INT NOT NULL, title VARCHAR(255) NOT NULL, reference VARCHAR(120) DEFAULT NULL, language VARCHAR(10) DEFAULT NULL, description LONGTEXT DEFAULT NULL, sequential TINYINT(1) DEFAULT NULL, image_url LONGTEXT DEFAULT NULL, rise_up_created_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', rise_up_updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', synced_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_AA9FC3841E4D0459 (external_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE learning_path_trainings (id INT AUTO_INCREMENT NOT NULL, learning_path_id INT NOT NULL, training_id INT NOT NULL, position INT DEFAULT NULL, is_required TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_B831CE18D59B0C57 (learning_path_id), INDEX IDX_B831CE185FB14BA7 (training_id), UNIQUE INDEX learning_path_training_unique (learning_path_id, training_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE learning_path_registrations (id INT AUTO_INCREMENT NOT NULL, external_id INT NOT NULL, learning_path_id INT NOT NULL, learner_id INT NOT NULL, reference VARCHAR(120) DEFAULT NULL, score DOUBLE PRECISION DEFAULT NULL, progress DOUBLE PRECISION DEFAULT NULL, subscribed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', rise_up_created_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', rise_up_updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', synced_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_BD545532D59B0C57 (learning_path_id), INDEX IDX_BD545532496E27F6 (learner_id), UNIQUE INDEX UNIQ_BD5455321E4D0459 (external_id), UNIQUE INDEX learning_path_registration_unique (learning_path_id, learner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE learning_path_trainings ADD CONSTRAINT FK_B831CE18D59B0C57 FOREIGN KEY (learning_path_id) REFERENCES learning_paths (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_path_trainings ADD CONSTRAINT FK_B831CE185FB14BA7 FOREIGN KEY (training_id) REFERENCES trainings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_path_registrations ADD CONSTRAINT FK_BD545532D59B0C57 FOREIGN KEY (learning_path_id) REFERENCES learning_paths (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_path_registrations ADD CONSTRAINT FK_BD545532496E27F6 FOREIGN KEY (learner_id) REFERENCES learners (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE learning_path_trainings DROP FOREIGN KEY FK_B831CE18D59B0C57');
        $this->addSql('ALTER TABLE learning_path_trainings DROP FOREIGN KEY FK_B831CE185FB14BA7');
        $this->addSql('ALTER TABLE learning_path_registrations DROP FOREIGN KEY FK_BD545532D59B0C57');
        $this->addSql('ALTER TABLE learning_path_registrations DROP FOREIGN KEY FK_BD545532496E27F6');
        $this->addSql('DROP TABLE learning_path_trainings');
        $this->addSql('DROP TABLE learning_path_registrations');
        $this->addSql('DROP TABLE learning_paths');
    }
}
