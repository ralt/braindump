<?php

namespace App\Service\AiChatClient;

use App\Entity\User;
use App\Service\ApiKeyEncryptorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AiChatClientFactory
{
    /**
     * Provider → [base URL, default model].
     * Anthropic is special-cased (different request shape).
     */
    private const OPENAI_COMPATIBLE_PROVIDERS = [
        'openai' => ['https://api.openai.com/v1', 'gpt-4o-mini'],
        'groq' => ['https://api.groq.com/openai/v1', 'llama-3.3-70b-versatile'],
        'mistral' => ['https://api.mistral.ai/v1', 'mistral-small-latest'],
        'deepseek' => ['https://api.deepseek.com/v1', 'deepseek-chat'],
        'xai' => ['https://api.x.ai/v1', 'grok-2-latest'],
        'openrouter' => ['https://openrouter.ai/api/v1', 'anthropic/claude-sonnet-4-5'],
        'google' => ['https://generativelanguage.googleapis.com/v1beta/openai', 'gemini-2.5-flash'],
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private ApiKeyEncryptorInterface $encryptor,
    ) {}

    /**
     * @return array{0: AiChatClientInterface, 1: string} the client and the decrypted API key
     */
    public function forUser(User $user): array
    {
        $encrypted = $user->getEncryptedAiApiKey();
        if ($encrypted === null) {
            throw new \RuntimeException('User has no AI provider API key configured.');
        }

        $apiKey = $this->encryptor->decrypt($encrypted);
        $provider = $user->getAiProvider() ?? 'anthropic';

        if ($provider === 'anthropic') {
            return [new AnthropicChatClient($this->httpClient), $apiKey];
        }

        if (!isset(self::OPENAI_COMPATIBLE_PROVIDERS[$provider])) {
            throw new \RuntimeException(sprintf('Unsupported AI provider: %s', $provider));
        }

        [$baseUrl, $model] = self::OPENAI_COMPATIBLE_PROVIDERS[$provider];

        return [new OpenAiCompatibleChatClient($this->httpClient, $baseUrl, $model), $apiKey];
    }
}
