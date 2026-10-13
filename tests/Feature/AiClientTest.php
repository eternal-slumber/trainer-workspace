<?php

use App\Models\AiRequestLog;
use App\Models\User;
use App\Services\AI\AiClientException;
use App\Services\AI\AiClientInterface;
use App\Services\AI\AiMessage;
use App\Services\AI\LmStudioClient;
use App\Services\AI\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

test('the configured provider is resolved through the AI client contract', function () {
    configureTestAi('openrouter');

    expect(resolveTestAiClient())->toBeInstanceOf(OpenRouterClient::class);

    configureTestAi('lmstudio');

    expect(resolveTestAiClient())->toBeInstanceOf(LmStudioClient::class);
});

test('openrouter sends an openai compatible request and logs its preview', function () {
    $user = User::factory()->create();
    configureTestAi('openrouter');

    Http::fake([
        'https://openrouter.test/v1/chat/completions' => Http::response([
            'id' => 'generation-1',
            'model' => 'provider/model-v2',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Работает',
                ],
            ]],
            'usage' => [
                'prompt_tokens' => 8,
                'completion_tokens' => 1,
                'total_tokens' => 9,
            ],
        ]),
    ]);

    $response = resolveTestAiClient()->chat([
        AiMessage::system('Отвечай кратко.'),
        AiMessage::user('Ответь одним словом: работает'),
    ], [
        'temperature' => 0.2,
        'user_id' => $user->id,
        'unsupported_option' => 'must-not-leak',
    ]);

    expect($response->content)->toBe('Работает')
        ->and($response->model)->toBe('provider/model-v2')
        ->and($response->provider)->toBe('openrouter')
        ->and($response->usage)->toMatchArray(['total_tokens' => 9]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.test/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && $request->hasHeader('HTTP-Referer', 'https://coach.test')
        && $request['model'] === 'provider/model'
        && data_get($request->data(), 'messages.1.role') === 'user'
        && $request['temperature'] === 0.2
        && ! isset($request['user_id'])
        && ! isset($request['unsupported_option']));

    $requestLog = AiRequestLog::query()->sole();

    expect($requestLog->user?->is($user))->toBeTrue()
        ->and($requestLog->status)->toBe('success')
        ->and($requestLog->provider)->toBe('openrouter')
        ->and($requestLog->prompt_preview)->toContain('[system] Отвечай кратко.')
        ->and($requestLog->response_preview)->toBe('Работает')
        ->and($requestLog->error_message)->toBeNull();
});

test('private generation requests never log user inputs or provider content', function () {
    configureTestAi('openrouter');
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Sensitive result']]]])]);
    resolveTestAiClient()->chat([AiMessage::user('Sensitive user context')], ['private' => true]);
    $log = AiRequestLog::query()->sole();
    expect($log->prompt_preview)->toBeNull()->and($log->response_preview)->toBeNull();
});

test('provider failure classification prevents blind retries after unknown outcomes', function (int $status, bool $unknown, bool $retry) {
    configureTestAi('openrouter');
    Http::fake(['*' => Http::response(['error' => 'Private provider body'], $status, ['Retry-After' => '90'])]);
    try {
        resolveTestAiClient()->chat([AiMessage::user('Sensitive user context')], ['private' => true]);
        $this->fail('Expected provider failure');
    } catch (AiClientException $exception) {
        expect($exception->outcomeUnknown)->toBe($unknown)->and($exception->safeToRetry)->toBe($retry)
            ->and(AiRequestLog::query()->sole()->prompt_preview)->toBeNull();
    }
})->with([[429, false, true], [400, false, false], [503, true, false]]);

test('lm studio works without an api key', function () {
    configureTestAi('lmstudio', [
        'base_url' => 'http://host.docker.internal:1234/v1',
        'api_key' => '',
        'model' => 'local-model',
    ]);

    Http::fake([
        'http://host.docker.internal:1234/v1/chat/completions' => Http::response([
            'model' => 'local-model',
            'choices' => [[
                'message' => ['content' => 'Работает локально'],
            ]],
        ]),
    ]);

    $response = resolveTestAiClient()->chat([
        AiMessage::user('Проверка'),
    ]);

    expect($response->provider)->toBe('lmstudio')
        ->and($response->content)->toBe('Работает локально');

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

test('providers use their own timeout without automatic retries', function (
    string $provider,
    int $timeout,
) {
    configureTestAi($provider, [
        $provider => ['timeout' => $timeout],
        'retry_times' => 5,
    ]);

    $client = resolveTestAiClient();
    $pendingRequestMethod = new ReflectionMethod($client, 'pendingRequest');
    $pendingRequest = $pendingRequestMethod->invoke($client);
    $triesProperty = new ReflectionProperty($pendingRequest, 'tries');

    expect($pendingRequest->getOptions())
        ->toMatchArray([
            'timeout' => $timeout,
            'connect_timeout' => 2,
        ])
        ->and($triesProperty->getValue($pendingRequest))->toBe(1);
})->with([
    'LM Studio' => ['lmstudio', 300],
    'OpenRouter' => ['openrouter', 120],
]);

test('array response content is normalized to json', function () {
    configureTestAi('lmstudio', [
        'base_url' => 'http://lmstudio.test/v1',
        'api_key' => '',
    ]);

    Http::fake([
        'http://lmstudio.test/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => ['status' => 'работает'],
                ],
            ]],
        ]),
    ]);

    $response = resolveTestAiClient()->chat([
        AiMessage::user('Проверка'),
    ]);

    expect($response->content)->toBe('{"status":"работает"}');
});

test('empty response content is exposed as a clear exception', function () {
    configureTestAi('lmstudio', [
        'base_url' => 'http://lmstudio.test/v1',
        'api_key' => '',
    ]);

    Http::fake([
        'http://lmstudio.test/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => '',
                    'reasoning_content' => 'Скрытые рассуждения не являются ответом.',
                ],
            ]],
        ]),
    ]);

    expect(fn () => resolveTestAiClient()->chat([
        AiMessage::user('Проверка'),
    ]))->toThrow(AiClientException::class, 'AI returned empty content.');

    expect(AiRequestLog::query()->sole()->error_message)
        ->toBe('AI returned empty content.');
});

test('provider errors are recorded and exposed as a safe exception', function () {
    configureTestAi('openrouter');

    Http::fake([
        'https://openrouter.test/v1/chat/completions' => Http::response([
            'error' => ['message' => 'Upstream secret details'],
        ], 503),
    ]);

    expect(fn () => resolveTestAiClient()->chat([
        AiMessage::user('Проверка ошибки'),
    ]))->toThrow(
        AiClientException::class,
        'Не удалось получить ответ от AI-провайдера openrouter.',
    );

    $requestLog = AiRequestLog::query()->sole();

    expect($requestLog->status)->toBe('error')
        ->and($requestLog->response_preview)->toBeNull()
        ->and($requestLog->error_message)->toBe('HTTP request failed with status 503.')
        ->and($requestLog->error_message)->not->toContain('Upstream secret details');
});

test('ai test command prints the provider response', function () {
    configureTestAi('lmstudio', [
        'base_url' => 'http://lmstudio.test/v1',
        'api_key' => '',
        'model' => 'local-model',
    ]);

    Http::fake([
        'http://lmstudio.test/v1/chat/completions' => Http::response([
            'model' => 'local-model',
            'choices' => [[
                'message' => ['content' => 'Работает'],
            ]],
        ]),
    ]);

    $exitCode = Artisan::call('ai:test');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('lmstudio / local-model')
        ->and($output)->toContain('Работает');
});

test('ai test command handles provider errors without a stack trace', function () {
    configureTestAi('lmstudio', [
        'base_url' => 'http://lmstudio.test/v1',
        'api_key' => '',
    ]);

    Http::fake([
        'http://lmstudio.test/v1/chat/completions' => Http::response([], 500),
    ]);

    $exitCode = Artisan::call('ai:test');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Не удалось получить ответ от AI-провайдера lmstudio.')
        ->and($output)->not->toContain('Stack trace');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function configureTestAi(string $provider, array $overrides = []): void
{
    Config::set('ai', [
        'provider' => $provider,
        'base_url' => 'https://openrouter.test/v1',
        'model' => 'provider/model',
        'api_key' => 'test-key',
        'timeout' => 5,
        'connect_timeout' => 2,
        'retry_times' => 1,
        'retry_sleep_ms' => 0,
        'preview_length' => 500,
        'openrouter' => [
            'referer' => 'https://coach.test',
            'title' => 'Coach Workspace',
        ],
        ...$overrides,
    ]);

    app()->forgetInstance(AiClientInterface::class);
}

function resolveTestAiClient(): AiClientInterface
{
    app()->forgetInstance(AiClientInterface::class);

    return app(AiClientInterface::class);
}
