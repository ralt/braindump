<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Drenso\OidcBundle\Exception\OidcException;
use Drenso\OidcBundle\Model\OidcTokens;
use Drenso\OidcBundle\Model\OidcUserData;
use Drenso\OidcBundle\Security\UserProvider\OidcUserProviderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @implements OidcUserProviderInterface<User>
 */
final class OidcUserProvider implements OidcUserProviderInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
    ) {}

    public function ensureUserExists(string $userIdentifier, OidcUserData $userData, OidcTokens $tokens): void
    {
        $user = $this->userRepository->findOneBy(['oidcSubject' => $userIdentifier]);

        if ($user !== null) {
            $user->setDisplayName($userData->getFullName() ?: $userData->getDisplayName() ?: $user->getDisplayName());
            $this->em->flush();

            return;
        }

        // Try to match by email — link existing account to OIDC
        $email = $userData->getEmail();
        if ($email === '') {
            throw new OidcException('OIDC provider did not return an email address.');
        }

        $user = $this->userRepository->findOneByEmail($email);

        if ($user !== null) {
            $user->setOidcSubject($userIdentifier);
            $user->setDisplayName($userData->getFullName() ?: $userData->getDisplayName() ?: $user->getDisplayName());
            $this->em->flush();

            return;
        }

        // Auto-create new user
        $user = new User();
        $user->setEmail($email);
        $user->setOidcSubject($userIdentifier);
        $user->setDisplayName($userData->getFullName() ?: $userData->getDisplayName() ?: $email);

        $this->em->persist($user);
        $this->em->flush();
    }

    public function loadOidcUser(string $userIdentifier): UserInterface
    {
        $user = $this->userRepository->findOneBy(['oidcSubject' => $userIdentifier]);

        if ($user === null) {
            throw new OidcException(sprintf('User with OIDC subject "%s" not found.', $userIdentifier));
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        return $this->userRepository->find($user->getId()) ?? throw new OidcException('User not found.');
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class || is_subclass_of($class, User::class);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->loadOidcUser($identifier);
    }
}
