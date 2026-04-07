<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'riseup_group_learning_paths',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_riseup_group_learning_path', columns: ['group_id', 'learning_path_external_id']),
    ],
    indexes: [
        new ORM\Index(name: 'idx_riseup_group_learning_path_ext', columns: ['learning_path_external_id']),
    ],
)]
class RiseUpGroupLearningPath
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RiseUpGroup $group;

    #[ORM\Column(name: 'learning_path_external_id')]
    private int $learningPathExternalId;

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

    public function getGroup(): RiseUpGroup
    {
        return $this->group;
    }

    public function setGroup(RiseUpGroup $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function getLearningPathExternalId(): int
    {
        return $this->learningPathExternalId;
    }

    public function setLearningPathExternalId(int $learningPathExternalId): self
    {
        $this->learningPathExternalId = $learningPathExternalId;

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

