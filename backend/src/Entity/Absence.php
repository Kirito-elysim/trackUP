<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'absences')]
class Absence
{
    public const TYPE_MASTERCLASS = 'masterclass';
    public const TYPE_PRESENTIEL = 'presentiel';

    public const STATUS_EN_ATTENTE = 'en_attente';
    public const STATUS_JUSTIFIEE = 'justifiee';
    public const STATUS_NON_JUSTIFIEE = 'non_justifiee';
    public const STATUS_AUTRE = 'autre';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ClassroomSessionRegistration $registration;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_EN_ATTENTE;

    #[ORM\Column]
    private \DateTimeImmutable $detectedAt;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $justificationToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $justificationTokenExpiresAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $justificationFilePath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $justificationFileOriginalName = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $justificationSubmittedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $validatedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminNote = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $notificationSentAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmationSentAt = null;

    public function __construct(ClassroomSessionRegistration $registration, string $type)
    {
        $this->registration = $registration;
        $this->type = $type;
        $this->detectedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRegistration(): ClassroomSessionRegistration
    {
        return $this->registration;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getDetectedAt(): \DateTimeImmutable
    {
        return $this->detectedAt;
    }

    public function getJustificationToken(): ?string
    {
        return $this->justificationToken;
    }

    public function getJustificationTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->justificationTokenExpiresAt;
    }

    public function setJustificationToken(?string $token, ?\DateTimeImmutable $expiresAt = null): self
    {
        $this->justificationToken = $token;
        $this->justificationTokenExpiresAt = $expiresAt;

        return $this;
    }

    public function getJustificationFilePath(): ?string
    {
        return $this->justificationFilePath;
    }

    public function getJustificationFileOriginalName(): ?string
    {
        return $this->justificationFileOriginalName;
    }

    public function setJustificationFile(?string $filePath, ?string $originalName): self
    {
        $this->justificationFilePath = $filePath;
        $this->justificationFileOriginalName = $originalName;

        return $this;
    }

    public function getJustificationSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->justificationSubmittedAt;
    }

    public function setJustificationSubmittedAt(?\DateTimeImmutable $justificationSubmittedAt): self
    {
        $this->justificationSubmittedAt = $justificationSubmittedAt;

        return $this;
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    public function getValidatedBy(): ?User
    {
        return $this->validatedBy;
    }

    public function setValidation(?\DateTimeImmutable $validatedAt, ?User $validatedBy): self
    {
        $this->validatedAt = $validatedAt;
        $this->validatedBy = $validatedBy;

        return $this;
    }

    public function getAdminNote(): ?string
    {
        return $this->adminNote;
    }

    public function setAdminNote(?string $adminNote): self
    {
        $this->adminNote = $adminNote;

        return $this;
    }

    public function getNotificationSentAt(): ?\DateTimeImmutable
    {
        return $this->notificationSentAt;
    }

    public function setNotificationSentAt(?\DateTimeImmutable $notificationSentAt): self
    {
        $this->notificationSentAt = $notificationSentAt;

        return $this;
    }

    public function getConfirmationSentAt(): ?\DateTimeImmutable
    {
        return $this->confirmationSentAt;
    }

    public function setConfirmationSentAt(?\DateTimeImmutable $confirmationSentAt): self
    {
        $this->confirmationSentAt = $confirmationSentAt;

        return $this;
    }
}
