<?php
declare(strict_types=1);

namespace RuneRide\Auth;

use Doctrine\DBAL\Connection;

/** Tout le SQL des comptes est ici, via DBAL (paramètres nommés ; MariaDB et PostgreSQL). */
final class UserRepository
{
    public function __construct(private readonly Connection $db) {}

    public function create(string $email, string $passwordHash): int
    {
        $this->db->insert('users', ['email' => $email, 'password_hash' => $passwordHash]);
        return (int) $this->findByEmail($email)['id'];
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->createQueryBuilder()
            ->select('id', 'email', 'password_hash', 'google_sub')->from('users')
            ->where('email = :email')->setParameter('email', $email)
            ->executeQuery()->fetchAssociative() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByGoogleSub(string $sub): ?array
    {
        return $this->db->createQueryBuilder()
            ->select('id', 'email', 'password_hash', 'google_sub')->from('users')
            ->where('google_sub = :sub')->setParameter('sub', $sub)
            ->executeQuery()->fetchAssociative() ?: null;
    }

    /** Compte sans mot de passe, ouvert par Google (adresse vérifiée par Google). */
    public function createGoogle(string $email, string $sub): int
    {
        $this->db->executeStatement(
            'INSERT INTO users (email, password_hash, google_sub, email_verified_at) VALUES (:email, NULL, :sub, CURRENT_TIMESTAMP)',
            ['email' => $email, 'sub' => $sub],
        );
        return (int) $this->findByEmail($email)['id'];
    }

    /**
     * Rattache Google à un compte existant. Si son adresse n'a jamais été vérifiée, son mot de passe est supprimé :
     * il a pu être posé par quelqu'un d'autre avant le vrai propriétaire de l'adresse. Le champ du mot de passe
     * est écrit avant `email_verified_at` : MariaDB évalue les affectations de gauche à droite.
     */
    public function linkGoogle(int $id, string $sub): void
    {
        $this->db->executeStatement(
            'UPDATE users SET password_hash = CASE WHEN email_verified_at IS NULL THEN NULL ELSE password_hash END, google_sub = :sub, email_verified_at = COALESCE(email_verified_at, CURRENT_TIMESTAMP) WHERE id = :id',
            ['sub' => $sub, 'id' => $id],
        );
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->createQueryBuilder()
            ->select('id', 'email')->from('users')
            ->where('id = :id')->setParameter('id', $id)
            ->executeQuery()->fetchAssociative() ?: null;
    }
}
