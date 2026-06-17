<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    private ?string $plainPassword = null;

    #[ORM\Column(length: 255)]
    private string $displayName;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $encryptedAiApiKey = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $aiProvider = null;

    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $oidcSubject = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Recording> */
    #[ORM\OneToMany(targetEntity: Recording::class, mappedBy: 'owner')]
    private Collection $recordings;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->recordings = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;
        return $this;
    }

    public function getEncryptedAiApiKey(): ?string
    {
        return $this->encryptedAiApiKey;
    }

    public function setEncryptedAiApiKey(?string $encryptedAiApiKey): static
    {
        $this->encryptedAiApiKey = $encryptedAiApiKey;
        return $this;
    }

    public function getAiProvider(): ?string
    {
        return $this->aiProvider;
    }

    public function setAiProvider(?string $aiProvider): static
    {
        $this->aiProvider = $aiProvider;
        return $this;
    }

    public function getOidcSubject(): ?string
    {
        return $this->oidcSubject;
    }

    public function setOidcSubject(?string $oidcSubject): static
    {
        $this->oidcSubject = $oidcSubject;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Recording> */
    public function getRecordings(): Collection
    {
        return $this->recordings;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;
        return $this;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // No-op: erasure of the transient plain password is handled in __serialize().
    }

    /**
     * Keep the transient plain password out of the serialized session payload.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        unset($data["\0".self::class."\0plainPassword"]);

        return $data;
    }

    public function __toString(): string
    {
        return $this->displayName;
    }
}
