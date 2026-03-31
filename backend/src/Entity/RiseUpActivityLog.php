<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'riseup_activity_logs',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_riseup_activity_log_fingerprint', columns: ['row_fingerprint']),
    ],
    indexes: [
        new ORM\Index(name: 'idx_riseup_activity_log_training_external', columns: ['training_external_id']),
        new ORM\Index(name: 'idx_riseup_activity_log_learner_external', columns: ['learner_external_id']),
        new ORM\Index(name: 'idx_riseup_activity_log_learner_email', columns: ['learner_email']),
        new ORM\Index(name: 'idx_riseup_activity_log_login_at', columns: ['login_at']),
        new ORM\Index(name: 'idx_riseup_activity_log_lookup', columns: ['learner_external_id', 'training_external_id', 'login_at']),
    ],
)]
class RiseUpActivityLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $sourceFileName;

    #[ORM\Column]
    private \DateTimeImmutable $sourceImportedAt;

    #[ORM\Column]
    private int $trainingExternalId;

    #[ORM\Column(nullable: true)]
    private ?int $learnerExternalId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $learnerEmail = null;

    #[ORM\Column]
    private \DateTimeImmutable $loginAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $logoutAt = null;

    #[ORM\Column]
    private int $durationSeconds = 0;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $device = null;

    #[ORM\Column(length: 64)]
    private string $rowFingerprint;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->sourceImportedAt = new \DateTimeImmutable();
        $this->loginAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->sourceFileName = '';
        $this->rowFingerprint = '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceFileName(): string
    {
        return $this->sourceFileName;
    }

    public function setSourceFileName(string $sourceFileName): self
    {
        $this->sourceFileName = $sourceFileName;

        return $this;
    }

    public function getSourceImportedAt(): \DateTimeImmutable
    {
        return $this->sourceImportedAt;
    }

    public function setSourceImportedAt(\DateTimeImmutable $sourceImportedAt): self
    {
        $this->sourceImportedAt = $sourceImportedAt;

        return $this;
    }

    public function getTrainingExternalId(): int
    {
        return $this->trainingExternalId;
    }

    public function setTrainingExternalId(int $trainingExternalId): self
    {
        $this->trainingExternalId = $trainingExternalId;

        return $this;
    }

    public function getLearnerExternalId(): ?int
    {
        return $this->learnerExternalId;
    }

    public function setLearnerExternalId(?int $learnerExternalId): self
    {
        $this->learnerExternalId = $learnerExternalId;

        return $this;
    }

    public function getLearnerEmail(): ?string
    {
        return $this->learnerEmail;
    }

    public function setLearnerEmail(?string $learnerEmail): self
    {
        $this->learnerEmail = $learnerEmail;

        return $this;
    }

    public function getLoginAt(): \DateTimeImmutable
    {
        return $this->loginAt;
    }

    public function setLoginAt(\DateTimeImmutable $loginAt): self
    {
        $this->loginAt = $loginAt;

        return $this;
    }

    public function getLogoutAt(): ?\DateTimeImmutable
    {
        return $this->logoutAt;
    }

    public function setLogoutAt(?\DateTimeImmutable $logoutAt): self
    {
        $this->logoutAt = $logoutAt;

        return $this;
    }

    public function getDurationSeconds(): int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(int $durationSeconds): self
    {
        $this->durationSeconds = max(0, $durationSeconds);

        return $this;
    }

    public function getDevice(): ?string
    {
        return $this->device;
    }

    public function setDevice(?string $device): self
    {
        $this->device = $device;

        return $this;
    }

    public function getRowFingerprint(): string
    {
        return $this->rowFingerprint;
    }

    public function setRowFingerprint(string $rowFingerprint): self
    {
        $this->rowFingerprint = $rowFingerprint;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
