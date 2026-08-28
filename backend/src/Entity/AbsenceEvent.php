<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

// Historique des actions d'une absence (carte "Historique" côté admin) : contrairement aux colonnes
// *_at de Absence (qui ne retiennent que le DERNIER envoi), chaque relance manuelle ou dépôt de
// justificatif ajoute une ligne ici, pour que l'admin voie toute la chronologie (plusieurs relances,
// plusieurs dépôts en cas de remplacement, etc.).
#[ORM\Entity]
#[ORM\Table(name: 'absence_events')]
class AbsenceEvent
{
    public const TYPE_NOTIFICATION_SENT = 'notification_sent';
    public const TYPE_CONFIRMATION_SENT = 'confirmation_sent';
    public const TYPE_JUSTIFICATION_SUBMITTED = 'justification_submitted';
    public const TYPE_STATUS_CHANGED = 'status_changed';
    public const TYPE_NOTE_ADDED = 'note_added';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Absence::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Absence $absence;

    #[ORM\Column(length: 30)]
    private string $type;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    // null = déclenché automatiquement (système ou apprenant via le lien public) ; renseigné =
    // action manuelle d'un admin (relance, changement de statut, note).
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(Absence $absence, string $type, ?User $actor = null, array $metadata = [])
    {
        $this->absence = $absence;
        $this->type = $type;
        $this->actor = $actor;
        $this->metadata = $metadata;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAbsence(): Absence
    {
        return $this->absence;
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
