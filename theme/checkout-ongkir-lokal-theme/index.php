<?php
/**
 * Main template file for Checkout Ongkir Lokal Companion theme.
 *
 * @package Checkout_Ongkir_Lokal_Companion
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<main style="max-width:960px;margin:3rem auto;padding:0 1rem;">
    <h1><?php esc_html_e('Checkout Ongkir Lokal Companion Theme', 'checkout-ongkir-lokal-companion'); ?></h1>
    <p>
        <?php
        esc_html_e(
            'Theme ini siap di-install sebagai ZIP WordPress Theme. Untuk fitur ongkir lokal, aktifkan plugin Checkout Ongkir Lokal pada menu Plugins.',
            'checkout-ongkir-lokal-companion'
        );
        ?>
    </p>
</main>
<?php wp_footer(); ?>
</body>
</html>
