<?php
/**
 * The header for our theme.
 *
 * Displays all of the <head> section and everything up till <div id="content">
 *
 * @package storefront
 */
global $wp;

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=2.0">
<link rel="profile" href="http://gmpg.org/xfn/11">
<link rel="pingback" href="<?php bloginfo( 'pingback_url' ); ?>">

<?php wp_head(); ?>
<link rel="prev" href="<?php echo home_url( $wp->request )?>">

</head>
<body <?php body_class(); ?>>
<?php

//$dt='';
if(isset($_GET['post_id'])) {
	$dt = get_post_meta($_GET['post_id'], 'hpp_lazy', true);
}
else if(isset($_GET['term_id'])) {
	$dt = get_term_meta($_GET['term_id'], 'hpp_lazy', 1);
}
else if(isset($_GET['id'])){
	$f = WP_CONTENT_DIR.'/uploads/pages/'.$_GET['id'].'.json';
    $dt = file_exists($f) ? file_get_contents($f ): '';
}
else {//from context
	if(is_singular()) {
      	global $post;
      	$dt = get_post_meta($post->ID, 'hpp_lazy', true);
      }
      else if(is_tax() || is_category() || is_tag()) {
      	$dt = get_term_meta(get_queried_object()->term_id, 'hpp_lazy', 1);
      }
      else {
      	$f = WP_CONTENT_DIR.'/uploads/pages/'.md5(preg_replace('#\?.+#','',$_SERVER['REQUEST_URI'])).'.json';
      	$dt = file_exists($f) ? file_get_contents($f ): '';
      }
}

$dt = !empty($dt)? hpp_unserialize($dt): array();#print_r($dt);
?>	
<div>
<?php 
foreach($dt as $row) {
	echo $row['text'];
}
?>	
</div>
<?php wp_footer(); ?>

</body>
</html>