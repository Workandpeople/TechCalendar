<?php

use App\Services\Support\ErrorPages;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    config(['app.debug' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('references planning server errors without disclosing raw exception or request details', function (string $routeName) {
    Log::spy();
    $request = Request::create('/planner/book/analyze?secret=PRIVATE', 'POST', [
        'crm_appointment_id' => 'dossier-16767', 'address' => 'PRIVATE ADDRESS', 'token' => 'PRIVATE TOKEN',
    ], server: ['HTTP_ACCEPT' => 'application/json']);
    $route = (new Route('POST', 'planner/book/analyze', fn () => null))->name($routeName);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);
    $response = app(ErrorPages::class)->render(new Response('<html>PRIVATE</html>', 500), new RuntimeException('PRIVATE SQL PASSWORD'), $request);
    $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    expect($response->getStatusCode())->toBe(500)->and(Str::isUuid($data['reference']))->toBeTrue()
        ->and($response->headers->get('X-Error-Reference'))->toBe($data['reference'])
        ->and($response->headers->get('Content-Type'))->toBe('application/json')
        ->and($response->getContent())->not->toContain('PRIVATE');
    Log::shouldHaveReceived('error')->once()->withArgs(function ($message, $context) use ($data, $routeName) {
        expect($message)->toBe('Planning API request failed.')->and($context['reference'])->toBe($data['reference'])
            ->and($context['operation'])->toBe('dossier-16767')->and($context['route'])->toBe($routeName)
            ->and($context['exception_class'])->toBe(RuntimeException::class)
            ->and(json_encode($context))->not->toContain('PRIVATE', 'actor_email', 'actor_name');

        return true;
    });
})->with(['planner.book.analyze', 'planner.book.calendar-window']);

it('keeps validation authentication and unrelated API errors unchanged', function (int $status, string $name) {
    $request = Request::create('/test', server: ['HTTP_ACCEPT' => 'application/json']);
    $request->setRouteResolver(fn () => (new Route('GET', 'test', fn () => null))->name($name));
    $response = new Response('{"message":"original"}', $status, ['Content-Type' => 'application/json']);
    app(ErrorPages::class)->render($response, new RuntimeException('fixture'), $request);
    expect($response->getContent())->toBe('{"message":"original"}')
        ->and($response->headers->has('X-Error-Reference'))->toBeFalse();
})->with([
    [401, 'planner.book.analyze'], [403, 'planner.book.analyze'], [419, 'planner.book.analyze'],
    [422, 'planner.book.analyze'], [500, 'unrelated.api'],
]);
