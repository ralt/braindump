<?php

namespace App\Controller;

use App\Entity\AiSession;
use App\Entity\Recording;
use App\Entity\User;
use App\Enum\RecordingStatus;
use App\Message\TranscribeRecordingMessage;
use App\Repository\AiSessionRepository;
use App\Repository\RecordingRepository;
use App\Search\SearchProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Message\Content\Audio;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class RecordingController extends AbstractController
{
    public function __construct(
        private RecordingRepository $recordingRepository,
        private AiSessionRepository $aiSessionRepository,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private SearchProviderInterface $searchProvider,
        private Authorization $mercureAuthorization,
        private PlatformInterface $aiPlatform,
        private string $audioStoragePath,
    ) {}

    #[Route('/', name: 'app_recording_index')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $query = $request->query->getString('q');

        if ($query !== '') {
            $recordings = $this->searchProvider->search($query, $user);
        } else {
            $recordings = $this->recordingRepository->findAccessibleByUser($user);
        }

        return $this->render('recording/index.html.twig', [
            'recordings' => $recordings,
            'query' => $query,
        ]);
    }

    #[Route('/recordings/new', name: 'app_recording_new')]
    public function new(): Response
    {
        return $this->render('recording/new.html.twig');
    }

    #[Route('/recordings/{id}', name: 'app_recording_show')]
    public function show(Recording $recording, Request $request): Response
    {
        $this->denyAccessUnlessGranted('RECORDING_VIEW', $recording);

        // Set Mercure authorization cookie for real-time transcription + chat updates
        $this->mercureAuthorization->setCookie($request, ['*']);

        /** @var User|null $user */
        $user = $this->getUser();

        $aiSession = null;
        $autoFirstMessage = null;
        $aiAvailable = $user !== null
            && $user->getEncryptedAiApiKey() !== null
            && $recording->getStatus() === RecordingStatus::Completed
            && $this->isGranted('RECORDING_AI_SESSION', $recording);

        if ($aiAvailable) {
            $aiSession = $this->aiSessionRepository->findOneByRecordingForUser($recording, $user);
            if ($aiSession === null) {
                $aiSession = (new AiSession())
                    ->setRecording($recording)
                    ->setUser($user);
                $this->em->persist($aiSession);
                $this->em->flush();
            }

            if ($aiSession->getMessages()->isEmpty() && $recording->getTranscription()) {
                $autoFirstMessage = $recording->getTranscription();
            }
        }

        return $this->render('recording/show.html.twig', [
            'recording' => $recording,
            'aiSession' => $aiSession,
            'autoFirstMessage' => $autoFirstMessage,
        ]);
    }

    #[Route('/api/recordings', name: 'api_recording_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        /** @var UploadedFile|null $audioFile */
        $audioFile = $request->files->get('audio');
        $title = $request->request->getString('title', 'Untitled');

        if ($audioFile === null) {
            return $this->json(['error' => 'No audio file provided'], Response::HTTP_BAD_REQUEST);
        }

        if ($audioFile->getSize() > 26 * 1024 * 1024) {
            return $this->json(['error' => 'File too large (max 25MB)'], Response::HTTP_BAD_REQUEST);
        }

        $recording = new Recording();
        $recording->setOwner($user);
        $recording->setTitle($title);
        $recording->setMimeType($audioFile->getMimeType() ?? 'audio/webm');
        $recording->setFileSizeBytes($audioFile->getSize());
        $recording->setStatus(RecordingStatus::Pending);

        $filename = $recording->getId() . '.webm';
        $recording->setAudioFilePath($filename);

        $audioFile->move($this->audioStoragePath, $filename);

        $this->em->persist($recording);
        $this->em->flush();

        $this->bus->dispatch(new TranscribeRecordingMessage($recording->getId()));

        return $this->json([
            'id' => $recording->getId(),
            'status' => $recording->getStatus()->value,
            'redirect' => $this->generateUrl('app_recording_show', ['id' => $recording->getId()]),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/recordings/{id}/retry', name: 'api_recording_retry', methods: ['POST'])]
    public function retry(Recording $recording, Request $request): Response
    {
        $this->denyAccessUnlessGranted('RECORDING_EDIT', $recording);

        if (!$this->isCsrfTokenValid('retry', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($recording->getStatus() !== RecordingStatus::Failed) {
            $this->addFlash('error', 'Only failed recordings can be retried.');
            return $this->redirectToRoute('app_recording_show', ['id' => $recording->getId()]);
        }

        $recording->setStatus(RecordingStatus::Pending);
        $recording->setErrorMessage(null);
        $this->em->flush();

        $this->bus->dispatch(new TranscribeRecordingMessage($recording->getId()));

        $this->addFlash('success', 'Transcription retry queued.');
        return $this->redirectToRoute('app_recording_show', ['id' => $recording->getId()]);
    }

    #[Route('/api/recordings/{id}/delete', name: 'api_recording_delete', methods: ['POST'])]
    public function delete(Recording $recording, Request $request): Response
    {
        $this->denyAccessUnlessGranted('RECORDING_DELETE', $recording);

        if (!$this->isCsrfTokenValid('delete-recording', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Delete audio file
        $audioPath = $this->audioStoragePath . '/' . $recording->getAudioFilePath();
        if (file_exists($audioPath)) {
            unlink($audioPath);
        }

        // Remove search index entry
        $this->searchProvider->remove($recording);

        $this->em->remove($recording);
        $this->em->flush();

        $this->addFlash('success', 'Recording deleted.');
        return $this->redirectToRoute('app_recording_index');
    }

    #[Route('/api/recordings/{id}/status', name: 'api_recording_status', methods: ['GET'])]
    public function status(Recording $recording): JsonResponse
    {
        $this->denyAccessUnlessGranted('RECORDING_VIEW', $recording);

        return $this->json([
            'status' => $recording->getStatus()->value,
            'transcription' => $recording->getTranscription(),
            'errorMessage' => $recording->getErrorMessage(),
        ]);
    }

    #[Route('/api/transcribe', name: 'api_transcribe', methods: ['POST'])]
    public function transcribe(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $audioFile */
        $audioFile = $request->files->get('audio');
        if ($audioFile === null) {
            return $this->json(['error' => 'No audio file provided'], Response::HTTP_BAD_REQUEST);
        }

        if ($audioFile->getSize() > 5 * 1024 * 1024) {
            return $this->json(['error' => 'Voice clip too large (max 5MB)'], Response::HTTP_BAD_REQUEST);
        }

        // Whisper resolves the audio format from the file extension; the raw upload tmp path has none.
        $ext = $audioFile->guessExtension() ?: 'webm';
        $tmpDir = sys_get_temp_dir();
        $tmpName = 'transcribe_' . uniqid('', true) . '.' . $ext;
        $audioFile->move($tmpDir, $tmpName);
        $tmpPath = $tmpDir . '/' . $tmpName;

        try {
            $audio = Audio::fromFile($tmpPath);
            $result = $this->aiPlatform->invoke('whisper-1', $audio);
            $text = $result->asText();
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            return $this->json(['error' => 'Transcription failed: ' . $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        @unlink($tmpPath);
        return $this->json(['text' => $text]);
    }
}
