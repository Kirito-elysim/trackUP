<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create RBAC tables for users, roles and features';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE features (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(120) NOT NULL, name VARCHAR(120) NOT NULL, category VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_7C2E7242E7927C74 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE roles (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(120) NOT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, is_system TINYINT(1) NOT NULL, UNIQUE INDEX UNIQ_B63E2EC7E7927C74 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(120) NOT NULL, last_name VARCHAR(120) NOT NULL, active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE role_feature (role_id INT NOT NULL, feature_id INT NOT NULL, INDEX IDX_7EF66F2FD60322AC (role_id), INDEX IDX_7EF66F2F60E4B879 (feature_id), PRIMARY KEY(role_id, feature_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user_role (user_id INT NOT NULL, role_id INT NOT NULL, INDEX IDX_2DE8C6A3A76ED395 (user_id), INDEX IDX_2DE8C6A3D60322AC (role_id), PRIMARY KEY(user_id, role_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE role_feature ADD CONSTRAINT FK_7EF66F2FD60322AC FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_feature ADD CONSTRAINT FK_7EF66F2F60E4B879 FOREIGN KEY (feature_id) REFERENCES features (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_role ADD CONSTRAINT FK_2DE8C6A3A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_role ADD CONSTRAINT FK_2DE8C6A3D60322AC FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE role_feature DROP FOREIGN KEY FK_7EF66F2FD60322AC');
        $this->addSql('ALTER TABLE role_feature DROP FOREIGN KEY FK_7EF66F2F60E4B879');
        $this->addSql('ALTER TABLE user_role DROP FOREIGN KEY FK_2DE8C6A3A76ED395');
        $this->addSql('ALTER TABLE user_role DROP FOREIGN KEY FK_2DE8C6A3D60322AC');
        $this->addSql('DROP TABLE role_feature');
        $this->addSql('DROP TABLE user_role');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE roles');
        $this->addSql('DROP TABLE features');
    }
}
