<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'classroom_session_registrations')]
class ClassroomSessionRegistration
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
    private ClassroomSession $session;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TrainingRegistration $trainingRegistration = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $subscribedAt = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(nullable: true)]
    private ?bool $attended = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(nullable: true)]
    private ?int $eduDuration = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $riseUpCreatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $riseUpUpdatedAt = null;

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

    public function getSession(): ClassroomSession
    {
        return $this->session;
    }

    public function setSession(ClassroomSession $session): self
    {
        $this->session = $session;

        return $this;
    }

    public function getTrainingRegistration(): ?TrainingRegistration
    {
        return $this->trainingRegistration;
    }

    public function setTrainingRegistration(?TrainingRegistration $trainingRegistration): self
    {
        $this->trainingRegistration = $trainingRegistration;

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

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(?string $state): self
    {
        $this->state = $state;

        return $this;
    }

    public function isAttended(): ?bool
    {
        return $this->attended;
    }

    public function setAttended(?bool $attended): self
    {
        $this->attended = $attended;

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

    public function getEduDuration(): ?int
    {
        return $this->eduDuration;
    }

    public function setEduDuration(?int $eduDuration): self
    {
        $this->eduDuration = $eduDuration;

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
