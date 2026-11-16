<?php

declare(strict_types=1);

namespace ContabilFlow\Validation;

use ContabilFlow\Validation\Rules\Cnpj;
use ContabilFlow\Validation\Rules\Whatsapp;
use LogicException;

/**
 * Validação no servidor, campo a campo.
 *
 * Regras: required, email, minLength:N, maxLength:N, in:a,b,c, date, month (AAAA-MM, devolve
 * o dia 01 como AAAA-MM-01), decimal:casas[,inteiros], cnpj, whatsapp.
 *
 * Por quê:
 * - Só os campos listados em $rules entram em `data` (whitelist): um campo
 *   extra no POST, como "perfil", nunca chega ao banco (mass assignment).
 * - Para cada campo vale o PRIMEIRO erro; os demais só confundiriam o usuário.
 * - Campo opcional vazio pula as outras regras e vira null (NULL no banco).
 * - Regra desconhecida lança LogicException: é erro de programação, não de usuário.
 * - Texto que não é UTF-8 válido é recusado (422) antes de chegar ao banco.
 */
final class Validator
{
    private const DEFAULT_INTEGER_DIGITS = 10;

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $rules ex.: ['cnpj' => ['required', 'cnpj']]
     */
    public function validate(array $input, array $rules): ValidationResult
    {
        $errors = [];
        $data = [];

        foreach ($rules as $field => $fieldRules) {
            $raw = $input[$field] ?? null;
            if ($raw !== null && !is_scalar($raw)) {
                $errors[$field] = 'Valor inválido';
                continue;
            }

            // Bytes fora de UTF-8 nunca chegam ao banco: no modo estrito ele recusaria (erro 500).
            if (!mb_check_encoding((string) $raw, 'UTF-8')) {
                $errors[$field] = 'Texto com caracteres inválidos';
                continue;
            }

            $value = trim((string) $raw);
            if ($value === '' && !in_array('required', $fieldRules, true)) {
                $data[$field] = null;
                continue;
            }

            foreach ($fieldRules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                [$error, $value] = $this->apply($name, $arg, $value);
                if ($error !== null) {
                    $errors[$field] = $error;
                    continue 2;
                }
            }
            $data[$field] = $value;
        }

        return new ValidationResult($errors, $data);
    }

    /** @return array{0: string|null, 1: string} [mensagem de erro, valor possivelmente normalizado] */
    private function apply(string $rule, ?string $arg, string $value): array
    {
        return match ($rule) {
            'required' => [$value === '' ? 'Campo obrigatório' : null, $value],
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                ? [null, strtolower($value)]
                : ['E-mail inválido', $value],
            'minLength' => mb_strlen($value) >= (int) $arg
                ? [null, $value]
                : ["Mínimo de {$arg} caracteres", $value],
            'maxLength' => mb_strlen($value) <= (int) $arg
                ? [null, $value]
                : ["Máximo de {$arg} caracteres", $value],
            'in' => in_array($value, explode(',', (string) $arg), true)
                ? [null, $value]
                : ['Valor não permitido', $value],
            'date' => $this->isDate($value)
                ? [null, $value]
                : ['Data inválida (use AAAA-MM-DD)', $value],
            'month' => preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $m) === 1
                ? [null, "{$m[1]}-{$m[2]}-01"]
                : ['Mês inválido (use AAAA-MM)', $value],
            'decimal' => $this->decimal($value, (string) $arg),
            'cnpj' => ($cnpj = Cnpj::normalize($value)) !== null
                ? [null, $cnpj]
                : ['CNPJ inválido', $value],
            'whatsapp' => ($phone = Whatsapp::normalize($value)) !== null
                ? [null, $phone]
                : ['WhatsApp inválido (informe DDD + número)', $value],
            default => throw new LogicException("Regra de validação desconhecida: {$rule}"),
        };
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Aceita 1234.56 ou 1234,56 (sem separador de milhar, que seria ambíguo) e
     * devolve sempre com ponto e todas as casas, pronto para DECIMAL(12,2).
     *
     * @return array{0: string|null, 1: string}
     */
    private function decimal(string $value, string $arg): array
    {
        $params = array_pad(explode(',', $arg), 2, (string) self::DEFAULT_INTEGER_DIGITS);
        [$places, $integerDigits] = array_map('intval', $params);
        $error = $places === 0
            ? 'Informe um número inteiro'
            : "Informe um valor numérico válido, com até {$places} casas decimais (ex.: 1234,56)";

        if (preg_match('/^(\d+)(?:[.,](\d+))?$/', $value, $m) !== 1) {
            return [$error, $value];
        }

        $integer = ltrim($m[1], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = $m[2] ?? '';

        if (strlen($integer) > $integerDigits || strlen($fraction) > $places) {
            return [$error, $value];
        }

        return [null, $places === 0 ? $integer : $integer . '.' . str_pad($fraction, $places, '0')];
    }
}
