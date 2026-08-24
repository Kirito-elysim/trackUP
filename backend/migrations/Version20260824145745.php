<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824145745 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add consecutive unjustified masterclass absence tracking fields to learners (roadmap 3.4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE learners ADD consecutive_unjustified_masterclass_absences INT NOT NULL DEFAULT 0, '
            . 'ADD disciplinary_alert_sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', '
            . 'ADD absence_counter_reset_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\''
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE learners DROP consecutive_unjustified_masterclass_absences, '
            . 'DROP disciplinary_alert_sent_at, DROP absence_counter_reset_at'
        );
    }
}
