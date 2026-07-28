<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'learning_path_registrations')]
#[ORM\UniqueConstraint(name: 'learning_path_registration_unique', columns: ['learning_path_id', 'learner_id'])]
class LearningPathRegistration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    private int $externalId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LearningPath $learningPath;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Learner $learner;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(nullable: true)]
    private ?float $score = null;

    #[ORM\Column]
    private ?float $progress = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $subscribedAt = null;

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

    public function getLearningPath(): LearningPath
    {
        return $this->learningPath;
    }

    public function setLearningPath(LearningPath $learningPath): self
    {
        $this->learningPath = $learningPath;

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

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference !== null ? trim($reference) : null;

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

    public function getProgress(): ?float
    {
        return $this->progress;
    }

    public function setProgress(?float $progress): self
    {
        $this->progress = $progress;

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
