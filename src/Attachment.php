<?php

namespace Agavesoft\Smartmailto;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * F-008 (B3): adjunto de `Smartmailto::send()`.
 *
 * El contenido se lee al construirlo (no al entregar): con la cola el job lleva el base64 y no depende
 * de que el archivo siga en disco. El `content_type` sale del parametro o de la extension con la misma
 * tabla que el servidor (nunca de finfo ni del navegador: Smartmailto rechaza un tipo que no corresponde
 * a la extension).
 */
final class Attachment
{
    /** Extension => tipos aceptados por Smartmailto (el primero es el default). Espejo del servidor. */
    public const TYPES = [
        'pdf' => ['application/pdf'],
        'xml' => ['application/xml', 'text/xml'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'csv' => ['text/csv', 'text/plain'],
        'txt' => ['text/plain'],
    ];

    private function __construct(
        public readonly string $filename,
        public readonly string $contents,
        public readonly string $contentType,
    ) {}

    /** Desde un archivo en disco. `$filename` es el nombre que vera el destinatario (default: el del archivo). */
    public static function fromPath(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Smartmailto attachment not readable: {$path}");
        }

        return self::fromData((string) file_get_contents($path), $filename ?? basename($path), $contentType);
    }

    /** Desde el contenido crudo (bytes, no base64), por ejemplo un PDF generado en memoria. */
    public static function fromData(string $contents, string $filename, ?string $contentType = null): self
    {
        if ($contents === '') {
            throw new InvalidArgumentException("Smartmailto attachment {$filename} is empty.");
        }

        return new self(self::validFilename($filename), $contents, self::resolveType($filename, $contentType));
    }

    /** Desde un archivo subido. El tipo sale de la extension del nombre, no del que mando el navegador. */
    public static function fromUpload(UploadedFile $file, ?string $filename = null, ?string $contentType = null): self
    {
        return self::fromData((string) $file->get(), $filename ?? $file->getClientOriginalName(), $contentType);
    }

    /** Un Attachment, un UploadedFile o la ruta de un archivo. */
    public static function from(self|UploadedFile|string $attachment): self
    {
        return match (true) {
            $attachment instanceof self => $attachment,
            $attachment instanceof UploadedFile => self::fromUpload($attachment),
            default => self::fromPath($attachment),
        };
    }

    public function size(): int
    {
        return strlen($this->contents);
    }

    /** @return array{filename: string, content: string, content_type: string} */
    public function toArray(): array
    {
        return ['filename' => $this->filename, 'content' => base64_encode($this->contents), 'content_type' => $this->contentType];
    }

    private static function validFilename(string $filename): string
    {
        // Mismas reglas que el servidor: nombre simple, sin rutas ni caracteres de control.
        if ($filename === '' || mb_strlen($filename) > 150 || preg_match('/[\/\\\\]|\p{Cc}|^\.|\.\./u', $filename) || ! mb_check_encoding($filename, 'UTF-8')) {
            throw new InvalidArgumentException("Smartmailto attachment filename must be a plain file name (max 150): {$filename}");
        }

        return $filename;
    }

    private static function resolveType(string $filename, ?string $contentType): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $accepted = self::TYPES[$extension] ?? null;
        if ($accepted === null) {
            throw new InvalidArgumentException("Smartmailto attachment type .{$extension} not allowed (allowed: ".implode(', ', array_keys(self::TYPES)).').');
        }

        if ($contentType === null || trim($contentType) === '') {
            return $accepted[0];
        }

        $type = strtolower(trim(explode(';', $contentType)[0]));
        if (! in_array($type, $accepted, true)) {
            throw new InvalidArgumentException("Smartmailto attachment content type {$type} does not match .{$extension} (expected ".implode(' or ', $accepted).').');
        }

        return $type;
    }
}
