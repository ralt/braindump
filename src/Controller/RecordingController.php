<?php

namespace App\Controller;

use App\Entity\Recording;
use App\Entity\User;
use App\Enum\RecordingStatus;
use App\Message\TranscribeRecordingMessage;
use App\Repository\RecordingRepository;
use App\Search\SearchProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Psr\Log\LoggerInterface;

class RecordingController extends AbstractController
{
    public function __construct(
        private RecordingRepository $recordingRepository,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private SearchProviderInterface $searchProvider,
        private string $audioStoragePath,
        private LoggerInterface $logger,
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
    public function show(Recording $recording): Response
    {
        $this->denyAccessUnlessGranted('RECORDING_VIEW', $recording);

        return $this->render('recording/show.html.twig', [
            'recording' => $recording,
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

        $this->logger->info('Moving audio file', [
            'from' => $audioFile->getPathname(),
            'to_dir' => $this->audioStoragePath,
            'filename' => $filename,
            'dir_exists' => is_dir($this->audioStoragePath),
            'dir_writable' => is_writable($this->audioStoragePath),
        ]);

        $audioFile->move($this->audioStoragePath, $filename);

        $targetPath = $this->audioStoragePath . '/' . $filename;
        $this->logger->info('Audio file moved', [
            'target' => $targetPath,
            'exists' => file_exists($targetPath),
            'readable' => is_readable($targetPath),
            'size' => file_exists($targetPath) ? filesize($targetPath) : null,
            'dir_contents' => scandir($this->audioStoragePath),
        ]);

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
}
