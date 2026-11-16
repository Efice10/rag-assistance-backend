<?php

use App\Models\User;

beforeEach(function () {
    $this->t = tenants();
    $this->user = User::factory()->in($this->t['procurement'])->manager(3)->create(['email' => 'manager@acme.test']);
});

it('issues a token and returns the user profile on login', function () {
    $response = $this->postJson('/api/login', ['email' => 'manager@acme.test', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('user.role', 'manager')
        ->assertJsonPath('user.clearance_level', 3)
        ->assertJsonPath('user.department.name', 'Procurement')
        ->assertJsonPath('user.organization.slug', 'acme');

    $this->withToken($response->json('token'))
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'manager@acme.test');
});

it('rejects wrong credentials', function () {
    $this->postJson('/api/login', ['email' => 'manager@acme.test', 'password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('revokes the token on logout', function () {
    $token = $this->postJson('/api/login', ['email' => 'manager@acme.test', 'password' => 'password'])->json('token');

    $this->withToken($token)->postJson('/api/logout')->assertOk();

    expect($this->user->tokens()->count())->toBe(0);
});

it('never exposes the password hash', function () {
    $this->postJson('/api/login', ['email' => 'manager@acme.test', 'password' => 'password'])
        ->assertJsonMissingPath('user.password');
});
