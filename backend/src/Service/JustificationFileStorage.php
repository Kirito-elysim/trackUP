<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Absence;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;

// Règles et stockage des justificatifs d'absence, partagés entre le dépôt de l'apprenant (lien à
// token) et le dépôt par l'équipe depuis la fiche absence : mêmes formats, même taille, même
// vérification du contenu réel, même emplacement sur disque (jamais servi statiquement).
class JustificationFileStorage
{
    public const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    public const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public function __construct(private readonly string $uploadDir)
    {
    }

    /**
     * @return array{message: string, status: int}|null null when the file is acceptable
     */
    public function validate(mixed $file): ?array
    {
        if (!$file instanceof UploadedFile) {
            return ['message' => 'Aucun fichier fourni.', 'status' => JsonResponse::HTTP_BAD_REQUEST];
        }

        if (!$file->isValid()) {
            $tooLarge = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

            return $tooLarge
                ? ['message' => 'Le fichier dépasse la taille maximale autorisée (10 Mo).', 'status' => JsonResponse::HTTP_REQUEST_ENTITY_TOO_LARGE]
                : ['message' => 'Le transfert du fichier a échoué. Merci de réessayer.', 'status' => JsonResponse::HTTP_BAD_REQUEST];
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!isset(self::MIME_TYPES[$extension])) {
            return ['message' => 'Le fichier doit être au format PDF, JPG ou PNG.', 'status' => JsonResponse::HTTP_BAD_REQUEST];
        }

        if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            return ['message' => 'Le fichier dépasse la taille maximale autorisée (10 Mo).', 'status' => JsonResponse::HTTP_BAD_REQUEST];
        }

        if ($file->getMimeType() !== self::MIME_TYPES[$extension]) {
            return ['message' => 'Le contenu du fichier ne correspond pas à un PDF, JPG ou PNG valide.', 'status' => JsonResponse::HTTP_BAD_REQUEST];
        }

        return null;
    }

    /**
     * Moves an already validated file next to the absence and records it on the entity.
     *
     * @return array{stored: string, previous: string|null}
     */
    public function store(Absence $absence, UploadedFile $file): array
    {
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0775, true);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $previous = $absence->getJustificationFilePath();
        $stored = sprintf('%d-%s.%s', $absence->getId(), bin2hex(random_bytes(8)), $extension);

        $file->move($this->uploadDir, $stored);
        $absence->setJustificationFile($stored, $file->getClientOriginalName());
        $absence->setJustificationSubmittedAt(new \DateTimeImmutable());

        return ['stored' => $stored, 'previous' => $previous];
    }

    // Called after a successful commit (old file) or a rollback (new file), so the disk never
    // keeps orphans nor loses the file still referenced in the database.
    public function delete(?string $storedFileName): void
    {
        if ($storedFileName === null) {
            return;
        }

        $fullPath = $this->uploadDir . '/' . $storedFileName;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}
