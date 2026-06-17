<?php

namespace App\Security\Voter;

use App\Entity\Recording;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class RecordingVoter extends Voter
{
    public const VIEW = 'RECORDING_VIEW';
    public const EDIT = 'RECORDING_EDIT';
    public const DELETE = 'RECORDING_DELETE';
    public const AI_SESSION = 'RECORDING_AI_SESSION';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Recording
            && \in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::AI_SESSION], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        assert($subject instanceof Recording);

        // Owner-only access. Sharing was removed; no other user can access a recording.
        return $subject->getOwner()->getId()->equals($user->getId());
    }
}
