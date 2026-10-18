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
        $st = $this->pdo->prepare('SELECT id, email, password_hash, google_sub FROM users WHERE email = ?');
        $st->execute([$email]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByGoogleSub(string $sub): ?array
    {
        $st = $this->pdo->prepare('SELECT id, email, password_hash, google_sub FROM users WHERE google_sub = ?');
        $st->execute([$sub]);
        return $st->fetch() ?: null;
    }

    /** Compte sans mot de passe, ouvert par Google (adresse vérifiée par Google). */
    public function createGoogle(string $email, string $sub): int
    {
        $this->pdo->prepare('INSERT INTO users (email, password_hash, google_sub, email_verified_at) VALUES (?, NULL, ?, CURRENT_TIMESTAMP)')->execute([$email, $sub]);
        return (int) $this->findByEmail($email)['id'];
    }

    /**
     * Rattache Google à un compte existant. Si son adresse n'a jamais été vérifiée, son mot de passe est supprimé :
     * il a pu être posé par quelqu'un d'autre avant le vrai propriétaire de l'adresse. Le champ du mot de passe
     * est écrit avant `email_verified_at` : MariaDB évalue les affectations de gauche à droite.
     */
    public function linkGoogle(int $id, string $sub): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = CASE WHEN email_verified_at IS NULL THEN NULL ELSE password_hash END, google_sub = ?, email_verified_at = COALESCE(email_verified_at, CURRENT_TIMESTAMP) WHERE id = ?')->execute([$sub, $id]);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, email FROM users WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
}
