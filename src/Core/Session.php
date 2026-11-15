<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

/**
 * Sessão sobre um array por referência ($_SESSION em produção).
 *
 * Por quê: receber o array (em vez de usar $_SESSION direto) deixa o código
 * testável sem sessão real do PHP. O cookie é HttpOnly (JS não lê), SameSite=Lax
 * (mitiga CSRF) e Secure em HTTPS; use_strict_mode recusa IDs de sessão
 * inventados pelo cliente (session fixation).
 */
final class Session
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array &$data)
    {
        $this->data = &$data;
    }

    public static function start(bool $secure): self
    {
        // Em CLI/testes a saída já começou: seguimos com um $_SESSION em memória.
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_name('cf_session');
            session_set_cookie_params(self::cookieParams($secure));
            session_start();
        }
        $_SESSION ??= [];

        return new self($_SESSION);
    }

    /** @return array{lifetime: int, path: string, secure: bool, httponly: bool, samesite: string} */
    public static function cookieParams(bool $secure): array
    {
        return ['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'];
    }

    /** @param array<string, mixed> $server */
    public static function isHttps(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';

        return is_string($https) && $https !== '' && strtolower($https) !== 'off';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /** Mensagem exibida uma única vez na próxima tela (padrão post-redirect-get). */
    public function flash(string $type, string $message): void
    {
        $queue = $this->data['_flash'] ?? [];
        $queue = is_array($queue) ? $queue : [];
        $queue[] = ['type' => $type, 'message' => $message];
        $this->data['_flash'] = $queue;
    }

    /** @return list<array{type: string, message: string}> lê e apaga as mensagens pendentes */
    public function pullFlash(): array
    {
        $queue = $this->data['_flash'] ?? [];
        unset($this->data['_flash']);

        $messages = [];
        foreach (is_array($queue) ? $queue : [] as $item) {
            if (is_array($item) && is_string($item['type'] ?? null) && is_string($item['message'] ?? null)) {
                $messages[] = ['type' => $item['type'], 'message' => $item['message']];
            }
        }

        return $messages;
    }

    public function clear(): void
    {
        $this->data = [];
    }

    /** Troca o ID da sessão (chamar no login para evitar session fixation). */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Registra atividade e expira a sessão ociosa.
     *
     * @return bool false se a sessão estava expirada (e foi limpa)
     */
    public function touch(int $now, int $idleTimeout): bool
    {
        $last = $this->data['_last_activity'] ?? null;
        $valid = !is_int($last) || $now - $last <= $idleTimeout;

        if (!$valid) {
            $this->clear();
            $this->regenerate();
        }
        $this->data['_last_activity'] = $now;

        return $valid;
    }
}
