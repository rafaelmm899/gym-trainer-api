<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Fixtures\Exceptions\StubDomainException;
use Tests\Fixtures\Exceptions\StubQuotaException;

beforeEach(function () {
    $this->renderer = app(ApiExceptionRenderer::class);
});

it('normalizes a ValidationException with its field errors under data', function () {
    $e = ValidationException::withMessages(['email' => ['The email field is required.']]);

    $response = $this->renderer->render($e);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))->toBe([
            'data' => [
                'code' => 'VALIDATION_EXCEPTION',
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ],
        ]);
});

it('normalizes an AuthenticationException without an errors key', function () {
    $response = $this->renderer->render(new AuthenticationException('Unauthenticated.'));

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true))->toBe([
            'data' => ['code' => 'AUTHENTICATION_EXCEPTION', 'message' => 'Unauthenticated.'],
        ]);
});

it('normalizes an AuthorizationException', function () {
    $response = $this->renderer->render(new AuthorizationException('This action is unauthorized.'));

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['data']['code'])->toBe('AUTHORIZATION_EXCEPTION');
});

it('does not leak the model class from a ModelNotFoundException', function () {
    $e = (new ModelNotFoundException)->setModel(User::class, [1]);

    $response = $this->renderer->render($e);

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true))->toBe([
            'data' => ['code' => 'NOT_FOUND_EXCEPTION', 'message' => 'Resource not found.'],
        ])
        ->and($response->getContent())->not->toContain('User')
        ->and($response->getContent())->not->toContain('App');
});

it('normalizes a NotFoundHttpException', function () {
    $response = $this->renderer->render(new NotFoundHttpException);

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true))->toBe([
            'data' => ['code' => 'NOT_FOUND_EXCEPTION', 'message' => 'Resource not found.'],
        ]);
});

it('normalizes a MethodNotAllowedHttpException', function () {
    $response = $this->renderer->render(new MethodNotAllowedHttpException(['GET']));

    expect($response->getStatusCode())->toBe(405)
        ->and($response->getData(true)['data']['code'])->toBe('METHOD_NOT_ALLOWED_EXCEPTION');
});

it('normalizes a TokenMismatchException', function () {
    $response = $this->renderer->render(new TokenMismatchException('CSRF token mismatch.'));

    expect($response->getStatusCode())->toBe(419)
        ->and($response->getData(true)['data']['code'])->toBe('CSRF_TOKEN_MISMATCH');
});

it('preserves rate-limit headers from a ThrottleRequestsException', function () {
    $e = new ThrottleRequestsException('Too Many Attempts.', null, [
        'Retry-After' => 60,
        'X-RateLimit-Limit' => 6,
    ]);

    $response = $this->renderer->render($e);

    expect($response->getStatusCode())->toBe(429)
        ->and($response->getData(true)['data']['code'])->toBe('RATE_LIMIT_EXCEPTION')
        ->and($response->headers->get('Retry-After'))->toBe('60')
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('6');
});

it('falls back to HTTP_EXCEPTION for an unmapped HttpException status', function () {
    $response = $this->renderer->render(new HttpException(503, 'Down for maintenance.'));

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getData(true))->toBe([
            'data' => ['code' => 'HTTP_EXCEPTION', 'message' => 'Down for maintenance.'],
        ]);
});

it('renders a base DomainException with its defaults', function () {
    $response = $this->renderer->render(new StubDomainException('This routine is archived.'));

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true))->toBe([
            'data' => ['code' => 'DOMAIN_EXCEPTION', 'message' => 'This routine is archived.'],
        ]);
});

it('lets a DomainException subclass override code and status', function () {
    $response = $this->renderer->render(new StubQuotaException('You already have an active routine.'));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))->toBe([
            'data' => ['code' => 'STUB_QUOTA', 'message' => 'You already have an active routine.'],
        ]);
});

it('masks an unhandled throwable when app.debug is off', function () {
    config(['app.debug' => false]);

    $response = $this->renderer->render(new RuntimeException('Redis connection refused at 10.0.0.5.'));

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getData(true))->toBe([
            'data' => ['code' => 'SERVER_EXCEPTION', 'message' => 'Server error.'],
        ])
        ->and($response->getContent())->not->toContain('Redis')
        ->and($response->getContent())->not->toContain('10.0.0.5');
});

it('still wraps an unhandled throwable when app.debug is on, with debug detail', function () {
    config(['app.debug' => true]);

    $response = $this->renderer->render(new RuntimeException('boom'));

    expect($response->getStatusCode())->toBe(500);

    $data = $response->getData(true)['data'];

    expect($data['code'])->toBe('SERVER_EXCEPTION')
        ->and($data['message'])->toBe('boom')
        ->and($data['exception'])->toBe(RuntimeException::class)
        ->and($data)->toHaveKeys(['file', 'line', 'trace']);
});
