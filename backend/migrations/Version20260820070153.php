<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260820070153 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sync_runs table to log grouped Rise Up sync executions (scheduled or manual)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE sync_runs ('
            . '  id INT AUTO_INCREMENT NOT NULL,'
            . '  triggered_by_id INT DEFAULT NULL,'
            . '  trigger_type VARCHAR(20) NOT NULL,'
            . '  status VARCHAR(20) NOT NULL,'
            . '  started_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . '  finished_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . '  steps JSON NOT NULL,'
            . '  INDEX IDX_SYNC_RUNS_TRIGGERED_BY (triggered_by_id),'
            . '  INDEX IDX_SYNC_RUNS_STARTED_AT (started_at),'
            . '  PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
        $this->addSql(
            'ALTER TABLE sync_runs ADD CONSTRAINT FK_SYNC_RUNS_TRIGGERED_BY '
            . 'FOREIGN KEY (triggered_by_id) REFERENCES users (id) ON DELETE SET NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sync_runs');
    }
}
