# ADR 0001 : suivi de trajet pour les utilisateurs sans GeoRide

- **Statut :** futur (pas de décision, rien à développer maintenant)
- **Date :** 2026-10-18

## Contexte

Les trajets de RuneRide viennent aujourd'hui de GeoRide (synchronisation par l'API GeoRide). Un utilisateur sans GeoRide n'a aucun moyen d'alimenter l'application. Le projet reste pour l'instant dans un cercle privé, et aucun contact avec GeoRide n'est pris : le risque lié à l'art. 9.3 des CGV GeoRide (usage personnel, non cessible) est accepté tant que le projet n'est pas public ni payant.

Besoin exprimé : un suivi de trajet à consommation ultra-légère (batterie et données), le téléphone en poche, écran éteint.

## Décision

**Différée.** Si un suivi devient nécessaire, la piste privilégiée est de demander à GeoRide un **partenariat de revente** plutôt que de construire un suivi propre. Le suivi propre ci-dessous reste l'option de repli.

## Options étudiées

| Option | Écran éteint | Économie d'énergie | Coût et contraintes |
|---|---|---|---|
| Android natif (Kotlin) | Service au premier plan de type `location` (déclaré dans le manifeste, obligatoire dès Android 14) | Arrêt du GPS à l'arrêt grâce à la reconnaissance d'activité | Gratuit ; APK direct possible en cercle privé (pas de revue Play) |
| iOS natif (Swift) | Mode d'arrière-plan « location » ; « Toujours » sert surtout à relancer l'app tuée | Changements significatifs (≈ 500 m) très économes, GPS continu plus gourmand | Compte développeur Apple ; TestFlight non vérifié |
| Cross-platform + SDK Transistor (React Native, Flutter, Capacitor) | Même cœur natif | Détection de mouvement intégrée, GPS coupé à l'arrêt | Licence payante pour les builds de production, selon plateforme : prix et conditions à revérifier |
| Cross-platform + Tracelet (Flutter, libre, Apache 2.0) | Plugin open source | Non vérifié | Gratuit, jeune, non vérifié en production |
| Balise (Traccar) | Hors téléphone | Aucune charge sur le téléphone | Matériel et abonnement éventuel à la charge de l'utilisateur |
| Import GPX | Aucun suivi dans RuneRide | Nulle | Dépend d'une autre application pour enregistrer |

Principes retenus si l'on construit : démarrage et arrêt automatiques par détection de mouvement, échantillonnage adaptatif, stockage local pendant le trajet, envoi par lots à l'arrêt, aucune carte affichée pendant le roulage.

## Esquisse côté serveur (si l'on construit)

Route de dépôt de trajet authentifiée par le jeton RuneRide, identifiant de trajet fourni par le client pour éviter les doublons, colonne d'origine sur les trajets (GeoRide ou suivi RuneRide). L'affichage actuel (liste, carte, détail) servirait tel quel.

## Non vérifié

Aucun benchmark de batterie n'a été trouvé : toute consommation chiffrée serait une invention. Restent à vérifier : prix et licences du SDK Transistor, TestFlight, comportement des fabricants Android qui tuent les services d'arrière-plan, règles Play et Apple en vigueur au moment du développement.

## Conséquences

- Aucune dépendance nouvelle ni développement tant que la décision n'est pas prise.
- Toute ouverture publique ou payante du projet rouvre la question du contrat avec GeoRide (partenariat, revente ou accès à l'API).

## Sources

- [Politique Play sur la position](https://support.google.com/googleplay/android-developer/answer/16965181)
- [Android : position en arrière-plan](https://developer.android.com/training/location/background)
- [Transistor Software : SDK de géolocalisation](https://github.com/transistorsoft)
- [Alternative gratuite à Transistor (Capawesome)](https://capawesome.io/blog/alternative-to-transistorsoft-background-geolocation/)
- [Tracelet, plugin Flutter libre](https://medium.com/@kiranbjm/tracelet-the-free-open-source-alternative-to-flutter-background-geolocation-for-flutter-6b74b5a8bc1c)
- [Pourquoi iOS arrête la position en arrière-plan (Capgo)](https://capgo.app/blog/why-ios-stops-background-location-updates-capacitor/)
