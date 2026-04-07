<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final class TimeMetricsService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Récupère le temps e-learning par apprenant pour un parcours donné
     * Basé sur riseup_activity_logs (temps réellement effectué)
     * 
     * @return array<int, array{learner_id: int, module_time: int}> Temps en secondes par learner_id
     */
    public function getElearningTimeByLearner(int $learningPathId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lpt2.learning_path_id,
                    l2.id AS learner_id,
                    COALESCE(SUM(ral.duration_seconds), 0) AS module_time
                FROM riseup_activity_logs ral
                INNER JOIN trainings t2 ON t2.external_id = ral.training_external_id
                INNER JOIN learning_path_trainings lpt2 ON lpt2.training_id = t2.id
                INNER JOIN learners l2 ON l2.external_id = ral.learner_external_id
                WHERE lpt2.learning_path_id = :learningPathId
                GROUP BY lpt2.learning_path_id, l2.id
            SQL,
            ['learningPathId' => $learningPathId]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['learner_id']] = [
                'learner_id' => (int) $row['learner_id'],
                'module_time' => (int) $row['module_time'],
            ];
        }

        return $result;
    }

    /**
     * Récupère le temps sessions (masterclass) par apprenant pour un parcours donné
     * Basé sur classroom_sessions avec signatures
     * 
     * @return array<int, array{learner_id: int, masterclass_time: int, expected_time: int}> Temps en minutes par learner_id
     */
    public function getSessionTimeByLearner(int $learningPathId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lpt2.learning_path_id,
                    csr.learner_id,
                    COALESCE(SUM(CASE WHEN css.has_signed = 1 THEN cs2.edu_duration ELSE 0 END), 0) AS masterclass_time,
                    COALESCE(SUM(cs2.edu_duration), 0) AS expected_time
                FROM classroom_session_registrations csr
                INNER JOIN classroom_sessions cs2 ON cs2.id = csr.session_id
                INNER JOIN learning_path_trainings lpt2 ON lpt2.training_id = cs2.training_id
                LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id
                WHERE lpt2.learning_path_id = :learningPathId
                GROUP BY lpt2.learning_path_id, csr.learner_id
            SQL,
            ['learningPathId' => $learningPathId]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['learner_id']] = [
                'learner_id' => (int) $row['learner_id'],
                'masterclass_time' => (int) $row['masterclass_time'], // en minutes
                'expected_time' => (int) $row['expected_time'], // en minutes
            ];
        }

        return $result;
    }

    /**
     * Récupère le temps e-learning attendu (prévu) par apprenant pour un parcours donné
     * Basé sur les modules e-learning des formations du parcours
     * 
     * @return array<int, array{learner_id: int, expected_elearning_time: int}> Temps en minutes par learner_id
     */
    public function getExpectedElearningTimeByLearner(int $learningPathId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lpr.learner_id,
                    COALESCE(SUM(tm.duration), 0) AS expected_elearning_time
                FROM learning_path_registrations lpr
                INNER JOIN learning_path_trainings lpt ON lpt.learning_path_id = lpr.learning_path_id
                INNER JOIN training_modules tm ON tm.training_id = lpt.training_id
                WHERE lpr.learning_path_id = :learningPathId
                  AND tm.type IN ('scorm', 'rise')
                GROUP BY lpr.learner_id
            SQL,
            ['learningPathId' => $learningPathId]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['learner_id']] = [
                'learner_id' => (int) $row['learner_id'],
                'expected_elearning_time' => (int) $row['expected_elearning_time'], // en minutes
            ];
        }

        return $result;
    }

    /**
     * Récupère les métriques de temps complètes par apprenant pour un parcours donné
     * Combine e-learning (secondes), sessions (minutes), et temps attendu
     * 
     * @return array<int, array{
     *     learner_id: int,
     *     module_time: int,
     *     masterclass_time: int,
     *     expected_time: int,
     *     expected_elearning_time: int,
     *     total_time_seconds: int,
     *     session_time_seconds: int
     * }> Métriques complètes par learner_id
     */
    public function getTimeMetricsByLearner(int $learningPathId): array
    {
        $elearningTime = $this->getElearningTimeByLearner($learningPathId);
        $sessionTime = $this->getSessionTimeByLearner($learningPathId);
        $expectedElearning = $this->getExpectedElearningTimeByLearner($learningPathId);

        // Récupérer tous les apprenants du parcours
        $learnerIds = $this->connection->fetchFirstColumn(
            'SELECT learner_id FROM learning_path_registrations WHERE learning_path_id = :learningPathId',
            ['learningPathId' => $learningPathId]
        );

        $result = [];
        foreach ($learnerIds as $learnerId) {
            $learnerId = (int) $learnerId;
            
            $moduleTimeSeconds = $elearningTime[$learnerId]['module_time'] ?? 0;
            $masterclassTimeMinutes = $sessionTime[$learnerId]['masterclass_time'] ?? 0;
            $expectedTimeMinutes = $sessionTime[$learnerId]['expected_time'] ?? 0;
            $expectedElearningTimeMinutes = $expectedElearning[$learnerId]['expected_elearning_time'] ?? 0;

            $result[$learnerId] = [
                'learner_id' => $learnerId,
                'module_time' => $moduleTimeSeconds, // secondes (e-learning effectué)
                'masterclass_time' => $masterclassTimeMinutes, // minutes (sessions effectuées)
                'expected_time' => $expectedTimeMinutes, // minutes (sessions prévues)
                'expected_elearning_time' => $expectedElearningTimeMinutes, // minutes (e-learning prévu)
                'total_time_seconds' => $moduleTimeSeconds + ($masterclassTimeMinutes * 60), // total en secondes
                'session_time_seconds' => $masterclassTimeMinutes * 60, // sessions en secondes
            ];
        }

        return $result;
    }

    /**
     * Récupère le temps total (e-learning + sessions) pour tous les apprenants d'un parcours
     * Agrégé au niveau parcours
     * 
     * @return int Temps total en secondes
     */
    public function getTotalTimeForLearningPath(int $learningPathId): int
    {
        $row = $this->connection->fetchAssociative(
            <<<SQL
                SELECT
                    COALESCE(SUM(COALESCE(module_logs.module_time, 0)), 0) + 
                    COALESCE(SUM(COALESCE(session_logs.masterclass_time, 0) * 60), 0) AS total_time
                FROM learning_path_registrations lpr
                LEFT JOIN (
                    SELECT
                        lpt2.learning_path_id,
                        l2.id AS learner_id,
                        COALESCE(SUM(ral.duration_seconds), 0) AS module_time
                    FROM riseup_activity_logs ral
                    INNER JOIN trainings t2 ON t2.external_id = ral.training_external_id
                    INNER JOIN learning_path_trainings lpt2 ON lpt2.training_id = t2.id
                    INNER JOIN learners l2 ON l2.external_id = ral.learner_external_id
                    GROUP BY lpt2.learning_path_id, l2.id
                ) module_logs ON module_logs.learning_path_id = lpr.learning_path_id AND module_logs.learner_id = lpr.learner_id
                LEFT JOIN (
                    SELECT
                        lpt2.learning_path_id,
                        csr.learner_id,
                        COALESCE(SUM(CASE WHEN css.has_signed = 1 THEN cs2.edu_duration ELSE 0 END), 0) AS masterclass_time
                    FROM classroom_session_registrations csr
                    INNER JOIN classroom_sessions cs2 ON cs2.id = csr.session_id
                    INNER JOIN learning_path_trainings lpt2 ON lpt2.training_id = cs2.training_id
                    LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id
                    GROUP BY lpt2.learning_path_id, csr.learner_id
                ) session_logs ON session_logs.learning_path_id = lpr.learning_path_id AND session_logs.learner_id = lpr.learner_id
                WHERE lpr.learning_path_id = :learningPathId
            SQL,
            ['learningPathId' => $learningPathId]
        );

        return (int) ($row['total_time'] ?? 0);
    }

    /**
     * Récupère les temps totaux pour plusieurs parcours
     * Optimisé pour le dashboard
     * 
     * @return array<int, int> Temps total en secondes par learning_path_id
     */
    public function getTotalTimeForMultipleLearningPaths(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lpr2.learning_path_id,
                    SUM(COALESCE(module_logs.module_time, 0)) + SUM(COALESCE(session_logs.masterclass_time, 0) * 60) AS total_time
                FROM learning_path_registrations lpr2
                LEFT JOIN (
                    SELECT
                        lpt2.learning_path_id,
                        l2.id AS learner_id,
                        COALESCE(SUM(ral.duration_seconds), 0) AS module_time
                    FROM riseup_activity_logs ral
                    INNER JOIN trainings t2 ON t2.external_id = ral.training_external_id
                    INNER JOIN learning_path_trainings lpt2 ON lpt2.training_id = t2.id
                    INNER JOIN learners l2 ON l2.external_id = ral.learner_external_id
                    GROUP BY lpt2.learning_path_id, l2.id
                ) module_logs ON module_logs.learning_path_id = lpr2.learning_path_id AND module_logs.learner_id = lpr2.learner_id
                LEFT JOIN (
                    SELECT
                        lpt2.learning_path_id,
                        csr.learner_id,
                        COALESCE(SUM(CASE WHEN css.has_signed = 1 THEN cs2.edu_duration ELSE 0 END), 0) AS masterclass_time
                    FROM classroom_session_registrations csr
                    INNER JOIN classroom_sessions cs2 ON cs2.id = csr.session_id
                    INNER JOIN learning_path_trainings lpt2 ON lpt2.training_id = cs2.training_id
                    LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id
                    GROUP BY lpt2.learning_path_id, csr.learner_id
                ) session_logs ON session_logs.learning_path_id = lpr2.learning_path_id AND session_logs.learner_id = lpr2.learner_id
                GROUP BY lpr2.learning_path_id
            SQL
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['learning_path_id']] = (int) $row['total_time'];
        }

        return $result;
    }

    /**
     * Récupère les métriques de temps par formation pour un parcours donné
     * Combine e-learning (riseup_activity_logs) et sessions (classroom_sessions)
     * 
     * @return array<int, array{
     *     training_id: int,
     *     module_time: int,
     *     masterclass_time: int,
     *     total_time_seconds: int
     * }> Métriques par training_id
     */
    public function getTimeMetricsByTraining(int $learningPathId): array
    {
        // Temps e-learning par formation
        $elearningRows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    t2.id AS training_id,
                    COALESCE(SUM(ral.duration_seconds), 0) AS module_time
                FROM riseup_activity_logs ral
                INNER JOIN trainings t2 ON t2.external_id = ral.training_external_id
                INNER JOIN learning_path_trainings lpt2 ON lpt2.training_id = t2.id
                WHERE lpt2.learning_path_id = :learningPathId
                GROUP BY t2.id
            SQL,
            ['learningPathId' => $learningPathId]
        );

        // Temps sessions par formation
        $sessionRows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    cs.training_id,
                    COALESCE(SUM(CASE WHEN css.has_signed = 1 THEN cs.edu_duration ELSE 0 END), 0) AS masterclass_time
                FROM classroom_session_registrations csr
                INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                INNER JOIN learning_path_trainings lpt2 ON lpt2.training_id = cs.training_id
                LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id
                WHERE lpt2.learning_path_id = :learningPathId
                GROUP BY cs.training_id
            SQL,
            ['learningPathId' => $learningPathId]
        );

        $elearningByTraining = [];
        foreach ($elearningRows as $row) {
            $elearningByTraining[(int) $row['training_id']] = (int) $row['module_time'];
        }

        $sessionByTraining = [];
        foreach ($sessionRows as $row) {
            $sessionByTraining[(int) $row['training_id']] = (int) $row['masterclass_time'];
        }

        // Récupérer toutes les formations du parcours
        $trainingIds = $this->connection->fetchFirstColumn(
            'SELECT training_id FROM learning_path_trainings WHERE learning_path_id = :learningPathId',
            ['learningPathId' => $learningPathId]
        );

        $result = [];
        foreach ($trainingIds as $trainingId) {
            $trainingId = (int) $trainingId;
            $moduleTimeSeconds = $elearningByTraining[$trainingId] ?? 0;
            $masterclassTimeMinutes = $sessionByTraining[$trainingId] ?? 0;

            $result[$trainingId] = [
                'training_id' => $trainingId,
                'module_time' => $moduleTimeSeconds, // secondes
                'masterclass_time' => $masterclassTimeMinutes, // minutes
                'total_time_seconds' => $moduleTimeSeconds + ($masterclassTimeMinutes * 60), // total en secondes
            ];
        }

        return $result;
    }

    /**
     * Récupère les temps totaux pour tous les groupes Rise Up
     * Agrège les temps de tous les parcours associés à chaque groupe
     * 
     * @return array<int, int> Temps total en secondes par group_id
     */
    public function getTotalTimeForGroups(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    rg.id AS group_id,
                    SUM(COALESCE(module_logs.module_time, 0)) + SUM(COALESCE(session_logs.masterclass_time, 0) * 60) AS total_time
                FROM riseup_groups rg
                INNER JOIN riseup_learner_groups rlg ON rlg.group_id = rg.id
                INNER JOIN learning_path_registrations lpr ON lpr.learner_id = rlg.learner_id
                INNER JOIN learning_paths lp ON lp.id = lpr.learning_path_id
                INNER JOIN riseup_group_learning_paths rglp ON rglp.group_id = rg.id AND rglp.learning_path_external_id = lp.external_id
                LEFT JOIN (
                    SELECT
                        lpt2.learning_path_id,
                        l2.id AS learner_id,
                        COALESCE(SUM(ral.duration_seconds), 0) AS module_time
                    FROM riseup_activity_logs ral
                    INNER JOIN trainings t2 ON t2.external_id = ral.training_external_id
                    INNER JOIN learning_path_trainings lpt2 ON lpt2.training_id = t2.id
                    INNER JOIN learners l2 ON l2.external_id = ral.learner_external_id
                    GROUP BY lpt2.learning_path_id, l2.id
                ) module_logs ON module_logs.learning_path_id = lpr.learning_path_id AND module_logs.learner_id = lpr.learner_id
                LEFT JOIN (
                    SELECT
                        lpt2.learning_path_id,
                        csr.learner_id,
                        COALESCE(SUM(CASE WHEN css.has_signed = 1 THEN cs2.edu_duration ELSE 0 END), 0) AS masterclass_time
                    FROM classroom_session_registrations csr
                    INNER JOIN classroom_sessions cs2 ON cs2.id = csr.session_id
                    INNER JOIN learning_path_trainings lpt2 ON lpt2.training_id = cs2.training_id
                    LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id
                    GROUP BY lpt2.learning_path_id, csr.learner_id
                ) session_logs ON session_logs.learning_path_id = lpr.learning_path_id AND session_logs.learner_id = lpr.learner_id
                GROUP BY rg.id
            SQL
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['group_id']] = (int) ($row['total_time'] ?? 0);
        }

        return $result;
    }

    /**
     * Récupère les métriques de temps par membre d'un groupe
     * Agrège les temps de TOUS les parcours associés au groupe pour chaque membre
     * 
     * @return array<int, array{
     *     learner_id: int,
     *     module_time: int,
     *     session_time_seconds: int,
     *     expected_time: int,
     *     expected_elearning_time: int,
     *     total_time_seconds: int
     * }> Métriques agrégées par learner_id
     */
    public function getTimeMetricsByGroupMember(int $groupId): array
    {
        // E-learning time par membre (agrégé de tous les parcours du groupe)
        // Déduplique les logs qui apparaissent dans plusieurs parcours via DISTINCT sur ral.id
        $elearningRows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    sub.learner_id,
                    COALESCE(SUM(sub.duration_seconds), 0) AS module_time
                FROM (
                    SELECT DISTINCT
                        rlg.learner_id,
                        ral.id AS log_id,
                        ral.duration_seconds
                    FROM riseup_learner_groups rlg
                    LEFT JOIN learners l ON l.id = rlg.learner_id
                    LEFT JOIN riseup_activity_logs ral ON ral.learner_external_id = l.external_id
                    LEFT JOIN trainings t ON t.external_id = ral.training_external_id
                    LEFT JOIN learning_path_trainings lpt ON lpt.training_id = t.id
                    LEFT JOIN learning_paths lp ON lp.id = lpt.learning_path_id
                    LEFT JOIN riseup_group_learning_paths rglp ON rglp.learning_path_external_id = lp.external_id AND rglp.group_id = rlg.group_id
                    WHERE rlg.group_id = :groupId
                ) sub
                GROUP BY sub.learner_id
            SQL,
            ['groupId' => $groupId]
        );

        // Session time par membre (agrégé de tous les parcours du groupe)
        // Chaque signature (matin/après-midi) compte la durée complète de la session
        // Déduplique les signatures qui apparaissent dans plusieurs parcours via DISTINCT sur css.id
        $sessionRows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    sub.learner_id,
                    COALESCE(SUM(sub.signed_duration), 0) AS masterclass_time,
                    COALESCE(SUM(sub.total_duration), 0) AS expected_time
                FROM (
                    SELECT DISTINCT
                        rlg.learner_id,
                        css.id AS signature_id,
                        CASE WHEN css.has_signed = 1 THEN cs.edu_duration ELSE 0 END AS signed_duration,
                        cs.edu_duration AS total_duration
                    FROM riseup_learner_groups rlg
                    LEFT JOIN classroom_session_registrations csr ON csr.learner_id = rlg.learner_id
                    LEFT JOIN classroom_sessions cs ON cs.id = csr.session_id
                    LEFT JOIN learning_path_trainings lpt ON lpt.training_id = cs.training_id
                    LEFT JOIN learning_paths lp ON lp.id = lpt.learning_path_id
                    LEFT JOIN riseup_group_learning_paths rglp ON rglp.learning_path_external_id = lp.external_id AND rglp.group_id = rlg.group_id
                    LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id
                    WHERE rlg.group_id = :groupId
                ) sub
                GROUP BY sub.learner_id
            SQL,
            ['groupId' => $groupId]
        );

        // Expected e-learning time par membre
        // Déduplique les modules qui apparaissent dans plusieurs parcours via DISTINCT sur tm.id
        $expectedElearningRows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    sub.learner_id,
                    COALESCE(SUM(sub.duration), 0) AS expected_elearning_time
                FROM (
                    SELECT DISTINCT
                        rlg.learner_id,
                        tm.id AS module_id,
                        tm.duration
                    FROM riseup_learner_groups rlg
                    LEFT JOIN learning_path_registrations lpr ON lpr.learner_id = rlg.learner_id
                    LEFT JOIN learning_paths lp ON lp.id = lpr.learning_path_id
                    LEFT JOIN riseup_group_learning_paths rglp ON rglp.learning_path_external_id = lp.external_id AND rglp.group_id = rlg.group_id
                    LEFT JOIN learning_path_trainings lpt ON lpt.learning_path_id = lp.id
                    LEFT JOIN training_modules tm ON tm.training_id = lpt.training_id
                    WHERE rlg.group_id = :groupId
                      AND tm.type IN ('scorm', 'rise')
                ) sub
                GROUP BY sub.learner_id
            SQL,
            ['groupId' => $groupId]
        );

        $elearningByMember = [];
        foreach ($elearningRows as $row) {
            $elearningByMember[(int) $row['learner_id']] = (int) $row['module_time'];
        }

        $sessionByMember = [];
        foreach ($sessionRows as $row) {
            $sessionByMember[(int) $row['learner_id']] = [
                'masterclass_time' => (int) $row['masterclass_time'],
                'expected_time' => (int) $row['expected_time'],
            ];
        }

        $expectedElearningByMember = [];
        foreach ($expectedElearningRows as $row) {
            $expectedElearningByMember[(int) $row['learner_id']] = (int) $row['expected_elearning_time'];
        }

        // Récupérer tous les membres du groupe
        $memberIds = $this->connection->fetchFirstColumn(
            'SELECT learner_id FROM riseup_learner_groups WHERE group_id = :groupId',
            ['groupId' => $groupId]
        );

        $result = [];
        foreach ($memberIds as $memberId) {
            $memberId = (int) $memberId;
            $moduleTimeSeconds = $elearningByMember[$memberId] ?? 0;
            $masterclassTimeMinutes = $sessionByMember[$memberId]['masterclass_time'] ?? 0;
            $expectedTimeMinutes = $sessionByMember[$memberId]['expected_time'] ?? 0;
            $expectedElearningTimeMinutes = $expectedElearningByMember[$memberId] ?? 0;

            $result[$memberId] = [
                'learner_id' => $memberId,
                'module_time' => $moduleTimeSeconds, // secondes
                'session_time_seconds' => $masterclassTimeMinutes * 60, // sessions en secondes
                'expected_time' => $expectedTimeMinutes, // minutes
                'expected_elearning_time' => $expectedElearningTimeMinutes, // minutes
                'total_time_seconds' => $moduleTimeSeconds + ($masterclassTimeMinutes * 60), // total en secondes
            ];
        }

        return $result;
    }
}
