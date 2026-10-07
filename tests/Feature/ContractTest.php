<?php

// Cuerpos exactos del contrato de ingesta v2 de Smartmailto (F-003). Si el servidor cambia el
// contrato, estas pruebas y tests/Feature/F003 del servidor deben cambiar juntas.

use Agavesoft\Smartmailto\Attachment;
use Agavesoft\Smartmailto\Exceptions\SmartmailtoException;
use Agavesoft\Smartmailto\Facades\Smartmailto;
use Agavesoft\Smartmailto\Identity;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['smartmailto.queue' => false]));

test('track manda identidad, event_id, secrets, object y occurred_at', function () {
    Http::fake(['smartmailto.test/api/track' => Http::response(['id' => 10], 202)]);

    Smartmailto::track(
        'orden_pagada',
        Identity::guest('ana@example.com'),
        ['timbres' => 50, 'total' => 199.0],
        eventId: 'ff:orden_pagada:100',
        secrets: ['activation_url' => 'https://ff.mx/activar?t=abc'],
        object: ['order', 100],
        occurredAt: Carbon::parse('2026-09-01T10:00:00-06:00'),
    );

    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/track'
        && $request->hasHeader('Authorization', 'Bearer mf_live_test')
        && $request->data() === [
            'email' => 'ana@example.com',
            'event' => 'orden_pagada',
            'event_id' => 'ff:orden_pagada:100',
            'properties' => ['timbres' => 50, 'total' => 199.0],
            'secrets' => ['activation_url' => 'https://ff.mx/activar?t=abc'],
            'object' => ['type' => 'order', 'id' => '100'],
            'occurred_at' => '2026-09-01T10:00:00-06:00',
        ]);
});

test('identify de una cuenta con su correo une al invitado', function () {
    Http::fake(['*' => Http::response(['id' => 1], 200)]);

    Smartmailto::identify(Identity::user(7, 'Ana@Example.com '), ['name' => 'Ana']);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/identify')
        && $request->data() === ['user_id' => '7', 'email' => 'Ana@Example.com', 'attributes' => ['name' => 'Ana']]);
});

test('send manda la idempotency key en el cuerpo y en el header', function () {
    Http::fake(['*' => Http::response(['id' => 5], 202)]);

    Smartmailto::send('recibo-compra', Identity::user(7, 'ana@example.com'), ['total' => 100], idempotencyKey: 'ff:recibo:55');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/send')
        && $request->hasHeader('Idempotency-Key', 'ff:recibo:55')
        && $request['idempotency_key'] === 'ff:recibo:55'
        && $request['template'] === 'recibo-compra');
});

test('el lote de carga inicial se parte en grupos de 100 con backfill', function () {
    Http::fake(['*' => Http::response(['results' => []], 207)]);

    $batch = Smartmailto::batch(backfill: true);
    foreach (range(1, 150) as $i) {
        $batch->track('orden_pagada', Identity::guest("p{$i}@example.com"), eventId: "ff:orden_pagada:{$i}");
    }
    $batch->dispatch();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request['backfill'] === true && count($request['items']) === 100 && $request['items'][0]['type'] === 'track');
});

test('track sin event_id y send sin idempotency key se rechazan antes de salir', function () {
    Http::fake();

    expect(fn () => Smartmailto::track('x', Identity::guest('a@example.com')))->toThrow(InvalidArgumentException::class, 'eventId');
    expect(fn () => Smartmailto::send('x', Identity::guest('a@example.com')))->toThrow(InvalidArgumentException::class, 'idempotencyKey');
    Http::assertNothingSent();
});

test('una identidad necesita id o correo', function () {
    expect(fn () => Identity::user(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Identity::guest(' '))->toThrow(InvalidArgumentException::class);
});

test('send con sendBefore manda send_before (F-008)', function () {
    Http::fake(['*' => Http::response(['id' => 5], 202)]);

    Smartmailto::send('recibo', Identity::user(7, 'a@example.com'), [], idempotencyKey: 'ff:recibo:1',
        sendBefore: Carbon::parse('2026-10-05T12:10:00-06:00'));

    Http::assertSent(fn (Request $request) => $request['send_before'] === '2026-10-05T12:10:00-06:00');
});

test('health consulta la salud del proyecto (F-008)', function () {
    Http::fake(['smartmailto.test/api/health' => Http::response(['status' => 'degraded'], 200)]);

    expect(Smartmailto::health())->toBe(['status' => 'degraded']);
});

test('contact consulta por id y forget borra por correo (F-006)', function () {
    Http::fake([
        'smartmailto.test/api/contacts?user_id=7' => Http::response(['id' => 1, 'email' => 'a@example.com'], 200),
        'smartmailto.test/api/contacts?email=*' => Http::response(['erased' => true], 200),
    ]);

    expect(Smartmailto::contact(Identity::user(7, 'a@example.com')))->toBe(['id' => 1, 'email' => 'a@example.com'])
        ->and(Smartmailto::forget(Identity::guest('a@example.com')))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === 'https://smartmailto.test/api/contacts?email=a%40example.com');
});

test('contact y forget de alguien que no existe', function () {
    Http::fake(['*' => Http::response(['error' => 'contact_not_found'], 404)]);

    expect(Smartmailto::contact(Identity::guest('x@example.com')))->toBeNull()
        ->and(Smartmailto::forget(Identity::guest('x@example.com')))->toBeFalse();
});

// F-008 (B3): envio completo para Factura Facilita. Espejo de tests/Feature/F008 del servidor y de
// docs/api-envio-y-aprovisionamiento.md de notificabot-lara-mailflow.

const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
const XML = '<?xml version="1.0"?><cfdi:Comprobante/>';

test('send sin opciones B3 manda exactamente el cuerpo de antes (F-008 B3)', function () {
    Http::fake(['*' => Http::response(['id' => 5], 202)]);

    Smartmailto::send('recibo', Identity::user(7, 'a@example.com'), ['folio' => 'A-9'], 'ff:recibo:9');

    Http::assertSent(fn (Request $request) => $request->data() === [
        'user_id' => '7',
        'email' => 'a@example.com',
        'template' => 'recibo',
        'data' => ['folio' => 'A-9'],
        'idempotency_key' => 'ff:recibo:9',
    ]);
});

test('send con adjuntos, to, cc, bcc, reply_to, from y secrets (F-008 B3)', function () {
    Http::fake(['smartmailto.test/api/send' => Http::response(['id' => 812], 202)]);
    $pdf = tempnam(sys_get_temp_dir(), 'sm');
    file_put_contents($pdf, PDF);

    Smartmailto::send('cfdi', Identity::user(7, 'ana@example.com'), ['folio' => 'A-9'], 'ff:cfdi:9',
        sendBefore: Carbon::parse('2026-10-07T18:30:00Z'),
        attachments: [
            Attachment::fromPath($pdf, 'RFC_SERIEFOLIO.PDF'),
            Attachment::fromData(XML, 'RFC_SERIEFOLIO.xml', 'text/xml'),
        ],
        cc: ['cc@example.com'],
        bcc: [' auditoria@example.com '],
        replyTo: ['email' => 'soporte@facturafacilita.com', 'name' => 'Soporte'],
        to: ['contador@example.com'],
        from: 'soporte@facturafacilita.com',
        secrets: ['reset_url' => 'https://ff.mx/reset?t=abc'],
    );

    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', 'ff:cfdi:9') && $request->data() === [
        'user_id' => '7',
        'email' => 'ana@example.com',
        'template' => 'cfdi',
        'data' => ['folio' => 'A-9'],
        'idempotency_key' => 'ff:cfdi:9',
        'send_before' => '2026-10-07T18:30:00+00:00',
        'to' => ['contador@example.com'],
        'cc' => ['cc@example.com'],
        'bcc' => ['auditoria@example.com'],
        'reply_to' => ['email' => 'soporte@facturafacilita.com', 'name' => 'Soporte'],
        'from' => ['email' => 'soporte@facturafacilita.com'],
        'attachments' => [
            ['filename' => 'RFC_SERIEFOLIO.PDF', 'content' => base64_encode(PDF), 'content_type' => 'application/pdf'],
            ['filename' => 'RFC_SERIEFOLIO.xml', 'content' => base64_encode(XML), 'content_type' => 'text/xml'],
        ],
        'secrets' => ['reset_url' => 'https://ff.mx/reset?t=abc'],
    ]);
});

test('un adjunto puede ser una ruta o un archivo subido (F-008 B3)', function () {
    Http::fake(['*' => Http::response(['id' => 1], 202)]);
    $upload = UploadedFile::fake()->createWithContent('Comprobante.pdf', PDF);
    $path = sys_get_temp_dir().'/smartmailto-'.uniqid().'.xml';
    file_put_contents($path, XML);

    Smartmailto::send('recibo', Identity::guest('a@example.com'), idempotencyKey: 'ff:recibo:1', attachments: [$upload, $path]);

    Http::assertSent(fn (Request $request) => $request['attachments'] === [
        ['filename' => 'Comprobante.pdf', 'content' => base64_encode(PDF), 'content_type' => 'application/pdf'],
        ['filename' => basename($path), 'content' => base64_encode(XML), 'content_type' => 'application/xml'],
    ]);
});

test('los adjuntos fuera del contrato se rechazan antes de salir (F-008 B3)', function () {
    Http::fake();
    $send = fn (array $attachments) => fn () => Smartmailto::send('recibo', Identity::guest('a@example.com'), idempotencyKey: 'k', attachments: $attachments);

    expect($send([Attachment::fromData(str_repeat('a', 7 * 1024 * 1024 - 10), 'a.txt'), Attachment::fromData(str_repeat('b', 11), 'b.txt')]))
        ->toThrow(InvalidArgumentException::class, 'limit')
        ->and($send(array_fill(0, 11, Attachment::fromData(PDF, 'a.pdf'))))->toThrow(InvalidArgumentException::class, 'at most 10')
        ->and(fn () => Attachment::fromData('x', 'malware.exe'))->toThrow(InvalidArgumentException::class, 'not allowed')
        ->and(fn () => Attachment::fromData('x', '../etc/passwd.txt'))->toThrow(InvalidArgumentException::class, 'plain file name')
        ->and(fn () => Attachment::fromData(PDF, 'a.pdf', 'text/plain'))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => Attachment::fromPath('/no/existe.pdf'))->toThrow(InvalidArgumentException::class, 'not readable')
        ->and(fn () => Smartmailto::send('r', Identity::guest('a@example.com'), idempotencyKey: 'k', cc: array_fill(0, 11, 'x@example.com')))
        ->toThrow(InvalidArgumentException::class, 'cc')
        ->and(fn () => Smartmailto::send('r', Identity::guest('a@example.com'), idempotencyKey: 'k', cc: [['email' => 'x@example.com']]))
        ->toThrow(InvalidArgumentException::class, 'list of email strings')
        // Firma del archivo, igual que el servidor: una pagina de error con nombre .pdf no sale.
        ->and(fn () => Attachment::fromData('<html>error</html>', 'factura.pdf'))->toThrow(InvalidArgumentException::class, 'does not look like')
        ->and(fn () => Attachment::fromData("PK\x03\x04", 'a.zip'))->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => Attachment::fromData("a\0b", 'a.xml'))->toThrow(InvalidArgumentException::class, 'does not look like');

    // Un archivo que solo ya rebasa el limite no se carga en memoria.
    $big = sys_get_temp_dir().'/smartmailto-'.uniqid().'.txt';
    file_put_contents($big, 'abc');
    config(['smartmailto.attachments.max_bytes' => 2]);
    expect(fn () => Attachment::fromPath($big))->toThrow(InvalidArgumentException::class, 'over the 2 bytes')
        ->and(fn () => Attachment::fromUpload(UploadedFile::fake()->createWithContent('a.txt', 'abc')))->toThrow(InvalidArgumentException::class, 'over the 2 bytes');

    // El limite es configurable para seguir al del servidor.
    config(['smartmailto.attachments.max_bytes' => 10]);
    expect($send([Attachment::fromData(PDF, 'a.pdf')]))->toThrow(InvalidArgumentException::class, 'limit');

    Http::assertNothingSent();
});

test('con enabled=false send no lee los adjuntos (F-008 B3)', function () {
    config(['smartmailto.enabled' => false]);
    Http::fake();

    expect(Smartmailto::send('r', Identity::guest('a@example.com'), idempotencyKey: 'k', attachments: ['/no/existe.pdf']))->toBeNull();
    Http::assertNothingSent();
});

test('templates lista y putTemplate manda solo lo que se pasa (F-008 B3)', function () {
    Http::fake([
        'smartmailto.test/api/templates/*' => Http::response(['result' => 'created', 'name' => 'recibo'], 201),
        'smartmailto.test/api/templates' => Http::response(['templates' => [['name' => 'recibo', 'checksum' => 'abc']]], 200),
    ]);

    expect(Smartmailto::templates())->toBe([['name' => 'recibo', 'checksum' => 'abc']])
        ->and(Smartmailto::putTemplate('recibo', 'Tu recibo {{data:folio}}', '<p>Hola</p>{{> pie}}', kind: 'transactional'))
        ->toBe(['result' => 'created', 'name' => 'recibo']);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://smartmailto.test/api/templates/recibo'
        && $request->hasHeader('Authorization', 'Bearer mf_live_test')
        && $request->data() === ['subject' => 'Tu recibo {{data:folio}}', 'body' => '<p>Hola</p>{{> pie}}', 'kind' => 'transactional']);
});

test('putPartial y putWorkflow (F-008 B3)', function () {
    Http::fake(['*' => Http::response(['result' => 'unchanged'], 200)]);

    Smartmailto::putPartial('pie', '<p>FF</p>', description: 'Pie');
    Smartmailto::putWorkflow('checkout', "name: checkout\n");
    Smartmailto::putWorkflow('activacion', ['name' => 'activacion', 'steps' => []]);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://smartmailto.test/api/partials/pie'
        && $request->data() === ['body' => '<p>FF</p>', 'description' => 'Pie']);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/workflows/checkout'
        && $request->data() === ['definition' => "name: checkout\n", 'format' => 'yaml']);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/workflows/activacion'
        && $request->data() === ['definition' => ['name' => 'activacion', 'steps' => []]]);
});

test('aprovisionamiento apagado en el proyecto se rechaza sin reintento (F-008 B3)', function () {
    Http::fake(['*' => Http::response(['error' => 'provisioning_disabled'], 403)]);

    expect(fn () => Smartmailto::putPartial('pie', 'x'))->toThrow(function (SmartmailtoException $e) {
        expect($e->transient)->toBeFalse()->and($e->status)->toBe(403)->and($e->response)->toBe(['error' => 'provisioning_disabled']);
    });
});
