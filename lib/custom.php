<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
if(!defined('TEMPLATE_PATH')) define('TEMPLATE_PATH', get_stylesheet_directory());
if(!defined('TEMPLATE_URL')) define('TEMPLATE_URL', get_stylesheet_directory_uri());

define('HS_PLUGIN_URL', rtrim(plugins_url('', dirname(__FILE__)),'/'));
define('HS_LIB_DIR', __DIR__);
#define('HPP_ROOT', __DIR__);//plugin_basename( __FILE__ )
define('HS_DEBUG', 1);
define('HS_TEST', 1);
if(!defined('WP_SITEURL')) define( 'WP_SITEURL', site_url('') );

include (__DIR__.'/classes/encoding.php');
include (__DIR__.'/classes/buffer.php');
include (__DIR__.'/classes/csstidy.php');

#if (php_sapi_name() != "cli") {
include (__DIR__.'/includes/utils.php');
include (__DIR__.'/includes/lazy.php');  //.min.php
include (__DIR__.'/includes/optimize.php');
include (__DIR__.'/plugins/plugins.php');
#}

#error_reporting(E_ALL ^ E_WARNING);

//for http
add_filter('wp_signature_hosts', function(){return [];});
#add_filter( 'widget_text', 'shortcode_unautop');
#add_filter('widget_text', 'do_shortcode');

/*setup amp
function hw_init() {	
	if(defined('AMP_QUERY_VAR')) {
		add_post_type_support( 'page', AMP_QUERY_VAR );
		add_post_type_support( 'product', AMP_QUERY_VAR );
	}
	#wp_register_script( 'hw-libs', TEMPLATE_URL.'/assets/js/require/libs.js', array( 'jquery' ), '1.0', true );
}
add_action('init','hw_init');

function hw_setup() {
	add_filter( 'amp_customizer_is_enabled', '__return_false' );
}
add_action( 'after_setup_theme', 'hw_setup' );
*/
//disable auto-update for pagespeed
/*add_filter( 'auto_update_plugin', '__return_false' );
add_filter( 'automatic_updater_disabled', '__return_true' );
add_filter( 'auto_update_theme', '__return_false' );
add_filter( 'auto_update_core', '__return_false' );
*/

/*
add_action('wp_head', function(){
	#echo '<link rel="dns-prefetch" href="https://www.googletagmanager.com" />';	
	?>
	<script>
_HWIO.readydoc(function(){});	//ready document
_HWIO.readyjs(function(){ });	//note: jquery ready
_HWIO.readyjs(function(){
	jQuery('.woocommerce-product-gallery').css('opacity','');
}, ['cookie']);	//for checking

_HWIO.readyjs('js-cookie',function(){
  console.log("~~ ready js-cookie")
});

_HWIO.readyjs(null,function(){
	_log('@when interactive');
});	

//combine php hook `hpp_delay_it_script`
_HWIO.add_event('run_script', function(v, js){
  if(js.indexOf('readyjs head')!==-1 || js.indexOf('ready js-cookie')!==-1)return 0;	//when interactive
  return v;
});
	</script>
	<?php
});

//@derepcated modify whole html or head part
add_filter('hpp_prepare_output_html', function($head, $html) {
	//return $html; #-> full page
	return $head;
}, 10, 2);

//do exist or not exist, 
add_action('print_critical_css', function($css) {
	//when no exist critical: fix css before generate critical css
	if(!$css) echo '<style>.gem-icon .gem-icon-half-1,body:not(.compose-mode) .gem-icon .gem-icon-half-2{opacity:0!important;}</style>';
	//font ,used with `font-display:optional`
	foreach(hpp_criticalcss_extract_fonts($css) as $i=>$font_url) {
		if($i<5 ) printf('<link rel="preload" as="font" href="%s" crossorigin>', $font_url);
	}
});
add_filter('hpp_filter_font_face', function($m0, $m){
	foreach($m0 as $i=>$s) if(strpos($s, '')!==false) unset($m0[$i]);	//exclude
	return $m0;
},10, 2);

add_action('hpp_print_initjs', function(){
	//not async load js+css when no exist critical: _HWIO.data.async_load=1;//mix js,css async load-> deprecated
	#if(is_home() || is_front_page())
	if(isset($GLOBALS['hpp-criticalfile'])) {	
	?>
	_HWIO
	<?php
	}
});

//use same critical file
add_filter('get_criticalcss_path', function($file, $uri){
	if(hpp_in_str($uri, ['/giai-phap-goi-tu-dong-senautocall/'])) {
		return WP_CONTENT_DIR.'/uploads/critical-css/page-page.css';
	}
	return $file;
}, 10, 2);

add_filter('hpp_should_defer_media_in_text', function($pass, $str){
	if(strpos($str, 'xx')!==false) return false;
	return $pass;
}, 10, 2);

add_filter('hpp_critical_css', function($css, $file){
	if(strpos($file, '.mobile.css')===false) $css = str_replace('.is-small,.is-small.button,.nav>li>a{}', '', $css);
	return $css;
}, 10, 2);

add_filter('hpp_save_merge_file', function($text, $file) {
	if(strpos($file,'.css')!==false) {
		preg_match('#pose-mode\) .gem-icon .gem-icon-half-2 {.*?}#si', $text, $m);
		if(!empty($m[0]) && strpos($m[0],'opacity: 0')!==false) {
			$text = str_replace($m[0], str_replace('opacity: 0', 'opacity: 1', $m[0]), $text);	//flatsome
		}
	}
	if(strpos($file,'.js')!==false) {
		$text = str_replace(';docReady(',';_HWIO.readyjs(',$text);
	}
	return $text;
}, 10, 2);

//exclude file from merge
add_filter('hpp_can_merge_file', function($ok, $handle, $ext, $handles){
	//some merge not work: (is_home() || is_front_page()
	if(hpp_in_str(join(',',$handles),['jquery-slick','jet-engine-frontend'],1) && in_array($handle,['jquery-slick','jet-engine-frontend'])) return 0;
	return $ok;
}, 10, 4);

add_filter('hpp_delay_asset_att', function($att, $tp) {
	if($tp=='js' ) {//&& !hw_config('merge_js')
		//support multiple check (OR). note: don't dep A<->B will err
		//if($att['id']=='jquery-blockui') $att['deps'].=',js-cookie';	
		if($att['id']=='mediaelement-migrate') $att['deps'].=',mediaelement-core';
		if($att['id']=='mediaelement-vimeo') $att['deps'].=',mediaelement-core';
		if($att['id']=='wp-mediaelement') $att['deps'].=',mediaelement-core';

		//note compare to ?nooptizpp=1 if missing js, can add by wp_enqueue_script
		if($att['id']=='jquery-masonry') $att['deps'].=',masonry-js';	

		//modify url
		if(isset($att['l']) && strpos($att['l'],'http://')!==false) $att['l'] = str_replace('http://','https://', $att['l']);
		//ignore asset
		unset($att['id']);
	}
	if($tp=='css') {
		//exclude
		if(in_array($att['id'], ['hpp-s-0','hpp-s-1','google-fonts-1'])) unset($att['id']);
	}
	return $att;
}, 10, 2);

add_action('wp_enqueue_scripts', function(){
	//if jquery not enqueue in <head, check in *.js.log file
	wp_enqueue_script('myjquery','/wp-includes/js/jquery/jquery.min.js');
},-1*PHP_INT_MAX);

add_filter('script_loader_src', function($src, $handle){
	static $login=null;
	if($login===null) $login = is_user_logged_in();
	return !$login && hpp_in_str($handle,['icon','font'])? false: $src;
  
  return (strpos($src, '/wp-admin/')===false 
	&& strpos($src, 'jquery-blockui')===false
	&& strpos($src, 'add-to-cart')===false
	#&& strpos($src, 'js-cookie')===false
	&& strpos($src, 'cart-fragments.min.js')===false
	&& strpos($src, 'password-strength-meter')===false
	)? $src: false;

}, PHP_INT_MAX, 2);
#add_filter('ignore_style_loader_src', '__return_true');

// hpp_inline_* may dupicate in hook `process_scripts, print_footer + hpp_delay_assets` ->no problem
//edit script tag in head,footer,footer-data,get_option.. combine hpp_allow_readyjs
add_filter('hpp_inline_script', function($js) {
	//in footer
	if(strpos($js, '_HWIO.extra_assets=')!==false) {
		$js = str_replace('})(jQuery);;','})(null);;', $js);
		$js = str_replace('_HWIO.readyjs(function()','_HWIO.readyjs(function($)', $js);
		
	}
	
	//remove `_HWIO.readyjs`
	if(strpos($js, 'function tdBlock')!==false) {
		$js = str_replace('_HWIO.readyjs(function(){','', substr($js,0, strlen($js)-2));
	}
	//ex
	if(strpos($js,'_HWIO.readyjs')===false) $js = str_replace('timer_metaslider_12()', '_HWIO.readyjs(timer_metaslider_12)', $js);

	return $js;
});

//script data
add_filter('hpp_inline_script_part', function($js, $handle){
	return $js;
}, 10, 2);

add_filter('hpp_inline_style', function($css){
	return $css;
});

//@deprecated
add_filter('hpp_should_inline_style', function($b){
	return $b;	//you can force, because it existed in criticalcss but if error critical
	//prevent duplicate in critical-css because when generate critical css it will copy from inline style
	return !isset($GLOBALS['hpp-criticalfile']) && !isset($_GET['hpp-gen-critical']);
});

add_filter('hpp_defer_html_tag', function($tag, $type){
	return $tag;
}, 10, 2);

//fix url if your theme add hook 'clean_url'
add_filter( 'clean_url', function($url){return $url;}, 11, 1 );

add_filter('hpp_defer_src_holder', function($holder){	//@deprecated
	//return 'placeholder';
	return $holder;
});

add_filter('hpp_allow_lazy_video', '__return_false');
add_filter('hpp_allow_lazy_video', function($ok, $str){
	return $ok;
}, 10,2);

add_filter('hpp_url_langs', function(){return 'en|vi|ja';});

add_filter('hpp_lazy_assets', function($assets){
  return $assets;
});

//change to your cdn
add_filter('hpp_cache_url', function($url){
  $url = str_replace(siteurl(), 'your-cdn', $url);
  return $url;
});

add_filter('hpp_config', function($v, $key){
  return $v;
}, 10,2);

add_filter('hpp_disallow_lazyload', function($ok, $tag){
  //class,src,srcset,.. ->attributes
  if(strpos($tag, 'logo.png')!==false) return 1;
  return $ok;
},10,2);

add_filter('hpp_disallow_lazyload_attr', function($ok, $attr){
  if(strpos($tag['src'],'logo.png')!==false) return 1;
  if(strpos($tag['class'],'custom-logo')!==false) return 1;
  //or
  foreach( $exclude_lazy_array as $val1 ){
	if(strpos($tag, $val1 )!==false) return 1;
  }
  return $ok;
},10, 2);

add_filter('hpp_sitedir', function($path){
	//return '';
  return $path;
});

add_filter('hpp_print_critical_priority', function($i){
	return $i;
});

add_filter('hpp_allow_async_js', function($ok, $tag){
  return $ok;
}, 10, 2);

//for variable & function
add_filter('hpp_disallow_global_var', function($ok, $str){
	if(strpos($str, 'xx')!==false) return 1;
	return $ok;
},10, 2);
*/
add_action('init', function(){
	if(function_exists('wp_doing_ajax') && wp_doing_ajax()) {
		error_reporting(E_ERROR | E_PARSE);
	}
});

add_filter('hpp_should_lazy', function($v){ 
	#static $value=null;if($value!==null) return $value;
	$uris=[
		'/checkout/','/thanh-toan/',
		#'/cart/','/gio-hang/',
		#'/login/','/dang-nhap/',
		'/order-received/',
		'/my-account/','/tai-khoan/',
	];
	if(function_exists('is_user_logged_in') && !is_user_logged_in()) {
		if(hpp_in_str($_SERVER['HTTP_USER_AGENT'],['MSIE','WOW64;','Trident/'])) return false;
		foreach($uris as $uri) if(stripos($_SERVER['REQUEST_URI'], $uri)!==false ) return false;
	}
	else $v=false;
	#if(function_exists('is_user_logged_in')) $value=$v;
	return $v;
	
});

/*
<link rel='dns-prefetch' href='https://www.googletagmanager.com' />
<link rel='dns-prefetch' href='https://connect.facebook.net' />
<link rel='dns-prefetch' href='https://cdn.autoads.asia' />
<link rel='dns-prefetch' href='https://www.google-analytics.com' />
<link rel='dns-prefetch' href='https://www.youtube.com' />
<link rel='dns-prefetch' href='https://api.autoads.asia' />
<link rel='dns-prefetch' href='https://www.facebook.com' />
<link rel='dns-prefetch' href='htpss://googleads.g.doubleclick.net' />
<link rel='dns-prefetch' href='https://maps.googleapis.com' />
<link rel='dns-prefetch' href='https://scontent-ort2-1.xx.fbcdn.net' />
<link rel='dns-prefetch' href='https://i.ytimg.com' />
<link rel='dns-prefetch' href='https://yt3.ggpht.com' />
<link rel='dns-prefetch' href='https://static.doubleclick.net' />

*/
add_action('wp_head', function(){
	echo <<<EOF
<link rel='preconnect' href='https://fonts.gstatic.com' crossorigin />
EOF;
	if(!hpp_shouldlazy()) echo '<script>if(typeof $=="undefined" && typeof jQuery!="undefined")$=jQuery;</script>';
});
/*override
add_filter('oembed_result', function($iframe_html, $video_url, $frame_attributes){
  return hpp_lazy_video($iframe_html, 2);
}, 20, 3);

remove_filter('the_content', 'hpp_defer_content',PHP_INT_MAX);
*/

add_action('hpp_purge_cache', function(){
	/*do something
	@mkdir(WP_CONTENT_DIR.'/cache/caos-analytics', 0755,true);
	file_put_contents(WP_CONTENT_DIR.'/cache/caos-analytics/analytics.js','');
	*/
});

add_filter('hpp_merge_file', function($text, $handle, $tp, $file){
	/*if($tp=='js' && in_array($handle,['jquery','jquery-core','jquery-migrate','myjquery'])) {
		if(!isset($GLOBALS['hpp-jq'])) $GLOBALS['hpp-jq']='';
		if($handle=='myjquery'||1)$GLOBALS['hpp-jq'].= $text;$text='';
	}*/
	if($tp=='js' ) {
		#if( in_array($handle, ['jquery','jquery-core'])) $text .= ';if(typeof $=="undefined")$=jQuery;';	//don't
		if($handle=='jquery.isotope') $text = 'if(!$.event.handle)$.event.handle=$.event.dispatch;'.$text;
		//if($handle=='bootstrap') $text = file_get_contents(str_replace('bootstrap.js', 'bootstrap.min.js', $file));
	}
	//important
	if($tp=='js' ) $text = "try{{$text}}catch(e){console.log(e)}";
	return $text;
}, PHP_INT_MAX, 4);

add_filter('hpp_can_merge_file', function($ok, $handle, $ext, $handles){
	#if(hpp_in_str(join(',',$handles),['lodash'],1) && in_array($handle,['lodash'])) return 0;
	#if( in_array($handle,['contact-form-7'])) return 0;
	return $ok;
}, 10, 4);

add_filter('wp_get_custom_css', function($css, $stylesheet ='') {
	return hpp_fix_stylesheet($css);
}, 10, 2);

add_filter('hpp_custom_css', function($css){
    //$css = preg_replace('#(background|background-image)([\s:]+?)url\(#', 'lazy-$1$2url(', $css);
    //$css = str_replace('url(', '_url(', $css);
    return hpp_fix_stylesheet($css);
});

add_filter('hpp_allow_readyjs', function($ok, $js){
	if(hpp_in_str($js, ['new PerformanceObserver','enable_page_level_ads','window.dataLayer = window.dataLayer','adsbygoogle = window.adsbygoogle','var anr_onloadCallback']) /*|| hpp_in_str($js,['function ','function('])*/ ) return false;
	#if(hpp_startsWith(trim($js), '<!--') && hpp_endsWith(trim($js), '-->')) return false;
	return $ok;
}, 10, 2);

add_filter('hpp_delay_it_script', function($v, $js){
	$find = [
		'www.googletagmanager.com','connect.facebook.net','www.google-analytics.com','bing.com','new Ya.Metrika',
		'googlesyndication','adsbygoogle',"fbq('set'",'fbq("set"',"fbq('init'","fbq('track'",'gtag(',
	];
	if(0&& hpp_in_str($js, $find)) return true;
	return $v;
},10, 2);

//purpose for inline script to replace in current dom, like bitrix. no need
add_filter('hpp_allow_delay_asset', function($ok, $url){
	if(strpos($url, 'app.getresponse')!==false) return false;
	return $ok;
}, 10, 2);

//fix css to generate critical
add_filter('hpp_lazycss', function($css) {
	// $css = str_replace('/* .fv-custom-block #promo', '.fv-custom-block #promo', $css);
	return $css;
});

add_filter('hpp_delay_asset_att', function($att, $tp) {
	if($tp=='js' ) {
		if($att['id']=='mediaelement-migrate') $att['deps'].=',mediaelement-core';
		if($att['id']=='mediaelement-vimeo') $att['deps'].=',mediaelement-core';
		if($att['id']=='wp-mediaelement') $att['deps'].=',mediaelement-core';
		//exclude external jquery because duplicate will clear defined plugins
		#if(isset($att['l']) && hpp_in_str($att['l'],['code.jquery.com','jquery.min.js'])) unset($att['id']);
	}
	return $att;
}, 10, 2);

add_filter('hpp_inline_script', function($js) {
	/*if(strpos($js, 'fbq(')!==false && strpos($js, '},"fbq")')===false) {
		$js = (substr($js,-3)=='});'? substr($js,0,-3): substr($js,0,-2)). '},"fbq")';
	}*/
	#if(hpp_startsWith(trim($js), '<!--')) $js = substr(trim($js), 0,4);
	if(strpos($js, 'document.write')!==false) $js = str_replace('document.write', 'jQuery("body").append', $js);
	/*if(strpos($js, 'var Tawk_API')!==false) {
		$js = str_replace('Tawk_LoadStart = new Date();', 'Tawk_LoadStart = new Date();window.Tawk_API=Tawk_API;', $js);
	}
	if(strpos($js, 'trustlogo/javascript/trustlogo.js')!==false) {
		$c=<<<EOF
var tmp = document.write;document.write = function () {document.getElementById('_TrustLogo').innerHTML = [].concat.apply([], arguments).join('');};
$(".copyright-footer").append("<span id=\"_TrustLogo\"></span>");
_HWIO._addjs('https://secure.trust-provider.com/trustlogo/javascript/trustlogo.js',function(){
	//setTimeout(function(){document.write = tmp;},2000);
});
EOF;
		$js = str_replace('document.write(',$c.'if(0)document.write(', $js);
	}
	if(strpos($js,'TrustLogo(')!==false) {
		$js=str_replace('})','},"TrustLogo")',$js);
	}
	*/
	//if(strpos($js, 'fbq(')!==false) $js = str_replace('_HWIO.readyjs(function(','_HWIO.readyjs(null,function(',$js);
	return $js;
},0);

add_filter('hpp_inline_script_part', function($js, $handle){
	// strpos($js,'jQuery')!==false
	if(hpp_in_str($js, hpp_var('heavy-js',[])) && strpos($js,'_HWIO.readyjs')===false && apply_filters('hpp_allow_readyjs', true, $js)) {
		$js='_HWIO.readyjs(function(){'. hpp_fix_script_variables($js).'});';
	}
	/*
	if(strpos($js,'var wp_theme_root_path')!==false) {
		preg_match('#var wp_theme_root_path(.*?);#', $js,$m);
		$js = $m[0].str_replace($m[0],'', $js);
	}
	*/
	if($handle=='lodash') $js = '_HWIO.readyjs(function(){if(typeof _=="undefined")_=lodash;'.$js.'},"lodash")';
	if(in_array($handle, ['wp-api-fetch','wp-i18n'])) $js = '_HWIO.readyjs(function(){'.$js.'},"wp")';
	if($handle=='wp-polyfill') $js='_HWIO.readyjs(function(){'.$js.'})';
	return $js;
}, 10000, 2);

add_filter('hpp_prepare_buffer_html', function($html, $merged=null){
	//for plugin thrive-visual-editor: 
	/*if(strpos($html, '<meta name="critical-css-name"')===false && strpos($html, '<style id="critical-css"')===false) {	//strpos($html, '{{hpp_critical}}')===false
		preg_match('#<head(.*)?>#', $html, $m);
		$html = str_replace($m[0], $m[0]. $GLOBALS['hpp-head-critical'], $html);
	}*/
	/*if(is_ssl()) {
		if(strpos($html, 'http://')!==false) $html = str_replace('http://', 'https://', $html);
		if(strpos($html, 'http:\/\/')!==false) $html = str_replace('http:\/\/', 'https:\/\/', $html);
		if(strpos($html, 'https://www.w3.org')!==false) $html = str_replace('https://www.w3.org','http://www.w3.org', $html);
	}*/
	//if($_GET['t'])file_put_contents(ABSPATH.'/1.txt', $html);
	//cause wp_kses_post remove 'data:'
	if(strpos($html, ' src="image/')!==false) $html = str_replace(' src="image/',' src="data:image/', $html);
	if(strpos($html, 'https://app.getresponse')!==false) $html = str_replace(' src="https://app.getresponse', ' data-src="https://app.getresponse', $html);
	return $html;
},10, 2);

add_filter('hpp_after_buffer_html', function($html){
	return $html;
});

add_filter('hpp_save_merge_file', function($text, $file) {
	if(strpos($file,'.css')!==false) {
		/*if(is_ssl()) {
			if(strpos($text, 'http://')!==false) $text = str_replace('http://', 'https://', $text);	//ssl
			if(strpos($text, 'https://www.w3.org')!==false) $text = str_replace('https://www.w3.org','http://www.w3.org', $text);
		}*/
	}
	if(strpos($file,'.js')!==false) {
		if(strpos($text, '/*! jQuery v')===false) {
			$GLOBALS['hpp-jq'] = file_get_contents(ABSPATH.'/wp-includes/js/jquery/jquery.min.js');
			if(!empty($GLOBALS['hpp-jq'])) $text = $GLOBALS['hpp-jq']."\n".$text;
		}
		#$text = str_replace("throw new Error('Bootstrap", "//throw new Error('Bootstrap", $text);
		if(strpos($text, 'google.maps.event')!==false) {
			$text = str_replace("google.maps.event.addDomListener(window, 'load', initialize)", '_HWIO.readyjs(initialize)', $text);
			if(strpos($text, '1||(')===false) $text = str_replace("typeof google === 'object' && typeof google.maps === 'object'", "1||(typeof google === 'object' && typeof google.maps === 'object')", $text);
		}
	}
	return $text;
}, 10, 2);

add_action('hpp_print_initjs', function(){
	if(!is_dir(__DIR__.'/tool/vendor')) echo '_HWIO.data.disalog=1;';//echo '_HWIO.data.gencss=1;';
	if(hw_config('sync')) echo '_HWIO.data.__sync=1;';
	if(get_option('w2p-fixlcp')==1) {
		echo '(function(){window.lazySizesConfig.init=false;var css="body.hpp-loading [data-src]{/*background:#fafafa;*/}",h=document.head||document.getElementsByTagName("head")[0],tg=document.createElement("style");h.appendChild(tg),tg.type="text/css",tg.styleSheet?tg.styleSheet.cssText=css:tg.appendChild(document.createTextNode(css))})();';
	}
	?>
_HWIO.add_event('hpp_allow_js', function(v,att){	
	<?php if(hw_config('test')) echo 'att.l+=(att.l.indexOf("?")!==-1? "&":"?")+"__t='.time().'";';
	if(!hw_config('merge_js')) echo 'return v;';
	?>
	if(att.l.indexOf(window.location.protocol)!==0 ) att.l = att.l.replace(/^http(s?):/g, window.location.protocol);
		/*|| (location.href.indexOf('merge_js=0')===-1 && (att.l.indexOf('code.jquery.com')!==-1 || att.l.indexOf('jquery.min.js')!==-1))*/
  return att.l.endsWith('.less') || att.l.endsWith('.html') || att.l.indexOf('/jquery.min.js')!==-1? 0:v;
});
_HWIO.add_event('hpp_allow_css', function(v,att){
	<?php if(hw_config('test')) echo 'att.l+=(att.l.indexOf("?")!==-1? "&":"?")+"__t='.time().'";';?>
	if(att.l.indexOf(window.location.protocol)!==0 ) att.l = att.l.replace(/^http(s?):/g, window.location.protocol);
	return v;
});
	<?php
});

//note: just keep fonts used in all pages, remove other fonts
add_action('print_critical_css', function($css) {
	if(isset($_GET['cls'])) {
		$js = file_get_contents(__DIR__.'/asset/init.min.js');
		if(strpos($js, '"hpp_criticalfonts"')!==false) {
			preg_match_all('#@font-face(\s+)?\{(.*?)\}#si', $css, $m);
			preg_match_all('#font-family(\s?):(.*?)(;|})#', join("\n",$m[0]), $m);
			$js = str_replace('["hpp_criticalfonts"]', json_encode( array_values(array_unique(array_map('hpp_attr_value',$m[2])))) , $js);
			file_put_contents(__DIR__.'/asset/init.min.js', $js);
		}
	}
});

add_filter('body_class', function($classes){
	if(hpp_shouldlazy()) {
      $classes[] = 'hpp-lazy';
      $classes[] = 'hpp-loading';
    }
	return $classes;
});

if(hw_config('server_cache')) {
	remove_action( 'template_redirect', 'redirect_canonical' );
	$_SERVER['HTTPS']='on';

	if (is_admin() || $GLOBALS['pagenow'] === 'wp-login.php'  ) { //&& ! empty( $_REQUEST['action'] ) && $_REQUEST['action'] === 'login'
        add_filter('site_url', function($url, $path, $scheme, $blog_id){
            $v1 = hw_config('server_cache');
            if(strpos($url, 'wp-login.php')!==false) $url = str_replace('://','://'.$v1.'.', $url); ;
            return $url;
        },10, 4);
        
        add_filter('admin_url', function($url, $path, $blog_id){
            $v1 = hw_config('server_cache');
            if(strpos($_SERVER['HTTP_HOST'], $v1.'.')===0 && strpos($url,$v1.'.')===false) $url = str_replace('://','://'.$v1.'.', $url);
            return $url;
        },10, 3);
    }
    //before redirect
    add_filter('login_url', function($login_url, $redirect, $force_reauth){
        $v1 = hw_config('server_cache');
        if(strpos($_SERVER['HTTP_HOST'], $v1.'.')===0 && strpos($login_url,$v1.'.')===false) {
            $login_url = str_replace('://','://'.$v1.'.', $login_url);
        }
        return $login_url;
    }, 10, 3);
    
    add_filter('logout_url', function($logout_url, $redirect){
        $v1 = hw_config('server_cache');
        if(strpos($_SERVER['HTTP_HOST'], $v1.'.')===0 && strpos($logout_url,$v1.'.')===false) {
            $logout_url = str_replace('://','://'.$v1.'.', $logout_url);
        }
        return $logout_url;
    },10, 2);

    add_action('wp_redirect', function($l){
        $v1 = hw_config('server_cache');
        if(strpos($_SERVER['HTTP_HOST'], $v1.'.')===0 && strpos($l, $v1.'.')===false && strpos($l, '/wp-admin')!==false) {
            $l = str_replace('://','://'.$v1.'.', $l);
        }
        if(!is_admin() && $v1 && strpos($l, $v1.'.')!==false) $l = str_replace($v1.'.','', $l);
        return $l;
    });

    add_action('customize_controls_print_footer_scripts', function(){
	    $v1 = hw_config('server_cache');
		echo <<<EOF
	<script>
	var __t=setInterval(function(){if(typeof jQuery!='undefined')clearInterval(__t);else return;
	jQuery(document).ready(function($){
	    var frm = $('#customize-preview iframe');
	    if(frm.attr('src').indexOf('{$v1}.')===-1) frm.attr('src', frm.attr('src').replace('https://','https://{$v1}.'));
	});
	},1000);
	</script>
EOF;

	});

}
//always should no merge inline
add_filter('hpp_allow_merge_inline', function($ok, $code, $tp) {
	if(!hw_config('merge_inline')) return false;
	if($tp=='js' && !apply_filters('hpp_allow_readyjs',true,$code)) return false;
	return $ok;
}, 10,3);

add_filter('hpp_merge_inline', function($code, $tp){
	if(strpos($code, '_HWIO.readyjs(function(){')!==0) $code= '_HWIO.readyjs(function(){'.$code.'});';
	return $code;
},10, 2);

/*hpp_inject*/