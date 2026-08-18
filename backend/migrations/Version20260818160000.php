<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password reset token fields to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD reset_token VARCHAR(64) DEFAULT NULL, ADD reset_token_expires_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USERS_RESET_TOKEN ON users (reset_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_USERS_RESET_TOKEN ON users');
        $this->addSql('ALTER TABLE users DROP reset_token, DROP reset_token_expires_at');
    }
}
