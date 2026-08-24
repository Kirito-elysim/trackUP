<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824142730 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create absences table for the attendance/justification tracking module';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE absences (id INT AUTO_INCREMENT NOT NULL, registration_id INT NOT NULL, validated_by_id INT DEFAULT NULL, type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, detected_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', justification_token VARCHAR(64) DEFAULT NULL, justification_token_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', justification_file_path VARCHAR(255) DEFAULT NULL, justification_file_original_name VARCHAR(255) DEFAULT NULL, justification_submitted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', validated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', admin_note LONGTEXT DEFAULT NULL, notification_sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', confirmation_sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_ABSENCES_REGISTRATION (registration_id), UNIQUE INDEX UNIQ_ABSENCES_TOKEN (justification_token), INDEX IDX_ABSENCES_VALIDATED_BY (validated_by_id), INDEX IDX_ABSENCES_STATUS (status), INDEX IDX_ABSENCES_TYPE (type), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE absences ADD CONSTRAINT FK_ABSENCES_REGISTRATION FOREIGN KEY (registration_id) REFERENCES classroom_session_registrations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE absences ADD CONSTRAINT FK_ABSENCES_VALIDATED_BY FOREIGN KEY (validated_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE absences DROP FOREIGN KEY FK_ABSENCES_REGISTRATION');
        $this->addSql('ALTER TABLE absences DROP FOREIGN KEY FK_ABSENCES_VALIDATED_BY');
        $this->addSql('DROP TABLE absences');
    }
}
