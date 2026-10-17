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
            ->select('id', 'email', 'password_hash')->from('users')
            ->where('email = :email')->setParameter('email', $email)
            ->executeQuery()->fetchAssociative() ?: null;
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
