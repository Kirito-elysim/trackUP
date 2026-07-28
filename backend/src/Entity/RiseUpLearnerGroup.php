<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'riseup_learner_groups',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_riseup_learner_group', columns: ['learner_id', 'group_id']),
    ],
    indexes: [
        new ORM\Index(name: 'idx_riseup_learner_group_group', columns: ['group_id']),
    ],
)]
class RiseUpLearnerGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Learner $learner;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RiseUpGroup $group;

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

    public function getLearner(): Learner
    {
        return $this->learner;
    }

    public function setLearner(Learner $learner): self
    {
        $this->learner = $learner;

        return $this;
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

