<?php

namespace Agavesoft\Smartmailto\Console;

use Agavesoft\Smartmailto\Exceptions\SmartmailtoException;
use Agavesoft\Smartmailto\Smartmailto;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * F-008 (B3): sube bloques, plantillas y workflows de la app a Smartmailto (plantillas como codigo).
 *
 * Convencion del directorio:
 *   partials/{nombre}.(html|md)     frontmatter opcional: description
 *   templates/{nombre}.(html|md)    frontmatter: subject (requerido), layout, kind, display_name, description
 *   workflows/{nombre}.(yaml|yml|json)
 *
 * El cuerpo se manda tal cual (Smartmailto no convierte Markdown: `.md` solo es el formato del archivo).
 * Orden: bloques, plantillas, workflows (cada uno valida lo que usa). Cada PUT es idempotente, asi que
 * correrlo en cada deploy es seguro. Los workflows quedan inactivos hasta que un admin los active.
 */
class ProvisionCommand extends Command
{
    protected $signature = 'smartmailto:provision
        {path : Directorio con partials/, templates/ y workflows/}
        {--dry-run : Solo lee y valida los archivos; no llama a Smartmailto}';

    protected $description = 'Sube bloques, plantillas y workflows a Smartmailto (idempotente)';

    /** Mismas reglas de nombre que el servidor (no se normaliza). */
    private const NAME = '/^[a-z0-9][a-z0-9_-]{0,99}$/';

    private const PARTIAL_NAME = '/^[a-z0-9_-]{1,64}$/';

    private const TEMPLATE_KEYS = ['subject', 'layout', 'kind', 'display_name', 'description'];

    public function handle(Smartmailto $smartmailto): int
    {
        $path = rtrim((string) $this->argument('path'), '/\\');
        if (! is_dir($path)) {
            $this->error("No existe el directorio {$path}.");

            return self::FAILURE;
        }

        try {
            $items = [
                ...$this->partials($path),
                ...$this->templates($path),
                ...$this->workflows($path),
            ];
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($items === []) {
            $this->warn("Nada que subir en {$path} (partials/, templates/, workflows/).");

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

        foreach ($items as $item) {
            try {
                $response = ($item['put'])($smartmailto);
            } catch (SmartmailtoException $e) {
                // Lo siguiente puede depender de este (una plantilla usa el bloque): se detiene aqui.
                $detail = $e->response !== [] ? ' '.json_encode($e->response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
                $this->error("{$item['type']} {$item['name']}: {$e->getMessage()}{$detail}");

                return self::FAILURE;
            }

            $line = "{$item['type']} {$item['name']}: ".($response['result'] ?? '?');
            if ($item['type'] === 'workflow' && ($response['requires_activation'] ?? false)) {
                $line .= ' (inactivo: activarlo en el panel'.(($response['deactivated'] ?? false) ? '; estaba activo y se desactivo' : '').')';
            }
            $this->line($line);
        }

        $this->info(count($items).' elemento(s) aprovisionados.');

        return self::SUCCESS;
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
                'put' => fn (Smartmailto $s) => $s->putPartial($name, $body, $meta['description'] ?? null),
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
                'put' => fn (Smartmailto $s) => $s->putTemplate(
                    $name, $meta['subject'], $body,
                    layout: $meta['layout'] ?? null, kind: $meta['kind'] ?? null,
                    displayName: $meta['display_name'] ?? null, description: $meta['description'] ?? null,
                ),
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
                'type' => 'workflow', 'name' => $name, 'file' => $file, 'detail' => "{$format}, queda inactivo hasta activarlo en el panel",
                'put' => fn (Smartmailto $s) => $s->putWorkflow($name, $definition, $format),
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
