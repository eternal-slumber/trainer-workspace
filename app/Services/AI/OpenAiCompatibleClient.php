<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

abstract class OpenAiCompatibleClient implements AiClientInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected AiRequestLogger $requestLogger,
    ) {}

    /**
     * @param  list<AiMessage>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options = []): AiResponse
    {
        $this->validateMessages($messages);

        $startedAt = hrtime(true);
        $private = ($options['private'] ?? false) === true;
        $promptPreview = $private ? null : $this->promptPreview($messages);
        $userId = is_numeric($options['user_id'] ?? null) ? (int) $options['user_id'] : null;

        try {
            $this->validateConfiguration();

            $response = $this->pendingRequest()
                ->post('chat/completions', $this->requestPayload($messages, $options))
                ->throw();

            $rawResponse = $response->json();

            if (! is_array($rawResponse)) {
                throw new AiClientException('AI provider returned an invalid JSON response.', outcomeUnknown: false);
            }

            $content = $this->responseContent($rawResponse);

            $model = data_get($rawResponse, 'model', $this->model());
            $usage = data_get($rawResponse, 'usage');
            $durationMs = $this->durationInMilliseconds($startedAt);

            $this->requestLogger->write([
                'user_id' => $userId,
                'provider' => $this->provider(),
                'model' => is_string($model) ? $model : $this->model(),
                'status' => 'success',
                'prompt_preview' => $promptPreview,
                'response_preview' => $private ? null : $this->preview($content),
                'error_message' => null,
                'duration_ms' => $durationMs,
            ]);

            return new AiResponse(
                content: $content,
                model: is_string($model) ? $model : $this->model(),
                provider: $this->provider(),
                raw: $rawResponse,
                usage: is_array($usage) ? $usage : null,
            );
        } catch (Throwable $exception) {
            $durationMs = $this->durationInMilliseconds($startedAt);

            $this->requestLogger->write([
                'user_id' => $userId,
                'provider' => $this->provider(),
                'model' => $this->model(),
                'status' => 'error',
                'prompt_preview' => $promptPreview,
                'response_preview' => null,
                'error_message' => $private ? 'AI request failed.' : $this->safeErrorMessage($exception),
                'duration_ms' => $durationMs,
            ]);

            Log::error('AI provider request failed.', [
                'provider' => $this->provider(),
                'model' => $this->model(),
                'duration_ms' => $durationMs,
                'error' => $private ? $exception::class : $this->safeErrorMessage($exception),
            ]);

            if ($exception instanceof AiClientException) {
                throw $exception;
            }

            throw new AiClientException(
                "Не удалось получить ответ от AI-провайдера {$this->provider()}.",
                previous: $exception,
                outcomeUnknown: ! ($exception instanceof InvalidArgumentException
                    || ($exception instanceof RequestException && $exception->response->status() < 500)),
                safeToRetry: $exception instanceof RequestException && $exception->response->status() === 429,
                retryAfter: $exception instanceof RequestException ? min(3600, max(0,
                    is_numeric($exception->response->header('Retry-After'))
                        ? (int) $exception->response->header('Retry-After')
                        : (int) (strtotime($exception->response->header('Retry-After')) ?: time()) - time())) : 0,
            );
        }
    }

    abstract public function provider(): string;

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        $apiKey = $this->apiKey();

        return $apiKey === '' ? [] : ['Authorization' => "Bearer {$apiKey}"];
    }

    protected function requiresApiKey(): bool
    {
        return false;
    }

    protected function pendingRequest(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl(), '/').'/')
            ->acceptJson()
            ->asJson()
            ->withHeaders($this->headers())
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 10))
            ->timeout($this->requestTimeout());
    }

    /**
     * @param  list<AiMessage>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function requestPayload(array $messages, array $options): array
    {
        $providerOptions = Arr::only($options, [
            'temperature',
            'max_tokens',
            'response_format',
            'seed',
            'stop',
            'top_p',
        ]);

        return [
            'model' => $this->model(),
            'messages' => array_map(
                fn (AiMessage $message): array => $message->toArray(),
                $messages,
            ),
            ...$providerOptions,
        ];
    }

    protected function validateConfiguration(): void
    {
        if ($this->baseUrl() === '') {
            throw new InvalidArgumentException('AI_BASE_URL is not configured.');
        }

        if ($this->model() === '') {
            throw new InvalidArgumentException('AI_MODEL is not configured.');
        }

        if ($this->requiresApiKey() && $this->apiKey() === '') {
            throw new InvalidArgumentException('AI_API_KEY is required for OpenRouter.');
        }
    }

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    protected function responseContent(array $rawResponse): string
    {
        $content = data_get($rawResponse, 'choices.0.message.content', '');

        if (is_array($content)) {
            $content = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $content = trim((string) $content);

        if ($content === '') {
            throw new AiClientException('AI returned empty content.', outcomeUnknown: false);
        }

        return $content;
    }

    /**
     * @param  list<AiMessage>  $messages
     */
    protected function validateMessages(array $messages): void
    {
        if ($messages === []) {
            throw new InvalidArgumentException('At least one AI message is required.');
        }
    }

    /**
     * @param  list<AiMessage>  $messages
     */
    protected function promptPreview(array $messages): string
    {
        $prompt = collect($messages)
            ->map(fn (AiMessage $message): string => "[{$message->role}] {$message->content}")
            ->implode("\n");

        return $this->preview($prompt);
    }

    protected function preview(string $value): string
    {
        return Str::limit($value, (int) ($this->config['preview_length'] ?? 500), '…');
    }

    protected function durationInMilliseconds(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    protected function safeErrorMessage(Throwable $exception): string
    {
        if ($exception instanceof RequestException) {
            return 'HTTP request failed with status '.$exception->response->status().'.';
        }

        if ($exception instanceof ConnectionException) {
            return 'Could not connect to the AI provider.';
        }

        if ($exception instanceof InvalidArgumentException || $exception instanceof AiClientException) {
            return $this->preview($exception->getMessage());
        }

        return 'Unexpected AI client error: '.$exception::class;
    }

    protected function baseUrl(): string
    {
        return trim((string) ($this->config['base_url'] ?? ''));
    }

    protected function model(): string
    {
        return trim((string) ($this->config['model'] ?? ''));
    }

    protected function apiKey(): string
    {
        return trim((string) ($this->config['api_key'] ?? ''));
    }

    protected function requestTimeout(): int
    {
        return (int) data_get(
            $this->config,
            $this->provider().'.timeout',
            $this->config['timeout'] ?? 60,
        );
    }
}
