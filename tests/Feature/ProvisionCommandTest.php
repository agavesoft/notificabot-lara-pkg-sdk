<?php

// F-008 (B3): `smartmailto:provision` sube partials/, templates/ y workflows/ en ese orden.

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
    ]);
}

test('dry-run valida y lista sin llamar a Smartmailto', function () {
    Http::fake();

    $this->artisan('smartmailto:provision', ['path' => fixtureDir(), '--dry-run' => true])
        ->expectsOutputToContain('recibo-compra')
        ->expectsOutputToContain('4 elemento(s) validos')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('sube bloques, plantillas y workflows en orden con el cuerpo exacto', function () {
    Http::fake([
        'smartmailto.test/api/workflows/*' => Http::response(['result' => 'updated', 'name' => 'checkout', 'status' => 'inactive', 'requires_activation' => true, 'deactivated' => true], 200),
        '*' => Http::response(['result' => 'created'], 201),
    ]);

    $this->artisan('smartmailto:provision', ['path' => fixtureDir()])
        ->expectsOutputToContain('workflow checkout: updated (inactivo: activarlo en el panel; estaba activo y se desactivo)')
        ->assertSuccessful();

    $urls = Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();
    expect($urls)->toBe([
        'https://smartmailto.test/api/partials/pie',
        'https://smartmailto.test/api/templates/bienvenida',
        'https://smartmailto.test/api/templates/recibo-compra',
        'https://smartmailto.test/api/workflows/checkout',
    ]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/templates/recibo-compra'
        && $request->data() === [
            'subject' => 'Tu recibo {{data:folio}}',
            'body' => "<p>Hola</p>\n{{> pie}}",
            'layout' => 'default',
            'kind' => 'transactional',
            'display_name' => 'Recibo de compra',
        ]);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/templates/bienvenida'
        && $request->data() === ['subject' => 'Bienvenido', 'body' => '<p>Bienvenido</p>']);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/partials/pie'
        && $request->data() === ['body' => '<p>Factura Facilita</p>']);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/workflows/checkout'
        && $request->data() === ['definition' => "name: checkout\ntrigger: { event: orden_creada }\nsteps: []\n", 'format' => 'yaml']);
});

test('se detiene en el primer rechazo del servidor', function () {
    Http::fake([
        'smartmailto.test/api/partials/*' => Http::response(['error' => 'invalid_partial', 'errors' => ['body' => ['x']]], 422),
        '*' => Http::response(['result' => 'created'], 201),
    ]);

    $this->artisan('smartmailto:provision', ['path' => fixtureDir()])
        ->expectsOutputToContain('invalid_partial')
        ->assertFailed();

    Http::assertSentCount(1);
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
