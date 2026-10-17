<?php
declare(strict_types=1);

namespace RuneRide\Auth;

use PDO;

/** Tout le SQL des comptes est ici, en syntaxe commune à MariaDB et PostgreSQL (? en paramètres). */
final class UserRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(string $email, string $passwordHash): int
    {
        $this->pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute([$email, $passwordHash]);
        return (int) $this->findByEmail($email)['id'];
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $st = $this->pdo->prepare('SELECT id, email, password_hash FROM users WHERE email = ?');
        $st->execute([$email]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, email FROM users WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
}
