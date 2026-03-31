<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'classroom_session_signatures')]
class ClassroomSessionSignature
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ClassroomSessionRegistration $registration;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $attendanceDate;

    #[ORM\Column(length: 40)]
    private string $period;

    #[ORM\Column]
    private bool $hasSigned = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $signatureDate = null;

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

    public function getRegistration(): ClassroomSessionRegistration
    {
        return $this->registration;
    }

    public function setRegistration(ClassroomSessionRegistration $registration): self
    {
        $this->registration = $registration;

        return $this;
    }

    public function getAttendanceDate(): \DateTimeImmutable
    {
        return $this->attendanceDate;
    }

    public function setAttendanceDate(\DateTimeImmutable $attendanceDate): self
    {
        $this->attendanceDate = $attendanceDate;

        return $this;
    }

    public function getPeriod(): string
    {
        return $this->period;
    }

    public function setPeriod(string $period): self
    {
        $this->period = $period;

        return $this;
    }

    public function hasSigned(): bool
    {
        return $this->hasSigned;
    }

    public function setHasSigned(bool $hasSigned): self
    {
        $this->hasSigned = $hasSigned;

        return $this;
    }

    public function getSignatureDate(): ?\DateTimeImmutable
    {
        return $this->signatureDate;
    }

    public function setSignatureDate(?\DateTimeImmutable $signatureDate): self
    {
        $this->signatureDate = $signatureDate;

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
