<?php
declare(strict_types=1);
namespace App\Controller\Api;

use App\Entity\Learner;
use App\Entity\User;
use App\Repository\RiseUpActivityLogFilters;
use App\Service\ActivityLogPdfService;
use App\Service\LearnerCommunicationLogger;
use App\Service\UserPermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/learner-log-deliveries')]
class LearnerLogDeliveryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPermissionResolver $permissions,
        private readonly ActivityLogPdfService $pdf,
        private readonly MailerInterface $mailer,
        private readonly LearnerCommunicationLogger $communications,
        private readonly string $fromAddress,
        #[Autowire('%kernel.project_dir%/var/log-deliveries')] private readonly string $directory,
    ) {}

    private function actor(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->permissions->userHasFeature($user, 'learners.manage') || !$this->permissions->userHasFeature($user, 'exports.view')) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }

    #[Route('/preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $actor = $this->actor();
        $data = $request->toArray();
        $ids = array_values(array_unique(array_map('intval', (array) ($data['learnerIds'] ?? []))));
        $pathId = (int) ($data['learningPathId'] ?? 0);
        $groupId = (int) ($data['groupId'] ?? 0);
        if (!$ids || count($ids) > 50 || min($ids) < 1 || ($pathId < 1 && $groupId < 1)) {
            return $this->json(['message' => 'Le contexte du parcours ou du groupe et 1 à 50 apprenants sont requis.'], 422);
        }
        $db = $this->em->getConnection();
        $title = $groupId > 0
            ? $db->fetchOne('SELECT name FROM riseup_groups WHERE id = ?', [$groupId])
            : $db->fetchOne('SELECT title FROM learning_paths WHERE id = ?', [$pathId]);
        if ($title === false) {
            return $this->json(['message' => 'Parcours introuvable.'], 422);
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create delivery storage.');
        }
        // Drafts contain personal data; expire them after one hour.
        foreach (glob($this->directory . '/*.json') ?: [] as $old) {
            if (filemtime($old) < time() - 3600) { @unlink($old); }
        }
        $items = [];
        foreach ($ids as $id) {
            $learner = $this->em->find(Learner::class, $id);
            if (!$learner instanceof Learner) {
                return $this->json(['message' => 'Apprenant introuvable.'], 422);
            }
            $name = trim($learner->getFirstName() . ' ' . $learner->getLastName());
            $tutor = $learner->getTutor();
            $email = $tutor?->getEmail();
            [$pathIds, $pathTitle] = $this->resolvePaths($id, $groupId, $pathId, (string) $title);
            $problem = (!$tutor || $tutor->isDeleted()) ? 'Aucun tuteur associé' : (!filter_var($email, FILTER_VALIDATE_EMAIL) ? 'Email du tuteur absent ou invalide' : null);
            // Where to fix it: tutor assignment lives on the learner page, the email on the tutor page.
            $fix = $problem === null ? null : (($tutor && !$tutor->isDeleted()) ? 'tutor' : 'learner');
            $subject = "Logs et signatures de {$name} - {$pathTitle}";
            $text = "Bonjour " . ($tutor?->getFirstName() ?? '') . ",\n\nVeuillez trouver en pièce jointe le relevé des signatures des classes virtuelles et des logs d’activité de {$name} ({$pathTitle}).\n\nCordialement,\nL’équipe pédagogique\nEd’Up Business School";
            $items[] = ['learningPathIds' => $pathIds, 'pathTitle' => $pathTitle, 'learnerId' => $id, 'name' => $name, 'tutorId' => $tutor?->getId(), 'tutor' => $tutor?->getFullName(), 'email' => $email, 'problem' => $problem, 'fix' => $fix, 'subject' => $subject, 'text' => $text, 'filename' => "logs-{$id}.pdf", 'status' => 'pending'];
        }
        $token = bin2hex(random_bytes(24));
        file_put_contents($this->directory . '/' . $token . '.json', json_encode(['actor' => $actor->getId(), 'expires' => time() + 3600, 'learningPathId' => $pathId, 'title' => $title, 'items' => $items], JSON_THROW_ON_ERROR), LOCK_EX);
        return $this->json(['token' => $token, 'items' => $items]);
    }

    /**
     * Group links to paths and path registrations are often incomplete in the RiseUp sync,
     * so fall back to the learner's own registrations, then to the learner's whole history.
     *
     * @return array{0: list<int>, 1: string}
     */
    private function resolvePaths(int $learnerId, int $groupId, int $pathId, string $contextTitle): array
    {
        $db = $this->em->getConnection();
        if ($groupId < 1) {
            return [[$pathId], $contextTitle];
        }
        $paths = $db->fetchAllAssociative('SELECT DISTINCT lp.id, lp.title FROM learning_paths lp INNER JOIN learning_path_registrations lpr ON lpr.learning_path_id = lp.id INNER JOIN riseup_group_learning_paths gp ON gp.learning_path_external_id = lp.external_id WHERE gp.group_id = :groupId AND lpr.learner_id = :learnerId ORDER BY lp.title', ['groupId' => $groupId, 'learnerId' => $learnerId]);
        if (!$paths) {
            $paths = $db->fetchAllAssociative('SELECT DISTINCT lp.id, lp.title FROM learning_paths lp INNER JOIN learning_path_registrations lpr ON lpr.learning_path_id = lp.id WHERE lpr.learner_id = ? ORDER BY lp.title', [$learnerId]);
        }
        if (!$paths) {
            return [[], $contextTitle];
        }
        return [array_values(array_unique(array_map(static fn (array $path): int => (int) $path['id'], $paths))), implode(' · ', array_column($paths, 'title'))];
    }

    // The lock serializes PDF generation and delivery, including repeated confirmations.
    private function draft(string $token, callable $callback): Response
    {
        $actor = $this->actor();
        if (!preg_match('/^[a-f0-9]{48}$/D', $token)) { throw $this->createNotFoundException(); }
        $file = $this->directory . '/' . $token . '.json';
        $handle = @fopen($file, 'r+');
        if (!$handle) { throw $this->createNotFoundException('Aperçu expiré.'); }
        flock($handle, LOCK_EX);
        try {
            $draft = json_decode(stream_get_contents($handle), true, 512, JSON_THROW_ON_ERROR);
            if ($draft['actor'] !== $actor->getId() || $draft['expires'] < time()) { throw $this->createAccessDeniedException('Aperçu expiré.'); }
            $save = static function () use ($handle, &$draft): void {
                rewind($handle); ftruncate($handle, 0);
                fwrite($handle, json_encode($draft, JSON_THROW_ON_ERROR)); fflush($handle);
            };
            return $callback($draft, $save, $actor);
        } finally {
            flock($handle, LOCK_UN); fclose($handle);
        }
    }

    #[Route('/{token}/pdf/{learnerId}', methods: ['GET'])]
    public function document(string $token, int $learnerId): Response
    {
        return $this->draft($token, function (array &$draft, callable $save) use ($learnerId): Response {
            foreach ($draft['items'] as &$item) {
                if ($item['learnerId'] !== $learnerId || $item['problem']) { continue; }
                if (!isset($item['pdf'])) {
                    $scope = $item['learningPathIds'] ? ' AND EXISTS (SELECT 1 FROM learning_path_trainings lpt WHERE lpt.training_id = cs.training_id AND lpt.learning_path_id IN (:paths))' : '';
                    $rows = $this->em->getConnection()->fetchAllAssociative('SELECT COALESCE(cs.reference, t.title) AS title, cs.start_at, cs.end_at, css.attendance_date, css.period, css.has_signed, css.signature_date FROM classroom_session_registrations csr INNER JOIN classroom_sessions cs ON cs.id = csr.session_id LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id LEFT JOIN trainings t ON t.id = cs.training_id WHERE csr.learner_id = :learner' . $scope . ' ORDER BY cs.start_at, css.attendance_date, css.period', ['learner' => $learnerId, 'paths' => $item['learningPathIds']], ['paths' => \Doctrine\DBAL\ArrayParameterType::INTEGER]);
                    $item['pdf'] = base64_encode($this->pdf->render(new RiseUpActivityLogFilters(learnerId: $learnerId, learningPathIds: $item['learningPathIds']), $item['name'] . ' — ' . $item['pathTitle'], $rows));
                    $save();
                }
                return new Response(base64_decode($item['pdf']), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $item['filename'] . '"', 'Cache-Control' => 'no-store']);
            }
            throw $this->createNotFoundException();
        });
    }

    #[Route('/{token}/send', methods: ['POST'])]
    public function send(string $token): Response
    {
        return $this->draft($token, function (array &$draft, callable $save, User $actor): Response {
            foreach ($draft['items'] as $item) {
                if (!$item['problem'] && !isset($item['pdf'])) {
                    return $this->json(['message' => 'Prévisualisez chaque PDF avant de confirmer.'], 422);
                }
            }
            foreach ($draft['items'] as &$item) {
                if ($item['problem'] || $item['status'] !== 'pending') { continue; }
                $learner = $this->em->find(Learner::class, $item['learnerId']);
                $tutor = $learner?->getTutor();
                if (!$tutor || $tutor->isDeleted() || $tutor->getEmail() !== $item['email']) {
                    $item['status'] = 'failed'; $item['error'] = 'Le tuteur a changé. Recréez un aperçu.'; $save(); continue;
                }
                // Persist before contacting SMTP: an interrupted request must never resend silently.
                $item['status'] = 'sending'; $save();
                try {
                    $this->mailer->send((new Email())->from($this->fromAddress)->to($item['email'])->subject($item['subject'])->text($item['text'])->attach(base64_decode($item['pdf']), $item['filename'], 'application/pdf'));
                    $item['status'] = 'sent'; $save();
                } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
                    $item['status'] = 'failed'; $item['error'] = 'Le serveur mail n’a pas confirmé l’envoi.'; $save(); continue;
                }
                $this->communications->log($learner, 'tutor_activity_logs', $actor, ['to' => $item['email'], 'subject' => $item['subject'], 'text' => $item['text'], 'learningPathIds' => $item['learningPathIds'] ?? [$draft['learningPathId']], 'filename' => $item['filename']]);
                $this->em->flush();
            }
            unset($item);
            return $this->json(['items' => array_map(static function (array $item): array { unset($item['pdf']); return $item; }, $draft['items'])]);
        });
    }
}
