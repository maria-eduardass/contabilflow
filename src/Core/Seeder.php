<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Popula dados de demonstração com guias em todas as faixas do semáforo.
 *
 * Por quê: as senhas vêm de fora (.env) e nunca ficam no código; as datas
 * são relativas a $today para o semáforo sempre ter exemplos de cada cor.
 */
final class Seeder
{
    private const MIN_PASSWORD_LENGTH = 10;

    /**
     * [índice da empresa, tipo, dias a partir de hoje, status, descrição]
     * Cores esperadas: -3 preto, +1 e 0 vermelho, +5 e +7 amarelo, +20/+30 verde.
     */
    private const OBRIGACOES = [
        [0, 'DAS', 1, 'pendente', null],
        [0, 'FGTS', -3, 'pendente', null],
        [0, 'INSS', 20, 'pendente', null],
        [1, 'DARF', 5, 'pendente', null],
        [1, 'ISS', 0, 'enviada', null],
        [1, 'ICMS', -10, 'paga', null],
        [2, 'DAS', 30, 'pendente', null],
        [2, 'DCTFWEB', 2, 'cancelada', null],
        [2, 'OUTRA', 7, 'pendente', 'Parcelamento de débitos'],
    ];

    private const EMPRESAS = [
        [
            'Padaria Pão Quente Ltda', 'Pão Quente', '11222333000181', 'simples',
            'contato@paoquente.example', '5511987654321',
        ],
        ['Oficina Mecânica Silva ME', null, '11444777000161', 'mei', 'silva@oficina.example', '5521998765432'],
        ['Tech Soluções S.A.', 'TechSol', '45723174000110', 'presumido', null, null],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function run(DateTimeImmutable $today, string $adminPassword, string $contadorPassword): void
    {
        foreach ([$adminPassword, $contadorPassword] as $password) {
            if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
                throw new InvalidArgumentException('Senhas do seed precisam ter ao menos 10 caracteres.');
            }
        }

        $existing = $this->pdo->query('SELECT COUNT(*) FROM usuarios');
        if ($existing !== false && (int) $existing->fetchColumn() > 0) {
            throw new RuntimeException('Seed já aplicado: a tabela usuarios não está vazia.');
        }

        $adminId = $this->insertUser('Administrador', 'admin@contabilflow.local', $adminPassword, 'admin');
        $contadorId = $this->insertUser('Contador Demo', 'contador@contabilflow.local', $contadorPassword, 'contador');

        $empresaIds = [];
        $stmt = $this->pdo->prepare(
            'INSERT INTO empresas (razao_social, nome_fantasia, cnpj, regime, email, whatsapp, responsavel_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (self::EMPRESAS as $empresa) {
            $stmt->execute([...$empresa, $contadorId]);
            $empresaIds[] = (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO obrigacoes (empresa_id, tipo, descricao, competencia, vencimento, valor, status, '
            . 'paga_em, criado_por) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (self::OBRIGACOES as $i => [$empresaIdx, $tipo, $days, $status, $descricao]) {
            $vencimento = $today->modify(sprintf('%+d days', $days));
            $competencia = $vencimento->modify('first day of last month');
            $stmt->execute([
                $empresaIds[$empresaIdx],
                $tipo,
                $descricao,
                $competencia->format('Y-m-01'),
                $vencimento->format('Y-m-d'),
                number_format(150 + $i * 75.5, 2, '.', ''),
                $status,
                $status === 'paga' ? $today->format('Y-m-d 09:00:00') : null,
                $adminId,
            ]);
        }
    }

    private function insertUser(string $nome, string $email, string $password, string $perfil): int
    {
        $this->pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')
            ->execute([$nome, $email, password_hash($password, PASSWORD_DEFAULT), $perfil]);

        return (int) $this->pdo->lastInsertId();
    }
}
