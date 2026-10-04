<?php
/**
 * Désinstallation — Woo Search Intelligence by SOYOO
 *
 * Arrête toujours les tâches planifiées. Les données (statistiques, synonymes, index)
 * ne sont supprimées que si l'option « Supprimer toutes les données à la désinstallation »
 * est cochée : une suppression accidentelle ne doit pas effacer des mois d'historique.
 *
 * @package Woo_Search_Intelligence_Soyoo
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-search-installer.php';

Woo_Search_Installer::deactivate();

$woo_search_settings = get_option( 'woo_search_settings', [] );

if ( is_array( $woo_search_settings ) && ! empty( $woo_search_settings['delete_data_on_uninstall'] ) ) {
	Woo_Search_Installer::purge_all_data();
}

unset( $woo_search_settings );
