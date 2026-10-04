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
>    - Utiliser systématiquement les filtres `woo_search_normalize_query`, `woo_search_default_synonyms`, `woo_search_accent_map` et `woo_search_is_bot`.

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

- [`woo-search-intelligence-soyoo.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/woo-search-intelligence-soyoo.php) : Point d'entrée de l'extension, vérification WooCommerce, déclaration HPOS (`custom_order_tables` et `cart_checkout_blocks`), initialisation de `plugin-update-checker` (PUC v5.6) avec `enableReleaseAssets()`, création de la table `{$wpdb->prefix}woo_search_logs` via `dbDelta` et crons.
- [`bin/build-zip.ps1`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/bin/build-zip.ps1) : Script de packaging automatisé pour générer l'archive release compatible Linux/WordPress.
- [`plugin-update-checker/`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/plugin-update-checker/) : Bibliothèque PUC v5.6 autonome pour les mises à jour automatiques via GitHub Releases.
- [`includes/class-search-engine.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/includes/class-search-engine.php) : Moteur de recherche natif, scoring multi-paliers (SKU variation/parent, titre préfixe, phrase exacte, tous les mots, mots distincts, extrait, stock), lemmatisation française, synonymes et transients.
- [`includes/class-search-tracker.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/includes/class-search-tracker.php) : Tracker universel (Live search AJAX + soumissions de formulaire standard sur `template_redirect`), Anti-Bot Shield sur User-Agent, anti-flood 15s et alertes par e-mail.
- [`includes/class-search-admin.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/includes/class-search-admin.php) : Page d'administration sous WooCommerce, gestion des 4 onglets, inline edit des synonymes, masquage universel des termes (Blacklist), pagination serveur 25 items et export CSV formaté Excel.
- [`includes/class-search-importer.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/includes/class-search-importer.php) : Outil de migration asynchrone par lots (250 items/lot) avec barre de progression temps réel depuis Search Analytics for WP (`mwt_search_terms`, `mwt_search_history`).
- [`assets/css/admin.css`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/assets/css/admin.css) : Styles soignés et responsives du tableau de bord.
- [`assets/js/admin.js`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/assets/js/admin.js) : Contrôleur client, interactions AJAX, filtrage temps réel et boucle séquentielle d'importation batchée.
- [`PROJECT_CONTEXT.md`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/PROJECT_CONTEXT.md) : Spécifications techniques et fonctionnelles complètes du projet.
