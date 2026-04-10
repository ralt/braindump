<?php

namespace App\Entity;

use App\Enum\SharePermission;
use App\Repository\RecordingShareRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RecordingShareRepository::class)]
#[ORM\UniqueConstraint(columns: ['recording_id', 'shared_with_id'])]
class RecordingShare
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Recording::class, inversedBy: 'shares')]
    #[ORM\JoinColumn(nullable: false)]
    private Recording $recording;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'sharedRecordings')]
    #[ORM\JoinColumn(nullable: false)]
    private User $sharedWith;

    #[ORM\Column(type: 'string', length: 10, enumType: SharePermission::class)]
    private SharePermission $permission = SharePermission::View;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRecording(): Recording
    {
        return $this->recording;
    }

    public function setRecording(Recording $recording): static
    {
        $this->recording = $recording;
        return $this;
    }

    public function getSharedWith(): User
    {
        return $this->sharedWith;
    }

    public function setSharedWith(User $sharedWith): static
    {
        $this->sharedWith = $sharedWith;
        return $this;
    }

    public function getPermission(): SharePermission
    {
        return $this->permission;
    }

    public function setPermission(SharePermission $permission): static
    {
        $this->permission = $permission;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
