<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825121227 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dashboard_preferences JSON column to users for per-account Dashboard customization';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD dashboard_preferences JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP dashboard_preferences');
    }
}
