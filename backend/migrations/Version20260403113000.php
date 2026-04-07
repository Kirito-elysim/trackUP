<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260403113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store Rise Up groups (classes) + group learning paths + learner group memberships.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE riseup_groups (id INT AUTO_INCREMENT NOT NULL, external_id INT NOT NULL, name VARCHAR(255) NOT NULL, reference VARCHAR(120) DEFAULT NULL, hidden TINYINT(1) NOT NULL DEFAULT 0, community TINYINT(1) NOT NULL DEFAULT 0, rise_up_created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', rise_up_updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_riseup_group_external_id (external_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE riseup_group_learning_paths (id INT AUTO_INCREMENT NOT NULL, `group_id` INT NOT NULL, learning_path_external_id INT NOT NULL, synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_riseup_group_learning_path_ext (learning_path_external_id), UNIQUE INDEX uniq_riseup_group_learning_path (`group_id`, learning_path_external_id), INDEX IDX_69A4524BFE54D947 (`group_id`), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE riseup_learner_groups (id INT AUTO_INCREMENT NOT NULL, learner_id INT NOT NULL, `group_id` INT NOT NULL, synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_riseup_learner_group_group (`group_id`), UNIQUE INDEX uniq_riseup_learner_group (learner_id, `group_id`), INDEX IDX_3A0C0B2896E27F6 (learner_id), INDEX IDX_3A0C0B2FE54D947 (`group_id`), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE riseup_group_learning_paths ADD CONSTRAINT FK_69A4524BFE54D947 FOREIGN KEY (`group_id`) REFERENCES riseup_groups (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE riseup_learner_groups ADD CONSTRAINT FK_3A0C0B2896E27F6 FOREIGN KEY (learner_id) REFERENCES learners (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE riseup_learner_groups ADD CONSTRAINT FK_3A0C0B2FE54D947 FOREIGN KEY (`group_id`) REFERENCES riseup_groups (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE riseup_group_learning_paths DROP FOREIGN KEY FK_69A4524BFE54D947');
        $this->addSql('ALTER TABLE riseup_learner_groups DROP FOREIGN KEY FK_3A0C0B2896E27F6');
        $this->addSql('ALTER TABLE riseup_learner_groups DROP FOREIGN KEY FK_3A0C0B2FE54D947');
        $this->addSql('DROP TABLE riseup_group_learning_paths');
        $this->addSql('DROP TABLE riseup_learner_groups');
        $this->addSql('DROP TABLE riseup_groups');
    }
}

