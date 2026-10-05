<?php

use Agavesoft\Smartmailto\Identity;

test('user con correo serializa ambos y limpia espacios', function () {
    expect(Identity::user(7, ' Ana@Example.com ')->toArray())->toBe(['user_id' => '7', 'email' => 'Ana@Example.com']);
});

test('user sin correo solo manda el id', function () {
    expect(Identity::user('u-1')->toArray())->toBe(['user_id' => 'u-1']);
});

test('guest solo manda el correo', function () {
    expect(Identity::guest('a@example.com')->toArray())->toBe(['email' => 'a@example.com']);
});
