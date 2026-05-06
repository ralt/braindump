<?php

namespace App\Service\AiChatClient;

interface AiChatClientInterface
{
    /**
     * Stream a chat completion and yield assistant text deltas as they arrive.
     *
     * @param string $apiKey provider API key (already decrypted)
     * @param string $systemPrompt system instructions
     * @param list<array{role: string, content: string}> $messages conversation history including the latest user message
     * @return iterable<string> text chunks
     */
    public function streamCompletion(string $apiKey, string $systemPrompt, array $messages): iterable;
}
