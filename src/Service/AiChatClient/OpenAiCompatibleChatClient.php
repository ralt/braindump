<?php

namespace App\Service\AiChatClient;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiCompatibleChatClient implements AiChatClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $model,
    ) {}

    public function streamCompletion(string $apiKey, string $systemPrompt, array $messages): iterable
    {
        $body = [
            'model' => $this->model,
            'stream' => true,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $messages,
            ),
        ];

        $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . '/chat/completions', [
            'headers' => [
                'authorization' => 'Bearer ' . $apiKey,
                'content-type' => 'application/json',
                'accept' => 'text/event-stream',
            ],
            'json' => $body,
            'buffer' => false,
            'timeout' => 300,
        ]);

        $buffer = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isTimeout() || $chunk->isLast()) {
                continue;
            }
            $buffer .= $chunk->getContent();

            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                $delta = $this->extractDelta($event);
                if ($delta !== null && $delta !== '') {
                    yield $delta;
                }
            }
        }
    }

    private function extractDelta(string $event): ?string
    {
        $dataLines = [];
        foreach (explode("\n", $event) as $line) {
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }
        if ($dataLines === []) {
            return null;
        }
        $data = implode("\n", $dataLines);
        if ($data === '[DONE]') {
            return null;
        }
        $payload = json_decode($data, true);
        if (!\is_array($payload)) {
            return null;
        }
        return $payload['choices'][0]['delta']['content'] ?? null;
    }
}
