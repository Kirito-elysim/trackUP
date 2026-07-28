<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'learner_step_states')]
class LearnerStepState
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
    private TrainingStep $step;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(nullable: true)]
    private ?int $timeSpent = null;

    #[ORM\Column(nullable: true)]
    private ?int $totalTime = null;

    #[ORM\Column(nullable: true)]
    private ?float $score = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $activityAt = null;

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

    public function getStep(): TrainingStep
    {
        return $this->step;
    }

    public function setStep(TrainingStep $step): self
    {
        $this->step = $step;

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

    public function getTimeSpent(): ?int
    {
        return $this->timeSpent;
    }

    public function setTimeSpent(?int $timeSpent): self
    {
        $this->timeSpent = $timeSpent;

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

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function setScore(?float $score): self
    {
        $this->score = $score;

        return $this;
    }

    public function getActivityAt(): ?\DateTimeImmutable
    {
        return $this->activityAt;
    }

    public function setActivityAt(?\DateTimeImmutable $activityAt): self
    {
        $this->activityAt = $activityAt;

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
