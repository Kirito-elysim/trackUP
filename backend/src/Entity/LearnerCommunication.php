<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

// Historique de communication propre à un apprenant (onglet "Communications" de la fiche apprenant) :
// pour les échanges qui ne se rattachent pas à UNE absence précise (ex. email disciplinaire manuel
// suite à un cumul d'absences), contrairement à AbsenceEvent qui est scopé à une absence. La fiche
// apprenant fusionne ces deux sources pour donner une vue complète. Type volontairement libre pour
// accueillir de futurs types de message sans nouvelle table (décision explicite de l'utilisateur :
// "on rajoutera au fur et à mesure").
#[ORM\Entity]
#[ORM\Table(name: 'learner_communications')]
class LearnerCommunication
{
    public const TYPE_DISCIPLINARY_EMAIL = 'disciplinary_email';
    public const TYPE_ELEARNING_REMINDER = 'elearning_reminder';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Learner::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Learner $learner;

    #[ORM\Column(length: 40)]
    private string $type;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    // null = automatique (système) ; renseigné = action manuelle d'un admin.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(Learner $learner, string $type, ?User $actor = null, array $metadata = [])
    {
        $this->learner = $learner;
        $this->type = $type;
        $this->actor = $actor;
        $this->metadata = $metadata;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLearner(): Learner
    {
        return $this->learner;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
