<?php

namespace App\Controller;

use App\Entity\Recording;
use App\Entity\User;
use App\Enum\RecordingStatus;
use App\Message\TranscribeRecordingMessage;
use App\Repository\AiSessionRepository;
use App\Repository\RecordingRepository;
use App\Repository\SkillRepository;
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
    private const int MAX_AUDIO_BYTES = 24 * 1024 * 1024;

    /** A day, well above what the size cap physically allows — this only rejects garbage. */
    private const int MAX_PLAUSIBLE_DURATION_SECONDS = 86400;

    /**
     * Audio a single user may upload per rolling 24 hours. It's an abuse ceiling, not a product
     * limit: nobody records faster than real time, so a human can't reach it by recording — only
     * by driving the upload endpoint programmatically.
     *
     * Rolling rather than per calendar day, which would let someone spend a full budget either
     * side of midnight and put 48 hours of audio through in a few minutes.
     */
    private const int DAILY_RECORDING_LIMIT_SECONDS = 24 * 3600;

    /**
     * Highest byte rate any realistic speech encoder produces (320 kbps), used to derive the
     * shortest duration a given file size could possibly represent. Deliberately far above the
     * 24 kbps the recorder asks for: this is a floor for charging the quota, and setting it near
     * the expected rate would over-charge anyone whose browser ignored the bitrate hint.
     */
    private const int MAX_PLAUSIBLE_BYTES_PER_SECOND = 40000;

    public function __construct(
        private RecordingRepository $recordingRepository,
        private AiSessionRepository $aiSessionRepository,
        private SkillRepository $skillRepository,
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
        $page = max(1, $request->query->getInt('page', 1));

        if ($query !== '') {
            // Search results stay unpaginated for now — the search backend
            // doesn't support limit/offset cleanly across providers and the
            // result sets are typically small in practice.
            $pager = null;
            $recordings = $this->searchProvider->search($query, $user);
        } else {
            $pager = $this->recordingRepository->paginatedForUser($user, $page);
            $recordings = $pager->items;
        }

        return $this->render('recording/index.html.twig', [
            'recordings' => $recordings,
            'query' => $query,
            'pager' => $pager,
        ]);
    }

    #[Route('/recordings/new', name: 'app_recording_new')]
    public function new(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('recording/new.html.twig', [
            // Only surfaced when it's nearly gone. A permanent readout of a budget nobody can
            // reach by talking would be noise on the one page that should be about recording.
            'dailyRemainingSeconds' => $this->remainingDailySeconds($user),
        ]);
    }

    /** How much of the rolling 24-hour budget the user has left, never negative. */
    private function remainingDailySeconds(User $user): int
    {
        $used = $this->recordingRepository->recordedSecondsSince(
            $user,
            new \DateTimeImmutable('-24 hours'),
            self::MAX_PLAUSIBLE_BYTES_PER_SECOND,
        );

        return max(0, self::DAILY_RECORDING_LIMIT_SECONDS - $used);
    }

    /**
     * What an upload costs against the quota: what the browser claimed, or the shortest length
     * this many bytes could represent, whichever is larger. Charging the reported value alone
     * would make the quota advisory — the field is client-supplied.
     */
    private function chargedSeconds(?int $reportedDuration, int $sizeBytes): int
    {
        return max(
            $reportedDuration ?? 0,
            intdiv($sizeBytes, self::MAX_PLAUSIBLE_BYTES_PER_SECOND),
        );
    }

    #[Route('/recordings/{id}', name: 'app_recording_show')]
    public function show(Recording $recording, Request $request): Response
    {
        $this->denyAccessUnlessGranted('RECORDING_VIEW', $recording);

        // Set Mercure authorization cookie for real-time transcription + chat updates
        $this->mercureAuthorization->setCookie($request, ['*']);

        return $this->render('recording/show.html.twig', $this->statusContext($recording));
    }

    #[Route('/recordings/{id}/status-content', name: 'app_recording_status_content', methods: ['GET'])]
    public function statusContent(Recording $recording): Response
    {
        $this->denyAccessUnlessGranted('RECORDING_VIEW', $recording);

        return $this->render('recording/_status_content.html.twig', $this->statusContext($recording));
    }

    /** @return array<string, mixed> */
    private function statusContext(Recording $recording): array
    {
        /** @var User|null $user */
        $user = $this->getUser();

        $aiAvailable = $user !== null
            && $user->getEncryptedAiApiKey() !== null
            && $recording->getStatus() === RecordingStatus::Completed
            && $this->isGranted('RECORDING_AI_SESSION', $recording);

        $aiSession = $aiAvailable
            ? $this->aiSessionRepository->findOneByRecordingForUser($recording, $user)
            : null;

        // An empty session only exists right after the user clicked "Start AI chat" —
        // hand the transcript to the JS controller so it auto-fires the first turn.
        $autoFirstMessage = ($aiSession !== null && $aiSession->getMessages()->isEmpty())
            ? ($recording->getTranscription() ?? '')
            : '';

        $activeSkillIds = [];
        if ($aiSession !== null) {
            foreach ($aiSession->getActiveSkills() as $skill) {
                $activeSkillIds[] = (string) $skill->getId();
            }
        }

        return [
            'recording' => $recording,
            // The file is the source of truth rather than a column: the transcription worker
            // deletes it on success, and nothing writes back to say so.
            'audioAvailable' => is_file($this->audioStoragePath . '/' . $recording->getAudioFilePath()),
            'aiAvailable' => $aiAvailable,
            'aiSession' => $aiSession,
            'autoFirstMessage' => $autoFirstMessage,
            'skills' => $user !== null ? $this->skillRepository->findByUser($user) : [],
            'activeSkillIds' => $activeSkillIds,
        ];
    }

    #[Route('/api/recordings', name: 'api_recording_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        /** @var UploadedFile|null $audioFile */
        $audioFile = $request->files->get('audio');
        // Empty title is preserved as "" — the transcription handler may auto-title
        // post-Whisper, and templates render "Untitled" as a fallback for display.
        $title = trim($request->request->getString('title', ''));

        if ($audioFile === null) {
            return $this->json(['error' => 'No audio file provided'], Response::HTTP_BAD_REQUEST);
        }

        // OpenAI's transcription endpoint rejects requests over 25 MB. Cap a MiB below that
        // so the multipart envelope we wrap the file in can't push a just-under-the-line
        // upload over the limit at transcription time, hours after the user hit stop.
        if ($audioFile->getSize() > self::MAX_AUDIO_BYTES) {
            return $this->json(['error' => 'File too large (max 24MB)'], Response::HTTP_BAD_REQUEST);
        }

        // Client-reported, so treat it as a hint: keep it only when it's plausible rather
        // than rendering a nonsense length. Left null when absent or out of range.
        $duration = $request->request->getInt('duration');
        $reportedDuration = ($duration > 0 && $duration <= self::MAX_PLAUSIBLE_DURATION_SECONDS) ? $duration : null;

        // Checked before the file is moved into place: over the quota, this upload leaves
        // nothing behind at all.
        $remaining = $this->remainingDailySeconds($user);
        if ($this->chargedSeconds($reportedDuration, $audioFile->getSize()) > $remaining) {
            return $this->json([
                'error' => 'Daily recording limit reached — 24 hours of audio in the last 24 hours. '
                    . 'Uploads resume as your earlier recordings age out of the window.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $recording = new Recording();
        $recording->setOwner($user);
        $recording->setTitle($title);
        $recording->setMimeType($audioFile->getMimeType() ?? 'audio/webm');
        $recording->setFileSizeBytes($audioFile->getSize());
        $recording->setStatus(RecordingStatus::Pending);

        $recording->setDurationSeconds($reportedDuration);

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

    /**
     * Audio is deleted as soon as a transcription succeeds, so this only resolves while a
     * recording is still pending, transcribing, or failed — which is exactly when you'd
     * want the file back to process it elsewhere.
     */
    #[Route('/recordings/{id}/audio', name: 'app_recording_audio', methods: ['GET'])]
    public function audio(Recording $recording): Response
    {
        $this->denyAccessUnlessGranted('RECORDING_VIEW', $recording);

        $audioPath = $this->audioStoragePath . '/' . $recording->getAudioFilePath();
        if (!is_file($audioPath)) {
            throw $this->createNotFoundException('The audio for this recording is no longer stored.');
        }

        $title = $recording->getTitle() !== '' ? $recording->getTitle() : 'recording';
        $downloadName = preg_replace('/[^A-Za-z0-9 _-]+/', '', $title) . '.webm';

        return $this->file($audioPath, $downloadName);
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
            'title' => $recording->getTitle(),
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
