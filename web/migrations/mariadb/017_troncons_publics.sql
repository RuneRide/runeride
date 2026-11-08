-- Score communautaire (J7) : parmi les tronçons d'un trajet, ceux parcourus hors des 500 premiers et derniers mètres (champ
-- troncons_publics de Raido /v1/apparier) sont les seuls à pouvoir être agrégés ; `publics` note que le trajet a été apparié
-- avec cette information (les trajets déjà traités sont réapparies à la prochaine synchronisation).
ALTER TABLE trajets_troncons ADD COLUMN publique TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE trajets_apparies ADD COLUMN publics TINYINT(1) NOT NULL DEFAULT 0;
CREATE INDEX idx_troncons_publique ON trajets_troncons (publique, graph_version, way_id, segment);
