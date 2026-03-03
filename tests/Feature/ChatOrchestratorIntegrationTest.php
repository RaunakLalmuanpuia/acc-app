<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Ai\ChatOrchestrator;
use App\Ai\Services\IntentRouterService;
use App\Ai\Services\AgentDispatcherService;
use App\Ai\Services\ResponseMergerService;

class ChatOrchestratorIntegrationTest extends TestCase
{
    /** @test */
    public function it_resolves_dependencies_via_container_and_executes_flow()
    {
        $user = User::factory()->make();

        $this->app->bind(IntentRouterService::class, function () {
            return new class extends IntentRouterService {

                public function __construct() {}

                public function resolve(string $message): array
                {
                    return ['invoice'];
                }
            };
        });

        $this->app->bind(AgentDispatcherService::class, function () {
            return new class extends AgentDispatcherService {

                public function __construct() {}

                public function dispatchAll(
                    array $intents,
                    \App\Models\User $user,
                    string $message,
                    ?string $conversationId,
                    array $attachments = []
                ): array {
                    return [
                        [
                            'reply' => 'Invoice processed.',
                            'conversation_id' => 'container123',
                        ]
                    ];
                }
            };
        });

        $this->app->bind(ResponseMergerService::class, function () {
            return new class extends ResponseMergerService {

                public function __construct() {}

                public function merge(array $replies): string
                {
                    return implode(' ', $replies);
                }

                public function unknownResponse(): string
                {
                    return 'Unknown.';
                }
            };
        });

        $orchestrator = app(ChatOrchestrator::class);

        $result = $orchestrator->handle(
            user: $user,
            message: 'Create invoice',
            conversationId: null
        );

        $this->assertEquals('Invoice processed.', $result['reply']);
        $this->assertEquals('container123', $result['conversation_id']);
    }
}
