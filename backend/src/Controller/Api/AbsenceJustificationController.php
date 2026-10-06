<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Absence;
use App\Entity\AbsenceEvent;
use App\Service\AbsenceEventLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

// Endpoint public (PUBLIC_ACCESS, voir security.yaml), sans authentification : l'apprenant y accède
// via le lien à token reçu par email (roadmap 3.2, étape 3). Même logique de validation de token que
// AuthController::resetPassword (token + expiration, aucune énumération possible côté client).
#[Route('/api/absences')]
class AbsenceJustificationController extends AbstractController
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    private const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AbsenceEventLogger $absenceEventLogger,
        private readonly string $uploadDir,
    ) {
    }

    // Chargée par la page publique au montage : permet d'afficher, en revenant sur le lien, le
    // justificatif déjà déposé (nom, date) plutôt qu'un formulaire vide qui masquerait un dépôt
    // existant — l'apprenant peut ensuite choisir de le remplacer via le même formulaire d'upload.
    #[Route('/justification', name: 'api_absences_justification_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $absence = $this->findValidAbsenceForToken((string) $request->query->get('token', ''));

        if (!$absence instanceof Absence) {
            return $this->json(
                ['message' => 'Ce lien de dépôt de justificatif est invalide ou a expiré.'],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $session = $absence->getRegistration()->getSession();
        $expiresAt = $absence->getJustificationTokenExpiresAt();
        $daysRemaining = $expiresAt !== null
            ? max(0, (int) ceil(($expiresAt->getTimestamp() - time()) / 86400))
            : null;

        return $this->json([
            'sessionLabel' => $session->getTraining()?->getTitle() ?? $session->getModule()?->getTitle() ?? 'votre session',
            'sessionStartAt' => $session->getStartAt()?->format(DATE_ATOM),
            'alreadySubmitted' => $absence->getJustificationSubmittedAt() !== null,
            'fileOriginalName' => $absence->getJustificationFileOriginalName(),
            'submittedAt' => $absence->getJustificationSubmittedAt()?->format(DATE_ATOM),
            'daysRemaining' => $daysRemaining,
            'canSubmit' => $absence->getStatus() === Absence::STATUS_EN_ATTENTE,
        ]);
    }

    #[Route('/justification', name: 'api_absences_justification', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $absence = $this->findValidAbsenceForToken((string) $request->request->get('token', ''));

        if (!$absence instanceof Absence) {
            return $this->json(
                ['message' => 'Ce lien de dépôt de justificatif est invalide ou a expiré.'],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($absence->getStatus() !== Absence::STATUS_EN_ATTENTE) {
            return $this->json(['message' => 'Cette absence a déjà été traitée. Le justificatif ne peut plus être modifié.'], JsonResponse::HTTP_CONFLICT);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->json(['message' => 'Aucun fichier fourni.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!$file->isValid()) {
            $tooLarge = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            return $this->json(['message' => $tooLarge ? 'Le fichier dépasse la taille maximale autorisée (10 Mo).' : 'Le transfert du fichier a échoué. Merci de réessayer.'], $tooLarge ? JsonResponse::HTTP_REQUEST_ENTITY_TOO_LARGE : JsonResponse::HTTP_BAD_REQUEST);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return $this->json(
                ['message' => 'Le fichier doit être au format PDF, JPG ou PNG.'],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            return $this->json(
                ['message' => 'Le fichier dépasse la taille maximale autorisée (10 Mo).'],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        if ($file->getMimeType() !== self::MIME_TYPES[$extension]) {
            return $this->json(['message' => 'Le contenu du fichier ne correspond pas à un PDF, JPG ou PNG valide.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $storedFileName = null;
        $this->entityManager->beginTransaction();
        try {
            $this->entityManager->refresh($absence, LockMode::PESSIMISTIC_WRITE);
            if ($absence->getStatus() !== Absence::STATUS_EN_ATTENTE
                || $absence->getJustificationTokenExpiresAt() <= new \DateTimeImmutable()
                || !hash_equals($absence->getJustificationToken() ?? '', (string) $request->request->get('token', ''))) {
                $this->entityManager->rollback();
                return $this->json(['message' => 'Le dépôt est fermé ou le lien a expiré.'], JsonResponse::HTTP_CONFLICT);
            }

            if (!is_dir($this->uploadDir)) {
                mkdir($this->uploadDir, 0775, true);
            }

            // Remplacement d'un dépôt existant (l'apprenant revient sur le lien pour corriger) : on
            // retire l'ancien fichier du disque pour ne pas accumuler d'orphelins.
            $previousFilePath = $absence->getJustificationFilePath();
            $isReplacement = $previousFilePath !== null;

            $storedFileName = sprintf('%d-%s.%s', $absence->getId(), bin2hex(random_bytes(8)), $extension);
            $file->move($this->uploadDir, $storedFileName);
            $absence->setJustificationFile($storedFileName, $file->getClientOriginalName());
            $absence->setJustificationSubmittedAt(new \DateTimeImmutable());
            $this->absenceEventLogger->log($absence, AbsenceEvent::TYPE_JUSTIFICATION_SUBMITTED, null, [
                'fileOriginalName' => $file->getClientOriginalName(),
                'replacement' => $isReplacement,
            ]);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();
            if ($storedFileName !== null && is_file($this->uploadDir . '/' . $storedFileName)) {
                unlink($this->uploadDir . '/' . $storedFileName);
            }
            throw $exception;
        }

        if ($previousFilePath !== null) {
            $previousFullPath = $this->uploadDir . '/' . $previousFilePath;
            if (is_file($previousFullPath)) {
                @unlink($previousFullPath);
            }
        }

        return $this->json(['message' => 'Votre justificatif a bien été transmis.']);
    }

    // Permet à l'apprenant de revoir (ou télécharger) le fichier qu'il a lui-même déposé, depuis la
    // même page à token — même modèle de confiance que le dépôt : le token fait office
    // d'authentification, pas de session apprenant dans ce projet.
    #[Route('/justification/file', name: 'api_absences_justification_file', methods: ['GET'])]
    public function viewFile(Request $request): Response
    {
        $absence = $this->findValidAbsenceForToken((string) $request->query->get('token', ''));
        $filePath = $absence?->getJustificationFilePath();

        if ($absence === null || $filePath === null) {
            return $this->json(['message' => 'Aucun justificatif disponible.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $fullPath = $this->uploadDir . '/' . $filePath;
        if (!is_file($fullPath)) {
            return $this->json(['message' => 'Fichier introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->buildFileResponse($fullPath, $absence->getJustificationFileOriginalName() ?? $filePath);
    }

    private function findValidAbsenceForToken(string $token): ?Absence
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $absence = $this->entityManager->getRepository(Absence::class)->findOneBy(['justificationToken' => $token]);
        $expiresAt = $absence?->getJustificationTokenExpiresAt();

        if (!$absence instanceof Absence || !$expiresAt || $expiresAt < new \DateTimeImmutable()) {
            return null;
        }

        return $absence;
    }

    private function buildFileResponse(string $fullPath, string $downloadName): BinaryFileResponse
    {
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $response = new BinaryFileResponse($fullPath);
        $response->headers->set('Content-Type', self::MIME_TYPES[$extension] ?? 'application/octet-stream');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $downloadName);

        return $response;
    }
}
