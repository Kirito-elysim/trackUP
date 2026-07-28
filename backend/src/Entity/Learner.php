<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'learners')]
class Learner
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    private int $externalId;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $language = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phoneNumber = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $timezone = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $riseUpRole = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

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

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): self
    {
        $this->username = $username;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): self
    {
        $this->language = $language;

        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): self
    {
        $this->phoneNumber = $phoneNumber;

        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(?string $timezone): self
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getRiseUpRole(): ?string
    {
        return $this->riseUpRole;
    }

    public function setRiseUpRole(?string $riseUpRole): self
    {
        $this->riseUpRole = $riseUpRole;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

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

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function setActivatedAt(?\DateTimeImmutable $activatedAt): self
    {
        $this->activatedAt = $activatedAt;

        return $this;
    }

    public function getSuspendedAt(): ?\DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function setSuspendedAt(?\DateTimeImmutable $suspendedAt): self
    {
        $this->suspendedAt = $suspendedAt;

        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): self
    {
        $this->lastLoginAt = $lastLoginAt;

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
