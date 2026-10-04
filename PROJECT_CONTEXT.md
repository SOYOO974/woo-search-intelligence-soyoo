# Contexte Technique du Projet — WooCommerce Search Intelligence by SOYOO

## 📌 Présentation & Genèse

**WooCommerce Search Intelligence by SOYOO** (`woo-search-intelligence-soyoo`) est une extension WooCommerce propriétaire développée par l'agence **SOYOO**.

### Contexte & Problématique Initiale
Sur plusieurs boutiques en ligne gérées par l'agence (notamment **Bâches MFM** et **Jardin Naturel**), l'extension tierce obsolète *Search Analytics for WP* (tables `mwt_search_terms` et `mwt_search_history`) était utilisée pour enregistrer les recherches des visiteurs.
Des implémentations partielles avaient été directement injectées dans les thèmes enfants de chaque boutique (`inc/search/class-*-search-engine.php` et `class-*-search-admin.php`), générant :
1. De la divergence de code et de la dette technique.
2. Des "trous noirs" de journalisation (seul le live search AJAX était capturé ; les soumissions de formulaire standard et accès par URL n'étaient pas loggés).
3. Une pollution massive des statistiques par les robots et crawlers (Googlebot, Bingbot, Semrush, Ahrefs, PetalBot).
4. Des imports incomplets ou à risque de timeout serveur lors des montées de version.
5. Une incohérence entre la liste déroulante du thème et la page de résultats complète (moteur natif `LIKE`).

### Objectif de l'Extension
Extension in-house unique, universelle, autonome et compatible WooCommerce HPOS, qui centralise la recherche e-commerce (moteur + mesure + pilotage) pour l'ensemble des clients SOYOO.

- **Slug** : `woo-search-intelligence-soyoo`
- **Version** : 1.1.1 (schéma de base de données v2)
- **Dépôt GitHub** : [`https://github.com/SOYOO974/woo-search-intelligence-soyoo.git`](https://github.com/SOYOO974/woo-search-intelligence-soyoo.git)
- **Branche principale** : `main`
- **Mécanisme de mise à jour** : Plugin Update Checker (PUC v5.6) connecté aux Releases GitHub (`enableReleaseAssets()`)
- **Politique de déploiement** : Zéro déploiement FTP manuel. Les mises à jour s'effectuent par commit/push Git + publication d'une Release GitHub contenant l'archive `.zip`. Les sites WordPress clients consomment la mise à jour automatiquement en 1 clic.
- **Auteur** : SOYOO (Julien Vanwinsberghe)
- **Prérequis** : PHP 8.1+, WordPress 6.5+ (`Requires Plugins: woocommerce`), WooCommerce 8.0+ (HPOS Ready)

---

## ⚙️ Spécifications Fonctionnelles & Architecture

### 1. Index de recherche dédié (`Woo_Search_Indexer`)
- Table `{prefix}woo_search_index` : une ligne par produit **publié et visible en recherche** (`catalog_visibility` = visible/search).
- Colonnes pré-pliées et bordées d'espaces (`" mot1 mot2 "`) pour des `LIKE '% mot%'` fiables : `title`, `skus` (parent + variations + GTIN/EAN, forme pliée et compacte), `terms` (catégories + ancêtres, étiquettes, marques, attributs), `excerpt`, plus `stock_status` et `total_sales`.
- Mise à jour incrémentale sur les hooks WooCommerce (CRUD produit/variation, stock, transition de statut, corbeille, suppression, modification/suppression de terme) ; file d'attente vidée au `shutdown` (≤ 50 produits en ligne, au-delà par lots Action Scheduler `woo_search_index_batch`).
- Reconstruction complète : automatique après installation/migration (`woo_search_needs_rebuild`, hook `woo_search_rebuild_index` par lots de 200) ou manuelle depuis l'admin (AJAX `woo_search_index_batch` avec curseur). Les lignes obsolètes sont purgées en fin de reconstruction.
- Vocabulaire du catalogue (`woo_search_vocab`) reconstruit en tâche de fond pour la correction orthographique.
- Filtres : `woo_search_index_taxonomies`, `woo_search_index_data`.

### 2. Couche texte (`Woo_Search_Text`, sans dépendance WordPress, testée)
- `fold()` : minuscules, translittération accents/ligatures (filtre `woo_search_accent_map`), ponctuation → espace, frontières chiffres/lettres (`4x5m` → `4 x 5 m`, `500g` → `500 g`).
- Lemmatisation française : `-eaux`, `-aux`, `-eux`, `-oux`, `-s`, féminin `-e` ; longueur minimale 4 ; exceptions invariables (`STEM_EXCEPTIONS` : *noix, prix, engrais, bois…*, filtre `woo_search_stem_exceptions`).
- Mots vides (`STOP_WORDS`, filtre `woo_search_stop_words`).
- `best_correction()` : Levenshtein ≤ 1 (mots courts) ou ≤ 2, buckets par première lettre + repli global à distance 1.
- `format_title()` : harmonisation des titres en MAJUSCULES avec sigles préservés (filtre `woo_search_title_acronyms`).
- Tests : `php tests/run-tests.php`.

### 3. Moteur de requête (`Woo_Search_Engine`)
- Plan de requête : jetons significatifs → groupes (formes exactes + préfixes + synonymes d'expansion), synonymes de remplacement appliqués avant.
- Modes successifs : `all` (tous les mots) → `corrected` (correction orthographique) → `any` (correspondance partielle, optionnelle) → `none`. Mode `fallback` si l'index n'est pas prêt.
- Scoring : SKU exact (100 000) > début de SKU (20 000, uniquement si la saisie contient un chiffre et ≥ 3 caractères) > titre exact (15 000) > titre commençant par la requête (10 000) > phrase dans le titre (5 000) > tous les mots dans le titre (2 000) > par groupe : titre 300 / préfixe titre 150 / termes 80 / SKU 50 / extrait 30 > bonus stock > ventes.
- Plafond de 500 IDs classés (`woo_search_max_ids`).
- Cache : `wp_cache` groupe `woo_search` avec clé de génération (`woo_search_cache_gen`, incrémentée à chaque modification du catalogue/synonymes/réglages) + mémo de requête. Aucun transient par frappe.
- Endpoint public `?wc-ajax=woo_live_search&term=` (+ `wp_ajax`), court-circuit `posts_pre_query` pour éviter la requête principale inutile. Actions additionnelles via `woo_search_ajax_actions` (alias de thème, **plus aucun alias client en dur**). Réponse enrichissable via `woo_search_live_response`.
- Intégration page de résultats : `pre_get_posts` (priorité 1000) sur la requête principale de recherche produit → `post__in` ordonné, recherche native neutralisée (`posts_search`, `posts_search_orderby`), tri explicite WooCommerce respecté, bandeau de correction.
- Catégories suggérées : transient unique `wsi_cats` versionné.
- Synonymes : option `woo_search_synonyms`, identifiants UUID stables, amorçage initial via `woo_search_default_synonyms`, packs via `woo_search_recommended_packs`.

### 4. Journalisation & mesure (`Woo_Search_Tracker`)
- **Canal AJAX** : journalisé par l'endpoint live search avec un `search_uid` (16 hex).
- **Canal page** : `template_redirect` priorité 5 (avant la redirection WooCommerce sur résultat unique), hors pagination/tri/filtres.
- **Écriture différée** au `shutdown` (priorité 1000) après `fastcgi_finish_request` / `litespeed_finish_request`.
- **Consolidation** (fenêtre 45 s, même session) : une frappe qui prolonge/raccourcit la précédente remplace la ligne ; la page de résultats fusionne avec la recherche live identique ; une recherche déjà cliquée n'est jamais réécrite.
- **Clics** : fragment `#wsi=<uid>.<pid>.<pos>` ajouté aux permaliens du live search, lu par `assets/js/frontend.js` sur la fiche produit ; capture des clics sur la page de résultats (`wsiFront.page.items`) ; `navigator.sendBeacon` → `?wc-ajax=wsi_click`.
- **Conversions** : cookie first-party `wsi_ref` (24 h, SameSite=Lax) → `woocommerce_checkout_order_created` et `woocommerce_store_api_checkout_order_processed` renseignent `order_id`/`order_total` et la méta `_wsi_search_uid`.
- **Anti-robots** : regex User-Agent + UA vide, filtre `woo_search_is_bot` ; exclusion optionnelle des gestionnaires (`manage_woocommerce`).
- **Session** : `wp_hash(IP|UA|jour UTC)`, aucune IP stockée.
- **Alertes** : rapport hebdomadaire (lundi 06:00, KPIs + top 10 sans résultat) ou alerte de seuil (visiteurs distincts sur 7 jours) exécutée en tâche de fond (`woo_search_threshold_check`), anti-doublon 3 jours par terme.
- **Maintenance quotidienne** (`woo_search_daily_maintenance`) : rétention (`log_retention_days`, 730 j par défaut, 0 = illimité), synchronisation stock/ventes de l'index, vocabulaire, vérification des crons.

### 5. Administration (`Woo_Search_Admin`)
- 4 onglets : **Paramètres & Index**, **Synonymes**, **Statistiques**, **0 Résultat**.
- KPIs mis en cache 10 min par période (`wsi_kpis_{jours}`) : recherches, visiteurs, taux 0 résultat, CTR, position moyenne, commandes et CA attribués (lignes importées exclues du CTR).
- 0 résultat : chaque terme re-testé sur le moteur courant (Résolu / partiel / suggestion / Toujours 0), association en 1 clic, ignorer unitaire ou en masse.
- Blacklist (`woo_search_ignored_terms`) exclue des KPIs, tableaux, exports et alertes ; réactivation en 1 clic.
- Export CSV : BOM UTF-8, séparateur `;`, échappement des formules (`=`, `+`, `-`, `@`).
- Toutes les actions AJAX : nonce `woo_search_admin_nonce` + capacité `manage_woocommerce`.

### 6. Importateur Search Analytics for WP (`Woo_Search_Importer`)
- Détection de `wp_mwt_search_terms` + `wp_mwt_search_history` (l'historique daté est indispensable ; la table agrégée seule n'est plus importée).
- Lots de 500 par curseur de clé (`h.id > cursor`), dates converties en UTC, `source = 'import'`, `session_hash = 'mwt_<id>'`, option de remplacement de l'import précédent (idempotent).

### 7. Installation, migrations & désinstallation (`Woo_Search_Installer`)
- `DB_VERSION` comparée à l'option `woo_search_db_version` à chaque chargement : `dbDelta` + fusion non destructive des réglages + crons + reconstruction d'index. Indispensable car les mises à jour PUC ne déclenchent pas le hook d'activation.
- Tâches asynchrones : Action Scheduler (groupe `woo-search-intelligence`) si disponible, sinon WP-Cron.
- Désactivation : crons et actions planifiées supprimés, données conservées.
- `uninstall.php` : purge complète (tables, options, transients `wsi_*`) uniquement si `delete_data_on_uninstall` est activé.

### 8. Découplage métier par hooks
Aucune règle client dans le cœur. Chaque boutique personnalise depuis son thème : `woo_search_normalize_query`, `woo_search_accent_map`, `woo_search_default_synonyms`, `woo_search_recommended_packs`, `woo_search_stop_words`, `woo_search_stem_exceptions`, `woo_search_title_acronyms`, `woo_search_index_taxonomies`, `woo_search_index_data`, `woo_search_ajax_actions`, `woo_search_live_response`, `woo_search_is_bot`, `woo_search_max_ids`, `woo_search_max_categories`, `woo_search_image_size`.
Les normalisations historiques MFM (dimensions `2x3`) et Jardin Naturel (contenances `500g`) sont désormais couvertes nativement par `fold()`.

### 9. Mises à Jour Automatiques via GitHub Releases & PUC v5.6
- **Bibliothèque intégrée** : Plugin Update Checker (v5.6) par YahnisElsts, embarquée dans le dossier `plugin-update-checker/`.
- **Surveillance des Releases GitHub** : Le module interroge le dépôt [`SOYOO974/woo-search-intelligence-soyoo`](https://github.com/SOYOO974/woo-search-intelligence-soyoo) sur la branche stable `main`.
- **Assets de Release Dédiés (`enableReleaseAssets()`)** :
  - WordPress ne télécharge pas un zipball brut de code source, mais l'archive autonome compilée `woo-search-intelligence-soyoo.zip` attachée à chaque GitHub Release.
  - Cette archive contient le dossier racine normé `woo-search-intelligence-soyoo/`, toutes les classes PHP, les assets CSS/JS, `uninstall.php` et la bibliothèque PUC, assurant une installation/mise à niveau sans corruption d'arborescence.
- **Zéro Déploiement FTP** : Les boutiques clientes (Bâches MFM, Jardin Naturel, Conforama, etc.) se mettent à jour directement en 1 clic depuis le tableau de bord WordPress (`wp-admin > Mises à jour` ou `Extensions`).

---

## 🚀 Protocole de Création de Release & Mise à Jour Automatique

À chaque nouvelle version (correctif, optimisation ou nouvelle fonctionnalité), suivre scrupuleusement la séquence d'actions ci-dessous :

### Étape 1 : Incrémenter le Numéro de Version
Modifier le numéro de version sémantique (`MAJOR.MINOR.PATCH`) à deux emplacements dans [`woo-search-intelligence-soyoo.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/woo-search-intelligence-soyoo.php) :
1. En-tête du plugin : `* Version: X.Y.Z`
2. Constante PHP : `define( 'WOO_SEARCH_INTEL_VERSION', 'X.Y.Z' );`

Si le schéma SQL change : incrémenter aussi `Woo_Search_Installer::DB_VERSION` (la migration s'exécutera automatiquement sur les sites après la mise à jour).

### Étape 2 : Validation Syntaxique, Linting PHP & Tests
S'assurer qu'aucun fichier ne contient d'erreur PHP et que la couche texte reste conforme :
```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
php tests/run-tests.php
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

## 🗄️ Structure de Données MySQL (schéma v2, dates en UTC)

```sql
CREATE TABLE {$wpdb->prefix}woo_search_logs (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    search_uid char(16) NOT NULL DEFAULT '',
    query varchar(191) NOT NULL DEFAULT '',
    normalized_query varchar(191) NOT NULL DEFAULT '',
    results_count int(10) unsigned NOT NULL DEFAULT 0,
    has_results tinyint(1) NOT NULL DEFAULT 1,
    source varchar(10) NOT NULL DEFAULT 'ajax',          -- ajax | page | import
    match_mode varchar(10) NOT NULL DEFAULT '',           -- all | corrected | any | fallback | none | native
    corrected_query varchar(191) NOT NULL DEFAULT '',
    session_hash char(32) NOT NULL DEFAULT '',
    clicks smallint(5) unsigned NOT NULL DEFAULT 0,
    clicked_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
    click_position smallint(5) unsigned NOT NULL DEFAULT 0,
    order_id bigint(20) unsigned NOT NULL DEFAULT 0,
    order_total decimal(14,2) NOT NULL DEFAULT 0.00,
    searched_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY search_uid (search_uid),
    KEY normalized_query (normalized_query),
    KEY session_time (session_hash,searched_at),
    KEY results_time (has_results,searched_at),
    KEY searched_at (searched_at)
);

CREATE TABLE {$wpdb->prefix}woo_search_index (
    product_id bigint(20) unsigned NOT NULL,
    title text NOT NULL,
    skus text NOT NULL,
    terms text NOT NULL,
    excerpt text NOT NULL,
    stock_status varchar(20) NOT NULL DEFAULT 'instock',
    total_sales bigint(20) unsigned NOT NULL DEFAULT 0,
    indexed_at datetime NOT NULL,
    PRIMARY KEY  (product_id),
    KEY stock_status (stock_status),
    KEY indexed_at (indexed_at)
);
```

---

## 📂 Organisation du Code Source

```text
woo-search-intelligence-soyoo/
├── bin/
│   └── build-zip.ps1                   # Script de packaging de l'archive ZIP Linux/WordPress
├── includes/
│   ├── class-search-text.php           # Normalisation pure (pliage, lemmatisation, correction) — testée
│   ├── class-search-installer.php      # Schéma versionné, réglages par défaut, crons, async, purge
│   ├── class-search-indexer.php        # Index produit incrémental, reconstruction par lots, vocabulaire
│   ├── class-search-engine.php         # Plan de requête, scoring, live search, page de résultats, synonymes
│   ├── class-search-tracker.php        # Journalisation, clics, conversions, anti-bot, alertes, maintenance
│   ├── class-search-admin.php          # Interface 4 onglets, KPIs, blacklist, pagination, export CSV
│   └── class-search-importer.php       # Migration par lots depuis Search Analytics for WP
├── assets/
│   ├── css/admin.css                   # Styles de l'interface d'administration
│   ├── css/frontend.css                # Styles du live search intégré (optionnel)
│   ├── js/admin.js                     # Contrôleur admin (AJAX, batchs index/import, synonymes)
│   └── js/frontend.js                  # Mesure des clics/conversions + live search intégré (optionnel)
├── plugin-update-checker/              # Bibliothèque PUC v5.6 pour auto-updates via GitHub Releases
├── tests/
│   └── run-tests.php                   # Tests CLI de Woo_Search_Text (hors archive de release)
├── uninstall.php                       # Nettoyage à la désinstallation (données sur option)
├── woo-search-intelligence-soyoo.php   # Point d'entrée, HPOS, PUC v5.6, bootstrap
├── PROJECT_CONTEXT.md                  # Spécifications et contexte technique complet (ce fichier)
├── AGENTS.md                           # Directives de maintenance et gouvernance pour agents
└── README.md                           # Documentation publique du dépôt
```
