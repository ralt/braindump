<?php

namespace App\Security\Voter;

use App\Entity\Recording;
use App\Entity\User;
use App\Repository\RecordingShareRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class RecordingVoter extends Voter
{
    public const VIEW = 'RECORDING_VIEW';
    public const EDIT = 'RECORDING_EDIT';
    public const SHARE = 'RECORDING_SHARE';
    public const DELETE = 'RECORDING_DELETE';
    public const AI_SESSION = 'RECORDING_AI_SESSION';

    public function __construct(
        private RecordingShareRepository $shareRepository,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Recording
            && \in_array($attribute, [self::VIEW, self::EDIT, self::SHARE, self::DELETE, self::AI_SESSION], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        assert($subject instanceof Recording);

        // Owner can do everything
        if ($subject->getOwner()->getId()->equals($user->getId())) {
            return true;
        }

        // Check share
        $share = $this->shareRepository->findByRecordingAndUser($subject, $user);
        if ($share === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW, self::AI_SESSION => true,
            self::EDIT => $share->getPermission()->value === 'edit',
            self::SHARE, self::DELETE => false, // only owner can share/delete
            default => false,
        };
    }
}
