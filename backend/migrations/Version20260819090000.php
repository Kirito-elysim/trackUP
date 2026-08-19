<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add prospects table (learner contact info: phone/address/comment, linked by email)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE prospects ('
            . '  id INT AUTO_INCREMENT NOT NULL,'
            . '  email VARCHAR(180) NOT NULL,'
            . '  phone VARCHAR(40) DEFAULT NULL,'
            . '  address LONGTEXT DEFAULT NULL,'
            . '  comment LONGTEXT DEFAULT NULL,'
            . '  created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . '  updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\','
            . '  UNIQUE INDEX UNIQ_PROSPECTS_EMAIL (email),'
            . '  PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE prospects');
    }
}
