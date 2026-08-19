<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'companies')]
#[ORM\HasLifecycleCallbacks]
class Company
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $siret = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\ManyToOne(targetEntity: Sector::class)]
    #[ORM\JoinColumn(name: 'sector_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Sector $sector = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $contactName = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $contactPhoneMobile = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $contactPhoneFixe = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /**
     * @var Collection<int, Tutor>
     */
    #[ORM\ManyToMany(targetEntity: Tutor::class, mappedBy: 'companies')]
    private Collection $tutors;

    /**
     * @var Collection<int, Learner>
     */
    #[ORM\OneToMany(targetEntity: Learner::class, mappedBy: 'company')]
    private Collection $learners;

    public function __construct()
    {
        $this->tutors = new ArrayCollection();
        $this->learners = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): self
    {
        $this->siret = $siret !== null && trim($siret) !== '' ? trim($siret) : null;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address !== null && trim($address) !== '' ? trim($address) : null;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): self
    {
        $this->postalCode = $postalCode !== null && trim($postalCode) !== '' ? trim($postalCode) : null;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $this->city = $city !== null && trim($city) !== '' ? trim($city) : null;

        return $this;
    }

    public function getSector(): ?Sector
    {
        return $this->sector;
    }

    public function setSector(?Sector $sector): self
    {
        $this->sector = $sector;

        return $this;
    }

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function setContactName(?string $contactName): self
    {
        $this->contactName = $contactName !== null && trim($contactName) !== '' ? trim($contactName) : null;

        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): self
    {
        $this->contactEmail = $contactEmail !== null && trim($contactEmail) !== '' ? trim($contactEmail) : null;

        return $this;
    }

    public function getContactPhoneMobile(): ?string
    {
        return $this->contactPhoneMobile;
    }

    public function setContactPhoneMobile(?string $contactPhoneMobile): self
    {
        $this->contactPhoneMobile = $contactPhoneMobile !== null && trim($contactPhoneMobile) !== '' ? trim($contactPhoneMobile) : null;

        return $this;
    }

    public function getContactPhoneFixe(): ?string
    {
        return $this->contactPhoneFixe;
    }

    public function setContactPhoneFixe(?string $contactPhoneFixe): self
    {
        $this->contactPhoneFixe = $contactPhoneFixe !== null && trim($contactPhoneFixe) !== '' ? trim($contactPhoneFixe) : null;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): self
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * @return Collection<int, Tutor>
     */
    public function getTutors(): Collection
    {
        return $this->tutors;
    }

    /**
     * @return Collection<int, Learner>
     */
    public function getLearners(): Collection
    {
        return $this->learners;
    }

    #[ORM\PrePersist]
    public function setTimestampsOnCreate(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function setTimestampOnUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
