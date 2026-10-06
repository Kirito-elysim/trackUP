<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the global disciplinary streak tracking date so learners without their own reset date (new syncs) use it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_settings (name VARCHAR(100) NOT NULL, value LONGTEXT DEFAULT NULL, PRIMARY KEY(name)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        // Same start date as Version20261005203000, which only reached the learners present at that time.
        $this->addSql("INSERT INTO app_settings (name, value) VALUES ('absences.streak_tracking_date', '2026-10-06 00:00:00')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_settings');
    }
}
