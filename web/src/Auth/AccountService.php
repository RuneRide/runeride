<?php
declare(strict_types=1);

namespace RuneRide\Auth;

final class AccountService
{
    public function __construct(private readonly UserRepository $users, private readonly Jwt $jwt) {}

    /** @return array{0:int,1:array<string,mixed>} */
    public function register(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            return [422, ['erreur' => 'adresse e-mail invalide']];
        }
        if (mb_strlen($password) < 10) {
            return [422, ['erreur' => 'mot de passe trop court (10 caractères minimum)']];
        }
        if ($this->users->findByEmail($email) !== null) {
            return [409, ['erreur' => 'compte déjà existant']];
        }
        $id = $this->users->create($email, password_hash($password, PASSWORD_ARGON2ID));
        return [201, ['jeton' => $this->jwt->issue($id)]];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    public function login(string $email, string $password): array
    {
        $user = $this->users->findByEmail(mb_strtolower(trim($email)));
        // Même réponse (et même coût) que le compte existe ou non.
        $hash = $user['password_hash'] ?? password_hash('inexistant', PASSWORD_ARGON2ID);
        if (!password_verify($password, $hash) || $user === null) {
            return [401, ['erreur' => 'identifiants incorrects']];
        }
        return [200, ['jeton' => $this->jwt->issue((int) $user['id'])]];
    }
}
