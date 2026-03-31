<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260403110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create local storage for exact Rise Up activity journal logs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE riseup_activity_logs (id INT AUTO_INCREMENT NOT NULL, source_file_name VARCHAR(255) NOT NULL, source_imported_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', training_external_id INT NOT NULL, learner_external_id INT DEFAULT NULL, learner_email VARCHAR(180) DEFAULT NULL, login_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', logout_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', duration_seconds INT NOT NULL, device VARCHAR(120) DEFAULT NULL, row_fingerprint VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_riseup_activity_log_training_external (training_external_id), INDEX idx_riseup_activity_log_learner_external (learner_external_id), INDEX idx_riseup_activity_log_learner_email (learner_email), INDEX idx_riseup_activity_log_login_at (login_at), INDEX idx_riseup_activity_log_lookup (learner_external_id, training_external_id, login_at), UNIQUE INDEX uniq_riseup_activity_log_fingerprint (row_fingerprint), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE riseup_activity_logs');
    }
}
