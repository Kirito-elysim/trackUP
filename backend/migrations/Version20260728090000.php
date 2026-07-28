<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop orphaned lti_users/time_tracking tables (dead LTI feature, no entity/controller ever referenced them)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS time_tracking');
        $this->addSql('DROP TABLE IF EXISTS lti_users');
    }

    public function down(Schema $schema): void
    {
        // Recreates the table structure only; dropped row data is not recoverable.
        $this->addSql(
            'CREATE TABLE lti_users ('
            . '  id INT AUTO_INCREMENT NOT NULL,'
            . '  learner_id INT DEFAULT NULL,'
            . '  lti_sub VARCHAR(255) NOT NULL,'
            . '  platform_id VARCHAR(255) NOT NULL,'
            . '  email VARCHAR(180) DEFAULT NULL,'
            . '  full_name VARCHAR(255) DEFAULT NULL,'
            . '  created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . '  updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . '  UNIQUE INDEX uniq_lti_sub_platform (lti_sub, platform_id),'
            . '  INDEX IDX_LTI_USERS_LEARNER (learner_id),'
            . '  PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB'
        );
        $this->addSql(
            'CREATE TABLE time_tracking ('
            . '  id INT AUTO_INCREMENT NOT NULL,'
            . '  lti_user_id INT NOT NULL,'
            . '  course_id VARCHAR(255) NOT NULL,'
            . '  step_id VARCHAR(255) NOT NULL,'
            . '  started_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . '  duration_seconds INT DEFAULT 0 NOT NULL,'
            . '  last_heartbeat_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . '  UNIQUE INDEX uniq_time_tracking_context (lti_user_id, course_id, step_id),'
            . '  INDEX IDX_TIME_TRACKING_LTI_USER (lti_user_id),'
            . '  PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB'
        );
        $this->addSql('ALTER TABLE lti_users ADD CONSTRAINT FK_LTI_USERS_LEARNER FOREIGN KEY (learner_id) REFERENCES learners (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE time_tracking ADD CONSTRAINT FK_TIME_TRACKING_LTI_USER FOREIGN KEY (lti_user_id) REFERENCES lti_users (id) ON DELETE CASCADE');
    }
}
