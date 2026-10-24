<?php

it('TC-6-01: GET /api/hello が Hello World のメッセージを JSON で返す', function () {
    $response = $this->getJson('/api/hello');

    $response->assertOk()
        ->assertExactJson(['message' => 'Hello World from Laravel']);
});

it('TC-6-06: GET 以外のメソッドでは 405 を JSON で返す', function () {
    $response = $this->postJson('/api/hello');

    $response->assertStatus(405)
        ->assertHeader('Content-Type', 'application/json');
});
