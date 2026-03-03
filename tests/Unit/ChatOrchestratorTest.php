<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Ai\ChatOrchestrator;
use App\Models\User;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use App\Ai\Services\IntentRouterService;
use App\Ai\Services\AgentDispatcherService;
use App\Ai\Services\ResponseMergerService;

class ChatOrchestratorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_returns_unknown_response_when_no_intents_are_resolved()
    {
        $user = User::factory()->make();

        $router = Mockery::mock(IntentRouterService::class);
        $router->shouldReceive('resolve')
            ->once()
            ->with('Hello')
            ->andReturn([]);

        $dispatcher = Mockery::mock(AgentDispatcherService::class);
        $dispatcher->shouldNotReceive('dispatchAll');

        $merger = Mockery::mock(ResponseMergerService::class);
        $merger->shouldReceive('unknownResponse')
            ->once()
            ->andReturn('Unknown domain.');

        $orchestrator = new ChatOrchestrator($router, $dispatcher, $merger);

        $result = $orchestrator->handle(
            user: $user,
            message: 'Hello',
            conversationId: null,
        );

        $this->assertEquals('Unknown domain.', $result['reply']);
        $this->assertNull($result['conversation_id']);
    }

    /** @test */
    public function it_dispatches_to_agent_and_returns_single_response()
    {
        $user = User::factory()->make();

        $router = Mockery::mock(IntentRouterService::class);
        $router->shouldReceive('resolve')
            ->once()
            ->andReturn(['invoice']);

        $dispatcher = Mockery::mock(AgentDispatcherService::class);
        $dispatcher->shouldReceive('dispatchAll')
            ->once()
            ->andReturn([
                [
                    'reply' => 'Invoice created.',
                    'conversation_id' => 'abc123',
                ]
            ]);

        $merger = Mockery::mock(ResponseMergerService::class);
        $merger->shouldReceive('merge')
            ->once()
            ->with(['Invoice created.'])
            ->andReturn('Invoice created.');

        $orchestrator = new ChatOrchestrator($router, $dispatcher, $merger);

        $result = $orchestrator->handle(
            user: $user,
            message: 'Create invoice',
            conversationId: null,
        );

        $this->assertEquals('Invoice created.', $result['reply']);
        $this->assertEquals('abc123', $result['conversation_id']);
    }

    /** @test */
    public function it_merges_multiple_agent_responses()
    {
        $user = User::factory()->make();

        $router = Mockery::mock(IntentRouterService::class);
        $router->shouldReceive('resolve')
            ->once()
            ->andReturn(['invoice', 'client']);

        $dispatcher = Mockery::mock(AgentDispatcherService::class);
        $dispatcher->shouldReceive('dispatchAll')
            ->once()
            ->andReturn([
                [
                    'reply' => 'Invoice created.',
                    'conversation_id' => 'xyz789',
                ],
                [
                    'reply' => 'Client updated.',
                    'conversation_id' => 'xyz789',
                ]
            ]);

        $merger = Mockery::mock(ResponseMergerService::class);
        $merger->shouldReceive('merge')
            ->once()
            ->with(['Invoice created.', 'Client updated.'])
            ->andReturn('Invoice created. Client updated.');

        $orchestrator = new ChatOrchestrator($router, $dispatcher, $merger);

        $result = $orchestrator->handle(
            user: $user,
            message: 'Create invoice and update client',
            conversationId: null,
        );

        $this->assertEquals('Invoice created. Client updated.', $result['reply']);
        $this->assertEquals('xyz789', $result['conversation_id']);
    }

    /** @test */
    public function it_preserves_existing_conversation_id()
    {
        $user = User::factory()->make();

        $router = Mockery::mock(IntentRouterService::class);
        $router->shouldReceive('resolve')
            ->once()
            ->andReturn(['invoice']);

        $dispatcher = Mockery::mock(AgentDispatcherService::class);
        $dispatcher->shouldReceive('dispatchAll')
            ->once()
            ->andReturn([
                [
                    'reply' => 'Invoice updated.',
                    'conversation_id' => 'should_not_override',
                ]
            ]);

        $merger = Mockery::mock(ResponseMergerService::class);
        $merger->shouldReceive('merge')
            ->once()
            ->andReturn('Invoice updated.');

        $orchestrator = new ChatOrchestrator($router, $dispatcher, $merger);

        $result = $orchestrator->handle(
            user: $user,
            message: 'Update invoice',
            conversationId: 'existing123',
        );

        $this->assertEquals('existing123', $result['conversation_id']);
        $this->assertEquals('Invoice updated.', $result['reply']);
    }
}
