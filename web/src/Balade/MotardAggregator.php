<?php
declare(strict_types=1);

namespace RuneRide\Balade;

/**
 * Agrégation hebdomadaire du score communautaire « motard » (spec §9, J7) : écrit un fichier de comptages par tronçon que
 * `raido-graphe construire --motard` lit pour noter le graphe. Ne sort que des tronçons parcourus par au moins SEUIL comptes distincts
 * ayant activé le partage, hors des 500 premiers et derniers mètres de chaque trajet ; le fichier ne contient ni compte, ni date, ni trajet.
 * Le retrait du partage (bascule) prend effet à la prochaine exécution, puisque l'opt-in est relu à chaque agrégation.
 */
final class MotardAggregator
{
    /** Seuil par tronçon (plus strict que le « 5 contributeurs par département » de la spec, OUVERT O5). */
    public const MIN_CONTRIBUTORS = 5;

    public function __construct(private readonly TronconRepository $troncons, private readonly int $minContributors = self::MIN_CONTRIBUTORS) {}

    /** @return array{graphe:?string,seuil:int,troncons:list<array{0:int,1:int,2:int}>} */
    public function build(?string $graphVersion = null): array
    {
        $version = $graphVersion ?? $this->troncons->latestPublicVersion();
        return ['graphe' => $version, 'seuil' => $this->minContributors, 'troncons' => $version === null ? [] : $this->troncons->communityCounts($version, $this->minContributors)];
    }

    /** Écrit le fichier (écriture atomique) et rend le nombre de tronçons publiés. */
    public function export(string $path, ?string $graphVersion = null): int
    {
        $data = $this->build($graphVersion);
        $tmp = $path . '.tmp' . getmypid();
        if (file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR)) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Écriture impossible : $path");
        }
        return count($data['troncons']);
    }
}
