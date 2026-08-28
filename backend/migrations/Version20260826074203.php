<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826074203 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create absence_events table (full history of notifications/justifications/status changes per absence) and backfill it from existing single-timestamp columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE absence_events (id INT AUTO_INCREMENT NOT NULL, absence_id INT NOT NULL, actor_id INT DEFAULT NULL, type VARCHAR(30) NOT NULL, occurred_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', metadata JSON NOT NULL, INDEX IDX_ABSENCE_EVENTS_ABSENCE (absence_id), INDEX IDX_ABSENCE_EVENTS_ACTOR (actor_id), INDEX IDX_ABSENCE_EVENTS_OCCURRED_AT (occurred_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE absence_events ADD CONSTRAINT FK_ABSENCE_EVENTS_ABSENCE FOREIGN KEY (absence_id) REFERENCES absences (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE absence_events ADD CONSTRAINT FK_ABSENCE_EVENTS_ACTOR FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL');

        // Backfill : reconstitue un historique minimal pour les absences déjà existantes, à partir des
        // colonnes *_at de `absences` (qui ne retenaient jusqu'ici que le dernier envoi/dépôt).
        $this->addSql(
            "INSERT INTO absence_events (absence_id, actor_id, type, occurred_at, metadata)
             SELECT id, NULL, 'notification_sent', notification_sent_at, JSON_OBJECT('delivered', true, 'backfilled', true)
             FROM absences WHERE notification_sent_at IS NOT NULL"
        );
        $this->addSql(
            "INSERT INTO absence_events (absence_id, actor_id, type, occurred_at, metadata)
             SELECT id, NULL, 'justification_submitted', justification_submitted_at,
                    JSON_OBJECT('fileOriginalName', justification_file_original_name, 'backfilled', true)
             FROM absences WHERE justification_submitted_at IS NOT NULL"
        );
        $this->addSql(
            "INSERT INTO absence_events (absence_id, actor_id, type, occurred_at, metadata)
             SELECT id, validated_by_id, 'status_changed', validated_at,
                    JSON_OBJECT('to', status, 'emailSent', confirmation_sent_at IS NOT NULL, 'backfilled', true)
             FROM absences WHERE validated_at IS NOT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE absence_events DROP FOREIGN KEY FK_ABSENCE_EVENTS_ABSENCE');
        $this->addSql('ALTER TABLE absence_events DROP FOREIGN KEY FK_ABSENCE_EVENTS_ACTOR');
        $this->addSql('DROP TABLE absence_events');
    }
}
