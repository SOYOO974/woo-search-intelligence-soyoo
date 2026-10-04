# Directives pour Agents Antigravity — Woo Search Intelligence by SOYOO

## 🔒 SANCTUARISATION DU PLUGIN & GOUVERNANCE

> [!CRITICAL]
> **SOURCE DE VÉRITÉ ABSOLUE : `C:\Antigravity\woo-plugins\woo-search-intelligence-soyoo`**
>
> 1. **Interdiction de modification depuis un projet client** :
>    - Dès qu'un agent intervient sur un projet client (ex: Jardin Naturel, Bâches MFM, Conforama), il lui est **formellement interdit** de modifier directement les fichiers de cette extension sur le serveur ou dans un sous-dossier client.
>    - Toute évolution ou correction de bug doit obligatoirement être réalisée et versionnée au sein de ce dépôt dédié.
> 2. **Découplage Métier par Hooks** :
>    - Ne jamais réintroduire de règles spécifiques à un client (ex: regex de dimensions MFM ou contenances Jardin Naturel) en dur dans le cœur du plugin.
>    - Utiliser systématiquement les filtres `woo_search_normalize_query`, `woo_search_default_synonyms`, `woo_search_accent_map` et `woo_search_is_bot`.

---

## 🔄 WORKFLOW DE PUBLICATION & CONTRÔLE QUALITÉ

Dès qu'une modification ou amélioration est apportée à cette extension :

1. **Validation & Linting Syntaxique Obligatoire** :
   - Exécuter impérativement `php -l` sur l'ensemble des fichiers PHP du projet :
     ```powershell
     Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
     ```

2. **Commit Conventionnel & Push Proactif sur GitHub** :
   - Exécuter automatiquement le cycle Git sans attendre de consigne de Julien :
     ```bash
     git add .
     git commit -m "feat/fix: <description explicite conventionnelle>"
     git push origin main
     ```

3. **Génération d'Archive Release (.zip)** (si demandée) :
   - Ne pas utiliser `Compress-Archive` sous Windows PowerShell (produit des antislashs `\` qui brisent l'archive sur serveurs Linux).
   - Utiliser `tar` avec forward slashes `/` depuis le dossier parent :
     ```bash
     cd C:\Antigravity\woo-plugins
     tar -a -cf woo-search-intelligence-soyoo\woo-search-intelligence-soyoo.zip --exclude=.git --exclude=woo-search-intelligence-soyoo.zip woo-search-intelligence-soyoo
     ```

---

## 🧱 ARCHITECTURE & FICHIERS DU PROJET

- [`woo-search-intelligence-soyoo.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/woo-search-intelligence-soyoo.php) : Point d'entrée de l'extension, vérification WooCommerce, déclaration HPOS (`custom_order_tables` et `cart_checkout_blocks`), création de la table `{$wpdb->prefix}woo_search_logs` via `dbDelta` et crons.
- [`includes/class-search-engine.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/includes/class-search-engine.php) : Moteur de recherche natif, scoring multi-paliers (SKU variation/parent, titre préfixe, phrase exacte, tous les mots, mots distincts, extrait, stock), lemmatisation française, synonymes et transients.
- [`includes/class-search-tracker.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/includes/class-search-tracker.php) : Tracker universel (Live search AJAX + soumissions de formulaire standard sur `template_redirect`), Anti-Bot Shield sur User-Agent, anti-flood 15s et alertes par e-mail.
- [`includes/class-search-admin.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/includes/class-search-admin.php) : Page d'administration sous WooCommerce, gestion des 4 onglets, inline edit des synonymes, masquage universel des termes (Blacklist), pagination serveur 25 items et export CSV formaté Excel.
- [`includes/class-search-importer.php`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/includes/class-search-importer.php) : Outil de migration asynchrone par lots (250 items/lot) avec barre de progression temps réel depuis Search Analytics for WP (`mwt_search_terms`, `mwt_search_history`).
- [`assets/css/admin.css`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/assets/css/admin.css) : Styles soignés et responsives du tableau de bord.
- [`assets/js/admin.js`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/assets/js/admin.js) : Contrôleur client, interactions AJAX, filtrage temps réel et boucle séquentielle d'importation batchée.
- [`PROJECT_CONTEXT.md`](file:///C:/Antigravity/woo-plugins/woo-search-intelligence-soyoo/PROJECT_CONTEXT.md) : Spécifications techniques et fonctionnelles complètes du projet.
