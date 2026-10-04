# Directives pour Agents Antigravity — Woo Search Intelligence by SOYOO

## 🔒 SANCTUARISATION DU PLUGIN & GOUVERNANCE

> [!CRITICAL]
> **SOURCE DE VÉRITÉ ABSOLUE : `C:\Antigravity\woo-plugins\woo-search-intelligence-soyoo`**
>
> 1. **Interdiction formelle de modification depuis un projet client** :
>    - Dès qu'un agent intervient sur un projet client (ex: Jardin Naturel, Bâches MFM, Conforama), il lui est **formellement interdit** de modifier directement les fichiers de cette extension sur le serveur ou dans un sous-dossier client.
>    - Toute évolution ou correction de bug doit obligatoirement être réalisée et versionnée au sein de ce dépôt dédié.
> 2. **Découplage Métier par Hooks** :
>    - Ne jamais réintroduire de règles spécifiques à un client (ex: regex de dimensions MFM ou contenances Jardin Naturel) en dur dans le cœur du plugin.
>    - Utiliser systématiquement les filtres `woo_search_normalize_query`, `woo_search_default_synonyms`, `woo_search_accent_map`, `woo_search_is_bot`, `woo_search_index_data`, `woo_search_index_taxonomies` et `woo_search_ajax_actions` (alias AJAX des thèmes, jamais en dur dans le cœur). Liste complète dans `README.md`.

---

## 🚫 PROTOCOLE DE DÉPLOIEMENT : ZÉRO DÉPLOIEMENT FTP PAR DÉFAUT

> [!CRITICAL]
> **INTERDICTION FORMELLE DE DÉPLOYER PAR FTP / SFTP OU DE PROPOSER DES TABLEAUX RÉCAPITULATIFS FTP SANS DEMANDE EXPLICITE DE JULIEN.**
> 
> Cette extension in-house mutualisée est hébergée sur GitHub (`SOYOO974/woo-search-intelligence-soyoo`) et intègre **Plugin Update Checker (PUC v5.6)** configuré pour surveiller les assets de release GitHub.
> Les sites WordPress/WooCommerce clients (Bâches MFM, Jardin Naturel, Conforama, etc.) se mettent à jour **automatiquement en 1 clic** via le tableau de bord WordPress dès qu'une nouvelle release GitHub est publiée.

---

## 🔄 WORKFLOW DE PUBLICATION & CONTRÔLE QUALITÉ

Dès qu'une modification ou amélioration est apportée à cette extension :

1. **Incrémentation de Version** :
   - Mettre à jour la version sémantique dans l'en-tête de [`woo-search-intelligence-soyoo.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/woo-search-intelligence-soyoo.php) (`Version: X.Y.Z`).
   - Mettre à jour la constante `WOO_SEARCH_INTEL_VERSION` dans [`woo-search-intelligence-soyoo.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/woo-search-intelligence-soyoo.php).

2. **Validation & Linting Syntaxique Obligatoire** :
   - Exécuter impérativement `php -l` sur l'ensemble des fichiers PHP du projet :
     ```powershell
     Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
     php tests/run-tests.php
     ```

3. **Commit Conventionnel & Push Proactif sur GitHub** :
   - Exécuter automatiquement le cycle Git sans attendre de consigne de Julien :
     ```bash
     git add .
     git commit -m "feat/fix: <description explicite conventionnelle> (vX.Y.Z)"
     git push origin main
     ```

4. **Génération de l'Archive Release (.zip)** :
   - Exécuter le script de build standardisé :
     ```powershell
     powershell -ExecutionPolicy Bypass -File .\bin\build-zip.ps1
     ```
   - Le script utilise `tar -a -cf` avec des séparateurs forward slashes `/` stricts pour garantir une compatibilité totale avec l'unzip WordPress sur serveurs Linux.

5. **Publication Proactive de la Release GitHub** :
   - Créer immédiatement la release GitHub avec l'archive attachée via GitHub CLI (`gh`) :
     ```bash
     gh release create vX.Y.Z woo-search-intelligence-soyoo.zip --title "vX.Y.Z - <Titre court>" --notes "<Changelog formaté>"
     ```
   - Les sites WordPress clients détectent automatiquement la nouvelle version et l'appliquent via le gestionnaire d'extensions WordPress.

---

## 🧱 ARCHITECTURE & FICHIERS DU PROJET

- [`woo-search-intelligence-soyoo.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/woo-search-intelligence-soyoo.php) : Point d'entrée, en-têtes (`Requires Plugins: woocommerce`), déclaration HPOS (`custom_order_tables` et `cart_checkout_blocks`), initialisation de `plugin-update-checker` (PUC v5.6) avec `enableReleaseAssets()`, hooks d'activation/désactivation délégués à `Woo_Search_Installer`, bootstrap des modules sur `plugins_loaded`.
- [`uninstall.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/uninstall.php) : Suppression des crons ; purge des données uniquement si l'option `delete_data_on_uninstall` est cochée.
- [`bin/build-zip.ps1`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/bin/build-zip.ps1) : Script de packaging automatisé pour générer l'archive release compatible Linux/WordPress.
- [`plugin-update-checker/`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/plugin-update-checker/) : Bibliothèque PUC v5.6 autonome pour les mises à jour automatiques via GitHub Releases.
- [`includes/class-search-text.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/includes/class-search-text.php) : Normalisation pure sans WordPress (pliage accents/ponctuation/chiffres-lettres, lemmatisation FR, correction orthographique, harmonisation des titres). Couverte par `tests/run-tests.php`.
- [`includes/class-search-installer.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/includes/class-search-installer.php) : Schéma versionné (`DB_VERSION`, migration auto via `maybe_upgrade()` car PUC ne déclenche pas l'activation), réglages par défaut, crons, exécution asynchrone (Action Scheduler / WP-Cron), purge.
- [`includes/class-search-indexer.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/includes/class-search-indexer.php) : Index produit `{prefix}woo_search_index` mis à jour en continu, reconstruction par lots, vocabulaire pour la correction orthographique, génération de cache.
- [`includes/class-search-engine.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/includes/class-search-engine.php) : Plan de requête, modes all/corrected/any, scoring (SKU, titre, termes, extrait, stock, ventes), endpoint `woo_live_search`, intégration de la page de résultats, synonymes à identifiants stables.
- [`includes/class-search-tracker.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/includes/class-search-tracker.php) : Journalisation différée (AJAX + page), consolidation 45 s, clics (`wsi_click`), attribution des commandes (cookie `wsi_ref`), Anti-Bot Shield, alertes et maintenance quotidienne.
- [`includes/class-search-admin.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/includes/class-search-admin.php) : Page d'administration sous WooCommerce, 4 onglets, KPIs (CTR, conversions), état/reconstruction de l'index, synonymes, blacklist, pagination serveur 25 items et export CSV sécurisé.
- [`includes/class-search-importer.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/includes/class-search-importer.php) : Migration par lots (500, curseur de clé, dates converties en UTC) depuis Search Analytics for WP (`mwt_search_terms`, `mwt_search_history`).
- [`assets/css/admin.css`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/assets/css/admin.css) / [`assets/js/admin.js`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/assets/js/admin.js) : Tableau de bord (helper AJAX unique avec gestion de session expirée, boucles batch index/import, édition en ligne).
- [`assets/js/frontend.js`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/assets/js/frontend.js) / [`assets/css/frontend.css`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/assets/css/frontend.css) : Mesure des clics/conversions (toujours chargé si la mesure est active) et live search intégré optionnel (désactivé par défaut).
- [`tests/run-tests.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/tests/run-tests.php) : Tests CLI de la couche texte (`php tests/run-tests.php`), exclus de l'archive.
- [`PROJECT_CONTEXT.md`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/PROJECT_CONTEXT.md) : Spécifications techniques et fonctionnelles complètes du projet.

> [!IMPORTANT]
> Toute modification du schéma SQL impose d'incrémenter `Woo_Search_Installer::DB_VERSION`. Toute modification de `Woo_Search_Text::fold()` ou des données indexées impose une reconstruction de l'index (déclenchée automatiquement si `DB_VERSION` change).
