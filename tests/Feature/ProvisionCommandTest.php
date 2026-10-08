<?php

// F-008 (B3) / F-009: `smartmailto:provision` sube variables/, partials/, templates/ y workflows/ en un solo
// paquete a POST /api/provision (todo o nada, RN-16).

use Agavesoft\Smartmailto\Facades\Smartmailto;
use Agavesoft\Smartmailto\Identity;
use Agavesoft\Smartmailto\Jobs\DeliverToSmartmailto;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** @param  array<string, string>  $files  ruta relativa => contenido */
function provisionDir(array $files): string
{
    $dir = sys_get_temp_dir().'/smartmailto-provision-'.uniqid();
    foreach ($files as $path => $content) {
        @mkdir(dirname("{$dir}/{$path}"), 0777, true);
        file_put_contents("{$dir}/{$path}", $content);
    }

    return $dir;
}

function fixtureDir(): string
{
    return provisionDir([
        // CRLF y BOM de un checkout en Windows: se sube igual que en Linux.
        'templates/recibo-compra.md' => "\xEF\xBB\xBF---\r\nsubject: \"Tu recibo {{data:folio}}\"\r\nkind: transactional\r\nlayout: default\r\ndisplay_name: Recibo de compra\r\n---\r\n<p>Hola</p>\r\n{{> pie}}\r\n",
        'templates/bienvenida.html' => "---\nsubject: Bienvenido\n---\n<p>Bienvenido</p>\n",
        'partials/pie.html' => "<p>Factura Facilita</p>\n",
        'workflows/checkout.yaml' => "name: checkout\ntrigger: { event: orden_creada }\nsteps: []\n",
        'workflows/notas.txt' => 'se ignora',
        // F-009: el catalogo. Una variable de evento por evento y una comun (sin `event`).
        'variables/contact.yaml' => "- scope: contact\n  key: plan\n  type: enum\n  allowed_values: [free, pro]\n  description: Plan contratado\n  filterable: true\n",
        'variables/eventos.json' => '[{"scope":"event","key":"orden","event":"orden_creada","type":"string","description":"Folio de la orden"},{"scope":"secret","key":"checkout_url","type":"url","description":"Liga de pago","event":null}]',
    ]);
}

/** Lo que se espera en POST /api/provision para fixtureDir(). */
function fixturePackage(): array
{
    return [
        'variables' => [
            ['scope' => 'contact', 'key' => 'plan', 'type' => 'enum', 'allowed_values' => ['free', 'pro'], 'description' => 'Plan contratado', 'filterable' => true],
            ['scope' => 'event', 'key' => 'orden', 'event' => 'orden_creada', 'type' => 'string', 'description' => 'Folio de la orden'],
            ['scope' => 'secret', 'key' => 'checkout_url', 'type' => 'url', 'description' => 'Liga de pago'],
        ],
        'partials' => [['name' => 'pie', 'body' => '<p>Factura Facilita</p>']],
        'templates' => [
            ['name' => 'bienvenida', 'subject' => 'Bienvenido', 'body' => '<p>Bienvenido</p>'],
            ['name' => 'recibo-compra', 'subject' => 'Tu recibo {{data:folio}}', 'body' => "<p>Hola</p>\n{{> pie}}", 'kind' => 'transactional', 'layout' => 'default', 'display_name' => 'Recibo de compra'],
        ],
        'workflows' => [['name' => 'checkout', 'definition' => "name: checkout\ntrigger: { event: orden_creada }\nsteps: []\n", 'format' => 'yaml']],
    ];
}

test('dry-run valida y lista sin llamar a Smartmailto', function () {
    Http::fake();

    $this->artisan('smartmailto:provision', ['path' => fixtureDir(), '--dry-run' => true])
        ->expectsOutputToContain('recibo-compra')
        ->expectsOutputToContain('event:orden@orden_creada')
        ->expectsOutputToContain('7 elemento(s) validos')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('manda todo en un solo paquete: variables, bloques, plantillas y workflows (F-009 RN-16)', function () {
    Http::fake(['smartmailto.test/api/provision' => Http::response([
        'results' => [
            ['type' => 'variable', 'name' => 'contact:plan', 'result' => 'created', 'status' => null],
            ['type' => 'template', 'name' => 'bienvenida', 'result' => 'created', 'status' => 'draft'],
            ['type' => 'workflow', 'name' => 'checkout', 'result' => 'updated', 'status' => 'inactive'],
        ],
        'warnings' => [['type' => 'template', 'name' => 'recibo-compra', 'code' => 'unknown_variable', 'ref' => 'data:folio', 'location' => 'subject']],
    ], 200)]);

    $this->artisan('smartmailto:provision', ['path' => fixtureDir()])
        ->expectsOutputToContain('aviso template recibo-compra: unknown_variable data:folio en subject')
        ->expectsOutputToContain('template bienvenida: created (draft)')
        ->expectsOutputToContain('--activate')
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://smartmailto.test/api/provision'
        && $request->data() === [...fixturePackage(), 'activate' => false]);
});

test('--activate pide la activacion en el mismo paquete', function () {
    Http::fake(['*' => Http::response(['results' => [['type' => 'workflow', 'name' => 'checkout', 'result' => 'activated', 'status' => 'active']], 'warnings' => []], 200)]);

    $this->artisan('smartmailto:provision', ['path' => fixtureDir(), '--activate' => true])
        ->expectsOutputToContain('workflow checkout: activated (active)')
        ->doesntExpectOutputToContain('quedo sin activar')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->data()['activate'] === true);
});

test('un paquete rechazado imprime todos los items y falla sin aplicar nada (F-009 RN-16)', function () {
    Http::fake(['*' => Http::response([
        'error' => 'provision_failed',
        'items' => [
            ['type' => 'template', 'name' => 'checkout-2', 'code' => 'unknown_variable', 'ref' => 'data:totl', 'location' => 'body'],
            ['type' => 'variable', 'name' => 'contact:plan', 'code' => 'type_locked', 'message' => 'El tipo no cambia con usos.', 'usages' => [['type' => 'template', 'name' => 'bienvenida', 'location' => 'body']]],
        ],
        'warnings' => [['type' => 'variable', 'name' => 'contact:telefono', 'code' => 'sensitive_kept', 'ref' => 'contact:telefono']],
    ], 422)]);

    $this->artisan('smartmailto:provision', ['path' => fixtureDir(), '--activate' => true])
        ->expectsOutputToContain('provision_failed')
        ->expectsOutputToContain('template checkout-2: unknown_variable data:totl en body')
        ->expectsOutputToContain('variable contact:plan: type_locked El tipo no cambia con usos.')
        ->expectsOutputToContain('aviso variable contact:telefono: sensitive_kept')
        ->assertFailed();

    Http::assertSentCount(1);
});

test('un rechazo sin items (aprovisionamiento apagado) muestra el cuerpo', function () {
    Http::fake(['*' => Http::response(['error' => 'provisioning_disabled'], 403)]);

    $this->artisan('smartmailto:provision', ['path' => fixtureDir()])
        ->expectsOutputToContain('provisioning_disabled')
        ->assertFailed();
});

test('--validate usa POST /api/validate y falla si el paquete no es valido (200 con valid=false)', function () {
    Http::fake(['smartmailto.test/api/validate' => Http::response([
        'valid' => false,
        'errors' => [['type' => 'template', 'name' => 'bienvenida', 'code' => 'unknown_variable', 'ref' => 'contact:nombre', 'location' => 'body']],
        'warnings' => [],
        'results' => [],
    ], 200)]);

    $this->artisan('smartmailto:provision', ['path' => fixtureDir(), '--validate' => true, '--activate' => true])
        ->expectsOutputToContain('no es valido')
        ->expectsOutputToContain('template bienvenida: unknown_variable contact:nombre en body')
        ->assertFailed();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/validate'
        && $request->data() === [...fixturePackage(), 'activate' => true]);
});

test('--validate con paquete valido no guarda nada y termina bien', function () {
    Http::fake(['smartmailto.test/api/validate' => Http::response(['valid' => true, 'errors' => [], 'warnings' => [
        ['type' => 'template', 'name' => 'bienvenida', 'code' => 'obsolete_variable', 'ref' => 'data:folio_viejo', 'location' => 'body'],
    ], 'results' => []], 200)]);

    $this->artisan('smartmailto:provision', ['path' => fixtureDir(), '--validate' => true])
        ->expectsOutputToContain('aviso template bienvenida: obsolete_variable')
        ->expectsOutputToContain('Paquete valido')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/api/provision'));
});

test('variables invalidas fallan antes de llamar (F-009)', function (string $content, string $message, string $file = 'variables/a.yaml') {
    Http::fake();

    $this->artisan('smartmailto:provision', ['path' => provisionDir([$file => $content])])
        ->expectsOutputToContain($message)
        ->assertFailed();

    Http::assertNothingSent();
})->with([
    'yaml invalido' => ["- scope: contact\n key: [", 'YAML invalido'],
    'no es lista' => ["scope: contact\nkey: plan\n", 'lista de variables'],
    'scope invalido' => ["- scope: perfil\n  key: plan\n", '`scope`'],
    'clave invalida' => ["- scope: contact\n  key: Plan-Pro\n", '`key`'],
    'event en contact' => ["- scope: contact\n  key: plan\n  event: orden_creada\n", 'solo aplica'],
    'llave desconocida' => ["- scope: contact\n  key: plan\n  sensible: true\n", 'sensible'],
    'fecha sin comillas' => ["- scope: contact\n  key: alta\n  type: date\n  default: 2026-01-01\n", 'comillas'],
    'json invalido' => ['[{nope', 'JSON', 'variables/a.json'],
    'vacio' => ["\n", 'vacio'],
]);

test('una variable repetida entre archivos falla antes de llamar', function () {
    Http::fake();

    $this->artisan('smartmailto:provision', ['path' => provisionDir([
        'variables/a.yaml' => "- scope: event\n  key: orden\n  event: orden_creada\n",
        'variables/b.yml' => "- scope: event\n  key: orden\n  event: orden_creada\n- scope: event\n  key: orden\n  event: orden_pagada\n",
    ])])->expectsOutputToContain('repetida `event:orden@orden_creada`')->assertFailed();

    Http::assertNothingSent();
});

test('una fecha entre comillas viaja como texto', function () {
    Http::fake(['*' => Http::response(['results' => [], 'warnings' => []], 200)]);

    $this->artisan('smartmailto:provision', ['path' => provisionDir(['variables/a.yaml' => "- scope: contact\n  key: alta\n  type: date\n  description: Alta\n  default: '2026-01-01'\n"])])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->data()['variables'][0]['default'] === '2026-01-01');
});

test('archivos invalidos fallan antes de llamar', function (array $files, string $message) {
    Http::fake();

    $this->artisan('smartmailto:provision', ['path' => provisionDir($files)])
        ->expectsOutputToContain($message)
        ->assertFailed();

    Http::assertNothingSent();
})->with([
    'sin subject' => [['templates/a.md' => "---\nkind: transactional\n---\nhola"], 'subject'],
    'kind invalido' => [['templates/a.md' => "---\nsubject: x\nkind: urgente\n---\nhola"], 'kind'],
    'llave desconocida' => [['templates/a.md' => "---\nsubject: x\nsubjet: y\n---\nhola"], 'subjet'],
    'nombre invalido' => [['templates/Recibo Compra.html' => "---\nsubject: x\n---\nhola"], 'no cumple'],
    'nombre repetido' => [['templates/a.md' => "---\nsubject: x\n---\nhola", 'templates/a.html' => "---\nsubject: x\n---\nhola"], 'repetido'],
    'cuerpo vacio' => [['partials/pie.html' => "  \n"], 'vacio'],
    'json invalido' => [['workflows/a.json' => '{nope'], 'JSON'],
]);

test('un frontmatter vacio no se sube como parte del cuerpo', function () {
    Http::fake(['*' => Http::response(['results' => [], 'warnings' => []], 200)]);

    $this->artisan('smartmailto:provision', ['path' => provisionDir(['partials/pie.md' => "---\n---\n<p>FF</p>\n"])])->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->data() === ['partials' => [['name' => 'pie', 'body' => '<p>FF</p>']], 'activate' => false]);
});

test('el comando con el fake registra el paquete sin red (F-009)', function () {
    Http::fake();
    $fake = Smartmailto::fake();

    $this->artisan('smartmailto:provision', ['path' => fixtureDir(), '--activate' => true])->assertSuccessful();

    $fake->assertPackageProvisioned(fn (array $package, bool $activate) => $activate && $package === fixturePackage());
    Http::assertNothingSent();
});

test('un directorio inexistente falla', function () {
    $this->artisan('smartmailto:provision', ['path' => '/no/existe'])->assertFailed();
});

test('con cola el job lleva los adjuntos ya codificados (no depende del archivo)', function () {
    Queue::fake();
    $path = sys_get_temp_dir().'/smartmailto-'.uniqid().'.pdf';
    file_put_contents($path, '%PDF-1.4');

    Smartmailto::send('recibo', Identity::guest('a@example.com'), idempotencyKey: 'ff:recibo:1', attachments: [$path]);
    unlink($path);

    Queue::assertPushed(DeliverToSmartmailto::class, fn ($job) => $job->body['attachments'] === [
        ['filename' => basename($path), 'content' => base64_encode('%PDF-1.4'), 'content_type' => 'application/pdf'],
    ]);
});

test('el fake registra el aprovisionamiento sin red', function () {
    Http::fake();
    $fake = Smartmailto::fake();

    Smartmailto::putTemplate('recibo', 'Asunto', 'Cuerpo', kind: 'transactional');
    Smartmailto::renderedEmail(1);

    $fake->assertProvisioned('templates', 'recibo', fn ($body) => $body['kind'] === 'transactional');
    expect(Smartmailto::templates())->toBe([]);
    Http::assertNothingSent();
});
