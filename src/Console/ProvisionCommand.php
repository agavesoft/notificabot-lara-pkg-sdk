<?php

namespace Agavesoft\Smartmailto\Console;

use Agavesoft\Smartmailto\Exceptions\SmartmailtoException;
use Agavesoft\Smartmailto\Smartmailto;
use DateTimeInterface;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * F-008 (B3) / F-009: sube el catalogo de variables, bloques, plantillas y workflows de la app a
 * Smartmailto (plantillas como codigo).
 *
 * Convencion del directorio:
 *   variables/{nombre}.(yaml|yml|json)  lista de variables: scope, key, event?, label, type, description,
 *                                       allowed_values, required, default, filterable, sensitive
 *   partials/{nombre}.(html|md)         frontmatter opcional: description
 *   templates/{nombre}.(html|md)        frontmatter: subject (requerido), layout, kind, display_name, description
 *   workflows/{nombre}.(yaml|yml|json)
 *
 * El cuerpo se manda tal cual (Smartmailto no convierte Markdown: `.md` solo es el formato del archivo).
 * F-009 (RN-16): todo viaja en un solo paquete a `POST /api/provision`, que se aplica completo o nada
 * (lo activo sigue enviando si algo falla). Lo nuevo queda en borrador salvo con `--activate`. Los
 * avisos se imprimen y no hacen fallar. Las reglas (tipos, secciones, usos) las valida el servidor:
 * aqui solo se revisa que los archivos se puedan leer.
 */
class ProvisionCommand extends Command
{
    protected $signature = 'smartmailto:provision
        {path : Directorio con variables/, partials/, templates/ y workflows/}
        {--activate : Activa plantillas y workflows en la misma operacion (mismas validaciones que el panel)}
        {--validate : Valida el paquete contra Smartmailto sin guardar nada (para CI)}
        {--dry-run : Solo lee los archivos; no llama a Smartmailto}';

    protected $description = 'Sube el catalogo de variables, bloques, plantillas y workflows a Smartmailto (todo o nada)';

    /** Mismas reglas de nombre que el servidor (no se normaliza). */
    private const NAME = '/^[a-z0-9][a-z0-9_-]{0,99}$/';

    private const PARTIAL_NAME = '/^[a-z0-9_-]{1,64}$/';

    private const TEMPLATE_KEYS = ['subject', 'layout', 'kind', 'display_name', 'description'];

    /** F-009: identidad de la variable (la ficha la valida el servidor). */
    private const VARIABLE_KEY = '/^[a-z][a-z0-9_]{0,63}$/';

    private const VARIABLE_SCOPES = ['contact', 'event', 'secret'];

    private const VARIABLE_FIELDS = ['scope', 'key', 'event', 'label', 'type', 'description', 'allowed_values', 'required', 'default', 'filterable', 'sensitive'];

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function handle(Smartmailto $smartmailto): int
    {
        $path = rtrim((string) $this->argument('path'), '/\\');
        if (! is_dir($path)) {
            $this->error("No existe el directorio {$path}.");

            return self::FAILURE;
        }

        try {
            $items = [
                ...$this->variables($path),
                ...$this->partials($path),
                ...$this->templates($path),
                ...$this->workflows($path),
            ];
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($items === []) {
            $this->warn("Nada que subir en {$path} (variables/, partials/, templates/, workflows/).");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(['Tipo', 'Nombre', 'Archivo', 'Detalle'], array_map(fn ($item) => [$item['type'], $item['name'], $item['file'], $item['detail']], $items));
            $this->info('Dry run: '.count($items).' elemento(s) validos; no se llamo a Smartmailto.');

            return self::SUCCESS;
        }

        if (! $smartmailto->enabled()) {
            $this->error('Smartmailto esta deshabilitado (SMARTMAILTO_ENABLED=false).');

            return self::FAILURE;
        }

        $package = ['variables' => [], 'partials' => [], 'templates' => [], 'workflows' => []];
        foreach ($items as $item) {
            $package[$item['section']][] = $item['payload'];
        }
        $package = array_filter($package);
        $activate = (bool) $this->option('activate');

        try {
            $response = $this->option('validate')
                ? $smartmailto->validatePackage($package, $activate)
                : $smartmailto->provisionPackage($package, $activate);
        } catch (SmartmailtoException $e) {
            $this->printWarnings($e->warnings());

            if ($e->items() === []) {
                $this->error($e->getMessage().($e->response !== [] ? ' '.json_encode($e->response, self::JSON_FLAGS) : ''));
                if ($e->transient) {
                    // Sin respuesta (timeout) el servidor pudo aplicar el paquete: es idempotente, repetirlo es seguro.
                    $this->line('No se sabe si se aplico: vuelve a correrlo (es idempotente).');
                }

                return self::FAILURE;
            }

            $this->error("Smartmailto rechazo el paquete ({$e->error()}); no se aplico nada:");
            foreach ($e->items() as $failed) {
                $this->error('  '.$this->describe($failed));
            }

            return self::FAILURE;
        }

        $response ??= [];
        $this->printWarnings($response['warnings'] ?? []);

        if ($this->option('validate')) {
            return $this->validated($response, count($items));
        }

        foreach ($response['results'] ?? [] as $result) {
            $this->line(trim(($result['type'] ?? '').' '.($result['name'] ?? '')).': '.($result['result'] ?? '?').(isset($result['status']) ? " ({$result['status']})" : ''));
        }
        $this->info(count($items).' elemento(s) aprovisionados en un solo paquete.');

        foreach ($response['results'] ?? [] as $result) {
            $name = ($result['type'] ?? '').' '.($result['name'] ?? '');
            // El paquete no dice si estaba activo: un workflow cambiado queda inactivo y deja de inscribir contactos.
            if (! $activate && ($result['type'] ?? null) === 'workflow' && ($result['result'] ?? null) === 'updated' && ($result['status'] ?? null) === 'inactive') {
                $this->warn("{$name} quedo inactivo: si estaba activo ya no inscribe contactos hasta activarlo (--activate o el panel).");
            }
            if (($result['status'] ?? null) === 'paused') {
                $this->warn("{$name} esta pausado por quejas: se reactiva en el panel.");
            }
        }

        $pending = array_filter($response['results'] ?? [], fn ($result) => in_array($result['status'] ?? null, ['draft', 'inactive'], true));
        if (! $activate && $pending !== []) {
            $this->line('Lo nuevo o cambiado quedo sin activar: vuelve a correrlo con --activate (o activalo en el panel).');
        }

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $response  `{ valid, errors, warnings, results }` (200 aunque no sea valido) */
    private function validated(array $response, int $count): int
    {
        if ($response['valid'] ?? false) {
            $this->info("Paquete valido: {$count} elemento(s); no se guardo nada.");

            return self::SUCCESS;
        }

        $this->error('El paquete no es valido; no se guardo nada:');
        foreach ($response['errors'] ?? [] as $failed) {
            $this->error('  '.$this->describe($failed));
        }

        return self::FAILURE;
    }

    /** @param  list<array<string, mixed>>  $warnings */
    private function printWarnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            $this->warn('aviso '.$this->describe($warning));
        }
    }

    /** `{type, name, code|error, ref, location, message, errors, usages}` en una linea. */
    private function describe(array $item): string
    {
        $parts = array_filter([
            $item['code'] ?? $item['error'] ?? null,
            $item['ref'] ?? null,
            isset($item['location']) ? "en {$item['location']}" : null,
            $item['message'] ?? null,
            ! empty($item['errors']) ? json_encode($item['errors'], self::JSON_FLAGS) : null,
            ! empty($item['usages']) ? 'usos: '.json_encode($item['usages'], self::JSON_FLAGS) : null,
        ], fn ($part) => is_string($part) && $part !== '');

        return trim(($item['type'] ?? '').' '.($item['name'] ?? '')).': '.implode(' ', $parts);
    }

    /**
     * F-009: variables del catalogo. Cada archivo es una lista; el orden es por archivo y luego el del
     * archivo. Una variable repetida (misma seccion, evento y clave) falla antes de llamar.
     *
     * @return list<array<string, mixed>>
     */
    private function variables(string $path): array
    {
        $items = [];
        $seen = [];
        foreach ($this->files($path.'/variables', ['yaml', 'yml', 'json']) as $file) {
            foreach ($this->variableEntries($file) as $index => $entry) {
                $where = "{$file} (variable ".($index + 1).')';
                $entry = $this->assertVariable($entry, $where);
                $ref = "{$entry['scope']}:{$entry['key']}".(isset($entry['event']) ? "@{$entry['event']}" : '');
                if (isset($seen[$ref])) {
                    throw new InvalidArgumentException("Variable repetida `{$ref}`: {$seen[$ref]} y {$where}.");
                }
                $seen[$ref] = $where;

                $items[] = [
                    'type' => 'variable', 'name' => $ref, 'file' => $file,
                    'detail' => is_string($entry['type'] ?? null) ? $entry['type'] : 'tipo sin cambio',
                    'section' => 'variables', 'payload' => $entry,
                ];
            }
        }

        return $items;
    }

    /** @return list<mixed> */
    private function variableEntries(string $file): array
    {
        $content = $this->read($file);
        $this->assertBody($content, $file);

        if (str_ends_with(strtolower($file), '.json')) {
            $entries = json_decode($content, true);
            if (! is_array($entries)) {
                throw new InvalidArgumentException("{$file}: JSON invalido.");
            }
        } else {
            try {
                // PARSE_DATETIME para detectar fechas sin comillas: sin el, `2026-01-01` llega como entero.
                $entries = Yaml::parse($content, Yaml::PARSE_DATETIME | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            } catch (ParseException $e) {
                throw new InvalidArgumentException("{$file}: YAML invalido: {$e->getMessage()}");
            }
        }

        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new InvalidArgumentException("{$file}: se espera una lista de variables (`- scope: contact` ...).");
        }

        return $entries;
    }

    /** @return array<string, mixed> */
    private function assertVariable(mixed $entry, string $where): array
    {
        if (! is_array($entry) || array_is_list($entry)) {
            throw new InvalidArgumentException("{$where}: cada variable es un mapa `llave: valor`.");
        }

        foreach ($entry as $field => $value) {
            if (! in_array($field, self::VARIABLE_FIELDS, true)) {
                throw new InvalidArgumentException("{$where}: llave `{$field}` no soportada (validas: ".implode(', ', self::VARIABLE_FIELDS).').');
            }
            $leaves = is_array($value) ? $value : [$value];
            array_walk_recursive($leaves, function ($leaf) use ($where, $field) {
                if ($leaf instanceof DateTimeInterface) {
                    throw new InvalidArgumentException("{$where}: `{$field}` trae una fecha sin comillas; escribela como texto ('2026-01-01').");
                }
            });
        }

        $scope = $entry['scope'] ?? null;
        if (! is_string($scope) || ! in_array($scope, self::VARIABLE_SCOPES, true)) {
            throw new InvalidArgumentException("{$where}: `scope` debe ser ".implode(', ', self::VARIABLE_SCOPES).'.');
        }

        $key = $entry['key'] ?? null;
        if (! is_string($key) || ! preg_match(self::VARIABLE_KEY, $key)) {
            throw new InvalidArgumentException("{$where}: `key` debe cumplir ".self::VARIABLE_KEY.' (minusculas, numeros y _).');
        }

        $event = $entry['event'] ?? null;
        if ($event === null) {
            unset($entry['event']);
        } elseif ($scope === 'contact') {
            throw new InvalidArgumentException("{$where}: `event` solo aplica a variables event o secret.");
        } elseif (! is_string($event) || trim($event) === '' || trim($event) !== $event) {
            throw new InvalidArgumentException("{$where}: `event` debe ser el nombre del evento, sin espacios alrededor (o no ponerlo: comun).");
        }

        return $entry;
    }

    /** @return list<array<string, mixed>> */
    private function partials(string $path): array
    {
        $items = [];
        foreach ($this->files($path.'/partials', ['html', 'md']) as $name => $file) {
            $this->assertName($name, self::PARTIAL_NAME, $file);
            [$meta, $body] = $this->frontmatter($this->read($file), $file, ['description']);
            $this->assertBody($body, $file);

            $items[] = [
                'type' => 'partial', 'name' => $name, 'file' => $file, 'detail' => strlen($body).' bytes',
                'section' => 'partials', 'payload' => ['name' => $name, 'body' => $body, ...$meta],
            ];
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function templates(string $path): array
    {
        $items = [];
        foreach ($this->files($path.'/templates', ['html', 'md']) as $name => $file) {
            $this->assertName($name, self::NAME, $file);
            [$meta, $body] = $this->frontmatter($this->read($file), $file, self::TEMPLATE_KEYS);
            $this->assertBody($body, $file);

            if (($meta['subject'] ?? '') === '') {
                throw new InvalidArgumentException("{$file}: falta `subject` en el frontmatter.");
            }
            if (isset($meta['kind']) && ! in_array($meta['kind'], ['marketing', 'transactional'], true)) {
                throw new InvalidArgumentException("{$file}: `kind` debe ser marketing o transactional.");
            }
            if (isset($meta['layout']) && ! in_array($meta['layout'], ['none', 'default'], true)) {
                throw new InvalidArgumentException("{$file}: `layout` debe ser none o default.");
            }

            $items[] = [
                'type' => 'template', 'name' => $name, 'file' => $file,
                'detail' => ($meta['kind'] ?? 'kind sin cambio').', layout '.($meta['layout'] ?? 'sin cambio'),
                // Mismo cuerpo que putTemplate(): solo viaja lo que trae el frontmatter.
                'section' => 'templates', 'payload' => ['name' => $name, 'subject' => $meta['subject'], 'body' => $body, ...$meta],
            ];
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function workflows(string $path): array
    {
        $items = [];
        foreach ($this->files($path.'/workflows', ['yaml', 'yml', 'json']) as $name => $file) {
            $this->assertName($name, self::NAME, $file);
            $definition = $this->read($file);
            $this->assertBody($definition, $file);
            $format = str_ends_with(strtolower($file), '.json') ? 'json' : 'yaml';
            if ($format === 'json' && ! is_array(json_decode($definition, true))) {
                throw new InvalidArgumentException("{$file}: JSON invalido.");
            }

            $items[] = [
                'type' => 'workflow', 'name' => $name, 'file' => $file, 'detail' => "{$format}, se activa con --activate",
                'section' => 'workflows', 'payload' => ['name' => $name, 'definition' => $definition, 'format' => $format],
            ];
        }

        return $items;
    }

    /**
     * Archivos por nombre (sin extension), ordenados: el orden de glob cambia entre sistemas.
     *
     * @param  list<string>  $extensions
     * @return array<string, string>
     */
    private function files(string $directory, array $extensions): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach (scandir($directory) ?: [] as $entry) {
            $file = $directory.'/'.$entry;
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (! is_file($file) || ! in_array($extension, $extensions, true)) {
                continue;
            }

            $name = pathinfo($entry, PATHINFO_FILENAME);
            if (isset($files[$name])) {
                throw new InvalidArgumentException("Nombre repetido `{$name}`: {$files[$name]} y {$file}.");
            }
            $files[$name] = $file;
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /** Sin BOM y con saltos LF: el mismo archivo en Windows y en Linux sube identico (no crea versiones). */
    private function read(string $file): string
    {
        $content = (string) file_get_contents($file);
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    /**
     * Frontmatter plano `llave: valor` entre lineas `---` (sin YAML anidado).
     *
     * @param  list<string>  $allowed
     * @return array{0: array<string, string>, 1: string}
     */
    private function frontmatter(string $content, string $file, array $allowed): array
    {
        // Un archivo que empieza con `---` siempre se lee como frontmatter (tambien vacio: `---\n---`).
        if (! preg_match('/\A---\n(?:(.*?)\n)?---(?:\n|\z)(.*)\z/s', $content, $match)) {
            return [[], trim($content)];
        }

        $meta = [];
        foreach (explode("\n", $match[1]) as $number => $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (! preg_match('/^([a-z_]+)\s*:\s*(.*)$/', trim($line), $pair)) {
                throw new InvalidArgumentException("{$file}: linea ".($number + 2).' del frontmatter invalida (se espera `llave: valor`).');
            }
            if (! in_array($pair[1], $allowed, true)) {
                throw new InvalidArgumentException("{$file}: llave `{$pair[1]}` no soportada (validas: ".implode(', ', $allowed).').');
            }

            $value = trim($pair[2]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $meta[$pair[1]] = $value;
        }

        return [$meta, trim($match[2])];
    }

    private function assertName(string $name, string $pattern, string $file): void
    {
        if (! preg_match($pattern, $name)) {
            throw new InvalidArgumentException("{$file}: el nombre `{$name}` no cumple {$pattern} (minusculas, numeros, - y _).");
        }
    }

    private function assertBody(string $body, string $file): void
    {
        if (trim($body) === '') {
            throw new InvalidArgumentException("{$file}: el contenido esta vacio.");
        }
    }
}
