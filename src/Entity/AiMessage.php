<?php

namespace App\Entity;

use App\Enum\AiMessageRole;
use App\Repository\AiMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AiMessageRepository::class)]
#[ORM\Index(columns: ['session_id', 'created_at'])]
class AiMessage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AiSession::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AiSession $session;

    #[ORM\Column(type: 'string', length: 20, enumType: AiMessageRole::class)]
    private AiMessageRole $role;

    #[ORM\Column(type: 'text')]
    private string $content;

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

    public function getSession(): AiSession
    {
        return $this->session;
    }

    public function setSession(AiSession $session): static
    {
        $this->session = $session;
        return $this;
    }

    public function getRole(): AiMessageRole
    {
        return $this->role;
    }

    public function setRole(AiMessageRole $role): static
    {
        $this->role = $role;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
