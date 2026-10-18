<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# RuneRide Raido : spécifications (`raido.runeride.fr`)

Statut : **spécification v1.0 du 18/10/2026, rien n'est développé ni déployé.** Décisions prises le 18/10/2026 : **D1 moteur maison en Rust** (pas de GraphHopper ni de Valhalla), **D2 sans serveur** (Lambda, aucun coût de fond ; le PHP reste sur o2switch), **D3 scores précalculés par tronçon, algorithme à nous**, **D4 palier gratuit limité aux boucles de 25 km et à 3 grands itinéraires par mois**, **D5 nom Raido** (ᚱ *Raidō*, rune du voyage et de la chevauchée), **D6 le pipeline et le moteur étendent `runeride-carto`**, **D7 tuiles de graphe en `rkyv`**, et les questions **O1 à O9 tranchées** (§13). Ce document s'appuie sur l'inventaire des dépôts `runeride`, `runeride-carto`, `runeride-weather` et `runeride-cloud` : **aucun graphe routier ni moteur de routage n'existe aujourd'hui**, le routage est renvoyé « hors mutualisé, sur AWS » par [runeride-carto/docs/plan.md](../../runeride-carto/docs/plan.md) (lot 6) et [osm.md](../../runeride-carto/docs/osm.md). Vocabulaire : **mesuré** (vérifié sur les vrais fichiers ou dépôts), **ESTIMATION** (calcul à confirmer), **DÉCISION** (prise ou à valider), **OUVERT** (question à trancher).

## 1. Vision

Un calculateur de **balade à moto sur place** : on est au guidon, on a trois heures devant soi, on veut rouler sur de belles routes et revenir au point de départ. L'appli propose une boucle (ou un trajet A → B) qui **favorise les routes sinueuses, scéniques et appréciées des motards**, respecte des contraintes (durée, étapes, évitements, autonomie, horaire) et tient compte de la **météo réelle à l'heure de passage** grâce à RuneRide Météo. Pour les habitués, un mode **découverte** pénalise les routes déjà parcourues, connues grâce aux tracés GeoRide synchronisés dans RuneRide.

Ce qui nous distingue d'un GPS routier ou de Calimoto/Kurviger : (1) le croisement **route × météo horaire** à maille kilométrique (AROME HD) ; (2) la **découverte personnelle** à partir de l'historique GeoRide de l'utilisateur ; (3) un score « motard » alimenté, à terme, par les tracés anonymisés de la communauté RuneRide (opt-in, §9) ; (4) la cohérence avec le fond de carte PMTiles et les stations carburant déjà servis par `runeride-carto` ; (5) un moteur **à nous, natif et sans serveur**, dont la fonction de coût évolue librement et qui pourra un jour tourner dans le téléphone (§12).

Référence d'ergonomie : Kurviger et Calimoto (curseur « sinueux », boucle par durée, évitements) ; on vise ce confort sans copier ni code ni ressources.

## 2. Périmètre

**Inclus (v1)** :

- emprise **France métropolitaine** (extrait Geofabrik `france-latest.osm.pbf`, déjà utilisé par `runeride-carto/bin/osm-extraire`) ; le domaine utile est l'intersection avec la couverture des tuiles vectorielles (aujourd'hui `aura-paca-bourgogne.pmtiles`, limites `[[43.0,1.9],[48.4,7.8]]`, **mesuré** dans `web/public/js/config.js`) ;
- **boucle par durée ou distance** depuis la position courante ou un point choisi, avec retour au départ ou arrivée imposée ;
- **trajet A → B** avec étapes, selon les mêmes préférences ;
- **préférences de parcours** : curseur sinuosité, curseur scénique, curseur « motard », mode découverte (GeoRide), vitesse de roulage ;
- **contraintes** : durée ou distance cible et tolérance ; points de passage obligatoires et arrêts (pauses, cols, panoramas) ; évitements (autoroutes, péages, traversées de villes, routes non revêtues, tunnels, ferries) ; autonomie carburant et insertion d'une station ; heure de départ, éviter la nuit ; fenêtre météo (éviter pluie, vent fort, froid ressenti) ;
- **météo sur l'itinéraire** affichée point par point à l'heure de passage (via `POST /v1/route` de RuneRide Météo) ;
- **export GPX** et ouverture dans une appli de navigation du téléphone ; enregistrement de la balade dans le compte ; partage par lien ;
- interface dans la **PWA existante** (`web/public/js/vues/`), utilisable au guidon avec des gants.

**Exclus (v1)** : guidage virage par virage et voix (on délègue à l'appli de navigation) ; calcul hors ligne (piste en §12, rendue naturelle par D1) ; Europe hors France (données AROME et OSM disponibles, lot ultérieur) ; multi-jours avec hébergements ; trafic temps réel ; autres véhicules que la moto.

**Pistes ultérieures** : moteur embarqué dans la PWA (WebAssembly) pour le hors ligne ; score communautaire des routes ; balades proposées par la communauté ; extension Europe (ARPEGE, ICON-D2 côté météo) ; prise en compte du relief pour le froid ressenti en altitude.

## 3. Cas d'usage

| N° | Cas | Entrées | Résultat attendu |
|---|---|---|---|
| U1 | Balade sur place | position GPS, « 3 h », sinuosité forte, retour au départ | boucle de 2 h 45 à 3 h 15, tracé affiché, score et météo le long, export GPX |
| U2 | Balade avec une étape | départ, « passer au col de la Croix-de-Fer », « 5 h » | boucle incluant le col, pause suggérée au col |
| U3 | Trajet A → B motard | adresse ou lieu de départ et d'arrivée, éviter autoroutes et péages | tracé plus long que le plus rapide, avec le surcoût de temps affiché |
| U4 | Découverte | comme U1, mode découverte à 80 % | boucle évitant les routes déjà parcourues (GeoRide), pourcentage de nouveauté affiché |
| U5 | Météo contraignante | comme U1, départ 14 h, « éviter la pluie » | boucle qui contourne la zone de pluie prévue à l'heure de passage, ou message si impossible |
| U6 | Autonomie | comme U1, autonomie 180 km, réservoir à moitié | station insérée avant le seuil, choisie parmi `osm_stations` avec prix connu (`fuel_stations`) |
| U7 | Rejouer | une balade enregistrée | rechargée, recalcul météo du jour, export |
| U8 | Petite boucle gratuite | compte gratuit, « 25 km » depuis ici | boucle calculée sans décompte du quota (§6.4) |

## 4. Données

### 4.1 Ce qui existe (mesuré)

| Source | Dépôt | Contenu utile | Manque pour Raido |
|---|---|---|---|
| Tuiles vectorielles Protomaps (PMTiles z0–15) | `runeride-carto` | affichage du fond, classes de routes (schéma basemaps v4) | affichage seulement, pas de graphe |
| `osm_stations`, `osm_lieux` (MariaDB `runeride_osm`) | `runeride-carto` | stations carburant, localités avec population, API `/v1/stations`, `/v1/lieux`, `/v1/vue` | aucune route extraite |
| `fuel_stations` | `runeride` (migration 007) | prix carburant France | — |
| `trips.path_json` | `runeride` (migration 006) | tracés GeoRide `[[lat, lon], …]` | pas d'horodatage, de vitesse ni d'altitude ; pas d'appariement au réseau |
| Tuiles météo PMTiles, `manifest.json`, `POST /v1/route` (prévu, non codé) | `runeride-weather` | vent, rafales, T, pluie, neige, nuages, CAPE, échéances 0–51 h | API `/v1/route` non livrée (jalon J6 météo) |
| Pipeline Rust météo (`rust/`), binaire Lambda arm64, S3 SigV4 | `runeride-weather` | **patrons réutilisables** : packaging `provided.al2023` arm64, accès S3 sans SDK lourd, tests | — |

### 4.2 Réseau routier à extraire (nouveau pipeline, dans `runeride-carto`)

Extraction depuis le PBF Geofabrik, en Rust (`osmpbf`), limitée aux voies `highway` carrossables :

| Attribut OSM | Usage |
|---|---|
| `highway` (motorway … unclassified, tertiary, residential) | classe, vitesse par défaut, exclusion des `track`, `path`, `service` sauf accès |
| `surface`, `smoothness`, `tracktype` | évitement des non revêtues |
| `maxspeed`, `oneway`, `access`, `motor_vehicle`, `motorcycle` | légalité et temps de parcours |
| `toll`, `tunnel`, `bridge`, `ferry` (route) | évitements |
| `ref`, `name` | affichage (« D 902 », « Route des Grandes Alpes ») |
| relations `type=route`, `route=road`, `tourism`, `network` | routes touristiques balisées, bonus scénique |
| `mountain_pass=yes`, `natural=peak`, `tourism=viewpoint`, `natural=water`, `landuse=forest`, `leisure=nature_reserve`, `boundary=national_park` | points et zones scéniques (bonus de proximité) |
| `place` + population (déjà dans `osm_lieux`) | pénalité de traversée d'agglomération |
| restrictions `type=restriction` | interdictions de tourner (sans elles, les boucles proposent des demi-tours illégaux) |

Données externes complémentaires :

- **Relief** (O1, tranché) : **BD ALTI 25 m de l'IGN** en France (Licence Ouverte Etalab 2.0 depuis le 01/01/2021, mention « © IGN » obligatoire), **Copernicus GLO-30** préparé dès le pipeline pour l'extension Europe ; sert au dénivelé, à la pente et au bonus « route de montagne ».
- **Géométrie** : la sinuosité est calculée depuis les nœuds OSM eux-mêmes, aucune source externe.

### 4.3 Score d'attractivité par tronçon (DÉCISION D3, prise)

Chaque tronçon (way OSM découpé aux intersections) reçoit des scores de 0 à 1, précalculés par le pipeline et stockés **dans le graphe lui-même** (un octet par score et par tronçon, §5.3) :

| Score | Calcul | Remarques |
|---|---|---|
| **Sinuosité** | somme des changements de cap (rad) divisée par la longueur (km), lissée sur 500 m, bornée | notre formule, ajustable sans toucher au moteur |
| **Scénique** | bonus par proximité (≤ 300 m) de panoramas, cols, sommets, eau, forêts, parcs ; bonus d'appartenance à une relation touristique ; bonus de dénivelé relatif ; pénalité en zone industrielle ou commerciale | pondérations à calibrer sur des balades connues (jalon J2) |
| **Motard** | fréquence de passage dans les tracés GeoRide anonymisés et agrégés des utilisateurs ayant **accepté** le partage (§9), normalisée par département | vide au lancement : repli sur la combinaison sinuosité + classe de route (secondary/tertiary de montagne) |
| **Agglomération** | 1 dans les `place` de population > 5 000, décroissant jusqu'à 2 km | pénalise les traversées, sauf étapes demandées |

Le coût d'un tronçon au moment du calcul est : `temps × (1 + Σ poids_k × (1 − score_k)) + pénalités` (évitements, déjà parcouru, zone météo), où les `poids_k` viennent des curseurs de l'utilisateur. Un curseur à zéro redonne l'itinéraire le plus rapide. Le coût étant toujours ≥ temps, le **temps à vol d'oiseau à la vitesse maximale** reste une borne inférieure admissible pour A\* quelle que soit la combinaison de curseurs : c'est ce qui permet de se passer de tout prétraitement dépendant du coût (contraction, points de repère).

### 4.4 Découverte personnelle (GeoRide)

- Les tracés `trips.path_json` de l'utilisateur sont **appariés au réseau** (map matching, Lambda `apparier`, §7.2) une fois pour toutes, lors de la synchronisation GeoRide, et stockés comme liste d'identifiants de tronçons parcourus (nouvelle table `trajets_troncons`, migration `012`, §7.3).
- Au calcul, les tronçons déjà parcourus reçoivent une pénalité proportionnelle au curseur découverte ; le résultat affiche le **pourcentage de nouveauté** (longueur jamais parcourue / longueur totale).
- Limite **mesurée** : `path_json` est un simple tableau `[lat, lon]`, réduit à l'affichage (`GET /api/trajets/carte` avec `points`). L'appariement se fait sur le tracé complet tel que renvoyé par GeoRide (`GeorideClient::positions`), pas sur la version simplifiée.

### 4.5 Météo

- La balade calculée est envoyée à `POST /v1/route` de RuneRide Météo (polyligne, heure de départ, vitesse moyenne) ; la réponse donne T, pluie, vent de face et de travers, rafales et froid ressenti par point, à l'heure de passage (spec météo §7, objectif < 2 s pour 500 points).
- **Contrainte météo** (U5) : boucle de recalcul. On calcule, on échantillonne les points en infraction (pluie > seuil, rafales > seuil, froid ressenti < seuil), on construit des **zones d'évitement** (polygones autour des mailles concernées à l'heure de passage ± 1 h), on recalcule avec ces zones. Trois itérations maximum, puis on rend le meilleur candidat avec l'avertissement « météo non évitable ».
- Seuils par défaut (O2, tranché) : **pluie 1 mm/h, rafales 60 km/h, froid ressenti 5 °C**, réglables par l'utilisateur et mémorisés dans ses préférences (§7.3).

## 5. Architecture (DÉCISIONS D1 et D2, prises)

### 5.1 Contrainte d'hébergement (mesuré) et principe

o2switch (où tournent `app.runeride.fr` et `carto.runeride.fr`) **interdit tout programme serveur persistant**. Le PHP d'o2switch garde donc le rôle de **façade authentifiée** (compte, quotas, enregistrement des balades, tracés GeoRide) et le calcul tourne sur AWS eu-north-1, **sans serveur** : aucune machine allumée en permanence, un coût qui suit l'usage, comme RuneRide Météo. Le moteur est **à nous, en Rust**, pour trois raisons : un binaire natif de quelques Mo démarre en dizaines de millisecondes dans Lambda là où une JVM GraphHopper demande des Go et des dizaines de secondes ; la fonction de coût et l'algorithme de boucle sont notre valeur ajoutée et doivent évoluer librement ; le même code, compilé en WebAssembly, ouvre le hors ligne dans la PWA (§12).

Ce que cette décision coûte et qu'on assume : il faut écrire et maintenir le moteur (graphe, A\*, boucle, appariement, restrictions de tourner), là où les moteurs existants sont déjà éprouvés. Le jalon J1 est dimensionné pour vérifier tôt que la performance tient (§8).

### 5.2 Schéma

```
Geofabrik france-latest.osm.pbf · MNT · relations touristiques · tracés GeoRide agrégés (opt-in)
        │  (1) runeride-carto/rust/raido-graphe : filtrage highway, restrictions, scores §4.3,
        │      découpage en tuiles de graphe + graphe grossier (§5.3)   [batch, GitHub Actions ou Lambda longue]
        ▼
 S3 « runeride-raido »  ──  graphe/<version>/{index.json, grossier.bin, tuiles/z8/x/y.bin}
        │  (2) chargement à la demande des tuiles couvrant la boîte de la requête, cache /tmp entre invocations
        ▼
 Lambda `raido-calcul` (Rust, arm64, provided.al2023, 2 Go)  ── eu-north-1 ──
   · préférences et contraintes → fonction de coût
   · A* bidirectionnel sur tuiles + graphe grossier au-delà de 60 km
   · génération de boucles (§5.4), étapes, stations carburant, itérations météo
   · appelle weather-data.runeride.fr POST /v1/route
        │
 Lambda `raido-apparier` (Rust) : appariement des tracés GeoRide → identifiants de tronçons
        │
 CloudFront raido.runeride.fr (Function URL + OAC, cache des réponses GET)  ◄── PWA app.runeride.fr (vue #/balade)
        │
 app.runeride.fr (PHP, o2switch) ◄───┘ jeton court, quotas, balades enregistrées, tronçons parcourus
```

Le motif « Lambda Function URL + OAC derrière CloudFront, concurrence réservée plafonnée » est celui que [runeride-weather/docs/architecture-aws.md](../../runeride-weather/docs/architecture-aws.md) §4.4 recommande pour son propre calcul d'itinéraire météo ; on en reprend les pièges connus (en POST, l'en-tête `x-amz-content-sha256` est obligatoire ; le type « Lambda Web Function » est exclu, Node.js seul).

### 5.3 Format du graphe (DÉCISION D7, prise)

- **Tuiles de graphe** au découpage z8 Web Mercator (≈ 150 × 100 km en France), un fichier binaire par tuile, **sérialisé avec `rkyv`** (accès zéro copie sans désérialisation, pur Rust, compile en WebAssembly ; la version de `rkyv` est épinglée et inscrite dans `index.json`, un changement de version majeure impose une reconstruction du graphe) : nœuds (lat, lon en entiers 1e-7), arcs sortants (nœud cible, longueur en m, temps de base en ds, classe, drapeaux péage/tunnel/ferry/non revêtu, 4 scores sur 8 bits, identifiant de way OSM), arcs de **liaison** vers les tuiles voisines, restrictions de tourner.
- **Identifiant stable de tronçon** (O6, tranché) : clé primaire `way_id` OSM × indice du segment entre intersections (survit à la plupart des mises à jour OSM), **doublée d'un géohash du milieu du tronçon** (précision 9, ≈ 5 m) qui sert de secours pour ré-apparier `trajets_troncons` quand un way a été scindé ou renuméroté ; les deux sont stockés dans la tuile.
- **Graphe grossier** : un seul fichier, routes `tertiary` et au-dessus hors agglomération, pour les trajets dont les extrémités sont à plus de 60 km : on route sur le grossier, puis on affine les 15 premiers et derniers km sur les tuiles fines (ESTIMATION : France grossier ≈ 150 Mo, chargé une fois et gardé dans `/tmp`).
- **Index** `index.json` : version (date OSM, version des scores), liste des tuiles et tailles, bornes.
- Volume ESTIMATION : France fine ≈ 1,5 à 2,5 Go au total, soit 2 à 10 Mo par tuile z8 ; une boucle de 3 h touche 4 à 9 tuiles, donc **20 à 80 Mo à charger** depuis S3 à froid (≈ 0,3 à 1 s dans la même région), rien à chaud.

### 5.4 Algorithmes

| Besoin | Méthode | Notes |
|---|---|---|
| Trajet A → B | A\* bidirectionnel, heuristique = distance à vol d'oiseau / vitesse max du graphe (admissible, §4.3) ; file de priorité binaire ; nœuds visités dans des tableaux par tuile | pas de prétraitement dépendant du coût, donc les curseurs sont libres |
| Restrictions de tourner | état de recherche = (arc entrant, nœud), pas (nœud) seul | nécessaire pour éviter les demi-tours sur routes de montagne |
| **Boucle** par distance L | **pétales** : pour 8 à 12 caps et 2 rayons, poser deux points de passage virtuels à ≈ L/3 sur un triangle orienté ; router D → P1 → P2 → D ; les arcs de la branche aller reçoivent une pénalité de **recouvrement** sur la branche retour ; conserver les 3 meilleurs candidats par un score composite (écart à L, attractivité moyenne, recouvrement, nouveauté) ; raffiner le meilleur par petites perturbations des points | les 3 candidats alimentent le bouton « Autre proposition » |
| Étapes | ordre imposé par l'utilisateur ; route segment par segment avec recouvrement pénalisé | pas de voyageur de commerce en v1 |
| Autonomie | si la distance cumulée dépasse `autonomie × réservoir − 20 %`, insérer la station la mieux notée (prix connu, écart au tracé ≤ 3 km) depuis `carto /v1/stations` et recalculer le segment | — |
| Nuit (O8, tranché) | heure de coucher du soleil calculée localement ; au-delà, **pénalité** sur les routes de classe inférieure et **avertissement** avec l'heure à laquelle la nuit tombe sur le tracé | pas de contrainte dure en v1 |
| Météo | zones d'évitement = pénalité forte sur les arcs dont le milieu est dans un polygone, 3 itérations (§4.5) | — |
| **Appariement** GeoRide | Viterbi sur candidats à ≤ 30 m avec probabilité de transition par longueur de plus court chemin ; sortie = suite de tronçons | tracés sans horodatage : la vitesse n'intervient pas |
| **Durée** (O3, tranché) | temps par tronçon selon la **vitesse par classe de route** (maxspeed, classe, sinuosité) **+ 10 min de pause par heure roulée** ; la durée cible inclut les pauses | valeurs par défaut, **personnalisables** par l'utilisateur (§7.3) |
| **Calibration personnelle** (O3) | les trajets GeoRide de l'utilisateur, une fois appariés, donnent sa vitesse réelle par classe de route (`distance_km` et `duration_min` de `trips`, répartis sur les tronçons) ; après 10 trajets, le modèle de durée propose ses propres vitesses, que l'utilisateur accepte ou corrige | calcul côté `raido-apparier`, résultat rangé dans les préférences |

### 5.5 Coût d'exploitation (ESTIMATION)

| Poste | Hypothèse | Coût mensuel |
|---|---|---|
| Lambda `raido-calcul` | 2 Go, 4 s par boucle (avec 3 trajets de pétales en moyenne 30 candidats, chaud), 2 000 calculs/mois → 16 000 Go·s | dans la franchise permanente (400 000 Go·s) : **0 €** ; au-delà ≈ 0,27 € par 16 000 Go·s |
| Lambda `raido-apparier` | 1 Go, 2 s par trajet, 5 000 trajets/mois | 0 € (franchise) |
| S3 stockage | 2,5 Go de graphe, 2 versions | ≈ 0,12 € |
| S3 requêtes | 10 tuiles par démarrage à froid, 1 000 démarrages/mois | ≈ 0,005 € |
| CloudFront | forfait Free (comme `tuiles/` et `meteo/`) | 0 € |
| Construction du graphe | GitHub Actions (gratuit, dépôt public) ou Lambda 15 min × 10 Go, 1 fois par mois | 0 à 0,15 € |
| **Total** | | **< 1 € par mois**, aligné sur la cible de RuneRide Météo |

Pas de stockage éphémère au-delà des 512 Mo gratuits : le cache `/tmp` garde le grossier (150 Mo) et les dernières tuiles. Alarme AWS Budgets à 5 €/mois pour le module.

### 5.6 Terraform

Nouveau module `runeride-cloud/terraform/raido/` calqué sur `meteo/` : S3 privé, Lambda arm64 `provided.al2023` × 2, Function URL avec OAC, CloudFront `raido.runeride.fr` (ACM us-east-1, CORS limité à `app.runeride.fr` et `runeride.fr`), concurrence réservée 10, alarmes, budget. Rien n'est appliqué avant le jalon J3.

## 6. Interface (PWA)

### 6.1 Entrée dans la PWA

Nouvelle vue `web/public/js/vues/balade.js`, route `#/balade`, accessible depuis la carte des trajets (bouton « Balade ») et depuis le pit-stop (« Balade depuis ici »). Même structure que `pitstop.js` : `ecranCarte` plein écran, feuille mobile remontable, boutons de taille gants. Icône `itineraire` déjà présente dans `js/icones.js`.

### 6.2 Feuille de réglage (un écran, trois niveaux)

1. **Essentiel** : départ (ma position, ou recherche via `/v1/lieu`), **durée** (molette 1 h à 8 h, pas 30 min) ou distance, **retour au départ** (bascule) sinon arrivée, bouton **Calculer**. En gratuit, la molette propose aussi « Petite boucle 25 km » mise en avant (U8).
2. **Caractère** : trois curseurs à cinq crans (sinueux, scénique, motard), bascule **Découverte** avec son intensité, allure (« tranquille », « normale », « soutenue », qui décale les vitesses par classe ; le réglage fin et la calibration GeoRide sont dans la vue Compte).
3. **Contraintes** : étapes (recherche de lieu, col, panorama ; glisser pour ordonner), évitements (autoroutes, péages, villes, non revêtu, tunnels, ferries), autonomie (km) et niveau de réservoir, heure de départ, éviter la nuit, météo à éviter (pluie, vent, froid, avec seuils).

### 6.3 Résultat

- Tracé sur la carte, coloré au choix par **météo** (pluie, vent de travers, température à l'heure de passage) ou par **nouveauté** (découverte) ; pastilles aux étapes, pauses et stations.
- Bandeau : distance, durée estimée, heure d'arrivée, dénivelé, **pourcentage de sinuosité**, **pourcentage de nouveauté**, écart par rapport à la durée demandée.
- Actions : **Autre proposition** (candidat suivant), **Inverser le sens**, **Enregistrer**, **GPX**, **Ouvrir dans** (Google Maps, Waze, OsmAnd, Kurviger par URL ou fichier GPX), **Partager** (lien public en lecture).
- Avertissements : météo non évitable, étape inaccessible, autonomie insuffisante sans station, revêtement inconnu sur plus de 10 % du tracé, quota atteint.

### 6.4 Paliers (DÉCISION D4, prise)

| Fonction | Gratuit | Abonné |
|---|---|---|
| **Petites boucles** (≤ 25 km, retour au départ) | illimitées | illimitées |
| **Grands itinéraires** (boucle > 25 km, ou tout trajet A → B) | **3 par mois** | illimités |
| Météo sur l'itinéraire | oui | oui |
| Découverte GeoRide | — | oui |
| Enregistrement et partage | 5 balades | illimité |
| Export GPX | oui | oui |

Le décompte se fait **à la délivrance du jeton** (§7.1), sur le mois civil, et n'est pas consommé si le calcul échoue. « Autre proposition » sur un calcul déjà payé ne décompte pas. Cohérent avec `users.tier` et l'erreur `abonnement_requis` déjà utilisée par `/api/ravitaillement` ; nouveau code `quota_epuise`.

## 7. API

### 7.1 Façade PHP (`app.runeride.fr`, Bearer JWT)

| Route | Rôle |
|---|---|
| `POST /api/balade/jeton` | corps : type de calcul (`petite_boucle`, `grand`) ; délivre un jeton court (15 min) signé RS256 pour `raido.runeride.fr`, portant le palier, le type autorisé, la limite de distance et un identifiant opaque d'utilisateur ; décompte le quota |
| `GET /api/balade/troncons` | liste des tronçons déjà parcourus (identifiants §5.3), que la PWA transmet au calcul pour la découverte (ou référence S3 si > 50 000) |
| `GET /api/balades`, `GET /api/balade?id=`, `POST /api/balades`, `DELETE /api/balades` | balades enregistrées (format §7.3) |
| `GET /api/balade/partage?cle=` | lecture publique d'une balade partagée, sans Bearer |
| `GET /api/balade/quota` | restant du mois, pour l'affichage |

Format des réponses : `[status, array]`, erreurs `{"erreur": "...", "code": "..."}` comme les routes existantes de `src/Trips/routes.php`. Le PHP appelle aussi `raido-apparier` après `POST /api/georide/synchronisation` (appel sortant HTTP, comme vers GeoRide) et range le résultat dans `trajets_troncons`.

### 7.2 Service Raido (`raido.runeride.fr`, jeton court)

**Nom des routes** : la météo réserve déjà `POST /v1/route` sur `weather-data.runeride.fr` pour « météo le long d'une trace » ; Raido expose `/v1/balade` et `/v1/trajet` sur son propre domaine.

| Route | Corps ou paramètres | Réponse |
|---|---|---|
| `POST /v1/balade` | `depart` {lat, lon}, `retour` (bool) ou `arrivee`, `duree_min` ou `distance_km`, `tolerance` (%), `preferences` {sinuosite, scenique, motard, decouverte : 0–4}, `vitesse_kmh`, `etapes` [...], `evitements` [...], `autonomie` {km, reservoir}, `depart_a` (ISO 8601), `meteo` {pluie_mmh, rafales_kmh, froid_c}, `troncons_connus` [...] ou `troncons_url`, `variante` (0–2) | `trace` (polyligne encodée), `distance_m`, `duree_s`, `denivele_m`, `scores` {sinuosite, scenique, motard, nouveaute}, `etapes` [...], `stations` [...], `meteo` [points], `avertissements` [...], `graphe` {version}, `attribution` |
| `POST /v1/trajet` | mêmes champs sans `duree_min` / `retour` ; `arrivee` obligatoire | idem |
| `POST /v1/apparier` (privé, clé de service) | `trace` [[lat, lon], …] | `troncons` [identifiants], `couverture` (%) |
| `GET /v1/lieu?q=` | mandataire vers `carto` `/v1/lieux` + cols, panoramas et sommets du graphe | liste `{nom, type, lat, lon}` |
| `GET /v1/sante` | — | version du graphe (date OSM), version des scores, état météo |
| `GET /v1/gpx?id=` | balade enregistrée | fichier GPX 1.1 avec étapes en `wpt` |

Le jeton court porte la limite de distance : un jeton `petite_boucle` fait rejeter toute requête dont `distance_km` > 25 ou `duree_min` implique plus de 25 km, ou tout `POST /v1/trajet`, avec `403 quota_requis`. Une réponse comprend toujours `attribution` (« © OpenStreetMap contributors (ODbL) · © IGN BD ALTI · Météo-France (Licence Ouverte) · Copernicus GLO-30 »).

### 7.3 Données en base (`runeride`, MariaDB)

Nouvelles migrations, une instruction par fichier, dans `web/migrations/mariadb/` :

| Migration | Table | Colonnes |
|---|---|---|
| `011_balades.sql` | `balades` | id, user_id, title, params_json (requête §7.2), trace_json (polyligne encodée), distance_m, duration_s, scores_json, graph_version, share_key (nullable, unique), created_at, updated_at |
| `012_trajets_troncons.sql` | `trajets_troncons` | trip_id, troncon_id (BIGINT, identifiant stable §5.3), graph_version ; index (trip_id), (troncon_id) |
| `013_partage_motard.sql` | `users` | `partage_traces` TINYINT(1) DEFAULT 0 (opt-in §9) |
| `014_balade_quota.sql` | `balade_quotas` | user_id, mois (CHAR(7)), grands_calculs INT ; clé (user_id, mois) |
| `015_balade_preferences.sql` | `balade_preferences` | user_id (clé), vitesses_json (par classe de route, NULL = défaut), pause_min_par_h (défaut 10), seuils_meteo_json (défaut §4.5), calibration_json (vitesses mesurées par `raido-apparier`, nombre de trajets, date), updated_at |

Les préférences sont éditées dans la vue Compte (`compte.js`, section « Balade ») et transmises à chaque appel de Raido dans le corps de la requête : Raido reste sans état.

## 8. Exigences non fonctionnelles

| Exigence | Cible v1 | Comment on vérifie |
|---|---|---|
| `POST /v1/balade` 3 h, Lambda chaude, sans météo | **< 4 s** au 95ᵉ centile | mesures J1 sur 50 départs tirés au sort dans l'emprise |
| Idem à froid (démarrage + tuiles) | < 7 s | idem, après vidage de `/tmp` |
| Avec contrainte météo (3 itérations) | < 12 s | idem, avec `/v1/route` météo simulée |
| `POST /v1/trajet` 300 km | < 3 s à chaud | idem |
| Mémoire Lambda | ≤ 2 Go | métrique CloudWatch `MaxMemoryUsed` |
| Durée maximale d'une invocation | 30 s (délai Lambda réglé à 29 s derrière CloudFront) | — |
| Fraîcheur du graphe | OSM ≤ 1 mois ; scores « motard » ≤ 1 semaine | `GET /v1/sante` |
| Budget | alarme AWS Budgets à 5 €/mois pour le module | `budget.tf` |
| Accessibilité au guidon | cibles ≥ 48 px, lisible au soleil, pas de saisie clavier dans l'essentiel | revue manuelle sur téléphone |
| Vie privée | aucune position brute journalisée côté Raido ; journaux sans identifiant utilisateur | revue de la configuration des journaux |
| Tests | moteur testé sur un graphe jouet et sur une tuile réelle ; parité du tracé entre Lambda et exécution locale | `cargo test`, jeu de 20 requêtes de référence |

## 9. Légal et vie privée

- **OSM** : attribution ODbL dans chaque réponse et sur chaque écran ; le graphe et les scores dérivés sont une **base de données dérivée**. **O4, tranché : les tuiles de graphe restent internes** (S3 privé derrière CloudFront, pas d'accès public) ; une publication ultérieure se ferait sous ODbL. L'usage interne d'une base dérivée est conforme à l'ODbL.
- **Météo-France** : Licence Ouverte v2.0, attribution déjà prévue par RuneRide Météo.
- **MNT** : BD ALTI 25 m de l'IGN (Licence Ouverte Etalab 2.0, « © IGN ») ; Copernicus GLO-30 (libre, attribution) hors France.
- **Code** : moteur, pipeline et orchestrateur sous EUPL-1.2 comme le reste de RuneRide ; dépendances Rust à vérifier (MIT/Apache-2.0 attendues).
- **Tracés GeoRide** : données personnelles de localisation. La **découverte** n'utilise que les tracés de l'utilisateur, pour lui ; le score **motard** n'agrège que les tracés des utilisateurs ayant **activé** `partage_traces`, après **suppression des 500 premiers et derniers mètres** de chaque trajet et agrégation par tronçon (comptage), sans conservation du lien tronçon → utilisateur. Mention dans la politique de confidentialité de la vitrine, droit de retrait immédiat (bascule), suppression des contributions au prochain recalcul hebdomadaire. OUVERT O5 : seuil minimal de contributeurs par département avant publication (proposition : 5).
- **Aucune responsabilité de guidage** : la balade est une suggestion ; signalisation et code de la route priment ; la météo renvoie vers la Vigilance Météo-France pour les alertes (comme la spec météo).

## 10. Jalons et critères d'acceptation

| Jalon | Contenu | Acceptation |
|---|---|---|
| **J0** | Décisions D1 à D7 et questions O1 à O9 tranchées (18/10/2026) | **atteint** ; ce document est en v1.0 |
| **J1** PoC moteur | `runeride-carto/rust/` : `raido-graphe` (extraction, scores sinuosité et agglo, tuiles z8, composantes connexes) et `raido-calcul` (A\* par arcs, restrictions, boucle par pétales, banc) sur l'extrait Rhône-Alpes ; mesures mémoire, temps, taille des tuiles | **Fait le 18/10/2026 en local** (détails : [runeride-carto/docs/raido.md](../../runeride-carto/docs/raido.md)). **Mesuré** : graphe Rhône-Alpes construit en 6 s (543 k intersections, 1,17 M d'arcs, 116 Mo) ; 90 boucles de 120 km sur un fil, 0 échec, **médiane 2,1 à 2,3 s, p95 3,8 à 4,1 s** ; 200 km : p95 7,1 s ; Lyon → Grenoble 42 ms (rapide) / 0,27 s (balade). **Tracés validés par le titulaire le 18/10/2026** (boucles Grenoble, Lyon, Annecy et Lyon → Grenoble, visionneuse Leaflet). **Non tenu** : tuile de Lyon à 41 Mo (> 10 Mo, passer en z9) ; pas de graphe grossier (inutile jusqu'ici) ; A\* unidirectionnel suffit. **J1 accepté.** |
| **J2** Scores | score scénique (MNT, relations, POI), calibration sur 10 balades de référence fournies par le titulaire | les 10 références sortent dans les 3 premiers candidats pour au moins 7 départs |
| **J3** Sans serveur | packaging Lambda arm64 (patron de `runeride-weather/rust`), chargement S3 et cache `/tmp`, étapes, évitements, autonomie et stations, GPX, `GET /v1/sante`, module Terraform `raido/`, `terraform validate` et `plan` | déployé en eu-north-1, cibles §8 tenues à chaud et à froid, budget et alarmes actifs, coût réel mesuré sur 2 semaines |
| **J4** PWA et quotas | vue `#/balade`, jeton court, quotas (migration 014), enregistrement (011), partage, paliers | parcours U1, U3, U6, U7, U8 réalisés sur iPhone et Android réels |
| **J5** Météo | intégration `POST /v1/route` (dépend du jalon J6 météo), coloration, boucle d'évitement | U5 réalisé ; 3 itérations < 12 s |
| **J6** Découverte | `raido-apparier`, migration 012, appel à la synchronisation GeoRide, pénalité et pourcentage de nouveauté | U4 réalisé sur le compte du titulaire |
| **J7** Communauté | opt-in (migration 013), agrégation hebdomadaire, score motard | score publié sur un département avec ≥ 5 contributeurs |

## 11. Risques et questions ouvertes

**Risques**

- **R1 Effort moteur** : écrire un routeur correct (restrictions, sens uniques, accès, boucles) est le gros du travail. Parade : périmètre v1 strict (§5.4), jeu de requêtes de référence dès J1, comparaison ponctuelle avec OSRM public en mode « rapide » (curseurs à zéro) pour valider la justesse de base.
- **R2 Performance de l'A\* sans prétraitement** sur des trajets de 300 km : si > 3 s à chaud, le graphe grossier passe de 60 à 30 km de seuil, ou on ajoute des points de repère (ALT) calculés sur le **temps seul**, qui restent admissibles pour tous les coûts.
- **R3 Démarrage à froid** : si le chargement des tuiles dépasse 3 s, réduire la taille des tuiles (z9) ou passer en SnapStart (non disponible pour `provided.al2023` : à vérifier) ; en dernier recours, une concurrence provisionnée de 1 (≈ 4 €/mois), à éviter.
- **R4 Scores scéniques décevants** (une « belle route » est subjective) : la calibration J2 sur des balades de référence est le garde-fou ; prévoir un retour « cette route était belle / sans intérêt » dans la PWA (hors v1).
- **R5 Dépendance au jalon J6 météo** (`/v1/route` non codé) : J5 glisse ; la v1 peut sortir sans coloration météo, la contrainte météo étant alors désactivée.
- **R6 Qualité OSM inégale** (`surface` absent sur beaucoup de routes) : on ne pénalise que le non revêtu **déclaré** et les `track` ; avertissement « revêtement inconnu » si > 10 % du tracé.
- **R7 Tracés GeoRide sans horodatage** : impossible de distinguer un passage à moto d'un transport sur remorque ; on filtre les trajets à distance < 2 km ou à vitesse moyenne hors plage (via `duration_min`).
- **R8 Contournement du quota** : un utilisateur gratuit pourrait enchaîner des petites boucles. Accepté : 25 km est utile mais ne remplace pas une balade de 3 h ; limite de 30 petites boucles par jour en garde-fou technique.

**Questions tranchées le 18/10/2026**

| N° | Question | Réponse |
|---|---|---|
| O1 | MNT | BD ALTI 25 m (IGN) en France, Copernicus GLO-30 hors France |
| O2 | Seuils météo par défaut | pluie 1 mm/h, rafales 60 km/h, froid ressenti 5 °C ; réglables |
| O3 | Durée | vitesses par classe de route + 10 min de pause par heure ; personnalisables ; calibrées sur les trajets GeoRide de l'utilisateur |
| O4 | Publication du graphe | interne pour l'instant |
| O5 | Seuil de contributeurs par département (score motard) | 5 |
| O6 | Identifiant de tronçon | `way_id` × segment, doublé d'un géohash du milieu en secours |
| O7 | Découverte multi-trackers | oui, tous les trackers du compte |
| O8 | Éviter la nuit | pénalité et avertissement, pas de contrainte dure |
| O9 | Format binaire des tuiles | `rkyv` (version épinglée) |

Il ne reste **aucune question ouverte** avant J1 ; les suivantes naîtront des mesures.

## 12. Piste hors ligne (hors v1)

D1 rend le hors ligne naturel : le même moteur Rust, compilé en WebAssembly, lit les mêmes tuiles de graphe `rkyv` (§5.3) téléchargées dans la PWA pour la région choisie, avec les scores embarqués. Seules la météo et la découverte communautaire exigent le réseau. À chiffrer après J4.

## 13. Décisions

| N° | Décision | Choix | État |
|---|---|---|---|
| D1 | Moteur de routage | **maison, en Rust** (pas de GraphHopper ni de Valhalla) | **prise le 18/10/2026** |
| D2 | Hébergement | **Lambda arm64 + S3 + CloudFront**, aucun coût de fond ; PHP sur o2switch en façade | **prise le 18/10/2026** |
| D3 | Scores précalculés par tronçon, algorithme à nous, stockés dans le graphe | oui | **prise le 18/10/2026** |
| D4 | Paliers | gratuit : boucles ≤ 25 km illimitées, 3 grands itinéraires par mois ; abonné : illimité, découverte | **prise le 18/10/2026** |
| D5 | Nom et domaine | **Raido** (ᚱ Raidō, rune du voyage), `raido.runeride.fr`, routes `/v1/balade`, `/v1/trajet` | **prise le 18/10/2026** |
| D6 | Emplacement du code | pipeline et moteur dans **`runeride-carto/rust/`** (`raido-graphe`, `raido-calcul`, `raido-apparier`) ; Terraform dans `runeride-cloud/terraform/raido/` | **prise le 18/10/2026** |
| D7 | Format du graphe | tuiles z8 sérialisées en `rkyv` + graphe grossier (§5.3) | **prise le 18/10/2026** ; taille et temps de chargement à mesurer en J1 |

## 14. Ce qui n'a pas été testé, honnêtement

Mise à jour du 18/10/2026 après J1 (mesures dans [runeride-carto/docs/raido.md](../../runeride-carto/docs/raido.md)) :

- **Mesuré en local** : l'A\* par arcs sans prétraitement tient la cible « < 4 s » en médiane et au p95 pour des boucles de 120 km **sur un seul fil** (équivalent d'une Lambda de 2 Go) ; le pipeline construit la région en 6 s avec 1,3 Go. R2 (performance) est donc levé pour les boucles de 3 h ; il reste ouvert pour 200 km et plus, où la parade mesurée est le **parallélisme des pétales** (÷ 7 sur 20 cœurs) : dans Lambda, plus de mémoire = plus de vCPU au même coût en Go·s.
- **Toujours non mesuré** : Lambda arm64, chargement depuis S3 (76 Mo par boucle à froid aujourd'hui, à réduire par z9), coût réel ; les valeurs de §5.5 restent des ESTIMATIONS jusqu'au jalon J3.
- **Scores** scénique et motard non implémentés (J2, J7) ; durées de parcours probablement pessimistes (40 à 50 km/h de moyenne en montagne), à calibrer sur GeoRide (O3).
- **Plausibilité** des tracés : relue et validée par le titulaire le 18/10/2026 sur quatre cas (J1 accepté) ; la calibration fine attend les 10 balades de référence de J2.
