<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Absence;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

// Endpoint public (PUBLIC_ACCESS, voir security.yaml), sans authentification : l'apprenant y accède
// via le lien à token reçu par email (roadmap 3.2, étape 3). Même logique de validation de token que
// AuthController::resetPassword (token + expiration, aucune énumération possible côté client).
#[Route('/api/absences')]
class AbsenceJustificationController extends AbstractController
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $uploadDir,
    ) {
    }

    #[Route('/justification', name: 'api_absences_justification', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $token = trim((string) $request->request->get('token', ''));

        if ($token === '') {
            return $this->json(['message' => 'Requête invalide.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $absence = $this->entityManager->getRepository(Absence::class)->findOneBy(['justificationToken' => $token]);
        $expiresAt = $absence?->getJustificationTokenExpiresAt();

        if (!$absence instanceof Absence || !$expiresAt || $expiresAt < new \DateTimeImmutable()) {
            return $this->json(
                ['message' => 'Ce lien de dépôt de justificatif est invalide ou a expiré.'],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if ($file === null) {
            return $this->json(['message' => 'Aucun fichier fourni.'], JsonResponse::HTTP_BAD_REQUEST);
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

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0775, true);
        }

        $storedFileName = sprintf('%d-%s.%s', $absence->getId(), bin2hex(random_bytes(8)), $extension);
        $file->move($this->uploadDir, $storedFileName);

        $absence->setJustificationFile($storedFileName, $file->getClientOriginalName());
        $absence->setJustificationSubmittedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json(['message' => 'Votre justificatif a bien été transmis.']);
    }
}
