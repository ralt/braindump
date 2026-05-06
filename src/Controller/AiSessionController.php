<?php

namespace App\Controller;

use App\Entity\AiMessage;
use App\Entity\AiSession;
use App\Entity\Recording;
use App\Entity\User;
use App\Enum\AiMessageRole;
use App\Repository\AiSessionRepository;
use App\Service\AiChatClient\AiChatClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;

class AiSessionController extends AbstractController
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are a writing assistant. The user has recorded speech that has been transcribed. They will ask you to rewrite, edit, restructure, summarize, reformat, or otherwise transform the text. Your only job is to produce rewritten text. Do not answer questions outside the writing task, do not browse the web, do not execute code, and do not perform research. If the user asks for something outside rewriting/editing, briefly redirect them back to the writing task.
        PROMPT;

    public function __construct(
        private EntityManagerInterface $em,
        private HubInterface $hub,
        private AiChatClientFactory $chatClientFactory,
        private AiSessionRepository $sessions,
    ) {}

    #[Route('/api/recordings/{id}/ai-chat/start', name: 'api_ai_chat_start', methods: ['POST'])]
    public function startChat(Recording $recording): JsonResponse
    {
        $this->denyAccessUnlessGranted('RECORDING_AI_SESSION', $recording);

        /** @var User $user */
        $user = $this->getUser();

        if ($user->getEncryptedAiApiKey() === null) {
            return $this->json(['error' => 'AI provider API key not configured'], Response::HTTP_BAD_REQUEST);
        }

        $session = $this->sessions->findOneByRecordingForUser($recording, $user);
        if ($session === null) {
            $session = (new AiSession())
                ->setRecording($recording)
                ->setUser($user);
            $this->em->persist($session);
            $this->em->flush();
        }

        // Render the chat card so the client can swap it in-place. autoFirstMessage
        // carries the transcript so the chat controller fires the first turn on connect.
        $html = $this->renderView('recording/_chat_card.html.twig', [
            'aiSession' => $session,
            'messages' => $session->getMessages(),
            'autoFirstMessage' => $recording->getTranscription() ?? '',
        ]);

        return $this->json([
            'sessionId' => (string) $session->getId(),
            'html' => $html,
        ]);
    }

    #[Route('/api/ai-sessions/{id}/messages', name: 'api_ai_session_message', methods: ['POST'])]
    public function postMessage(AiSession $session, Request $request): Response
    {
        $this->assertSessionOwner($session);

        $data = json_decode($request->getContent(), true);
        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '') {
            return $this->json(['error' => 'Empty message'], Response::HTTP_BAD_REQUEST);
        }

        ignore_user_abort(true);
        @set_time_limit(0);

        $topic = 'ai-session/' . $session->getId();

        // Capture the existing conversation BEFORE adding the new user message,
        // so we can build the provider history without depending on collection state.
        $history = [];
        foreach ($session->getMessages() as $msg) {
            $history[] = [
                'role' => $msg->getRole()->value,
                'content' => $msg->getContent(),
            ];
        }
        $history[] = ['role' => AiMessageRole::User->value, 'content' => $content];

        $userMessage = (new AiMessage())
            ->setRole(AiMessageRole::User)
            ->setContent($content);
        $session->addMessage($userMessage);
        $this->em->persist($userMessage);
        $this->em->flush();

        $this->hub->publish(new Update($topic, json_encode([
            'type' => 'user',
            'messageId' => (string) $userMessage->getId(),
            'content' => $content,
        ])));

        $assistantMessage = (new AiMessage())
            ->setRole(AiMessageRole::Assistant)
            ->setContent('');
        $session->addMessage($assistantMessage);
        $this->em->persist($assistantMessage);
        $this->em->flush();
        $assistantMessageId = (string) $assistantMessage->getId();
        $assistantBuffer = '';

        try {
            [$client, $apiKey] = $this->chatClientFactory->forUser($session->getUser());

            foreach ($client->streamCompletion($apiKey, self::SYSTEM_PROMPT, $history) as $delta) {
                $assistantBuffer .= $delta;
                $this->hub->publish(new Update($topic, json_encode([
                    'type' => 'delta',
                    'messageId' => $assistantMessageId,
                    'content' => $delta,
                ])));
            }
        } catch (\Throwable $e) {
            $this->em->remove($assistantMessage);
            $this->em->flush();
            $this->hub->publish(new Update($topic, json_encode([
                'type' => 'error',
                'message' => 'AI provider error: ' . $e->getMessage(),
            ])));
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        if ($assistantBuffer === '') {
            $this->em->remove($assistantMessage);
        } else {
            $assistantMessage->setContent($assistantBuffer);
        }
        $this->em->flush();

        $this->hub->publish(new Update($topic, json_encode([
            'type' => 'done',
            'messageId' => $assistantMessageId,
        ])));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/ai-sessions/{id}/clear', name: 'api_ai_session_clear', methods: ['POST'])]
    public function clear(AiSession $session): JsonResponse
    {
        $this->assertSessionOwner($session);

        // Drop the whole session (cascades to messages) so the recording page falls
        // back to the "Start AI chat" button on reload — no half-empty placeholder.
        $this->em->remove($session);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    private function assertSessionOwner(AiSession $session): void
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$session->getUser()->getId()->equals($user->getId())) {
            throw $this->createAccessDeniedException();
        }
    }
}
