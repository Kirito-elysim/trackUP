<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'learning_paths')]
class LearningPath
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    private int $externalId;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $language = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?bool $sequential = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $imageUrl = null;

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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = trim($title);

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

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): self
    {
        $this->language = $language !== null ? trim($language) : null;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description !== null ? trim($description) : null;

        return $this;
    }

    public function isSequential(): ?bool
    {
        return $this->sequential;
    }

    public function setSequential(?bool $sequential): self
    {
        $this->sequential = $sequential;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): self
    {
        $this->imageUrl = $imageUrl !== null ? trim($imageUrl) : null;

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
