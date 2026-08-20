<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260820075403 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add current_step_index/current_step_label to sync_runs for real-time progress polling';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sync_runs ADD current_step_index INT DEFAULT NULL, ADD current_step_label VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sync_runs DROP current_step_index, DROP current_step_label');
    }
}
