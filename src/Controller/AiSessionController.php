<?php

namespace App\Controller;

use App\Entity\AiSession;
use App\Entity\Recording;
use App\Entity\User;
use App\Enum\AiSessionStatus;
use App\Message\StartAiSessionMessage;
use App\Service\ApiKeyEncryptorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;

class AiSessionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ApiKeyEncryptorInterface $encryptor,
        private Authorization $mercureAuthorization,
        private HubInterface $hub,
    ) {}

    #[Route('/recordings/{id}/ai-session', name: 'app_ai_session')]
    public function show(Recording $recording, Request $request): Response
    {
        $this->denyAccessUnlessGranted('RECORDING_AI_SESSION', $recording);

        /** @var User $user */
        $user = $this->getUser();

        if ($user->getEncryptedAiApiKey() === null) {
            $this->addFlash('error', 'Please configure your AI provider API key in Settings before starting a session.');
            return $this->redirectToRoute('app_user_settings');
        }

        // Set Mercure authorization cookie so the browser can subscribe
        $this->mercureAuthorization->setCookie($request, ['*']);

        return $this->render('recording/ai_session.html.twig', [
            'recording' => $recording,
        ]);
    }

    #[Route('/api/recordings/{id}/ai-session', name: 'api_ai_session_start', methods: ['POST'])]
    public function start(Recording $recording): JsonResponse
    {
        $this->denyAccessUnlessGranted('RECORDING_AI_SESSION', $recording);

        /** @var User $user */
        $user = $this->getUser();

        if ($user->getEncryptedAiApiKey() === null) {
            return $this->json(['error' => 'AI provider API key not configured'], Response::HTTP_BAD_REQUEST);
        }

        // Prevent duplicate sessions — only consider sessions created in the last 5 minutes
        // to avoid stale sessions from crashed workers blocking new ones forever
        $cutoff = new \DateTimeImmutable('-5 minutes');
        $existing = $this->em->createQueryBuilder()
            ->select('s')
            ->from(AiSession::class, 's')
            ->where('s.recording = :recording')
            ->andWhere('s.user = :user')
            ->andWhere('s.status IN (:statuses)')
            ->andWhere('s.createdAt > :cutoff')
            ->setParameter('recording', $recording)
            ->setParameter('user', $user)
            ->setParameter('statuses', [AiSessionStatus::Starting, AiSessionStatus::Running])
            ->setParameter('cutoff', $cutoff)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existing) {
            return $this->json([
                'sessionId' => $existing->getId(),
                'mercureTopic' => 'ai-session/' . $existing->getId(),
            ]);
        }

        // Close any stale starting/running sessions
        $this->em->createQueryBuilder()
            ->update(AiSession::class, 's')
            ->set('s.status', ':closed')
            ->set('s.closedAt', ':now')
            ->where('s.recording = :recording')
            ->andWhere('s.user = :user')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('closed', AiSessionStatus::Closed)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('recording', $recording)
            ->setParameter('user', $user)
            ->setParameter('statuses', [AiSessionStatus::Starting, AiSessionStatus::Running])
            ->getQuery()
            ->execute();

        $session = new AiSession();
        $session->setRecording($recording);
        $session->setUser($user);
        $session->setStatus(AiSessionStatus::Starting);

        $this->em->persist($session);
        $this->em->flush();

        return $this->json([
            'sessionId' => $session->getId(),
            'mercureTopic' => 'ai-session/' . $session->getId(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/ai-sessions/{id}/dispatch', name: 'api_ai_session_dispatch', methods: ['POST'])]
    public function dispatch(AiSession $session): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$session->getUser()->getId()->equals($user->getId())) {
            throw $this->createAccessDeniedException();
        }

        if ($session->getStatus() !== AiSessionStatus::Starting) {
            return $this->json(['error' => 'Session already dispatched'], Response::HTTP_CONFLICT);
        }

        // Immediate feedback while the worker picks up the message
        $topic = 'ai-session/' . $session->getId();
        $this->hub->publish(new Update($topic, json_encode(['output' => "Queuing session...\r\n"])));

        $this->bus->dispatch(new StartAiSessionMessage(
            $session->getId(),
            $session->getRecording()->getId(),
            $user->getId(),
        ));

        return $this->json(['ok' => true]);
    }

    #[Route('/api/ai-sessions/{id}/status', name: 'api_ai_session_status', methods: ['GET'])]
    public function status(AiSession $session): JsonResponse
    {
        return $this->json([
            'status' => $session->getStatus()->value,
        ]);
    }

    #[Route('/api/ai-sessions/{id}/input', name: 'api_ai_session_input', methods: ['POST'])]
    public function input(AiSession $session, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$session->getUser()->getId()->equals($user->getId())) {
            throw $this->createAccessDeniedException();
        }

        $data = json_decode($request->getContent(), true);
        $input = $data['input'] ?? '';

        if ($input === '') {
            return $this->json(['error' => 'No input provided'], Response::HTTP_BAD_REQUEST);
        }

        // Write to the session's FIFO
        $fifoPath = sys_get_temp_dir() . '/ai-sessions/' . $session->getId() . '/input.fifo';

        if (!file_exists($fifoPath)) {
            return $this->json(['error' => 'Session not ready'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $fifo = fopen($fifoPath, 'w');
        if ($fifo === false) {
            return $this->json(['error' => 'Could not write to session'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        fwrite($fifo, $input);
        fclose($fifo);

        return $this->json(['ok' => true]);
    }

    #[Route('/api/ai-sessions/{id}', name: 'api_ai_session_close', methods: ['DELETE'])]
    public function close(AiSession $session): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$session->getUser()->getId()->equals($user->getId())) {
            throw $this->createAccessDeniedException();
        }

        // Signal the worker to stop by writing a sentinel value
        $fifoPath = sys_get_temp_dir() . '/ai-sessions/' . $session->getId() . '/input.fifo';
        if (file_exists($fifoPath)) {
            $fifo = fopen($fifoPath, 'w');
            if ($fifo) {
                fwrite($fifo, "\x04"); // EOT character signals close
                fclose($fifo);
            }
        }

        $session->setStatus(AiSessionStatus::Closed);
        $session->setClosedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json(['ok' => true]);
    }
}
