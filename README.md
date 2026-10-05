# WooCommerce Search Intelligence by SOYOO

Moteur de recherche e-commerce propriétaire pour WooCommerce, conçu et maintenu par **SOYOO**.
Version actuelle : **1.2.0** — PHP 7.4+, WordPress 6.5+, WooCommerce 8.0+, compatible HPOS.

---

## 🚀 Pourquoi ce moteur

La recherche native WooCommerce (`LIKE` sur `post_title`/`post_content`) pose quatre problèmes :

1. **Aucune tolérance** : pluriels (*« bâches »*), accents (*« bache »*), fautes (*« tondeuze »*) et SKU partiels échouent.
2. **Incohérence** : la liste déroulante du thème et la page « Voir tous les résultats » ne renvoient pas les mêmes produits.
3. **Pas de mesure utile** : on sait ce que les clients tapent, pas s'ils cliquent ni s'ils achètent.
4. **Statistiques polluées** par les robots et par chaque frappe du live search.

Le plugin remplace ce moteur par un index dédié, le branche sur le live search **et** sur la page de résultats, et mesure recherche → clic → commande.

---

## ✨ Fonctionnalités

### Moteur
- **Index dédié** `{prefix}woo_search_index` : une ligne par produit visible (titre, SKU parent + variations + GTIN, catégories et ancêtres, étiquettes, marques, attributs, extrait), textes pré-pliés (minuscules, sans accents ni ponctuation). Mis à jour en continu sur les événements WooCommerce (CRUD produit, stock, statut, corbeille, termes), reconstruction complète en tâche de fond (Action Scheduler) ou depuis l'admin.
- **Tous les mots significatifs requis** (mots vides ignorés), avec repli :
  1. correspondance de tous les mots ;
  2. **correction orthographique** à partir du vocabulaire du catalogue (Levenshtein ≤ 1 ou 2) : *« tondeuze »* → *« tondeuse »* ;
  3. correspondances partielles (optionnel) : produits contenant le plus de mots.
- **Lemmatisation française** (pluriels `-s`, `-x`, `-eaux`, `-aux`, féminins), liste d'exceptions (*noix, prix, engrais…*) pour éviter le bruit.
- **Frontières chiffres/lettres** natives : *« 4x5m »* = *« 4 x 5 m »*, *« 500g »* = *« 500 g »*.
- **Classement** : SKU exact > début de SKU (si la saisie contient un chiffre, ≥ 3 caractères) > titre exact > titre commençant par la requête > phrase dans le titre > tous les mots dans le titre > mots dans catégories/attributs/extrait > en stock > ventes.
- **Synonymes** : *Expansion* (le terme OU son équivalent) et *Remplacement* (réécriture), identifiants stables, packs suggérés.
- **Intégration de la page de résultats** (`?s=…&post_type=product`) : mêmes produits et même ordre que le live search, tri WooCommerce explicite respecté, bandeau « Résultats pour … » en cas de correction.
- **Support natif WoodMart** (`woodmart_ajax_search`) : injection automatique de la pertinence SOYOO dans le dropdown AJAX du thème WoodMart, scoring complet et fragments `#wsi=...` pour la mesure des clics.
- **Cache** : object cache (Redis) si disponible, invalidation par génération à chaque modification du catalogue ou des synonymes. Aucun transient par frappe dans `wp_options`.

### Mesure
- **Journalisation universelle** : live search (AJAX) et page de résultats, écriture différée après l'envoi de la réponse (`fastcgi_finish_request`), consolidation des frappes successives d'une même session.
- **CTR et position cliquée** : fragment `#wsi=<uid>.<produit>.<position>` sur les liens du live search (fonctionne aussi avec la liste déroulante du thème), capture des clics sur la page de résultats, envoi par `navigator.sendBeacon`.
- **Attribution des commandes** : cookie first-party `wsi_ref` (24 h, `SameSite=Lax`), commandes classiques et checkout Blocks (Store API).
- **Anti-robots** sur User-Agent (filtrable), exclusion optionnelle des gestionnaires, empreinte de session salée renouvelée chaque jour (aucune IP stockée).
- **Rétention** configurable (730 jours par défaut), purge quotidienne.

### Administration (WooCommerce > Search Intelligence)
- **Paramètres & Index** : réglages moteur/affichage/mesure, état de l'index et reconstruction par lots avec progression, vidage du cache, migration Search Analytics for WP.
- **Synonymes** : ajout, édition en ligne, filtre, packs suggérés.
- **Statistiques** : volume, visiteurs, taux de 0 résultat, CTR, position moyenne, commandes et CA attribués, top recherches (7 j / 30 j / 90 j / tout).
- **0 résultat** : chaque terme est re-testé sur le moteur actuel (« Résolu » / « Toujours 0 » / suggestion), association en 1 clic, ignorer (unitaire ou en masse).
- **Export CSV** (BOM UTF-8 pour Excel, protection contre l'injection de formules).
- **Alertes e-mail** en tâche de fond : rapport hebdomadaire (KPIs + top 10 sans résultat) ou alerte sur seuil de visiteurs distincts.

### Interface front optionnelle
Live search intégré sans dépendance (désactivé par défaut) pour les thèmes sans liste déroulante : ARIA combobox, navigation clavier, requêtes annulables, styles surchargeables par variables CSS (`--wsi-accent`, `--wsi-radius`…).

---

## 🗄️ Base de données (schéma v2)

Créée à l'activation puis **migrée automatiquement** à chaque chargement si `woo_search_db_version` diffère (les mises à jour par GitHub Releases ne déclenchent pas le hook d'activation). Toutes les dates sont en **UTC**.

| Table | Rôle |
|---|---|
| `{prefix}woo_search_logs` | Une ligne par recherche : `search_uid`, `query`, `normalized_query`, `results_count`, `has_results`, `source` (ajax/page/import), `match_mode`, `corrected_query`, `session_hash`, `clicks`, `clicked_product_id`, `click_position`, `order_id`, `order_total`, `searched_at` |
| `{prefix}woo_search_index` | Index produit : `product_id`, `title`, `skus`, `terms`, `excerpt`, `stock_status`, `total_sales`, `indexed_at` |

Options principales : `woo_search_settings`, `woo_search_synonyms`, `woo_search_ignored_terms`, `woo_search_vocab`, `woo_search_index_state`, `woo_search_cache_gen`.

---

## 🛠️ Endpoint live search

```
GET /?wc-ajax=woo_live_search&term=bache%20verte
```

Paramètre `term` (ou `s`, `q`), 100 caractères max. Réponse `{ success, data }` :

| Clé | Contenu |
|---|---|
| `products[]` | `id`, `title`, `permalink` (avec `#wsi=…` si mesure active), `image_url`, `price_html`, `sku`, `is_sku_match`, `in_stock`, `stock_status`, `position` |
| `categories[]` | `id`, `name`, `permalink`, `count` |
| `total_count` / `total_found` | Produits renvoyés / trouvés |
| `see_all_url` | Lien vers la page de résultats complète |
| `match_mode` | `all`, `corrected`, `any`, `fallback`, `none` |
| `corrected_query`, `notice` | Correction appliquée et message prêt à afficher |
| `search_uid`, `query`, `display` | Identifiant de mesure, requête normalisée, options d'affichage |

Les thèmes doivent utiliser `permalink` tel quel pour que les clics soient mesurés.

### Migration d'un thème existant (Bâches MFM, Jardin Naturel)

1. Supprimer (ou désactiver) l'ancien moteur du thème (`inc/search/class-*-search-engine.php`) **avant** d'activer le plugin, pour éviter deux handlers sur la même action.
2. Si le JS du thème appelle encore ses anciennes actions, les rediriger vers le moteur depuis le thème :

```php
add_filter( 'woo_search_ajax_actions', function ( array $actions ): array {
    return array_merge( $actions, [ 'mfm_live_search', 'jd_live_search' ] );
} );
```

3. Laisser « Interface de live search intégrée » désactivée si le thème a sa propre liste déroulante.
4. Reconstruire l'index (automatique à l'activation) puis vérifier l'onglet 0 résultat après quelques jours.

---

## 🔌 Hooks (découplage métier)

Aucune règle client dans le cœur : chaque boutique se personnalise depuis son thème.

| Filtre | Arguments | Usage |
|---|---|---|
| `woo_search_normalize_query` | `string $display, string $raw` | Règles métier sur la requête avant pliage |
| `woo_search_accent_map` | `array $map` | Équivalences de caractères (reconstruire l'index après modification) |
| `woo_search_default_synonyms` | `array $rules` | Synonymes injectés au premier démarrage |
| `woo_search_recommended_packs` | `array $packs` | Packs suggérés dans l'admin |
| `woo_search_stop_words` | `array $words` | Mots vides |
| `woo_search_stem_exceptions` | `array $words` | Mots invariables (pas de lemmatisation) |
| `woo_search_title_acronyms` | `array $acronyms` | Sigles préservés par l'harmonisation des titres |
| `woo_search_index_taxonomies` | `array $taxonomies` | Taxonomies indexées (marques tierces…) |
| `woo_search_index_data` | `array $data, WC_Product $product` | Enrichir la ligne d'index (ex. méta personnalisée) |
| `woo_search_ajax_actions` | `array $actions` | Actions AJAX servies par le moteur (alias de thème) |
| `woo_search_live_response` | `array $payload, array $result` | Enrichir la réponse JSON |
| `woo_search_is_bot` | `bool $is_bot, string $user_agent` | Détection des robots |
| `woo_search_max_ids` | `int` (500) | Plafond de résultats classés |
| `woo_search_max_categories` | `int` (3) | Catégories suggérées |
| `woo_search_image_size` | `string` | Taille des miniatures |
| `woo_search_synonym_placeholders` | `array $placeholders` | Placeholders d'exemple du formulaire d'ajout de synonyme |
| `woo_search_ignored_query_params` | `array $keys` | Paramètres GET de facettes/filtres ignorés par le tracking (exact ou wildcard `*`) |

Exemples :

```php
// Indexer une méta « référence fournisseur » (valeurs brutes : le pliage est appliqué ensuite).
add_filter( 'woo_search_index_data', function ( array $data, WC_Product $product ): array {
    $ref = (string) $product->get_meta( '_ref_fournisseur' );
    if ( '' !== $ref ) {
        $data['skus'][] = $ref;
    }
    return $data;
}, 10, 2 );

// Synonymes métier par défaut.
add_filter( 'woo_search_default_synonyms', function ( array $rules ): array {
    return array_merge( $rules, [
        [ 'from' => 'toile', 'to' => 'bâche', 'type' => 'expand' ],
        [ 'from' => 'camion', 'to' => 'remorque', 'type' => 'replace' ],
    ] );
} );

// Exclure une sonde de monitoring interne des statistiques.
add_filter( 'woo_search_is_bot', function ( bool $is_bot, string $ua ): bool {
    return $is_bot || str_contains( $ua, 'MonSondeInterne' );
}, 10, 2 );

// Ignorer les filtres et facettes personnalisés d'un thème dans le tracking de recherche.
add_filter( 'woo_search_ignored_query_params', function ( array $keys ): array {
    return array_merge( $keys, [ 'cdc_cat', 'cdc_brand', 'cdc_stock', 'cdc_*' ] );
} );
```

---

## 🔒 Confidentialité

- Aucune IP ni identifiant client stocké : empreinte de session `wp_hash(IP + UA + jour)`, non réversible et renouvelée chaque jour.
- Cookie `wsi_ref` (identifiant de recherche aléatoire, 24 h) déposé uniquement après un clic sur un résultat, si l'attribution des commandes est activée. À déclarer dans la politique cookies comme cookie de mesure first-party ; désactivable dans les réglages.

---

## 📦 Installation & mises à jour

1. Installer l'archive `woo-search-intelligence-soyoo.zip` de la dernière [release GitHub](https://github.com/SOYOO974/woo-search-intelligence-soyoo/releases) (Extensions > Ajouter > Téléverser).
2. Activer : les tables sont créées et l'index se construit en tâche de fond.
3. **WooCommerce > Search Intelligence** : vérifier l'état de l'index, régler les alertes, importer l'historique Search Analytics for WP si présent.

Les mises à jour suivantes arrivent dans **Extensions** via Plugin Update Checker (GitHub Releases), sans FTP. Le schéma est migré automatiquement.

**Désinstallation** : les tâches planifiées sont toujours supprimées ; les données ne le sont que si « Supprimer toutes les données à la désinstallation » est coché.

---

## 🧪 Développement

```powershell
# Lint PHP
Get-ChildItem -Recurse -Filter *.php | Where-Object { $_.FullName -notmatch 'plugin-update-checker' } | ForEach-Object { php -l $_.FullName }

# Tests unitaires de la couche texte (sans WordPress)
php tests/run-tests.php

# Archive de release
powershell -ExecutionPolicy Bypass -File .\bin\build-zip.ps1
```

---

## 📝 Changelog

### 1.2.1
- Ajout du filtre `woo_search_ignored_query_params` et de la méthode `Woo_Search_Tracker::is_ignored_refinement_query()` : permet aux thèmes d'exclure leurs paramètres personnalisés de facettes et de filtres (ex: `cdc_cat`, `cdc_brand`, `cdc_stock`, ou motifs avec joker `cdc_*`) pour éviter les faux ré-enregistrements de recherches lors des affinages de catalogue.

### 1.2.0
- Intégration et compatibilité native avec le live search AJAX WoodMart (`woodmart_ajax_search`) et mesure complète des clics associés.

### 1.1.3
- Rétrocompatibilité PHP 7.4.33 et shims polyfill pour `str_starts_with`, `str_ends_with` et `str_contains`.

### 1.1.2
- Harmonisation universelle des placeholders de synonymes via le filtre `woo_search_synonym_placeholders`.

### 1.1.1
- Rendu de la carte KPI « Sans résultat » (onglet Statistiques) cliquable pour accéder directement à l'onglet « 0 Résultat & Opportunités » en conservant la période filtrée active.
- Micro-interaction visuelle sur la carte KPI cliquable (élévation au survol, flèche indicatrice animée).

### 1.1.0
- Index dédié `woo_search_index` mis à jour en continu + reconstruction par lots (Action Scheduler / admin).
- Correction orthographique sur le vocabulaire du catalogue, repli partiel optionnel.
- Intégration de la page de résultats (mêmes produits et même ordre que le live search).
- Mesure des clics (CTR, position) et attribution des commandes (cookie `wsi_ref`, checkout classique et Blocks).
- Schéma v2 versionné avec migration automatique (compatible mises à jour PUC), dates en UTC.
- Synonymes à identifiants stables, KPIs mis en cache, export CSV sécurisé, termes « Résolu » dans l'onglet 0 résultat.
- Alias AJAX client retirés du cœur (filtre `woo_search_ajax_actions`), filtre `woo_search_accent_map` rétabli.
- Importateur par curseur (lots de 500), `uninstall.php`, live search front optionnel sans dépendance.

### 1.0.0
- Version initiale.

---

## 🔒 Sanctuarisation & maintenance SOYOO

Actif propriétaire SOYOO. Toute modification se fait et se teste dans le dépôt dédié :
`https://github.com/SOYOO974/woo-search-intelligence-soyoo`

Développé par Julien Vanwinsberghe — **SOYOO**.
