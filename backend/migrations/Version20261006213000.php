<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Close sync runs left "running" by the learning path registration duplicate crash';
    }

    public function up(Schema $schema): void
    {
        // A deploy restarts the worker, so a run still marked running for over an hour is orphaned.
        $this->addSql("UPDATE sync_runs SET status = 'failed', current_step_index = NULL, current_step_label = NULL, finished_at = NOW() WHERE status = 'running' AND started_at < NOW() - INTERVAL 1 HOUR");
    }

    public function down(Schema $schema): void
    {
    }
}
