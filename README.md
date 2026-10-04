# WooCommerce Search Intelligence by SOYOO

Moteur de recherche e-commerce propriétaire haute performance pour WooCommerce, conçu et développé par **SOYOO**.

---

## 🚀 Présentation & Proposition de Valeur

Sur beaucoup de boutiques en ligne WooCommerce, le moteur de recherche par défaut souffre de limitations critiques :
1. **Tolérance zéro aux fautes et pluriels** : une recherche au pluriel (*« bâches »*, *« gâteaux »*, *« biscuits »*) échoue si le produit est au singulier.
2. **Trou noir de tracking** : les extensions d'autocomplétion AJAX ne loggent souvent que le direct search, ignorant les formulaires standards ou les filtres d'URL, faussant les statistiques commerciales.
3. **Pollution par les bots et crawlers** : les robots de scraping et d'indexation (Semrush, Ahrefs, PetalBot, Googlebot) génèrent des milliers de fausses requêtes à 0 résultat, trompant les gestionnaires e-commerce.
4. **Dette technique des plugins tiers** : l'extension *Search Analytics for WP* n'est plus maintenue, alourdit les bases MySQL sans indexation moderne et n'est pas optimisée pour WooCommerce HPOS.

**WooCommerce Search Intelligence** résout définitivement ces problématiques au sein d'une extension propriétaire 100% autonome, ultra-légère et extensible.

---

## ✨ Fonctionnalités Clés

- **⚡ Scoring SQL Multi-Paliers** :
  - **Tier 0** : Correspondance exacte SKU sur produits parents et déclinaisons (+100 000 points).
  - **Tier 1** : Titre commençant par la requête exacte (+10 000 points).
  - **Tier 2** : Titre contenant la phrase exacte (+5 000 points).
  - **Tier 3** : Titre contenant tous les mots significatifs (+2 000 points).
  - **Tier 4** : Bonus par mot distinct dans le titre (+150 points / mot).
  - **Tier 5** : Bonus par mot dans l'extrait / courte description (+30 points / mot).
  - **Tier 6** : Bonus produit en stock (+20 points).
  - Tri secondaire par popularité des ventes (`total_sales`) pour maximiser le taux de conversion.

- **🇫🇷 Lemmatisation & Accents Français** :
  - Gestion native des pluriels irréguliers français : `-eaux / -eau`, `-aux / -al / -ail`, `-s`, `-x`.
  - Dictionnaire d'équivalences d'accents fréquentes (*« thé »* ↔ *« the »*, *« bâche »* ↔ *« bache »*, *« café »* ↔ *« cafe »*, *« pâte »* ↔ *« pate »*, etc.).

- **🛡️ Bouclier Anti-Robots (Anti-Bot Shield)** :
  - Détection et exclusion systématique des crawlers et scrapers (Googlebot, Bingbot, Semrush, Ahrefs, PetalBot, Yandex, ByteSpider, curl, Python, headless browsers).
  - Vos KPIs reflètent 100% de visiteurs humains réels.

- **🌐 Journalisation Universelle (0 Trou Noir)** :
  - Interception des requêtes AJAX Live Search.
  - Interception des formulaires standards de recherche et des accès directs par URL (`template_redirect`), différée au `shutdown` pour préserver le TTFB.
  - Anti-flood intelligent de 15 secondes pour consolider les frappes successives d'une même session.

- **📖 Dictionnaire & Synonymes Intelligents** :
  - Règles de **Remplacement** (redirection stricte) et d'**Expansion** (élargissement de la requête).
  - Modification en ligne (Inline Edit) sans rechargement de page.
  - Filtrage instantané et installation de packs sectoriels en 1 clic.

- **🚨 0 Résultat & Blacklist Universelle** :
  - Détection des opportunités commerciales manquées.
  - Bouton **« Associer en 1 clic »** à la suggestion catalogue la plus proche.
  - Bouton **« Ignorer ce terme »** (Blacklist) utilisable indifféremment depuis le Top Recherches ou l'écran 0 Résultat. Les termes ignorés sont exclus des KPIs, des tableaux et des alertes.
  - Alertes automatiques par e-mail : rapport hebdomadaire ou alerte instantanée sur franchissement de seuil.

- **📥 Importateur Batché Search Analytics for WP** :
  - Détection automatique des tables `wp_mwt_search_terms` et `wp_mwt_search_history`.
  - Migration par lots de 250 enregistrements avec barre de progression temps réel (0 à 100%) sans timeout serveur ni duplication.

- **📊 Export CSV & Pagination Dédiée** :
  - Export CSV direct formaté avec BOM UTF-8 (ouverture immédiate dans Microsoft Excel sans problème d'accents).
  - Pagination par lots de 25 lignes pour absorber sans ralentissement des dizaines de milliers de logs.

- **🔄 Mises à Jour Automatiques en 1 Clic (GitHub Releases)** :
  - Intégration native de Plugin Update Checker (PUC v5.6).
  - Détection et mise à niveau transparente depuis l'administration WordPress (`wp-admin > Extensions`) dès qu'une release GitHub est publiée, sans nécessiter de transfert FTP manuel.

---

## 🗄️ Structure de la Base de Données

Table MySQL créée automatiquement lors de l'activation via `dbDelta` :

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

## 🔌 Découplage Métier par Hooks WordPress

Le cœur de l'extension reste universel et indépendant. Chaque site client personnalise la normalisation et les synonymes via son thème ou un snippet d'administration.

### 1. Normalisation Métier (`woo_search_normalize_query`)

#### Exemple pour Bâches MFM (Normalisation des dimensions) :
```php
add_filter( 'woo_search_normalize_query', function( string $clean_query, string $raw_query ): string {
    // Standardise les dimensions : "2x3", "2*3", "2X3", "2,5x4" -> "2 x 3"
    return preg_replace( '/(\d+(?:[.,]\d+)?)\s*(?:[xX*×]|x)\s*(\d+(?:[.,]\d+)?)/ui', '$1 x $2', $clean_query );
}, 10, 2 );
```

#### Exemple pour Jardin Naturel (Normalisation des contenances bio) :
```php
add_filter( 'woo_search_normalize_query', function( string $clean_query, string $raw_query ): string {
    // Standardise les contenances : "500g" -> "500 g", "1kg" -> "1 kg", "250ml" -> "250 ml"
    return preg_replace( '/(\d+(?:[.,]\d+)?)\s*(g|kg|ml|cl|l)\b/ui', '$1 $2', $clean_query );
}, 10, 2 );
```

---

### 2. Synonymes par Défaut (`woo_search_default_synonyms`)

Permet de pré-remplir le dictionnaire lors de l'initialisation du site client :

```php
add_filter( 'woo_search_default_synonyms', function( array $synonyms ): array {
    return array_merge( $synonyms, [
        [ 'from' => 'camion',  'to' => 'remorque', 'type' => 'replace' ],
        [ 'from' => 'toile',   'to' => 'bâche',    'type' => 'replace' ],
        [ 'from' => 'corde',   'to' => 'sandow',   'type' => 'expand' ],
    ] );
} );
```

---

### 3. Filtre Anti-Bot Personnalisé (`woo_search_is_bot`)

```php
add_filter( 'woo_search_is_bot', function( bool $is_bot, string $user_agent ): bool {
    // Exemple : exclure un outil de monitoring interne
    if ( str_contains( $user_agent, 'MonSondeInterne' ) ) {
        return true;
    }
    return $is_bot;
}, 10, 2 );
```

---

## 🛠️ Endpoints AJAX Disponibles

- `woo_live_search` (compatible `wp_ajax_` et `wc_ajax_`) :
  - Paramètre : `s` (ou `term`).
  - Retourne les produits scorés, images, prix formatés et suggestions de catégories.
  - Inclus des alias de transition transparente pour les anciens thèmes (`mfm_live_search`, `jd_live_search`).

---

## 📦 Procédure d'Installation & Déploiement

1. Téléversez le dossier `woo-search-intelligence-soyoo` dans le répertoire `/wp-content/plugins/` de votre boutique WordPress.
2. Activez l'extension via le menu **Extensions > Extensions installées** ou via WP-CLI :
   ```bash
   wp plugin activate woo-search-intelligence-soyoo
   ```
3. Rendez-vous dans **WooCommerce > Search Intelligence** pour :
   - Ajuster vos seuils de recherche et configurer vos alertes e-mails.
   - Lancer la migration de vos données historiques si Search Analytics était présent.
   - Configurer vos premiers synonymes ou installer un pack suggéré.

---

## 🔒 Sanctuarisation & Maintenance SOYOO

Cette extension est un **actif propriétaire SOYOO**.  
Toute modification doit être effectuée et testée au sein du dépôt dédié :  
`https://github.com/SOYOO974/woo-search-intelligence-soyoo`

Développé avec rigueur et exigence par Julien Vanwinsberghe — **SOYOO**.
