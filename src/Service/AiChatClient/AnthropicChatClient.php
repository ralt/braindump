<?php

namespace App\Service\AiChatClient;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AnthropicChatClient implements AiChatClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $model = 'claude-sonnet-4-5',
    ) {}

    public function streamCompletion(string $apiKey, string $systemPrompt, array $messages): iterable
    {
        $response = $this->httpClient->request('POST', 'https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
                'accept' => 'text/event-stream',
            ],
            'json' => [
                'model' => $this->model,
                'max_tokens' => 4096,
                'system' => $systemPrompt,
                'messages' => $messages,
                'stream' => true,
            ],
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
        $payload = json_decode(implode("\n", $dataLines), true);
        if (!\is_array($payload)) {
            return null;
        }
        if (($payload['type'] ?? '') !== 'content_block_delta') {
            return null;
        }
        $delta = $payload['delta'] ?? [];
        if (($delta['type'] ?? '') !== 'text_delta') {
            return null;
        }
        return $delta['text'] ?? null;
    }
}
