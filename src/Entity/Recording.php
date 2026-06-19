<?php

namespace App\Entity;

use App\Enum\RecordingStatus;
use App\Repository\RecordingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;


#[ORM\Entity(repositoryClass: RecordingRepository::class)]
class Recording
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'recordings')]
    #[ORM\JoinColumn(nullable: false)]
    private User $owner;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 512)]
    private string $audioFilePath;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $fileSizeBytes;

    #[ORM\Column(type: 'string', length: 20, enumType: RecordingStatus::class)]
    private RecordingStatus $status = RecordingStatus::Pending;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $transcription = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, AiSession> */
    #[ORM\OneToMany(targetEntity: AiSession::class, mappedBy: 'recording', cascade: ['remove'])]
    private Collection $aiSessions;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->aiSessions = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Title for display: falls back to a status-aware placeholder so the UI
     * doesn't show a confusing blank or stale "Untitled" while the auto-title
     * step is still pending behind transcription.
     */
    public function getDisplayTitle(): string
    {
        if ($this->title !== '') {
            return $this->title;
        }
        return match ($this->status) {
            RecordingStatus::Pending, RecordingStatus::Transcribing => 'Transcribing…',
            default => 'Untitled',
        };
    }

    public function getAudioFilePath(): string
    {
        return $this->audioFilePath;
    }

    public function setAudioFilePath(string $audioFilePath): static
    {
        $this->audioFilePath = $audioFilePath;
        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getFileSizeBytes(): int
    {
        return $this->fileSizeBytes;
    }

    public function setFileSizeBytes(int $fileSizeBytes): static
    {
        $this->fileSizeBytes = $fileSizeBytes;
        return $this;
    }

    public function getStatus(): RecordingStatus
    {
        return $this->status;
    }

    public function setStatus(RecordingStatus $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getTranscription(): ?string
    {
        return $this->transcription;
    }

    public function setTranscription(?string $transcription): static
    {
        $this->transcription = $transcription;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, AiSession> */
    public function getAiSessions(): Collection
    {
        return $this->aiSessions;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
