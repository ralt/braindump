<?php

namespace App\Entity;

use App\Repository\AiSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AiSessionRepository::class)]
class AiSession
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Recording::class, inversedBy: 'aiSessions')]
    #[ORM\JoinColumn(nullable: false)]
    private Recording $recording;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, AiMessage> */
    #[ORM\OneToMany(targetEntity: AiMessage::class, mappedBy: 'session', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    /** @var Collection<int, Skill> */
    #[ORM\ManyToMany(targetEntity: Skill::class)]
    #[ORM\JoinTable(name: 'ai_session_skill')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $activeSkills;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->messages = new ArrayCollection();
        $this->activeSkills = new ArrayCollection();
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, AiMessage> */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(AiMessage $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setSession($this);
        }
        return $this;
    }

    /** @return Collection<int, Skill> */
    public function getActiveSkills(): Collection
    {
        return $this->activeSkills;
    }

    public function addActiveSkill(Skill $skill): static
    {
        if (!$this->activeSkills->contains($skill)) {
            $this->activeSkills->add($skill);
        }
        return $this;
    }

    public function removeActiveSkill(Skill $skill): static
    {
        $this->activeSkills->removeElement($skill);
        return $this;
    }
}
