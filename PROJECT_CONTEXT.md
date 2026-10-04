# Contexte Technique du Projet — WooCommerce Search Intelligence by SOYOO

## 📌 Présentation & Genèse

**WooCommerce Search Intelligence by SOYOO** (`woo-search-intelligence-soyoo`) est une extension WooCommerce propriétaire haute performance développée par l'agence **SOYOO**.

### Contexte & Problématique Initiale
Sur plusieurs boutiques en ligne gérées par l'agence (notamment **Bâches MFM** et **Jardin Naturel**), l'extension tierce obsolète *Search Analytics for WP* (tables `mwt_search_terms` et `mwt_search_history`) était utilisée pour enregistrer les recherches des visiteurs.
Des implémentations partielles avaient été directement injectées dans les thèmes enfants de chaque boutique (`inc/search/class-*-search-engine.php` et `class-*-search-admin.php`), générant :
1. De la divergence de code et de la dette technique.
2. Des "trous noirs" de journalisation (seul le live search AJAX était capturé ; les soumissions de formulaire standard et accès par URL n'étaient pas loggés).
3. Une pollution massive des statistiques par les robots et crawlers (Googlebot, Bingbot, Semrush, Ahrefs, PetalBot).
4. Des imports incomplets ou à risque de timeout serveur lors des montées de version.

### Objectif de l'Extension
Créer l'extension in-house unique, universelle, 100% autonome et compatible WooCommerce HPOS, qui centralise et sublime toutes les capacités de recherche e-commerce pour l'ensemble des clients SOYOO.

- **Slug** : `woo-search-intelligence-soyoo`
- **Dépôt GitHub** : [`https://github.com/SOYOO974/woo-search-intelligence-soyoo.git`](https://github.com/SOYOO974/woo-search-intelligence-soyoo.git)
- **Branche principale** : `main`
- **Mécanisme de mise à jour** : Plugin Update Checker (PUC v5.6) connecté aux Releases GitHub (`enableReleaseAssets()`)
- **Politique de déploiement** : Zéro déploiement FTP manuel. Les mises à jour s'effectuent par commit/push Git + publication d'une Release GitHub contenant l'archive `.zip`. Les sites WordPress clients consomment la mise à jour automatiquement en 1 clic.
- **Auteur** : SOYOO (Julien Vanwinsberghe)
- **Prérequis** : PHP 8.1+, WordPress 6.0+, WooCommerce 7.0+ (HPOS Ready)

---

## ⚙️ Spécifications Fonctionnelles & Architecture

### 1. Journalisation Universelle (0 Trou Noir)
- **Canal AJAX** : Endpoints `wc_ajax_woo_live_search` (haute vélocité sur Cloudflare/Rocket.net) et `wp_ajax_woo_live_search`, avec alias de transition transparente pour les thèmes existants (`mfm_live_search`, `jd_live_search`).
- **Canal Requêtes Standards** : Interception sur le hook `template_redirect` (priorité 20) lorsque `is_search() && !is_admin()`, avec extraction de `get_search_query()` et de `$wp_query->found_posts`.
- **Zéro Impact TTFB** : L'écriture en base MySQL est systématiquement différée sur le hook `shutdown` de WordPress.
- **Consolidation Anti-Flood** : Si une requête identique normalisée est soumise par la même session dans les 15 secondes, un simple `UPDATE` du nombre de résultats et de la date est effectué au lieu d'une insertion doublon.

### 2. Bouclier Anti-Robots (Anti-Bot Shield)
- Analyse du `HTTP_USER_AGENT` via une expression régulière couvrant les principaux moteurs et scrapers (`Googlebot`, `bingbot`, `Baiduspider`, `YandexBot`, `DuckDuckBot`, `Sogou`, `facebot`, `facebookexternalhit`, `ia_archiver`, `AhrefsBot`, `SemrushBot`, `MJ12bot`, `DotBot`, `PetalBot`, `Bytespider`, `DataForSeoBot`, `BLEXBot`, `UptimeRobot`, `WP_Rocket`, `curl`, `Wget`, `python`, `guzzle`, `headless`, `lighthouse`, `phantomjs`).
- Les bots et requêtes sans User-Agent sont immédiatement éliminés avant journalisation.
- Filtre extensible : `apply_filters('woo_search_is_bot', $is_bot, $user_agent)`.

### 3. Moteur de Pertinence SQL Multi-Paliers
- **Tier 0 (+100 000 points)** : Correspondance exacte SKU sur les produits simples et les déclinaisons (liaison automatique au `post_parent`).
- **Tier 1 (+10 000 points)** : Titre produit commençant par l'expression exacte.
- **Tier 2 (+5 000 points)** : Titre produit contenant l'expression exacte.
- **Tier 3 (+2 000 points)** : Titre contenant tous les mots significatifs (hors stop words français : *de, du, des, le, la, pour, avec...*).
- **Tier 4 (+150 points / mot)** : Bonus de présence de chaque mot distinct dans le titre.
- **Tier 5 (+30 points / mot)** : Bonus de présence de chaque mot dans l'extrait / courte description.
- **Tier 6 (+20 points)** : Bonus produit en stock (`lookup.stock_status = 'instock'`).
- Tri secondaire par volume des ventes (`total_sales`) pour privilégier la conversion.
- Optimisation des requêtes : exclusion native des produits masqués du catalogue (`exclude-from-search`) et pré-chargement en masse via `_prime_post_caches()`.

### 4. Lemmatisation Française & Gestion des Accents
- Gestion des pluriels et singuliers irréguliers français :
  - `-eaux` ↔ `-eau` (*gâteaux / gâteau, rouleaux / rouleau*)
  - `-aux` ↔ `-al / -ail` (*bocaux / bocal, métaux / métal, travaux / travail*)
  - `-s` (*bâches / bâche, graines / graine, huiles / huile*)
  - `-x`
- Table de correspondances d'accents (*thé / the, café / cafe, bâche / bache, œillet / oeillet, pâte / pate, céréale / cereale, légume / legume, blé / ble, bière / biere, séché / seche, câble / cable, élastique / elastique*).
- Filtre extensible : `apply_filters('woo_search_accent_map', $accent_map, $word)`.

### 5. Découplage Métier par Hooks WordPress
Le plugin reste générique et agnostique. Les règles métiers spécifiques sont injectées par chaque boutique :
- `apply_filters('woo_search_normalize_query', $clean, $query)` :
  - *Bâches MFM* : normalisation des dimensions (`2x3` -> `2 x 3`).
  - *Jardin Naturel* : normalisation des contenances bio (`500g` -> `500 g`, `1kg` -> `1 kg`).
- `apply_filters('woo_search_default_synonyms', $synonyms)` : initialisation du dictionnaire avec les synonymes sectoriels du catalogue.
- `apply_filters('woo_search_recommended_packs', $packs)` : injection des suggestions de packs thématiques dans l'admin.

### 6. Masquage Universel des Termes (Blacklist)
- Permet d'ignorer définitivement un terme en 1 clic directement depuis l'onglet **Statistiques (Top Recherches)** ou depuis l'onglet **0 Résultat**.
- Les termes ignorés (`woo_search_ignored_terms`) sont strictement exclus :
  - Du calcul des KPIs globaux (Total recherches, Taux de succès, Taux 0 résultat).
  - Des graphiques et tables d'analyse.
  - De l'envoi des alertes e-mails (mode seuil et rapport hebdomadaire).
- Section dédiée permettant la réactivation (dé-masquage) instantanée en 1 clic.

### 7. Importateur Search Analytics for WP 1-Clic par Batchs
- Détection dynamique des tables `wp_mwt_search_terms` et `wp_mwt_search_history`.
- Migration par lots de 250 enregistrements avec barre de progression interactive en temps réel (0 à 100%).
- Zéro timeout serveur : chaque lot s'exécute en une fraction de seconde (< 100 ms).
- Idempotence garantie : réinitialisation propre sans duplication (`session_hash LIKE 'mwt_%'`).

### 8. Export CSV & Pagination Dédiée
- Tables administratives paginées à 25 résultats par page pour supporter des volumes de plus de 100 000 requêtes sans saturer la RAM ni ralentir le wp-admin.
- Export CSV direct avec insertion du BOM UTF-8 (`\xEF\xBB\xBF`) pour une ouverture parfaite dans Microsoft Excel sans encodage corrompu.

### 9. Mises à Jour Automatiques via GitHub Releases & PUC v5.6
- **Bibliothèque intégrée** : Plugin Update Checker (v5.6) par YahnisElsts, embarquée dans le dossier `plugin-update-checker/`.
- **Surveillance des Releases GitHub** : Le module interroge le dépôt [`SOYOO974/woo-search-intelligence-soyoo`](https://github.com/SOYOO974/woo-search-intelligence-soyoo) sur la branche stable `main`.
- **Assets de Release Dédiés (`enableReleaseAssets()`)** :
  - WordPress ne télécharge pas un zipball brut de code source, mais l'archive autonome compilée `woo-search-intelligence-soyoo.zip` attachée à chaque GitHub Release.
  - Cette archive contient le dossier racine normé `woo-search-intelligence-soyoo/`, toutes les classes PHP, les assets CSS/JS et la bibliothèque PUC, assurant une installation/mise à niveau sans corruption d'arborescence.
- **Zéro Déploiement FTP** : Les boutiques clientes (Bâches MFM, Jardin Naturel, Conforama, etc.) se mettent à jour directement en 1 clic depuis le tableau de bord WordPress (`wp-admin > Mises à jour` ou `Extensions`).

---

## 🚀 Protocole de Création de Release & Mise à Jour Automatique

À chaque nouvelle version (correctif, optimisation ou nouvelle fonctionnalité), suivre scrupuleusement la séquence d'actions ci-dessous :

### Étape 1 : Incrémenter le Numéro de Version
Modifier le numéro de version sémantique (`MAJOR.MINOR.PATCH`) à deux emplacements dans [`woo-search-intelligence-soyoo.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/woo-search-intelligence-soyoo.php) :
1. En-tête du plugin : `* Version: X.Y.Z`
2. Constante PHP : `define( 'WOO_SEARCH_INTEL_VERSION', 'X.Y.Z' );`

### Étape 2 : Validation Syntaxique & Linting PHP
S'assurer qu'aucun fichier ne contient d'erreur PHP :
```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

### Étape 3 : Commit Conventionnel & Push sur GitHub
Commiter l'ensemble des modifications et pousser sur la branche `main` :
```bash
git add .
git commit -m "feat/fix: <description concise et explicite> (vX.Y.Z)"
git push origin main
```

### Étape 4 : Compilation de l'Archive Release (.zip)
Exécuter le script de build standardisé qui utilise `tar` avec forward slashes `/` (compatibilité Linux/WordPress garantie) :
```powershell
powershell -ExecutionPolicy Bypass -File .\bin\build-zip.ps1
```
*Le script effectue automatiquement une vérification syntaxique préalable, prépare un répertoire de staging temporaire et génère `woo-search-intelligence-soyoo.zip` à la racine.*

### Étape 5 : Publication de la Release sur GitHub
Créer la release officielle avec l'archive attachée via la CLI GitHub (`gh`) :
```bash
gh release create vX.Y.Z woo-search-intelligence-soyoo.zip --title "vX.Y.Z - <Titre de la release>" --notes "<Description des nouveautés et correctifs>"
```

### Étape 6 : Propagation Automatique
Dès la publication, Plugin Update Checker sur les sites WordPress clients détecte la mise à jour (au rafraîchissement du catalogue d'extensions ou sous 12h via le cache transient natif WordPress) et permet la mise à niveau en 1 clic.

---

## 🗄️ Structure de Données MySQL

```sql
CREATE TABLE {$wpdb->prefix}woo_search_logs (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    query varchar(191) NOT NULL,
    normalized_query varchar(191) NOT NULL,
    results_count smallint(5) unsigned NOT NULL DEFAULT 0,
    has_results tinyint(1) NOT NULL DEFAULT 1,
    session_hash char(32) NOT NULL DEFAULT '',
    searched_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY query (query),
    KEY normalized_query (normalized_query),
    KEY has_results (has_results),
    KEY searched_at (searched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 📂 Organisation du Code Source

```text
woo-search-intelligence-soyoo/
├── bin/
│   └── build-zip.ps1                   # Script de packaging de l'archive ZIP Linux/WordPress
├── includes/
│   ├── class-search-engine.php         # Moteur de scoring SQL, lemmatisation, transients, live search
│   ├── class-search-tracker.php        # Interception universelle AJAX & standard, anti-bot shield, alertes
│   ├── class-search-admin.php          # Interface 4 onglets, blacklist, pagination, export CSV
│   └── class-search-importer.php       # Migration asynchrone par lots depuis Search Analytics for WP
├── plugin-update-checker/              # Bibliothèque PUC v5.6 pour auto-updates via GitHub Releases
├── assets/
│   ├── css/admin.css                   # Styles de l'interface d'administration
│   └── js/admin.js                     # Contrôleur JS, gestion des batchs, filtres, inline edit
├── woo-search-intelligence-soyoo.php   # Point d'entrée, déclaration HPOS, init PUC v5.6, dbDelta
├── PROJECT_CONTEXT.md                  # Spécifications et contexte technique complet (ce fichier)
├── AGENTS.md                           # Directives de maintenance et gouvernance pour agents
└── README.md                           # Documentation publique du dépôt
```
