<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Security;

/** Load separate role credentials outside the document root; no secret in container source. */
final class Secret implements \Stringable
{
    public function __construct(private readonly string $value, private readonly string $file = '') {}
    public function __toString(): string
    {
        if ($this->file === '') return trim($this->value);
        if (!str_starts_with($this->file, '/') || is_link($this->file) || !is_file($this->file) || (fileperms($this->file) & 0077)) throw new \RuntimeException('Search credential file is not protected.');
        $value = file_get_contents($this->file, false, null, 0, 4097);
        if ($value === false || strlen($value) > 4096 || strlen(trim($value)) < 32 || preg_match('/\s/', trim($value))) throw new \RuntimeException('Invalid search credential file.');
        return trim($value);
    }
}
