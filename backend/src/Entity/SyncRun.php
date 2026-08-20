<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

// Journal opérationnel des exécutions groupées des 9 syncs Rise Up (planifiées ou manuelles) —
// pas une ressource CRUD, donc pas de soft delete ici, juste un append + une mise à jour finale.
#[ORM\Entity]
#[ORM\Table(name: 'sync_runs')]
class SyncRun
{
    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_MANUAL = 'manual';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'trigger_type', length: 20)]
    private string $triggerType;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * @var array<int, array{command: string, label: string, status: string, durationMs: int, output: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $steps = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'triggered_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $triggeredBy = null;

    // Reflète l'étape en train de tourner (1 à 9) pendant l'exécution, pour que le polling front
    // affiche une progression en temps réel plutôt qu'un statut figé jusqu'à la fin d'une étape
    // qui peut prendre plusieurs dizaines de secondes (sessions notamment). Remis à null en fin de run.
    #[ORM\Column(name: 'current_step_index', nullable: true)]
    private ?int $currentStepIndex = null;

    #[ORM\Column(name: 'current_step_label', length: 120, nullable: true)]
    private ?string $currentStepLabel = null;

    public function __construct(string $triggerType, ?User $triggeredBy = null)
    {
        $this->triggerType = $triggerType;
        $this->status = self::STATUS_RUNNING;
        $this->startedAt = new \DateTimeImmutable();
        $this->triggeredBy = $triggeredBy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTriggerType(): string
    {
        return $this->triggerType;
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

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    /**
     * @return array<int, array{command: string, label: string, status: string, durationMs: int, output: string}>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * @param array{command: string, label: string, status: string, durationMs: int, output: string} $step
     */
    public function addStep(array $step): self
    {
        $this->steps[] = $step;

        return $this;
    }

    public function getTriggeredBy(): ?User
    {
        return $this->triggeredBy;
    }

    public function getCurrentStepIndex(): ?int
    {
        return $this->currentStepIndex;
    }

    public function getCurrentStepLabel(): ?string
    {
        return $this->currentStepLabel;
    }

    public function setCurrentStep(?int $index, ?string $label): self
    {
        $this->currentStepIndex = $index;
        $this->currentStepLabel = $label;

        return $this;
    }
}
