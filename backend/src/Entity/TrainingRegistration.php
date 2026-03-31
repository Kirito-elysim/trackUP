<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'training_registrations')]
class TrainingRegistration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    private int $externalId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Learner $learner;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Training $training;

    #[ORM\Column(nullable: true)]
    private ?int $companyExternalId = null;

    #[ORM\Column(nullable: true)]
    private ?int $validatorExternalId = null;

    #[ORM\Column(nullable: true)]
    private ?int $registeredByExternalId = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(nullable: true)]
    private ?int $totalTime = null;

    #[ORM\Column(nullable: true)]
    private ?float $progress = null;

    #[ORM\Column(nullable: true)]
    private ?float $score = null;

    #[ORM\Column(nullable: true)]
    private ?bool $forceFinished = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $subscribedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $trainingEndAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $riseUpCreatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $riseUpUpdatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $coursePeriodExternalId = null;

    #[ORM\Column]
    private \DateTimeImmutable $syncedAt;

    public function __construct()
    {
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExternalId(): int
    {
        return $this->externalId;
    }

    public function setExternalId(int $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getLearner(): Learner
    {
        return $this->learner;
    }

    public function setLearner(Learner $learner): self
    {
        $this->learner = $learner;

        return $this;
    }

    public function getTraining(): Training
    {
        return $this->training;
    }

    public function setTraining(Training $training): self
    {
        $this->training = $training;

        return $this;
    }

    public function getCompanyExternalId(): ?int
    {
        return $this->companyExternalId;
    }

    public function setCompanyExternalId(?int $companyExternalId): self
    {
        $this->companyExternalId = $companyExternalId;

        return $this;
    }

    public function getValidatorExternalId(): ?int
    {
        return $this->validatorExternalId;
    }

    public function setValidatorExternalId(?int $validatorExternalId): self
    {
        $this->validatorExternalId = $validatorExternalId;

        return $this;
    }

    public function getRegisteredByExternalId(): ?int
    {
        return $this->registeredByExternalId;
    }

    public function setRegisteredByExternalId(?int $registeredByExternalId): self
    {
        $this->registeredByExternalId = $registeredByExternalId;

        return $this;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(?string $state): self
    {
        $this->state = $state;

        return $this;
    }

    public function getTotalTime(): ?int
    {
        return $this->totalTime;
    }

    public function setTotalTime(?int $totalTime): self
    {
        $this->totalTime = $totalTime;

        return $this;
    }

    public function getProgress(): ?float
    {
        return $this->progress;
    }

    public function setProgress(?float $progress): self
    {
        $this->progress = $progress;

        return $this;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function setScore(?float $score): self
    {
        $this->score = $score;

        return $this;
    }

    public function isForceFinished(): ?bool
    {
        return $this->forceFinished;
    }

    public function setForceFinished(?bool $forceFinished): self
    {
        $this->forceFinished = $forceFinished;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getSubscribedAt(): ?\DateTimeImmutable
    {
        return $this->subscribedAt;
    }

    public function setSubscribedAt(?\DateTimeImmutable $subscribedAt): self
    {
        $this->subscribedAt = $subscribedAt;

        return $this;
    }

    public function getTrainingEndAt(): ?\DateTimeImmutable
    {
        return $this->trainingEndAt;
    }

    public function setTrainingEndAt(?\DateTimeImmutable $trainingEndAt): self
    {
        $this->trainingEndAt = $trainingEndAt;

        return $this;
    }

    public function getRiseUpCreatedAt(): ?\DateTimeImmutable
    {
        return $this->riseUpCreatedAt;
    }

    public function setRiseUpCreatedAt(?\DateTimeImmutable $riseUpCreatedAt): self
    {
        $this->riseUpCreatedAt = $riseUpCreatedAt;

        return $this;
    }

    public function getRiseUpUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->riseUpUpdatedAt;
    }

    public function setRiseUpUpdatedAt(?\DateTimeImmutable $riseUpUpdatedAt): self
    {
        $this->riseUpUpdatedAt = $riseUpUpdatedAt;

        return $this;
    }

    public function getCoursePeriodExternalId(): ?int
    {
        return $this->coursePeriodExternalId;
    }

    public function setCoursePeriodExternalId(?int $coursePeriodExternalId): self
    {
        $this->coursePeriodExternalId = $coursePeriodExternalId;

        return $this;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(\DateTimeImmutable $syncedAt): self
    {
        $this->syncedAt = $syncedAt;

        return $this;
    }
}
