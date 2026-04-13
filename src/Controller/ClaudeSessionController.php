<?php

namespace App\Controller;

use App\Entity\ClaudeSession;
use App\Entity\Recording;
use App\Entity\User;
use App\Enum\ClaudeSessionStatus;
use App\Message\StartClaudeSessionMessage;
use App\Service\ApiKeyEncryptorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class ClaudeSessionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ApiKeyEncryptorInterface $encryptor,
    ) {}

    #[Route('/recordings/{id}/claude', name: 'app_claude_session')]
    public function show(Recording $recording): Response
    {
        $this->denyAccessUnlessGranted('RECORDING_CLAUDE', $recording);

        /** @var User $user */
        $user = $this->getUser();

        if ($user->getEncryptedAnthropicApiKey() === null) {
            $this->addFlash('error', 'Please configure your Anthropic API key in Settings before starting a Claude session.');
            return $this->redirectToRoute('app_user_settings');
        }

        return $this->render('recording/claude.html.twig', [
            'recording' => $recording,
        ]);
    }

    #[Route('/api/recordings/{id}/claude', name: 'api_claude_session_start', methods: ['POST'])]
    public function start(Recording $recording): JsonResponse
    {
        $this->denyAccessUnlessGranted('RECORDING_CLAUDE', $recording);

        /** @var User $user */
        $user = $this->getUser();

        if ($user->getEncryptedAnthropicApiKey() === null) {
            return $this->json(['error' => 'Anthropic API key not configured'], Response::HTTP_BAD_REQUEST);
        }

        // Prevent duplicate sessions
        $existing = $this->em->getRepository(ClaudeSession::class)->findOneBy([
            'recording' => $recording,
            'user' => $user,
            'status' => [ClaudeSessionStatus::Starting, ClaudeSessionStatus::Running],
        ]);

        if ($existing) {
            return $this->json([
                'sessionId' => $existing->getId(),
                'mercureTopic' => 'claude-session/' . $existing->getId(),
            ]);
        }

        $session = new ClaudeSession();
        $session->setRecording($recording);
        $session->setUser($user);
        $session->setStatus(ClaudeSessionStatus::Starting);

        $this->em->persist($session);
        $this->em->flush();

        $this->bus->dispatch(new StartClaudeSessionMessage(
            $session->getId(),
            $recording->getId(),
            $user->getId(),
        ));

        return $this->json([
            'sessionId' => $session->getId(),
            'mercureTopic' => 'claude-session/' . $session->getId(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/claude-sessions/{id}/input', name: 'api_claude_session_input', methods: ['POST'])]
    public function input(ClaudeSession $session, Request $request): JsonResponse
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
        $fifoPath = sys_get_temp_dir() . '/claude-sessions/' . $session->getId() . '/input.fifo';

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

    #[Route('/api/claude-sessions/{id}', name: 'api_claude_session_close', methods: ['DELETE'])]
    public function close(ClaudeSession $session): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$session->getUser()->getId()->equals($user->getId())) {
            throw $this->createAccessDeniedException();
        }

        // Signal the worker to stop by writing a sentinel value
        $fifoPath = sys_get_temp_dir() . '/claude-sessions/' . $session->getId() . '/input.fifo';
        if (file_exists($fifoPath)) {
            $fifo = fopen($fifoPath, 'w');
            if ($fifo) {
                fwrite($fifo, "\x04"); // EOT character signals close
                fclose($fifo);
            }
        }

        $session->setStatus(ClaudeSessionStatus::Closed);
        $session->setClosedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json(['ok' => true]);
    }
}
