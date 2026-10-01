<?php
/*
Plugin Name: Mercadito Panel Rápido
Description: Panel «Mi tienda» para la comunidad, en el escritorio de WordPress y en «Mi cuenta» del sitio: pedidos, productos, clientes, código QR, personalización de la tienda, WhatsApp y redes sociales. Incluye números de pedido consecutivos, compra con 7 datos y envío del comprobante de pago por WhatsApp.
Version: 1.7.0
Author: Grupo 3 · Proyecto de Vinculación UIDE
License: MIT
License URI: https://opensource.org/licenses/MIT
Requires at least: 6.5
Requires PHP: 7.4
Requires Plugins: woocommerce

Proyecto: Bit a Bit: Construyendo Conocimiento Digital en Fundación Esmeralda 2.0
Tema del grupo: eCommerce. Crear WEB sencilla
Integrantes: Henry Aliaga Coronel, Néstor Gavilanes Oñate, Francisco López Zambrano
Universidad Internacional del Ecuador (UIDE), septiembre de 2026
*/

if (!defined('ABSPATH')) {
    exit;
}

define('MERCADITO_VERSION', '1.7.0');

// Se busca el patrón sincronizado «Código QR de pago»
function mercadito_qr_pattern_id() {
    $post = get_page_by_path('codigo-qr-de-pago', OBJECT, 'wp_block');
    if (!$post) {
        $found = get_posts(array(
            'post_type'   => 'wp_block',
            'title'       => 'Código QR de pago',
            'post_status' => 'publish',
            'numberposts' => 1,
        ));
        $post = $found ? $found[0] : null;
    }
    return $post ? (int) $post->ID : 0;
}

function mercadito_qr_edit_path() {
    $id = mercadito_qr_pattern_id();
    return $id ? 'post.php?post=' . $id . '&action=edit' : 'edit.php?post_type=wp_block';
}

// Pantalla de pedidos según cómo guarda WooCommerce los pedidos (HPOS o entradas)
function mercadito_orders_url() {
    if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
        return admin_url('admin.php?page=wc-orders');
    }
    return admin_url('edit.php?post_type=shop_order');
}

function mercadito_puede() {
    return current_user_can('edit_products') || current_user_can('manage_woocommerce');
}

function mercadito_tienda_url() {
    return admin_url('admin.php?page=mercadito-tienda');
}

// Imagen actual del QR: primero la del patrón y, si no hay, la opción guardada
function mercadito_qr_imagen() {
    $patron = mercadito_qr_pattern_id();
    if ($patron && preg_match('/<img[^>]+src="([^"]+)"/i', get_post_field('post_content', $patron), $m)) {
        return $m[1];
    }
    $id = (int) get_option('mercadito_qr_id');
    return ($id && wp_attachment_is_image($id)) ? wp_get_attachment_image_url($id, 'large') : '';
}

// Se cambia la imagen dentro del patrón (id del bloque, src y clase)
function mercadito_qr_actualizar_patron($id_imagen) {
    $patron = mercadito_qr_pattern_id();
    $url = wp_get_attachment_image_url($id_imagen, 'full');
    if (!$patron || !$url) {
        return false;
    }
    $contenido = get_post_field('post_content', $patron);
    $contenido = preg_replace('/(<!-- wp:image \{[^}]*"id":)\d+/', '${1}' . (int) $id_imagen, $contenido, 1);
    $contenido = preg_replace('/(<img[^>]+src=")[^"]+(")/i', '${1}' . esc_url_raw($url) . '${2}', $contenido, 1);
    $contenido = preg_replace('/wp-image-\d+/', 'wp-image-' . (int) $id_imagen, $contenido, 1);
    $resultado = wp_update_post(wp_slash(array('ID' => $patron, 'post_content' => $contenido)), true);
    return !is_wp_error($resultado);
}

// Pedido de la página de confirmación, validado con su clave
function mercadito_pedido_actual() {
    global $wp;
    $id = isset($wp->query_vars['order-received']) ? absint($wp->query_vars['order-received']) : 0;
    $pedido = $id ? wc_get_order($id) : false;
    $clave = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
    if (!$pedido || !$clave || !hash_equals($pedido->get_order_key(), $clave)) {
        return false;
    }
    return $pedido;
}

// Filtro de bloques: el QR solo aparece en pedidos pagados con código QR
add_filter('render_block', function ($html, $block) {
    if (empty($block['blockName']) || 'core/block' !== $block['blockName']) {
        return $html;
    }
    $ref = isset($block['attrs']['ref']) ? (int) $block['attrs']['ref'] : 0;
    if (!$ref || $ref !== mercadito_qr_pattern_id() || !function_exists('is_order_received_page') || !is_order_received_page()) {
        return $html;
    }
    $pedido = mercadito_pedido_actual();
    if (!$pedido || 'cheque' !== $pedido->get_payment_method()) {
        return '';
    }
    return mercadito_qr_con_comprobante($html, $pedido);
}, 10, 2);

// Tarjeta del QR en «Pedido recibido»: número, total a pagar y botón para enviar el comprobante por WhatsApp
function mercadito_qr_con_comprobante($html, $pedido) {
    // El filtro puede pasar dos veces por el mismo bloque: se agrega una sola vez
    if (false !== strpos($html, 'mc-pago-datos')) {
        return $html;
    }
    $numero = $pedido->get_order_number();
    $total = html_entity_decode(wp_strip_all_tags(wc_price($pedido->get_total(), array('currency' => $pedido->get_currency()))), ENT_QUOTES, 'UTF-8');
    $extra = sprintf('<p class="mc-pago-datos"><span>Pedido N.º <b>%s</b></span><span>Total a pagar <b>%s</b></span></p>', esc_html($numero), esc_html($total));
    $whatsapp = preg_replace('/\D+/', '', mercadito_opcion('whatsapp'));
    if ('' !== $whatsapp) {
        // Se abre WhatsApp con el mensaje ya escrito; la captura del pago la adjunta el cliente
        $mensaje = sprintf('Hola, te envío el comprobante de pago de mi pedido N.º %s por %s.', $numero, $total);
        $extra .= sprintf(
            '<a class="mc-pago-wa" href="%s" target="_blank" rel="noopener">%sEnviar comprobante por WhatsApp</a><small class="mc-pago-nota">Se abre WhatsApp con tu número de pedido; ahí adjuntas la captura del pago.</small>',
            esc_url('https://wa.me/' . $whatsapp . '?text=' . rawurlencode($mensaje)),
            mercadito_icono('chat')
        );
    }
    // Se agrega dentro del recuadro del patrón, antes de su último cierre
    $pos = strrpos($html, '</div>');
    $html = false === $pos ? $html . $extra : substr_replace($html, $extra, $pos, 0);
    return '<div class="mc-pago">' . $html . '</div>';
}

add_action('admin_enqueue_scripts', function ($hook) {
    if ('toplevel_page_mercadito-tienda' === $hook) {
        wp_enqueue_media();
    }
});

// Se guarda el QR elegido en la biblioteca de medios
add_action('admin_post_mercadito_guardar_qr', function () {
    if (!mercadito_puede()) {
        wp_die('No tienes permiso para hacer esto.');
    }
    check_admin_referer('mercadito_qr');
    $id = isset($_POST['qr_id']) ? absint($_POST['qr_id']) : 0;
    $estado = 'error';
    if ($id && wp_attachment_is_image($id) && mercadito_qr_actualizar_patron($id)) {
        update_option('mercadito_qr_id', $id, false);
        $estado = 'ok';
    }
    wp_safe_redirect(add_query_arg('qr', $estado, mercadito_tienda_url()) . '#mi-qr');
    exit;
});

// Primera vez: se toma el QR que ya tiene el patrón
add_action('admin_init', function () {
    if (get_option('mercadito_qr_id')) {
        return;
    }
    $patron = mercadito_qr_pattern_id();
    if ($patron && preg_match('/wp-image-(\d+)/', get_post_field('post_content', $patron), $m)) {
        update_option('mercadito_qr_id', (int) $m[1], false);
    }
});

// Conteos para los resúmenes
function mercadito_contar_pedidos($estado) {
    return count(wc_get_orders(array('status' => $estado, 'return' => 'ids', 'limit' => -1)));
}

function mercadito_ventas_mes() {
    $pedidos = wc_get_orders(array(
        'status'       => array('wc-processing', 'wc-completed', 'wc-on-hold'),
        'date_created' => '>=' . strtotime(wp_date('Y-m-01 00:00:00')),
        'limit'        => -1,
    ));
    $total = 0;
    foreach ($pedidos as $pedido) {
        $total += (float) $pedido->get_total();
    }
    return $total;
}

function mercadito_plural($numero, $singular, $plural) {
    return (int) $numero . ' ' . (1 === (int) $numero ? $singular : $plural);
}

// Menú del panel de WordPress
add_action('admin_menu', function () {
    add_menu_page('Mi tienda', 'Mi tienda', 'edit_products', 'mercadito-tienda', 'mercadito_render_tienda', 'dashicons-store', 2);
    add_menu_page('Mi código QR', 'Mi código QR', 'edit_products', 'admin.php?page=mercadito-tienda#mi-qr', '', 'dashicons-smartphone', 3);
});

// El encargado de la tienda entra directo a «Mi tienda»
add_filter('login_redirect', function ($destino, $pedido, $usuario) {
    if ($usuario instanceof WP_User && user_can($usuario, 'edit_products') && !user_can($usuario, 'manage_options')) {
        return mercadito_tienda_url();
    }
    return $destino;
}, 99, 3);

// Acceso rápido en el escritorio de WordPress
add_action('wp_dashboard_setup', function () {
    if (!mercadito_puede()) {
        return;
    }
    add_meta_box('mercadito_panel', 'Mi tienda', 'mercadito_render_widget', 'dashboard', 'normal', 'high');
    global $wp_meta_boxes;
    $high = isset($wp_meta_boxes['dashboard']['normal']['high']) ? $wp_meta_boxes['dashboard']['normal']['high'] : array();
    if (isset($high['mercadito_panel'])) {
        $mine = array('mercadito_panel' => $high['mercadito_panel']);
        unset($high['mercadito_panel']);
        $wp_meta_boxes['dashboard']['normal']['high'] = array_merge($mine, $high);
    }
}, 99);

// Estilos de la página «Mi tienda» del panel
function mercadito_estilos() {
    return '<style>
.mt{--v:#1F6F50;--v2:#185A41;--c:#FFF9F1;--t:#2B2118;--m:#6B5B4B;--l:#E6DCCD;--n:#E08A2B;font-size:14px;color:var(--t);max-width:1180px}
.mt *{box-sizing:border-box}
.mt-hero{display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between;background:var(--v);color:#fff;border-radius:18px;padding:26px 28px;margin:18px 0 18px}
.mt-hero h1{color:#fff;font-size:28px;line-height:1.2;margin:0 0 6px;padding:0}
.mt-hero p{margin:0;opacity:.9;font-size:15px}
.mt-hero-acc{display:flex;flex-wrap:wrap;gap:10px}
.mt-btn{display:inline-flex;align-items:center;gap:8px;background:#fff;color:var(--v);border:1px solid transparent;border-radius:12px;padding:12px 18px;font-weight:700;text-decoration:none;font-size:15px}
.mt-btn:hover,.mt-btn:focus{background:var(--c);color:var(--v2)}
.mt-btn.borde{background:transparent;color:#fff;border-color:rgba(255,255,255,.6)}
.mt-btn.borde:hover,.mt-btn.borde:focus{background:rgba(255,255,255,.12);color:#fff}
.mt-btn.alt{background:var(--v);color:#fff}
.mt-btn.alt:hover,.mt-btn.alt:focus{background:var(--v2);color:#fff}
.mt-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:18px}
.mt-stat{background:#fff;border:1px solid var(--l);border-radius:16px;padding:18px}
.mt-stat b{display:block;font-size:30px;line-height:1.1;color:var(--v)}
.mt-stat span{color:var(--m)}
.mt-stat.alerta b{color:var(--n)}
.mt-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:18px}
.mt-tile{display:flex;flex-direction:column;gap:10px;background:#fff;border:1px solid var(--l);border-radius:16px;padding:20px;text-decoration:none;color:var(--t);transition:transform .15s,box-shadow .15s}
.mt-tile:hover,.mt-tile:focus{transform:translateY(-2px);box-shadow:0 8px 22px rgba(43,33,24,.08);color:var(--t);border-color:var(--v)}
.mt-tile .dashicons{width:46px;height:46px;font-size:26px;line-height:46px;text-align:center;border-radius:12px;background:var(--c);color:var(--v)}
.mt-tile strong{font-size:16px}
.mt-tile small{color:var(--m);font-size:13px;line-height:1.4}
.mt-cols{display:grid;grid-template-columns:2fr 1fr;gap:14px}
.mt-card{background:#fff;border:1px solid var(--l);border-radius:16px;padding:20px}
.mt-card h2{font-size:18px;margin:0 0 12px;padding:0}
.mt-table{width:100%;border-collapse:collapse}
.mt-table th,.mt-table td{text-align:left;padding:10px 8px;border-bottom:1px solid var(--l);vertical-align:middle}
.mt-table th{color:var(--m);font-weight:600;font-size:12px;text-transform:uppercase}
.mt-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;background:#EEE7DC;color:#5E5245}
.mt-badge.on-hold{background:#FCE2C4;color:#8A4B0F}
.mt-badge.processing{background:#D8E9F7;color:#1D4F7A}
.mt-badge.completed{background:#CDEBDD;color:#14533B}
.mt-qr{text-align:center}
.mt-qr img{max-width:180px;width:100%;height:auto;border:1px solid var(--l);border-radius:12px;background:#fff}
.mt-pasos{margin:12px 0 0;padding-left:18px;text-align:left;color:var(--m)}
.mt-ayuda{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-top:14px}
.mt-ayuda h3{margin:0 0 6px;font-size:15px}
.mt-ayuda ol{margin:0;padding-left:18px;color:var(--m)}
.mt-vacio{color:var(--m);margin:0}
.mt-mini{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}
.mt-mini p{margin:0;color:var(--m)}
.mt-ok{background:#CDEBDD;color:#14533B;border-radius:10px;padding:10px;font-weight:600}
.mt-err{background:#F6C7C0;color:#7A1F12;border-radius:10px;padding:10px;font-weight:600}
.mt-qr button.mt-btn{border:0;cursor:pointer}
.mt-alt{margin-top:10px;color:var(--m)}
@media (max-width:900px){.mt-cols{grid-template-columns:1fr}.mt-hero{padding:20px}}
</style>';
}

function mercadito_estado_badge($pedido) {
    $estado = $pedido->get_status();
    return '<span class="mt-badge ' . esc_attr($estado) . '">' . esc_html(wc_get_order_status_name($estado)) . '</span>';
}

function mercadito_render_widget() {
    $espera = mercadito_contar_pedidos('on-hold');
    $proceso = mercadito_contar_pedidos('processing');
    echo mercadito_estilos();
    echo '<div class="mt mt-mini">';
    printf('<p><strong>%d</strong> pagos por confirmar · <strong>%d</strong> pedidos por entregar</p>', (int) $espera, (int) $proceso);
    printf('<a class="mt-btn alt" href="%s"><span class="dashicons dashicons-store"></span>Abrir Mi tienda</a>', esc_url(mercadito_tienda_url()));
    echo '</div>';
}

// Página «Mi tienda» dentro del panel de WordPress
function mercadito_render_tienda() {
    if (!mercadito_puede()) {
        return;
    }
    $usuario = wp_get_current_user();
    $espera = mercadito_contar_pedidos('on-hold');
    $proceso = mercadito_contar_pedidos('processing');
    $productos = (int) wp_count_posts('product')->publish;
    $ventas = mercadito_ventas_mes();
    $qr = mercadito_qr_imagen();
    $recientes = wc_get_orders(array('limit' => 5, 'orderby' => 'date', 'order' => 'DESC', 'status' => mercadito_numero_estados()));
    $tiles = array(
        array('dashicons-plus-alt', 'Agregar un producto', 'Nombre, precio, foto y listo.', admin_url('post-new.php?post_type=product')),
        array('dashicons-products', 'Mis productos', 'Cambia precios, fotos o descripciones.', admin_url('edit.php?post_type=product')),
        array('dashicons-cart', 'Mis pedidos', 'Revisa quién compró y confirma pagos.', mercadito_orders_url()),
        array('dashicons-smartphone', 'Mi código QR', 'Sube el QR con el que cobras.', mercadito_tienda_url() . '#mi-qr'),
        array('dashicons-admin-users', 'Mis clientes', 'Quién te compró, cuántas veces y cuánto gastó.', admin_url('admin.php?page=wc-admin&path=%2Fcustomers')),
        array('dashicons-visibility', 'Ver mi tienda', 'Mírala como la ve un cliente.', home_url('/')),
        array('dashicons-admin-appearance', 'Personalizar', 'Nombre, logo, colores, WhatsApp y redes.', add_query_arg('ver', 'personalizar', wc_get_account_endpoint_url('mi-tienda'))),
    );
    echo mercadito_estilos();
    echo '<div class="wrap mt">';
    echo '<hr class="wp-header-end">';
    echo '<div class="mt-hero"><div>';
    printf('<h1>Hola, %s</h1>', esc_html($usuario->display_name));
    echo '<p>Todo lo importante de tu tienda en un solo lugar. También lo tienes en el sitio, en «Mi cuenta».</p></div>';
    echo '<div class="mt-hero-acc">';
    printf('<a class="mt-btn" href="%s" target="_blank" rel="noopener"><span class="dashicons dashicons-external"></span>Ver mi tienda</a>', esc_url(home_url('/')));
    printf('<a class="mt-btn borde" href="%s" target="_blank" rel="noopener"><span class="dashicons dashicons-admin-site-alt3"></span>Mi tienda en el sitio</a>', esc_url(wc_get_account_endpoint_url('mi-tienda')));
    echo '</div></div>';
    echo '<div class="mt-stats">';
    printf('<div class="mt-stat%s"><b>%d</b><span>Pagos por confirmar</span></div>', $espera ? ' alerta' : '', (int) $espera);
    printf('<div class="mt-stat%s"><b>%d</b><span>Pedidos por entregar</span></div>', $proceso ? ' alerta' : '', (int) $proceso);
    printf('<div class="mt-stat"><b>%d</b><span>Productos en la tienda</span></div>', $productos);
    printf('<div class="mt-stat"><b>%s</b><span>Ventas de este mes</span></div>', wp_kses_post(wc_price($ventas)));
    echo '</div>';
    echo '<div class="mt-grid">';
    foreach ($tiles as $tile) {
        printf('<a class="mt-tile" href="%s"><span class="dashicons %s"></span><strong>%s</strong><small>%s</small></a>', esc_url($tile[3]), esc_attr($tile[0]), esc_html($tile[1]), esc_html($tile[2]));
    }
    echo '</div>';
    echo '<div class="mt-cols"><div class="mt-card"><h2>Últimos pedidos</h2>';
    if ($recientes) {
        echo '<table class="mt-table"><thead><tr><th>N.º</th><th>Cliente</th><th>Total</th><th>Estado</th><th></th></tr></thead><tbody>';
        foreach ($recientes as $pedido) {
            $cliente = trim($pedido->get_billing_first_name() . ' ' . $pedido->get_billing_last_name());
            printf(
                '<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td><td><a class="button" href="%s">Abrir</a></td></tr>',
                esc_html($pedido->get_order_number()),
                esc_html($cliente ? $cliente : 'Sin nombre'),
                wp_kses_post($pedido->get_formatted_order_total()),
                mercadito_estado_badge($pedido),
                esc_url($pedido->get_edit_order_url())
            );
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="mt-vacio">Todavía no hay pedidos.</p>';
    }
    echo '</div><div class="mt-card mt-qr" id="mi-qr"><h2>Mi código QR</h2>';
    if (isset($_GET['qr']) && 'ok' === $_GET['qr']) {
        echo '<p class="mt-ok">¡Listo! Tu nuevo código QR ya aparece en los pedidos pagados con QR.</p>';
    } elseif (isset($_GET['qr'])) {
        echo '<p class="mt-err">No se pudo guardar. Elige una imagen (JPG o PNG).</p>';
    }
    if ($qr) {
        printf('<img src="%s" alt="Código QR de pago">', esc_url($qr));
    }
    printf('<form id="mt-qr-form" method="post" action="%s">', esc_url(admin_url('admin-post.php')));
    echo '<input type="hidden" name="action" value="mercadito_guardar_qr"><input type="hidden" name="qr_id" id="mt-qr-id" value="">';
    wp_nonce_field('mercadito_qr');
    echo '<p><button type="button" class="mt-btn alt" id="mt-cambiar-qr"><span class="dashicons dashicons-upload"></span>Cambiar mi código QR</button></p></form>';
    echo '<ol class="mt-pasos"><li>Pulsa «Cambiar mi código QR».</li><li>Arrastra la foto de tu QR o pulsa «Seleccionar archivos».</li><li>Pulsa «Usar este código QR».</li></ol>';
    printf('<p class="mt-alt">¿Quieres cambiar también el texto? <a href="%s">Ábrelo en el editor</a>.</p>', esc_url(admin_url(mercadito_qr_edit_path())));
    echo "<script>jQuery(function($){ $('#mt-cambiar-qr').on('click', function(e){ e.preventDefault(); var f = wp.media({ title: 'Elige la foto de tu código QR', button: { text: 'Usar este código QR' }, library: { type: 'image' }, multiple: false }); f.on('select', function(){ var a = f.state().get('selection').first().toJSON(); $('#mt-qr-id').val(a.id); $('#mt-qr-form').trigger('submit'); }); f.open(); }); });</script>";
    echo '</div></div>';
    echo '<div class="mt-card" style="margin-top:14px"><h2>Ayuda rápida</h2><div class="mt-ayuda">';
    echo '<div><h3>Agregar un producto</h3><ol><li>Escribe el nombre y la descripción.</li><li>Pon el precio en «Precio normal».</li><li>Sube la foto en «Imagen del producto».</li><li>Pulsa «Publicar».</li></ol></div>';
    echo '<div><h3>Cambiar precio o foto</h3><ol><li>Abre «Mis productos».</li><li>Haz clic en el nombre del producto.</li><li>Cambia el precio o la imagen.</li><li>Pulsa «Actualizar».</li></ol></div>';
    echo '<div><h3>Confirmar un pago con QR</h3><ol><li>Abre «Mis pedidos».</li><li>Entra al pedido «En espera».</li><li>Revisa que el dinero llegó a tu cuenta.</li><li>Cambia el estado a «Completado» y pulsa «Actualizar».</li></ol></div>';
    echo '</div></div></div>';
}

// Finalizar compra con 7 datos: correo, nombre, apellido, teléfono, provincia, ciudad y dirección.
// Se ocultan empresa, apartamento y código postal; el país (solo Ecuador) se oculta en assets/compra.css
add_filter('woocommerce_get_country_locale', function ($locale) {
    $locale['EC'] = array_replace_recursive(isset($locale['EC']) ? $locale['EC'] : array(), array(
        'first_name' => array('priority' => 10),
        'last_name'  => array('priority' => 20),
        'phone'      => array('required' => true, 'priority' => 30),
        'state'      => array('label' => 'Provincia', 'required' => true, 'priority' => 40),
        'city'       => array('priority' => 50),
        'address_1'  => array('priority' => 60),
        'company'    => array('required' => false, 'hidden' => true),
        'address_2'  => array('required' => false, 'hidden' => true),
        'postcode'   => array('required' => false, 'hidden' => true),
    ));
    return $locale;
});

// Numeración consecutiva de pedidos (1, 2, 3...) sin tocar el ID interno
function mercadito_numero_estados() {
    return array_values(array_diff(array_keys(wc_get_order_statuses()), array('wc-checkout-draft')));
}

function mercadito_hay_otros_numerados($excluir_id) {
    $ids = wc_get_orders(array(
        'limit'      => 1,
        'return'     => 'ids',
        'status'     => array_merge(mercadito_numero_estados(), array('trash')),
        'exclude'    => array((int) $excluir_id),
        'meta_query' => array(array('key' => '_mercadito_numero', 'compare' => 'EXISTS')),
    ));
    return !empty($ids);
}

// Se reserva el siguiente número en una sola consulta para evitar duplicados
function mercadito_siguiente_numero($order_id) {
    global $wpdb;
    if (false === get_option('mercadito_contador_pedidos')) {
        add_option('mercadito_contador_pedidos', '0', '', 'no');
    }
    if (!mercadito_hay_otros_numerados($order_id)) {
        update_option('mercadito_contador_pedidos', '0', false);
    }
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s", 'mercadito_contador_pedidos'));
    $numero = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
    wp_cache_delete('mercadito_contador_pedidos', 'options');
    return $numero;
}

function mercadito_asignar_numero($order) {
    if (!$order instanceof WC_Order || $order->get_meta('_mercadito_numero')) {
        return;
    }
    if (in_array($order->get_status(), array('checkout-draft', 'draft', 'auto-draft'), true)) {
        return;
    }
    $order->update_meta_data('_mercadito_numero', mercadito_siguiente_numero($order->get_id()));
    $order->save_meta_data();
}

add_action('woocommerce_new_order', function ($order_id, $order = null) {
    mercadito_asignar_numero($order instanceof WC_Order ? $order : wc_get_order($order_id));
}, 20, 2);

add_action('woocommerce_order_status_changed', function ($order_id, $from, $to, $order = null) {
    mercadito_asignar_numero($order instanceof WC_Order ? $order : wc_get_order($order_id));
}, 20, 4);

add_filter('woocommerce_order_number', function ($number, $order) {
    $numero = $order instanceof WC_Order ? $order->get_meta('_mercadito_numero') : '';
    return $numero ? (string) $numero : $number;
}, 10, 2);

// El buscador de pedidos también encuentra el número consecutivo
add_filter('woocommerce_shop_order_search_fields', function ($fields) {
    $fields[] = '_mercadito_numero';
    return $fields;
});

add_filter('woocommerce_order_table_search_query_meta_keys', function ($keys) {
    $keys[] = '_mercadito_numero';
    return $keys;
});

// Una sola vez: se numeran los pedidos que ya existían
add_action('admin_init', function () {
    if (!function_exists('wc_get_orders') || get_option('mercadito_panel_numeracion') === '1') {
        return;
    }
    $pedidos = wc_get_orders(array(
        'limit'   => -1,
        'orderby' => 'date',
        'order'   => 'ASC',
        'status'  => mercadito_numero_estados(),
    ));
    foreach ($pedidos as $pedido) {
        mercadito_asignar_numero($pedido);
    }
    update_option('mercadito_panel_numeracion', '1', false);
});

// Íconos de línea para Mi cuenta y Mi tienda en el sitio
function mercadito_iconos() {
    return array(
        'resumen'   => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'tienda'    => '<path d="M3 9l2-5h14l2 5"/><path d="M4 9h16v11H4z"/><path d="M9 20v-6h6v6"/>',
        'bolsa'     => '<path d="M6 7h12l-1 13H7L6 7z"/><path d="M9 7a3 3 0 0 1 6 0"/>',
        'ubicacion' => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'persona'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
        'reloj'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'camion'    => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'caja'      => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        'dinero'    => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M7 9h.01M17 15h.01"/>',
        'qr'        => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14h.01M14 20h.01M17 20h4v-3"/>',
        'mas'       => '<path d="M12 5v14M5 12h14"/>',
        'foto'      => '<rect x="3" y="5" width="18" height="15" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M21 17l-5-5-9 8"/>',
        'subir'     => '<path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
        'clientes'  => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c1-3.5 3.6-5.5 6.5-5.5s5.5 2 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14.5c1.8.7 3 2.6 3.5 5.5"/>',
        'externo'   => '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'flecha'    => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
        'lapiz'     => '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M14 6l4 4"/>',
        'ajustes'   => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
        'chat'      => '<path d="M20.5 11.6a8.4 8.4 0 0 1-12.4 7.4L3.5 20.5l1.5-4.4a8.4 8.4 0 1 1 15.5-4.5z"/><path d="M8.5 10.5h7M8.5 13.5h4.5"/>',
        'libro'     => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5v-15z"/><path d="M4 20.5A2.5 2.5 0 0 1 6.5 18H20v3H6.5A2.5 2.5 0 0 1 4 20.5z"/>',
        'paleta'    => '<path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.8-.8 1.8-1.8 0-.5-.2-.9-.5-1.2-.3-.3-.5-.7-.5-1.2 0-1 .8-1.8 1.8-1.8H16a5 5 0 0 0 5-5c0-3.9-4-7-9-7z"/><circle cx="7.5" cy="11.5" r="1.2"/><circle cx="10.5" cy="7.5" r="1.2"/><circle cx="15.5" cy="8.5" r="1.2"/>',
        'texto'     => '<path d="M4 7V5h16v2"/><path d="M12 5v14"/><path d="M9 19h6"/>',
    );
}

function mercadito_icono($nombre) {
    $iconos = mercadito_iconos();
    if (!isset($iconos[$nombre])) {
        return '';
    }
    return '<svg class="mc-svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $iconos[$nombre] . '</svg>';
}

function mercadito_es_encargado() {
    return is_user_logged_in() && current_user_can('edit_products');
}

// Gancho de WooCommerce: nombres del menú de Mi cuenta y acceso a «Mi tienda»
add_filter('woocommerce_account_menu_items', function ($items) {
    $nombres = array(
        'dashboard'       => 'Mi resumen',
        'orders'          => 'Mis pedidos',
        'edit-address'    => 'Mi dirección',
        'edit-account'    => 'Mis datos',
        'customer-logout' => 'Cerrar sesión',
    );
    $nuevo = array();
    foreach ($items as $clave => $nombre) {
        if ('downloads' === $clave) {
            continue;
        }
        $nuevo[$clave] = isset($nombres[$clave]) ? $nombres[$clave] : $nombre;
        if ('dashboard' === $clave && mercadito_es_encargado()) {
            $nuevo['mi-tienda'] = 'Mi tienda';
        }
    }
    return $nuevo;
}, 20);

// Títulos de cada sección con los mismos nombres del menú
foreach (array('orders' => 'Mis pedidos', 'edit-address' => 'Mi dirección', 'edit-account' => 'Mis datos', 'mi-tienda' => 'Mi tienda') as $mercadito_punto => $mercadito_titulo) {
    add_filter('woocommerce_endpoint_' . $mercadito_punto . '_title', function () use ($mercadito_titulo) {
        return $mercadito_titulo;
    });
}

// Se reemplaza la plantilla del resumen de Mi cuenta por la del plugin
add_filter('wc_get_template', function ($plantilla, $nombre) {
    if ('myaccount/dashboard.php' === $nombre) {
        $propia = plugin_dir_path(__FILE__) . 'plantillas/resumen-cuenta.php';
        if (file_exists($propia)) {
            return $propia;
        }
    }
    return $plantilla;
}, 10, 2);

// Tarjeta del usuario sobre el menú lateral de Mi cuenta
add_action('woocommerce_before_account_navigation', function () {
    $usuario = wp_get_current_user();
    $nombre = trim($usuario->first_name . ' ' . $usuario->last_name);
    if ('' === $nombre) {
        $nombre = $usuario->display_name;
    }
    $inicial = function_exists('mb_substr') ? mb_substr($nombre, 0, 1) : substr($nombre, 0, 1);
    $inicial = function_exists('mb_strtoupper') ? mb_strtoupper($inicial) : strtoupper($inicial);
    echo '<aside class="mc-lateral"><div class="mc-perfil">';
    printf('<span class="mc-avatar" aria-hidden="true">%s</span><div class="mc-perfil-txt"><strong>%s</strong><small>%s</small>', esc_html($inicial), esc_html($nombre), esc_html($usuario->user_email));
    echo mercadito_es_encargado() ? '<span class="mc-rol">Encargado de la tienda</span>' : '<span class="mc-rol cliente">Cliente</span>';
    echo '</div></div>';
});

add_action('woocommerce_after_account_navigation', function () {
    echo '</aside>';
});

// Textos de la pantalla de acceso y registro
add_action('woocommerce_before_customer_login_form', function () {
    printf('<div class="mc-intro"><span class="mc-kicker">%s</span><h2>%s</h2><p>%s</p></div>', esc_html(get_bloginfo('name')), esc_html(mercadito_opcion('acceso_titulo')), esc_html(mercadito_opcion('acceso_texto')));
}, 5);

add_action('woocommerce_after_customer_login_form', function () {
    printf(
        '<div class="mc-invitado"><span class="mc-icono">%s</span><div><strong>¿Solo quieres comprar?</strong><span>No necesitas cuenta: agrega productos al carrito y paga con código QR o contra entrega.</span></div><a class="mc-btn mc-btn-verde" href="%s">Ir a la tienda</a></div>',
        mercadito_icono('bolsa'),
        esc_url(wc_get_page_permalink('shop'))
    );
});

// Estilos y script del sitio
add_action('wp_enqueue_scripts', function () {
    // Colores del panel como variables CSS para todas las hojas del plugin
    wp_enqueue_style('mercadito-sitio', plugins_url('assets/sitio.css', __FILE__), array(), MERCADITO_VERSION);
    wp_add_inline_style('mercadito-sitio', vsprintf(':root{--mc-v:%s;--mc-v2:%s;--mc-v3:%s;--mc-g1:%s;--mc-g2:%s}', mercadito_paleta()));
    // Finalizar compra y Pedido recibido
    if (function_exists('is_checkout') && is_checkout()) {
        wp_enqueue_style('mercadito-compra', plugins_url('assets/compra.css', __FILE__), array('mercadito-sitio'), MERCADITO_VERSION);
    }
    if (function_exists('is_account_page') && is_account_page()) {
        wp_enqueue_style('mercadito-cuenta', plugins_url('assets/cuenta.css', __FILE__), array('mercadito-sitio'), MERCADITO_VERSION);
        if (mercadito_es_encargado()) {
            wp_enqueue_script('mercadito-mi-tienda', plugins_url('assets/mi-tienda.js', __FILE__), array(), MERCADITO_VERSION, true);
        }
        return;
    }
    if (mercadito_es_encargado()) {
        wp_enqueue_style('mercadito-encargado', plugins_url('assets/encargado.css', __FILE__), array('mercadito-sitio'), MERCADITO_VERSION);
    }
}, 20);

add_filter('body_class', function ($clases) {
    if (mercadito_es_encargado() && !(function_exists('is_account_page') && is_account_page())) {
        $clases[] = 'mc-con-barra';
    }
    return $clases;
});

// Barra del encargado cuando recorre su propia tienda (los clientes no la ven)
add_action('wp_footer', function () {
    if (!mercadito_es_encargado() || (function_exists('is_account_page') && is_account_page())) {
        return;
    }
    $mi_tienda = wc_get_account_endpoint_url('mi-tienda');
    echo '<div class="mc-barra" role="region" aria-label="Opciones del encargado">';
    printf('<span class="mc-barra-titulo">%s<span>Modo encargado</span></span>', mercadito_icono('tienda'));
    if (function_exists('is_product') && is_product()) {
        printf('<a href="%s">%s<span>Editar este producto</span></a>', esc_url(add_query_arg(array('ver' => 'productos', 'producto' => get_queried_object_id()), $mi_tienda)), mercadito_icono('lapiz'));
    }
    printf('<a class="mc-barra-principal" href="%s">%s<span>Agregar producto</span></a>', esc_url(add_query_arg('ver', 'nuevo', $mi_tienda)), mercadito_icono('mas'));
    printf('<a href="%s">%s<span>Mi tienda</span></a>', esc_url($mi_tienda), mercadito_icono('resumen'));
    echo '</div>';
});

// Botón «Editar» sobre la foto de cada producto, solo para el encargado
add_filter('render_block_woocommerce/product-image', function ($html, $block, $instancia = null) {
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || !mercadito_es_encargado()) {
        return $html;
    }
    $id = ($instancia instanceof WP_Block && isset($instancia->context['postId'])) ? (int) $instancia->context['postId'] : 0;
    if (!$id || 'product' !== get_post_type($id)) {
        return $html;
    }
    $url = add_query_arg(array('ver' => 'productos', 'producto' => $id), wc_get_account_endpoint_url('mi-tienda'));
    $enlace = sprintf('<a class="mc-editar" href="%s">%sEditar</a>', esc_url($url), mercadito_icono('lapiz'));
    $cierre = strrpos($html, '</div>');
    return false === $cierre ? $html . $enlace : substr_replace($html, $enlace, $cierre, 0);
}, 10, 3);

// Punto de acceso «mi-tienda» dentro de Mi cuenta
add_filter('woocommerce_get_query_vars', function ($vars) {
    $vars['mi-tienda'] = 'mi-tienda';
    return $vars;
});

add_action('init', function () {
    if ('1' !== get_option('mercadito_endpoint_v')) {
        flush_rewrite_rules(false);
        update_option('mercadito_endpoint_v', '1', false);
    }
}, 99);

// Se sube una foto con el cargador de medios de WordPress
function mercadito_subir_imagen($campo, $padre) {
    if (empty($_FILES[$campo]['name']) || !current_user_can('upload_files')) {
        return 0;
    }
    $tipo = wp_check_filetype(sanitize_file_name(wp_unslash($_FILES[$campo]['name'])));
    if (!in_array(strtolower((string) $tipo['ext']), array('jpg', 'jpeg', 'png', 'webp', 'gif'), true)) {
        return 0;
    }
    $id = media_handle_upload($campo, $padre);
    return is_wp_error($id) ? 0 : (int) $id;
}

function mercadito_precio_valido($campo) {
    $precio = isset($_POST[$campo]) ? wc_format_decimal(sanitize_text_field(wp_unslash($_POST[$campo]))) : '';
    return ('' !== $precio && is_numeric($precio) && (float) $precio >= 0) ? $precio : '';
}

function mercadito_metodo_pago($pedido) {
    $metodos = array('cheque' => 'Código QR', 'cod' => 'Contra entrega');
    $clave = $pedido->get_payment_method();
    if (isset($metodos[$clave])) {
        return $metodos[$clave];
    }
    return $pedido->get_payment_method_title() ? $pedido->get_payment_method_title() : '—';
}

// Clientes armados a partir de los pedidos (con cuenta o como invitados)
function mercadito_clientes() {
    $pedidos = wc_get_orders(array(
        'limit'   => -1,
        'orderby' => 'date',
        'order'   => 'DESC',
        'status'  => array('wc-pending', 'wc-on-hold', 'wc-processing', 'wc-completed'),
    ));
    $clientes = array();
    foreach ($pedidos as $pedido) {
        $correo = strtolower(trim($pedido->get_billing_email()));
        $clave = $correo ? $correo : 'pedido-' . $pedido->get_id();
        if (!isset($clientes[$clave])) {
            $clientes[$clave] = array(
                'nombre'   => trim($pedido->get_billing_first_name() . ' ' . $pedido->get_billing_last_name()),
                'correo'   => $pedido->get_billing_email(),
                'telefono' => $pedido->get_billing_phone(),
                'pedidos'  => 0,
                'total'    => 0,
                'ultimo'   => $pedido->get_date_created(),
                'cuenta'   => $pedido->get_customer_id() > 0,
            );
        }
        $clientes[$clave]['pedidos']++;
        $clientes[$clave]['total'] += (float) $pedido->get_total();
    }
    return $clientes;
}

// Textos, colores y contactos que el encargado cambia en «Personalizar»
function mercadito_opcion($clave) {
    $base = array(
        'panel_titulo'   => 'Tu tienda hoy',
        'panel_texto'    => 'Lo mismo que haces en el panel de WordPress, ahora desde aquí.',
        'color'          => 'verde',
        'color_hex'      => '',
        'bienvenida'     => 'Aquí revisas tus pedidos, tu dirección y tus datos.',
        'acceso_titulo'  => 'Entra a tu cuenta',
        'acceso_texto'   => 'Revisa tus pedidos y compra más rápido. Si prefieres, también puedes comprar sin cuenta.',
        'whatsapp'       => '',
        'whatsapp_texto' => 'Hola, tengo una consulta sobre la tienda.',
        'correo'         => '',
        'horario'        => '',
        'facebook'       => '',
        'instagram'      => '',
        'tiktok'         => '',
    );
    if (!isset($base[$clave])) {
        return '';
    }
    $valor = (string) get_option('mercadito_' . $clave, $base[$clave]);
    $opcionales = array('color_hex', 'whatsapp', 'correo', 'horario', 'facebook', 'instagram', 'tiktok');
    return ('' === trim($valor) && !in_array($clave, $opcionales, true)) ? $base[$clave] : $valor;
}

// Colores listos: nombre, color principal, al pasar el mouse, fondo suave y extremos del degradado
function mercadito_colores() {
    return array(
        'verde'     => array('Verde', '#1F6F50', '#185A41', '#E6F2EC', '#17563E', '#2E8A66'),
        'azul'      => array('Azul', '#1D4ED8', '#1E40AF', '#E3EBFF', '#1E3A8A', '#3B82F6'),
        'morado'    => array('Morado', '#6D28D9', '#5B21B6', '#EEE8FE', '#4C1D95', '#8B5CF6'),
        'terracota' => array('Terracota', '#B4532A', '#93401F', '#FBEAE2', '#7C2D12', '#D9774B'),
        'grafito'   => array('Grafito', '#334155', '#1E293B', '#E8ECF1', '#0F172A', '#64748B'),
    );
}

// Se mezcla un color con otro para sacar tonos más oscuros o más claros
function mercadito_mezclar($hex, $con, $peso) {
    $a = sscanf(ltrim($hex, '#'), '%02x%02x%02x');
    $b = sscanf(ltrim($con, '#'), '%02x%02x%02x');
    $c = array();
    for ($i = 0; $i < 3; $i++) {
        $c[] = (int) round($a[$i] * (1 - $peso) + $b[$i] * $peso);
    }
    return vsprintf('#%02X%02X%02X', $c);
}

// Luminosidad relativa de un color (0 = negro, 1 = blanco)
function mercadito_luz($hex) {
    $luz = 0;
    foreach (array(0.2126, 0.7152, 0.0722) as $i => $peso) {
        $canal = hexdec(substr(ltrim($hex, '#'), $i * 2, 2)) / 255;
        $canal = $canal <= 0.03928 ? $canal / 12.92 : pow(($canal + 0.055) / 1.055, 2.4);
        $luz += $peso * $canal;
    }
    return $luz;
}

// Contraste entre dos colores (desde 4.5 se lee bien; desde 7, muy bien)
function mercadito_contraste($a, $b) {
    $la = mercadito_luz($a);
    $lb = mercadito_luz($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

function mercadito_contraste_blanco($hex) {
    return mercadito_contraste($hex, '#FFFFFF');
}

// Tonos del panel según el color elegido (listo o con el lápiz)
function mercadito_paleta() {
    $colores = mercadito_colores();
    $clave = mercadito_opcion('color');
    $hex = mercadito_opcion('color_hex');
    if ('personalizado' === $clave && preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
        // Un color muy claro se oscurece un poco para que el texto blanco se lea
        for ($i = 0; $i < 25 && mercadito_contraste_blanco($hex) < 4.5; $i++) {
            $hex = mercadito_mezclar($hex, '#000000', 0.08);
        }
        return array(strtoupper($hex), mercadito_mezclar($hex, '#000000', 0.2), mercadito_mezclar($hex, '#FFFFFF', 0.88), mercadito_mezclar($hex, '#000000', 0.3), mercadito_mezclar($hex, '#FFFFFF', 0.2));
    }
    $c = isset($colores[$clave]) ? $colores[$clave] : $colores['verde'];
    return array_slice($c, 1);
}

// Fondos claros para la tienda (el texto oscuro del tema se lee bien sobre todos)
function mercadito_fondos() {
    return array(
        'crema'  => array('Crema', '#FFF9F1'),
        'blanco' => array('Blanco', '#FFFFFF'),
        'gris'   => array('Gris', '#F3F4F6'),
        'menta'  => array('Menta', '#EEF7F1'),
        'cielo'  => array('Cielo', '#EEF4FB'),
    );
}

// Estilos globales del tema: los mismos que cambia «Editar el sitio › Estilos»
function mercadito_estilos_globales() {
    if (!class_exists('WP_Theme_JSON_Resolver')) {
        return null;
    }
    $id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
    $post = $id ? get_post($id) : null;
    $datos = $post ? json_decode($post->post_content, true) : null;
    return is_array($datos) ? array($post, $datos) : null;
}

function mercadito_guardar_estilos_globales($cambios) {
    $actual = mercadito_estilos_globales();
    if (!$actual) {
        return false;
    }
    list($post, $datos) = $actual;
    $datos = array_replace_recursive($datos, $cambios);
    $datos['isGlobalStylesUserThemeJSON'] = true;
    if (empty($datos['version']) && class_exists('WP_Theme_JSON')) {
        $datos['version'] = WP_Theme_JSON::LATEST_SCHEMA;
    }
    $resultado = wp_update_post(wp_slash(array('ID' => $post->ID, 'post_content' => wp_json_encode($datos))), true);
    if (function_exists('wp_clean_theme_json_cache')) {
        wp_clean_theme_json_cache();
    }
    return !is_wp_error($resultado);
}

// Color guardado en los estilos globales (null si no es un color #RRGGBB)
function mercadito_estilo_color($ruta) {
    $valor = function_exists('wp_get_global_styles') ? wp_get_global_styles($ruta) : '';
    return (is_string($valor) && preg_match('/^#[0-9a-fA-F]{6}$/', $valor)) ? strtoupper($valor) : null;
}

// Un fondo muy oscuro se aclara hasta que el texto del tema se lea muy bien
function mercadito_fondo_legible($hex) {
    $texto = mercadito_estilo_color(array('color', 'text'));
    $texto = $texto ? $texto : '#2B2118';
    for ($i = 0; $i < 30 && mercadito_contraste($hex, $texto) < 7; $i++) {
        $hex = mercadito_mezclar($hex, '#FFFFFF', 0.1);
    }
    return strtoupper($hex);
}

// Enlace final de WhatsApp o de cada red (vacío si no está configurado)
function mercadito_enlace_red($red) {
    if ('whatsapp' === $red) {
        $numero = preg_replace('/\D+/', '', mercadito_opcion('whatsapp'));
        if ('' === $numero) {
            return '';
        }
        $texto = mercadito_opcion('whatsapp_texto');
        return 'https://wa.me/' . $numero . ('' !== $texto ? '?text=' . rawurlencode($texto) : '');
    }
    if (in_array($red, array('facebook', 'instagram', 'tiktok'), true)) {
        return esc_url_raw(mercadito_opcion($red), array('http', 'https'));
    }
    return '';
}

// Enlaces cortos /?mercadito_ir=whatsapp: se cambia el destino sin tocar las páginas
add_action('template_redirect', function () {
    if (empty($_GET['mercadito_ir'])) {
        return;
    }
    $destino = mercadito_enlace_red(sanitize_key(wp_unslash($_GET['mercadito_ir'])));
    nocache_headers();
    wp_redirect($destino ? $destino : home_url('/'), 302);
    exit;
}, 1);

// Íconos de redes del pie: toman el enlace guardado en «Personalizar» y se ocultan si no hay
add_filter('render_block_core/social-link', function ($html, $block) {
    $url = isset($block['attrs']['url']) ? (string) $block['attrs']['url'] : '';
    if (!preg_match('/mercadito_ir=([a-z]+)/', $url, $m)) {
        return $html;
    }
    $destino = mercadito_enlace_red($m[1]);
    if ('' === $destino) {
        return '';
    }
    return preg_replace('/href="[^"]*"/', 'href="' . esc_url($destino) . '"', $html, 1);
}, 10, 2);

add_filter('render_block_core/social-links', function ($html) {
    return false === strpos($html, '<li') ? '' : $html;
});

// Botón flotante de WhatsApp (no se muestra mientras el encargado trabaja en «Mi tienda» ni junto al QR de pago)
add_action('wp_footer', function () {
    $enlace = mercadito_enlace_red('whatsapp');
    if ('' === $enlace || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('mi-tienda'))) {
        return;
    }
    $pedido = function_exists('is_order_received_page') && is_order_received_page() ? mercadito_pedido_actual() : false;
    if ($pedido && 'cheque' === $pedido->get_payment_method()) {
        return;
    }
    printf('<a class="mc-wa" href="%s" target="_blank" rel="noopener" aria-label="Escríbenos por WhatsApp">%s<span>¿Dudas? Escríbenos</span></a>', esc_url($enlace), mercadito_icono('chat'));
}, 20);

// [mercadito_contacto]: botones de WhatsApp, correo, horario y redes con los datos de «Personalizar»
add_shortcode('mercadito_contacto', function () {
    $redes = array('whatsapp' => 'WhatsApp', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok');
    $botones = '';
    foreach ($redes as $red => $nombre) {
        $enlace = mercadito_enlace_red($red);
        if ($enlace) {
            $botones .= sprintf('<a class="mc-contacto-btn %s" href="%s" target="_blank" rel="noopener">%s</a>', esc_attr($red), esc_url($enlace), esc_html('whatsapp' === $red ? 'Escríbenos por WhatsApp' : $nombre));
        }
    }
    $datos = '';
    $numero = preg_replace('/\D+/', '', mercadito_opcion('whatsapp'));
    if ($numero) {
        $datos .= '<li><strong>WhatsApp:</strong> +' . esc_html($numero) . '</li>';
    }
    $correo = mercadito_opcion('correo');
    if (is_email($correo)) {
        $datos .= sprintf('<li><strong>Correo:</strong> <a href="%s">%s</a></li>', esc_url('mailto:' . $correo), esc_html($correo));
    }
    if (mercadito_opcion('horario')) {
        $datos .= '<li><strong>Horario de atención:</strong> ' . esc_html(mercadito_opcion('horario')) . '</li>';
    }
    if ('' === $botones . $datos) {
        $aviso = current_user_can('manage_woocommerce')
            ? sprintf('Agrega tu WhatsApp y tus redes en <a href="%s">Mi tienda › Personalizar</a>.', esc_url(add_query_arg('ver', 'personalizar', wc_get_account_endpoint_url('mi-tienda'))))
            : 'Muy pronto publicaremos aquí nuestros datos de contacto.';
        return '<div class="mc-contacto"><p>' . $aviso . '</p></div>';
    }
    return '<div class="mc-contacto">' . ($botones ? '<div class="mc-contacto-botones">' . $botones . '</div>' : '') . ($datos ? '<ul class="mc-contacto-datos">' . $datos . '</ul>' : '') . '</div>';
});

// Páginas extra de la tienda; las de solo texto también se editan desde «Personalizar»
function mercadito_paginas() {
    return array(
        'historia'             => 'Nuestra historia',
        'preguntas-frecuentes' => 'Preguntas frecuentes',
        'contacto'             => 'Contacto',
        'aviso-legal'          => 'Aviso legal',
        'privacidad'           => 'Política de privacidad',
    );
}

// La política de privacidad se busca por el ajuste de WordPress y las demás por su dirección
function mercadito_pagina($clave) {
    if ('privacidad' === $clave) {
        $id = (int) get_option('wp_page_for_privacy_policy');
        $pagina = $id ? get_post($id) : null;
        return ($pagina && 'page' === $pagina->post_type && 'trash' !== $pagina->post_status) ? $pagina : null;
    }
    return get_page_by_path($clave, OBJECT, 'page');
}

// Texto de la página con «# » delante de cada subtítulo; null si tiene otros bloques o enlaces
function mercadito_pagina_a_texto($pagina) {
    $partes = array();
    foreach (parse_blocks($pagina->post_content) as $bloque) {
        if (empty($bloque['blockName'])) {
            if ('' !== trim(wp_strip_all_tags($bloque['innerHTML']))) {
                return null;
            }
            continue;
        }
        if (!in_array($bloque['blockName'], array('core/paragraph', 'core/heading'), true) || false !== stripos($bloque['innerHTML'], '<a ')) {
            return null;
        }
        $html = preg_replace('/<br\s*\/?>/i', "\n", $bloque['innerHTML']);
        $texto = trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8'));
        if ('' !== $texto) {
            $partes[] = ('core/heading' === $bloque['blockName'] ? '# ' : '') . $texto;
        }
    }
    return implode("\n\n", $partes);
}

// Cada párrafo separado por una línea en blanco pasa a ser un bloque; «# » crea un subtítulo
function mercadito_texto_a_bloques($texto) {
    $salida = array();
    foreach (preg_split('/\R\s*\R/u', trim($texto)) as $parrafo) {
        $parrafo = trim($parrafo);
        if ('' === $parrafo) {
            continue;
        }
        if (0 === strpos($parrafo, '# ')) {
            $salida[] = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html(trim(substr($parrafo, 2))) . "</h2>\n<!-- /wp:heading -->";
        } else {
            $salida[] = "<!-- wp:paragraph -->\n<p>" . nl2br(esc_html($parrafo), false) . "</p>\n<!-- /wp:paragraph -->";
        }
    }
    return implode("\n\n", $salida);
}

// Pie de página del tema guardado en la base de datos (el mismo que abre «Editar el sitio»)
function mercadito_pie() {
    $partes = get_posts(array(
        'post_type'      => 'wp_template_part',
        'name'           => 'footer',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'tax_query'      => array(array('taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => get_stylesheet())),
    ));
    return $partes ? $partes[0] : null;
}

// Línea final del pie (párrafo con la clase mc-pie-nota); null si el pie no la tiene
function mercadito_pie_texto() {
    $pie = mercadito_pie();
    if (!$pie || !preg_match('/<p[^>]*class="[^"]*\bmc-pie-nota\b[^"]*"[^>]*>(.*?)<\/p>/s', $pie->post_content, $m)) {
        return null;
    }
    return trim(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES, 'UTF-8'));
}

function mercadito_guardar_pie_texto($texto) {
    $pie = mercadito_pie();
    if (!$pie || null === mercadito_pie_texto() || $texto === mercadito_pie_texto()) {
        return;
    }
    $contenido = preg_replace_callback('/(<p[^>]*class="[^"]*\bmc-pie-nota\b[^"]*"[^>]*>)(.*?)(<\/p>)/s', function ($m) use ($texto) {
        return $m[1] . esc_html($texto) . $m[3];
    }, $pie->post_content, 1);
    wp_update_post(wp_slash(array('ID' => $pie->ID, 'post_content' => $contenido)));
}

// Se guarda cada tarjeta de «Personalizar»: 1 nombre y logo, 2 colores, 3 fondo, 4 textos, 5 WhatsApp y redes
function mercadito_guardar_personalizacion($seccion) {
    if ('tienda' === $seccion) {
        $nombre = isset($_POST['nombre']) ? sanitize_text_field(wp_unslash($_POST['nombre'])) : '';
        if ('' === $nombre) {
            wc_add_notice('Escribe el nombre de la tienda.', 'error');
            return;
        }
        // Los correos de WooCommerce siguen el nombre nuevo si usaban el anterior
        if (get_option('woocommerce_email_from_name') === get_option('blogname')) {
            update_option('woocommerce_email_from_name', $nombre);
        }
        update_option('blogname', $nombre);
        update_option('blogdescription', isset($_POST['frase']) ? sanitize_text_field(wp_unslash($_POST['frase'])) : '');
        if (!empty($_POST['quitar_logo'])) {
            delete_option('site_logo');
            remove_theme_mod('custom_logo');
        }
        $logo = mercadito_subir_imagen('logo', 0);
        if ($logo) {
            update_option('site_logo', $logo);
        }
        wc_add_notice('¡Listo! Se guardaron el nombre, la frase y el logo.');
    } elseif ('colores' === $seccion) {
        $color = isset($_POST['color']) ? sanitize_key(wp_unslash($_POST['color'])) : 'verde';
        $hex = isset($_POST['color_hex']) ? sanitize_text_field(wp_unslash($_POST['color_hex'])) : '';
        if ('personalizado' === $color && preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            update_option('mercadito_color_hex', strtoupper($hex), false);
        } elseif (!array_key_exists($color, mercadito_colores())) {
            $color = 'verde';
        }
        update_option('mercadito_color', $color, false);
        // Opcional: el mismo color en los botones y enlaces de toda la tienda
        if (!empty($_POST['botones_tienda'])) {
            $principal = mercadito_paleta();
            $estilos = array('elements' => array(
                'button' => array('color' => array('background' => $principal[0], 'text' => '#FFFFFF')),
                'link'   => array('color' => array('text' => $principal[0])),
            ));
            if (!mercadito_guardar_estilos_globales(array('styles' => $estilos))) {
                wc_add_notice('El color del panel se guardó, pero no se pudo cambiar el de los botones. Cámbialo en Editar el sitio › Estilos.', 'error');
                return;
            }
        }
        wc_add_notice('¡Listo! Se guardó el color.');
    } elseif ('fondo' === $seccion) {
        $fondo = isset($_POST['fondo']) ? sanitize_key(wp_unslash($_POST['fondo'])) : '';
        $fondos = mercadito_fondos();
        $fondo_hex = isset($_POST['fondo_hex']) ? sanitize_text_field(wp_unslash($_POST['fondo_hex'])) : '';
        $elegido = '';
        if (isset($fondos[$fondo])) {
            $elegido = $fondos[$fondo][1];
        } elseif ('personalizado' === $fondo && preg_match('/^#[0-9a-fA-F]{6}$/', $fondo_hex)) {
            $elegido = mercadito_fondo_legible($fondo_hex);
        }
        if ('' === $elegido || !mercadito_guardar_estilos_globales(array('styles' => array('color' => array('background' => $elegido))))) {
            wc_add_notice('No se pudo cambiar el fondo de la tienda. Cámbialo en Editar el sitio › Estilos.', 'error');
            return;
        }
        wc_add_notice('¡Listo! Se guardó el fondo de la tienda.');
    } elseif ('textos' === $seccion) {
        foreach (array('panel_titulo', 'panel_texto', 'acceso_titulo') as $clave) {
            update_option('mercadito_' . $clave, isset($_POST[$clave]) ? sanitize_text_field(wp_unslash($_POST[$clave])) : '', false);
        }
        foreach (array('bienvenida', 'acceso_texto') as $clave) {
            update_option('mercadito_' . $clave, isset($_POST[$clave]) ? sanitize_textarea_field(wp_unslash($_POST[$clave])) : '', false);
        }
        if (isset($_POST['pie_texto'])) {
            mercadito_guardar_pie_texto(sanitize_text_field(wp_unslash($_POST['pie_texto'])));
        }
        $paginas = current_user_can('edit_pages') ? mercadito_guardar_paginas() : 0;
        wc_add_notice('¡Listo! Se guardaron los textos' . ($paginas ? ' (' . mercadito_plural($paginas, 'página actualizada', 'páginas actualizadas') . ')' : '') . '.');
    } elseif ('contacto' === $seccion) {
        $numero = isset($_POST['whatsapp']) ? preg_replace('/\D+/', '', wp_unslash($_POST['whatsapp'])) : '';
        if ('' !== $numero && (strlen($numero) < 8 || strlen($numero) > 15)) {
            wc_add_notice('El número de WhatsApp debe tener entre 8 y 15 dígitos, con el código del país (Ecuador: 593).', 'error');
            return;
        }
        $correo = isset($_POST['correo']) ? sanitize_email(wp_unslash($_POST['correo'])) : '';
        if ('' !== $correo && !is_email($correo)) {
            wc_add_notice('Revisa el correo de contacto.', 'error');
            return;
        }
        update_option('mercadito_whatsapp', $numero, false);
        update_option('mercadito_whatsapp_texto', isset($_POST['whatsapp_texto']) ? sanitize_text_field(wp_unslash($_POST['whatsapp_texto'])) : '', false);
        update_option('mercadito_correo', $correo, false);
        update_option('mercadito_horario', isset($_POST['horario']) ? sanitize_text_field(wp_unslash($_POST['horario'])) : '', false);
        foreach (array('facebook', 'instagram', 'tiktok') as $red) {
            $url = isset($_POST[$red]) ? esc_url_raw(trim(wp_unslash($_POST[$red])), array('http', 'https')) : '';
            update_option('mercadito_' . $red, $url, false);
        }
        wc_add_notice('¡Listo! Se guardaron WhatsApp, el correo y las redes sociales.');
    }
}

// Textos de las páginas: solo se guardan las que cambiaron
function mercadito_guardar_paginas() {
    $guardadas = 0;
    $textos = isset($_POST['pagina']) && is_array($_POST['pagina']) ? wp_unslash($_POST['pagina']) : array();
    foreach (mercadito_paginas() as $clave => $titulo) {
        $pagina = mercadito_pagina($clave);
        if (!$pagina || !isset($textos[$clave]) || !current_user_can('edit_post', $pagina->ID)) {
            continue;
        }
        $actual = mercadito_pagina_a_texto($pagina);
        $texto = sanitize_textarea_field(str_replace(array("\r\n", "\r"), "\n", (string) $textos[$clave]));
        if (null !== $actual && '' !== trim($texto) && $texto !== $actual) {
            wp_update_post(wp_slash(array('ID' => $pagina->ID, 'post_content' => mercadito_texto_a_bloques($texto))));
            $guardadas++;
        }
    }
    return $guardadas;
}

// Se procesan los formularios de «Mi tienda» en el sitio
add_action('template_redirect', function () {
    if (empty($_POST['mercadito_accion']) || !mercadito_es_encargado()) {
        return;
    }
    $base = wc_get_account_endpoint_url('mi-tienda');
    if (!isset($_POST['_mercadito']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_mercadito'])), 'mercadito_frontal')) {
        wc_add_notice('La página estuvo abierta mucho tiempo. Vuelve a intentarlo.', 'error');
        wp_safe_redirect($base);
        exit;
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $accion = sanitize_key(wp_unslash($_POST['mercadito_accion']));
    $ver = 'pedidos';
    $extra = array();

    if ('qr' === $accion) {
        $ver = 'qr';
        $id = mercadito_subir_imagen('qr_archivo', 0);
        if ($id && mercadito_qr_actualizar_patron($id)) {
            update_option('mercadito_qr_id', $id, false);
            wc_add_notice('¡Listo! Tu nuevo código QR ya aparece en los pedidos pagados con QR.');
        } else {
            wc_add_notice('No se pudo subir el QR. Elige una imagen JPG o PNG.', 'error');
        }
    } elseif ('producto_nuevo' === $accion) {
        $ver = 'productos';
        $nombre = isset($_POST['nombre']) ? sanitize_text_field(wp_unslash($_POST['nombre'])) : '';
        $precio = mercadito_precio_valido('precio');
        if ('' === $nombre || '' === $precio) {
            $ver = 'nuevo';
            wc_add_notice('Escribe el nombre y un precio válido del producto.', 'error');
        } else {
            $producto = new WC_Product_Simple();
            $producto->set_name($nombre);
            $producto->set_regular_price($precio);
            $producto->set_short_description(isset($_POST['descripcion']) ? sanitize_textarea_field(wp_unslash($_POST['descripcion'])) : '');
            $categoria = isset($_POST['categoria']) ? absint($_POST['categoria']) : 0;
            if ($categoria) {
                $producto->set_category_ids(array($categoria));
            }
            $producto->set_status(current_user_can('publish_products') ? 'publish' : 'pending');
            $id = $producto->save();
            $foto = mercadito_subir_imagen('foto', $id);
            if ($foto) {
                $producto->set_image_id($foto);
                $producto->save();
            }
            $extra['producto'] = $id;
            wc_add_notice('¡Listo! «' . esc_html($nombre) . '» ya está en tu tienda.');
        }
    } elseif ('producto_editar' === $accion) {
        $ver = 'productos';
        $producto = wc_get_product(isset($_POST['producto']) ? absint($_POST['producto']) : 0);
        if ($producto && current_user_can('edit_product', $producto->get_id())) {
            $nombre = isset($_POST['nombre']) ? sanitize_text_field(wp_unslash($_POST['nombre'])) : '';
            if ('' !== $nombre) {
                $producto->set_name($nombre);
            }
            $precio = mercadito_precio_valido('precio');
            if ('' !== $precio) {
                $producto->set_regular_price($precio);
            }
            $producto->set_stock_status(isset($_POST['disponible']) ? 'instock' : 'outofstock');
            $categoria = isset($_POST['categoria']) ? absint($_POST['categoria']) : 0;
            if ($categoria && !in_array($categoria, $producto->get_category_ids(), true)) {
                $producto->set_category_ids(array($categoria));
            }
            $descripcion = isset($_POST['descripcion']) ? sanitize_textarea_field(wp_unslash($_POST['descripcion'])) : null;
            $original = isset($_POST['descripcion_original']) ? sanitize_textarea_field(wp_unslash($_POST['descripcion_original'])) : null;
            if (null !== $descripcion && $descripcion !== $original) {
                $producto->set_short_description($descripcion);
            }
            $foto = mercadito_subir_imagen('foto_' . $producto->get_id(), $producto->get_id());
            if ($foto) {
                $producto->set_image_id($foto);
            }
            $producto->save();
            $extra['producto'] = $producto->get_id();
            wc_add_notice('¡Listo! Se guardaron los cambios de «' . esc_html($producto->get_name()) . '».');
        }
    } elseif ('personalizar' === $accion) {
        $ver = 'personalizar';
        $seccion = isset($_POST['seccion']) ? sanitize_key(wp_unslash($_POST['seccion'])) : '';
        if (current_user_can('manage_woocommerce')) {
            mercadito_guardar_personalizacion($seccion);
        }
        $extra['seccion'] = $seccion;
    } elseif ('pedido_estado' === $accion && current_user_can('edit_shop_orders')) {
        $pedido = wc_get_order(isset($_POST['pedido']) ? absint($_POST['pedido']) : 0);
        $estado = isset($_POST['estado']) ? sanitize_key(wp_unslash($_POST['estado'])) : '';
        if ($pedido && in_array($estado, array('processing', 'completed'), true)) {
            $pedido->update_status($estado, 'Actualizado desde Mi tienda en el sitio.');
            wc_add_notice('Pedido N.º ' . esc_html($pedido->get_order_number()) . ': ' . esc_html(wc_get_order_status_name($estado)) . '.');
        }
    }
    wp_safe_redirect(add_query_arg(array_merge(array('ver' => $ver), $extra), $base));
    exit;
});

// Contenido de «Mi tienda» en el sitio
add_action('woocommerce_account_mi-tienda_endpoint', function () {
    if (!mercadito_es_encargado()) {
        echo '<p>Esta sección es solo para quienes administran la tienda.</p>';
        return;
    }
    include plugin_dir_path(__FILE__) . 'plantillas/mi-tienda.php';
});
