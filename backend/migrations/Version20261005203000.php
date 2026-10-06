<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Start disciplinary absence tracking on 6 October 2026 to avoid alerts from imported history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE learners SET absence_counter_reset_at = '2026-10-06 00:00:00', "
            . 'consecutive_unjustified_masterclass_absences = 0, disciplinary_alert_sent_at = NULL'
        );

        // Neutralise sans email les demandes historiques encore en attente. Elles restent visibles
        // dans l'historique, mais ne seront ni expirées ni incluses dans une série disciplinaire.
        $this->addSql(
            "UPDATE absences a INNER JOIN classroom_session_registrations r ON r.id = a.registration_id "
            . "INNER JOIN classroom_sessions s ON s.id = r.session_id "
            . "SET a.status = 'autre', a.justification_token = NULL, a.justification_token_expires_at = NULL "
            . "WHERE a.status = 'en_attente' AND a.justification_submitted_at IS NULL "
            . "AND s.start_at < '2026-10-06 00:00:00'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'The previous disciplinary counters and historical pending-absence states cannot be reconstructed safely.'
        );
    }
}
