<?php

namespace App\Controller;

use App\Entity\Recording;
use App\Entity\RecordingShare;
use App\Entity\User;
use App\Enum\SharePermission;
use App\Repository\RecordingShareRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SharingController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private RecordingShareRepository $shareRepository,
    ) {}

    #[Route('/api/recordings/{id}/share', name: 'api_recording_share', methods: ['POST'])]
    public function share(Recording $recording, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('RECORDING_SHARE', $recording);

        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? '';
        $permission = $data['permission'] ?? 'view';

        if (!$email) {
            return $this->json(['error' => 'Email is required'], Response::HTTP_BAD_REQUEST);
        }

        $targetUser = $this->userRepository->findOneByEmail($email);
        if ($targetUser === null) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        if ($targetUser->getId()->equals($recording->getOwner()->getId())) {
            return $this->json(['error' => 'Cannot share with the owner'], Response::HTTP_BAD_REQUEST);
        }

        $existingShare = $this->shareRepository->findByRecordingAndUser($recording, $targetUser);
        if ($existingShare !== null) {
            $existingShare->setPermission(SharePermission::from($permission));
            $this->em->flush();
            return $this->json(['message' => 'Share updated']);
        }

        $share = new RecordingShare();
        $share->setRecording($recording);
        $share->setSharedWith($targetUser);
        $share->setPermission(SharePermission::from($permission));

        $this->em->persist($share);
        $this->em->flush();

        return $this->json(['message' => 'Recording shared successfully'], Response::HTTP_CREATED);
    }

    #[Route('/api/recordings/{id}/share/{userId}', name: 'api_recording_unshare', methods: ['POST'])]
    public function unshare(Recording $recording, string $userId, Request $request): Response
    {
        $this->denyAccessUnlessGranted('RECORDING_SHARE', $recording);

        if (!$this->isCsrfTokenValid('unshare', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $targetUser = $this->em->find(User::class, $userId);
        if ($targetUser === null) {
            throw $this->createNotFoundException('User not found');
        }

        $share = $this->shareRepository->findByRecordingAndUser($recording, $targetUser);
        if ($share !== null) {
            $this->em->remove($share);
            $this->em->flush();
        }

        return $this->redirectToRoute('app_recording_show', ['id' => $recording->getId()]);
    }

    #[Route('/api/recordings/{id}/shares', name: 'api_recording_shares', methods: ['GET'])]
    public function listShares(Recording $recording): JsonResponse
    {
        $this->denyAccessUnlessGranted('RECORDING_VIEW', $recording);

        $shares = [];
        foreach ($recording->getShares() as $share) {
            $shares[] = [
                'id' => $share->getId(),
                'user' => [
                    'id' => $share->getSharedWith()->getId(),
                    'email' => $share->getSharedWith()->getEmail(),
                    'displayName' => $share->getSharedWith()->getDisplayName(),
                ],
                'permission' => $share->getPermission()->value,
            ];
        }

        return $this->json($shares);
    }
}
