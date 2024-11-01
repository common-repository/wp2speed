<?php
/**
 * Plugin Name: WP2Speed Faster
 * Description: Make website faster, speed up page load time and improve performance scores in services like GTmetrix, Pingdom, YSlow and PageSpeed.
 * Plugin URI: https://wordpress.org/plugins/wp2speed
 * Version: 1.0.1
 * Author: Wp2speed
 * Author URI: https://wp2speed.com/
 * Text Domain: wp2speed
 * License: GNU General Public License v3.0
*/
if (!defined('ABSPATH')) {
	exit;
}
include_once __DIR__.'/lib/custom.php';

#if(!class_exists('WP2Speed')) :
class WP2Speed
{
	private $host = '';
	private $root = '';
	private $refreshed = false;
	
	private $mergecss = true;
	private $checkcssimports = true;
	private $mergejs = true;
	private $cssmin = true;
	private $jsmin = true;
	private $http2pushCSS = false;
	private $http2pushJS = false;
	private $outputbuffering = false;
	private $buffering = false;
	private $gzip = false;
	private $ignore = array();
	
	private $wordpressdir = '';
	
	private $scriptcount = 0;
	
	private $hasMerged = false;

	private $rootRelativeWPContentDir = '';
	//hoang
	private $inline_scripts = ['js'=>[],'css'=>[]];
	private $scripts = [
		'js'=>['log'=>[],'files'=>[],], //'script'=>'',
		'css'=>['log'=>[],'style'=>[],'files'=>[]]
	];
	private $_data = ['filter_assets'=>[], 'lazy_assets'=>[]];

	public function __construct()
	{
		//turn on output buffering as early as possible if required
		$this->outputbuffering = 0;//hoang|$this->outputbuffering = get_option('mmr-outputbuffering');
		if(!is_admin() && $this->outputbuffering)
		{
			$this->buffering = ob_start();
		}
		
		$this->min = defined('PHP_INT_MIN') ? PHP_INT_MIN : -9223372036854775808;
		$this->max = defined('PHP_INT_MAX') ? PHP_INT_MAX : 9223372036854775807;
		/*
		Init MMR after all other inits.
		WordPress loads plugins in alphabetical order so we do this to ensure that should_mmr filter will run after everything is ready.
		*/
		add_action('init', array($this, 'init'), $this->max);
	}
	
	function init()
	{		
		/*
		Valid Configs:
		
		MMR_CACHE_DIR + MMR_CACHE_URL
		MMR_CACHE_DIR + MMR_JS_CACHE_URL + MMR_CSS_CACHE_URL
		MMR_CACHE_DIR + MMR_CACHE_URL + MMR_JS_CACHE_URL + MMR_CSS_CACHE_URL // MMR_CACHE_URL becomes unnecessary
		MMR_CACHE_DIR + MMR_CACHE_URL + MMR_JS_CACHE_URL
		MMR_CACHE_DIR + MMR_CACHE_URL + MMR_CSS_CACHE_URL
		MMR_CACHE_URL
		MMR_JS_CACHE_URL + MMR_CSS_CACHE_URL
		MMR_CACHE_URL + MMR_JS_CACHE_URL + MMR_CSS_CACHE_URL // MMR_CACHE_URL becomes unnecessary
		MMR_CACHE_URL + MMR_JS_CACHE_URL
		MMR_CACHE_URL + MMR_CSS_CACHE_URL
		MMR_CSS_CACHE_URL
		MMR_JS_CACHE_URL	
		*/
		
		if(!defined('MMR_CACHE_DIR'))
		{
			define('MMR_CACHE_DIR', WP_CONTENT_DIR . '/mmr');
			
			if(!defined('MMR_CACHE_URL'))
			{
				define('MMR_CACHE_URL', apply_filters('hpp_cache_url',WP_CONTENT_URL . '/mmr'));
			}
		}
		else if(WP_DEBUG && !defined('MMR_CACHE_URL') && (!defined('MMR_JS_CACHE_URL') || !defined('MMR_CSS_CACHE_URL')))
		{
			wp_die("You must specify MMR_CACHE_URL or MMR_JS_CACHE_URL & MMR_CSS_CACHE_URL");
		}
		
		if(!defined('MMR_JS_CACHE_URL'))
		{
			define('MMR_JS_CACHE_URL', MMR_CACHE_URL);
		}
		if(!defined('MMR_CSS_CACHE_URL'))
		{
			define('MMR_CSS_CACHE_URL', MMR_CACHE_URL);
		}

		if(!is_dir(MMR_CACHE_DIR))
		{
			mkdir(MMR_CACHE_DIR);
		}

		/* Calculate Root Relative path to WP Content */
		if(defined('WP_CONTENT_URL'))
		{
			$this->rootRelativeWPContentDir = parse_url(WP_CONTENT_URL,PHP_URL_PATH);
			if(!$this->rootRelativeWPContentDir) $this->rootRelativeWPContentDir = '/wp-content';//hoang

		}
		else
		{
			$this->rootRelativeWPContentDir = str_replace($_SERVER['DOCUMENT_ROOT'],'', WP_CONTENT_DIR);
		}

		$this->root = $_SERVER["DOCUMENT_ROOT"];
		$this->wordpressdir = apply_filters('hpp_sitedir', rtrim(parse_url(network_site_url(), PHP_URL_PATH),'/'));
		#if(!is_admin() && (!function_exists('hpp_shouldLazy') || !hpp_shouldLazy())) {return;}	//done in should_mmr
/*#may css error?*/
		add_action('mmr_minify', array($this, 'minify_action'), 10, 1);
		add_action('mmr_minify_check', array($this, 'minify_action'), 10, 1);
		
		add_action('compress_css', array($this, 'minify_action'), 10, 1); // Depreciated
		add_action('compress_js', array($this, 'minify_action'), 10, 1); // Depreciated

		if(is_admin())
		{
			if(current_user_can('administrator'))
			{
				add_action( 'admin_menu', array($this, 'admin_menu') );
				add_action( 'admin_enqueue_scripts', array($this, 'load_admin_jscss') );
				add_action( 'wp_ajax_mmr_files', array($this, 'mmr_files_callback') );
				add_action( 'admin_init', array($this, 'register_settings') );
				register_deactivation_hook( __FILE__, array($this, 'plugin_deactivate') );
				
				if(hw_config('minify_merge') && !wp_next_scheduled('mmr_minify_check'))
				{
			        wp_schedule_event(time(), 'hourly', 'mmr_minify_check');
			    }
			    
			    #add_action('in_plugin_update_message-wp2speed/wp2speed.php', array($this, 'showUpgradeNotification'), 10, 2);
			}
			#return;
		}
		else if(apply_filters('should_mmr', true))
		{
			//https://wordpress.org/support/topic/php-notice-with-wp-5-1-1/#post-11494275
			/*if(array_key_exists('HTTP_HOST', $_SERVER))
			{
				$this->host = $_SERVER['HTTP_HOST'];
				//php < 5.4.7 returns null if host without scheme entered
				if(mb_substr($this->host, 0, 4) !== 'http')
				{
					$this->host = 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 's' : '') . '://' . $this->host;
				}
				$this->host = parse_url($this->host, PHP_URL_HOST);
			}*/
			$this->host = parse_url(WP_SITEURL, PHP_URL_HOST);	//fix for my varnish cdn
		#$this->host="cdn.{$this->host}";
			$this->mergecss = 1;//!get_option('mmr-nomergecss');
			$this->checkcssimports = 0;//!get_option('mmr-nocheckcssimports');
			$this->mergejs = 1;//!get_option('mmr-nomergejs');
			$this->cssmin = 1;//!get_option('mmr-nocssmin');
			$this->jsmin = 1;//!get_option('mmr-nojsmin');
			
			/* Depreciated mmr-http2push - remove June 2020 */
			/*if(get_option('mmr-http2push') && get_option('mmr-http2push-css', 'undefined') == 'undefined' && get_option('mmr-http2push-js', 'undefined')  == 'undefined')
			{
				add_option('mmr-http2push-css', true);
				add_option('mmr-http2push-js', true);
			}
			delete_option('mmr-http2push');
			/* End Depreciated mmr-http2push - remove June 2020 */
			
			#$this->http2pushCSS = get_option('mmr-http2push-css');
			#$this->http2pushJS = get_option('mmr-http2push-js');
			
			$this->gzip = 0;//get_option('mmr-gzip');
			$this->ignore = [];//array_map('trim',explode(PHP_EOL,get_option('mmr-ignore')));

			add_action( 'wp_print_scripts', array($this,'inspect_scripts'), $this->max);
			add_action( 'wp_print_styles', array($this,'inspect_styles'), $this->max);
		
			add_filter( 'style_loader_src', array($this,'remove_cssjs_ver'), 10, 2);
			add_filter( 'script_loader_src', array($this,'remove_cssjs_ver'), 10, 2);

			add_action( 'wp_print_footer_scripts', array($this,'inspect_stylescripts_footer'), 9.999999); //10 = Internal WordPress Output
			add_action('wp_footer', array($this, 'print_footer'), PHP_INT_MAX);

			add_action('shutdown', array($this, 'refreshed'), 10);
		}
		else if( $this->buffering) //stop output buffering if we started but didn't need to
		{
			$this->buffering = false;
			ob_end_flush();
		}
	}

	public function mmr_files_callback()
	{
		if(isset($_POST['purge']) && $_POST['purge'] == 'all')
		{
			if(hw_config('minify_merge') ) wp_clear_scheduled_hook('mmr_minify');
			$this->rrmdir(MMR_CACHE_DIR); 
			if(function_exists('hpp_purge_cache')) hpp_purge_cache();	//hoang
		}
		else if(isset($_POST['purge']))
		{
			array_map('unlink', glob(MMR_CACHE_DIR . '/' . basename($_POST['purge']) . '*'));
		}

		$return = array('js'=>array(),'css'=>array(),'stamp'=>$_POST['stamp']);

		$files = (array)glob(MMR_CACHE_DIR . '/*.log', GLOB_BRACE);

		if(count($files) > 0)
		{
			foreach($files as $file)
			{
				$script_path = substr($file, 0, -4); 
				
				$ext = pathinfo($script_path, PATHINFO_EXTENSION);

				$log = file_get_contents($file);
				
				$error = false;
				if(strpos($log,'COMPRESSION FAILED') !== false)
				{
					$error = true;
				}

				$filename = basename($script_path);
				
				switch($ext)
				{
					case 'css':
						$minpath = substr($script_path,0,-4) . '.min.css';
					break;
					case 'js':
						$minpath = substr($script_path,0,-3) . '.min.js';
					break;
				}
				
				if(file_exists($minpath))
				{
					$filename = basename($minpath);
				}
				
				$hash = substr($filename,0,strpos($filename,'-'));$hash=md5($filename);//hoang
				$accessed = 'Unknown';
				if( file_exists($script_path . '.accessed'))
				{
					$accessed = file_get_contents($script_path . '.accessed');
					if(strtotime('today') <= $accessed)
					{
						$accessed = 'Today';
					}
					else if(strtotime('yesterday') <= $accessed)
					{
						$accessed = 'Yesterday';
					}
					else if(strtotime('this week') <= $accessed)
					{
						$accessed = 'This Week';
					}
					else if(strtotime('this month') <= $accessed)
					{
						$accessed = 'This Month';
					}
					else
					{
						$accessed = date(get_option('date_format'), $accessed);
					}
				}
				array_push($return[$ext], array('hash'=>$hash, 'filename'=>$filename, 'log'=>$log, 'error'=>$error, 'accessed'=>$accessed));							
			}
		}

		header('Content-Type: application/json');
		echo json_encode($return);
		
		wp_die(); // this is required to terminate immediately and return a proper response
	}

	public function plugin_deactivate()
	{	
		if(hw_config('minify_merge')) {
			wp_clear_scheduled_hook('mmr_minify');
			wp_clear_scheduled_hook('mmr_minify_check');
		}
		if(is_dir(MMR_CACHE_DIR))
		{
			$this->rrmdir(MMR_CACHE_DIR); 
		}
	}

	private function rrmdir($dir)
	{ 
		foreach(glob($dir . '/{,.}*', GLOB_BRACE) as $file)
		{ 
			if(basename($file) != '.' && basename($file) != '..')
			{
				if(is_dir($file)) $this->rrmdir($file); else unlink($file); 
			}
		}
		rmdir($dir); 
	}

	public function load_admin_jscss($hook)
	{
		if('settings_page_wp2speed' != $hook)
		{
			return;
		}
		wp_enqueue_style( 'wp2speed', plugins_url('lib/asset/admin.css', __FILE__));
		wp_enqueue_script( 'wp2speed', plugins_url('lib/asset/admin.js', __FILE__), array(), false, true );
	}

	public function admin_menu()
	{
		add_options_page('PageSpeed Optimizer Settings', 'WP2Speed', 'manage_options', 'wp2speed', array($this,'merge_minify_refresh_settings'));
	}

	public function register_settings()
	{
		register_setting('w2p-group', 'w2p-code');
		register_setting('w2p-group', 'w2p-fixlcp');
		/*register_setting('mmr-group', 'mmr-nocheckcssimports');
		register_setting('mmr-group', 'mmr-nomergejs');
		register_setting('mmr-group', 'mmr-nocssmin');
		register_setting('mmr-group', 'mmr-nojsmin');
		register_setting('mmr-group', 'mmr-http2push-css');
		register_setting('mmr-group', 'mmr-http2push-js');
		register_setting('mmr-group', 'mmr-outputbuffering');
		register_setting('mmr-group', 'mmr-gzip');
		register_setting('mmr-group', 'mmr-ignore');*/
	}
	
	public function merge_minify_refresh_settings()
	{
		if(!current_user_can('manage_options')) 
		{
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}

		//echo '<pre>';var_dump(_get_cron_array()); echo '</pre>';

		#$files = glob(MMR_CACHE_DIR . '/*.{js,css}', GLOB_BRACE);
		$class = (empty($_GET['hpp-dev'])?'mmr-hidden':'');
		//<span>Visit our website: <a href="https://wp2speed.com" target="_blank">https://wp2speed.com</a></span><hr/>
		
	echo '<form method="post" id="w2p_options" action="options.php">';
		settings_fields('w2p-group'); 
		do_settings_sections('w2p-group'); 
		//tabs
		$tabs = [
			'file'=> ['title'=>'General', 'desc'=>''], 
			'lic'=> ['title'=>'License', 'desc'=>'Purchase Code'], 
		];
		echo '<h2 class="nav-tab-wrapper" id="w2p-tabs">';
		foreach($tabs as $id=> $tab) {
			printf('<a class="nav-tab %s" id="%s" href="#%s">%s</a>', $id=='file'? 'nav-tab-active':'', $id.'-tab', $id, $tab['title']);
		}
		echo '</h2>';
		//tab content
		foreach($tabs as $id=> $tab) {
			printf('<div id="%s" class="w2ptab %s">', $id, $id=='file'?'nosave active':'save');
			if($id=='lic') {
				if($tab['desc']) echo "<h3>{$tab['desc']}</h3>";
				echo '<p><label>License Code</label> <input type="text" name="w2p-code" value="' . get_option('w2p-code') . '" style="width:300px"/>';
				echo '<span id="w2p-license-info"></span>';
				echo '<p><button type="submit" class="button button-primary">SAVE</button></p>';
			}
			else if($id=='file') {
				$lcp = checked(1 == get_option('w2p-fixlcp') , true, false);

				echo <<<END
		<div id="w2p-page">
				<h2>Re-build Merge Files</h2>				
				<p>When a CSS or JS file is modified the plugin will automatically re-process the files. However, when a dependancy changes these files may become stale.<br>You can click to button below to re-build the merge files.</p>
		
				<div id="mmr_processed">
					<a href="#" class="button button-secondary purgeall">Purge All</a>
				
					<div id="mmr_jsprocessed" class="{$class}">
						<h4>The following Javascript files have been processed:</h4>
						<ul class="processed"></ul>
					</div>
				
					<div id="mmr_cssprocessed" class="{$class}">
						<h4>The following CSS files have been processed:</h4>
						<ul class="processed"></ul>
					</div>
				</div>
				<p><label><input type="checkbox" name="w2p-fixlcp" value="1" {$lcp}/> Optimize LCP</label></p>
				<p id="mmr_noprocessed"><strong style="display:none">No files have been processed</strong></p>
				<p><button type="submit" class="button button-primary">SAVE</button></p>
			</div>
END;

			}
			echo '</div>';
		}
		echo '</form>';
/*
		echo '<form method="post" id="mmr_options" action="options.php">';
		settings_fields('mmr-group'); 
		do_settings_sections('mmr-group'); 
		echo '<p><label><input type="checkbox" name="mmr-nomergecss" value="1" ' . checked(1 == get_option('mmr-nomergecss') , true, false) . '/> Don\'t Merge CSS</label>';
		echo '<label><input type="checkbox" name="mmr-nomergejs" value="1" ' . checked(1 == get_option('mmr-nomergejs'), true, false) . '/> Don\'t Merge JS</label>';
		echo '<br/><em>Note: Selecting these will increase requests but may be required for some themes. e.g. Themes using @import</em></p>';

		echo '<p><label><input type="checkbox" name="mmr-nocssmin" value="1" ' . checked(1 == get_option('mmr-nocssmin'), true, false) . '/> Disable CSS Minification</label>';

		echo '<label><input type="checkbox" name="mmr-nojsmin" value="1" ' . checked(1 == get_option('mmr-nojsmin'), true, false) . '/> Disable JS Minification</label>';
		echo '<br/><em>Note: Disabling CSS/JS minification may require a "Purge All" to take effect.</em></p>';
		
		echo '<p><label><input type="checkbox" name="mmr-nocheckcssimports" value="1" ' . checked(1 == get_option('mmr-nocheckcssimports'), true, false) . '/> Skip checking for @import in CSS.</label>';
		echo '<br/><em>Check this if you are sure your CSS doesn\'t have any @import statements. Merging will be faster.</em></p>';

		echo '<p><label><input type="checkbox" name="mmr-http2push-css" value="1" ' . checked(1 == get_option('mmr-http2push-css'), true, false) . '/> Enable Preload/Push Headers for CSS</label>';
		echo '<br/>';
		
		echo '<label><input type="checkbox" name="mmr-http2push-js" value="1" ' . checked(1 == get_option('mmr-http2push-js'), true, false) . '/> Enable Preload/Push Headers for Javascript</label>';
		echo '<br/><em>Add response headers for CSS or JS to allow browsers to start downloading assets before parsing the DOM.</em></p>';

		echo '<p><label><input type="checkbox" name="mmr-outputbuffering" value="1" ' . checked(1 == get_option('mmr-outputbuffering'), true, false) . '/> Enable Output Buffering</label>';
		echo '<br/><em>Output buffering may be required for compatibility with some plugins.</em></p>';
		
		echo '<p><label><input type="checkbox" name="mmr-gzip" value="1" ' . checked(1 == get_option('mmr-gzip'), true, false) . '/> Enable Gzip Encoding</label>';
		echo '<br/><em>Checking this option will generate additional .css.gz and .js.gz files. Your webserver may need to be configured to use these files.</em></p>';

		echo '<p><label class="textlabel">Ignore these files (one per line):<textarea name="mmr-ignore" placeholder="file paths (view logs to get paths)">' . get_option('mmr-ignore') . '</textarea></label></p>';

		echo '<p><button type="submit" class="button">SAVE</button></p></form>';*/
	}

	public function remove_cssjs_ver($src)
	{
		if(strpos($src,'?ver='))
		{
			$src = remove_query_arg('ver', $src);
		}
		return $src;
	}
	//@deprecated
	private function http2push_reseource($url, $type = '')
	{
		if(headers_sent())
		{
			return false;
		}
		
		if($type == 'style' && !$this->http2pushCSS)
		{
			return false;
		}
		
		if($type == 'script' && !$this->http2pushJS)
		{
			return false;
		}

		$url = parse_url($url, PHP_URL_PATH); //push only works with paths
		#$url = WP_CONTENT_URL.$url;//hoang
		$http_link_header = array("Link: <{$url}>; rel=preload");

		if($type != '')
		{
			$http_link_header[] = "as={$type}";
		}

		header( implode('; ', $http_link_header), false);
	}

	private function host_match($url)
	{
		if(empty($url))
		{
			return false;
		}

		$url = $this->ensure_scheme($url);
		$url_host = parse_url($url, PHP_URL_HOST);
		
		if( !$url_host || $url_host == $this->host || strpos($url_host , $this->host)!==false)	//hoang
		{
			return true;
		}
		else
		{
			return false;
		}
	}
	
	//php < 5.4.7 parse_url returns null if host without scheme entered
	private function ensure_scheme($url)
	{
		return preg_replace("/(http(s)?:\/\/|\/\/)(.*)/i", "http$2://$3", $url);
	}
	
	private function remove_scheme($url)
	{
		return preg_replace("/(http(s)?:\/\/|\/\/)(.*)/i", "//$3", $url);
	}
	
	private function fix_wp_subfolder($file_path)
	{//echo $file_path."<br>\n";
		//hoang
		if(0&& strpos($file_path,'/wp-')!==0) {
			$file_path="/wp-content{$file_path}";#die;
		}

		if(!is_main_site() && defined('SUBDOMAIN_INSTALL') && !SUBDOMAIN_INSTALL) //WordPress site is within a subfolder
		{ 
			$details = get_blog_details();
			$file_path = preg_replace('|^' . $details->path . '|', '/', $file_path);
		}	
		/* WordPress includes files relative to its core. This fixes paths when WordPress isn't in the document root. */
		if(
			$this->wordpressdir != '' && //WordPress core is within a subfolder
			substr($file_path, 0, strlen($this->wordpressdir) + 1) != $this->wordpressdir . '/' && //File is not in WordPress core directory
			substr($file_path, 0, strlen($this->rootRelativeWPContentDir) + 1) != $this->rootRelativeWPContentDir . '/' //File is not in the wp-content directory
			//&& file_exists($this->root.'/'.$this->wordpressdir . $file_path)
		) {
			if($this->wordpressdir && !file_exists($this->root.'/'.$this->wordpressdir . $file_path)) $this->wordpressdir = '';
			else $file_path = $this->wordpressdir . $file_path;
		}
		#print_r('-->'.$file_path."\n");
		return $file_path;
	}
	private function fix_path_url($file_path) {
		if($this->wordpressdir!='' && strpos($file_path, $this->wordpressdir.'/')===0) {
			$file_path = substr($file_path, strlen($this->wordpressdir));
		}
		return $file_path;
	}
	private function fix_resource_url($url) {
		$home_url = $this->home_url();
		if($this->wordpressdir!='' && strpos($url, $home_url)===0) {
			$fpath = trim(substr($url, strlen($home_url)),'/');
			if(strpos($fpath, $this->wordpressdir.'/')===0 ) {
				$url = rtrim($home_url,'/').substr($fpath, strlen($this->wordpressdir));
			}
		}
		//lang: (\w){2,3}
		return $url;//preg_replace('#\/(en|vi)\/wp-content\/#', '/wp-content/', $url);
	}
	function home_url() {
		if(!isset($this->_data['siteurl'])) {
			$url = trim(get_home_url(),'/');
			$url = preg_replace('#\/('.apply_filters('hpp_url_langs','en|vi').')/?$#', '', $url);
			$this->_data['siteurl'] = $url;
		}
		return $this->_data['siteurl'];	
	}
	public function inspect_styles()
	{
		if(!hpp_shouldLazy()) return;
		wp_styles(); //ensure styles is initialised
		
		global $wp_styles;	
		$this->process_scripts($wp_styles, 'css');	
	}

	public function inspect_scripts()
	{
		if(!hpp_shouldLazy()) return;
		wp_scripts(); //ensure scripts is initialised

		global $wp_scripts;
		$this->process_scripts($wp_scripts, 'js');
	}
	
	public function inspect_stylescripts_footer()
	{
		if(!hpp_shouldLazy()) return;
		global $wp_scripts;
		$this->process_scripts($wp_scripts, 'js', true);

		global $wp_styles;	
		$this->process_scripts($wp_styles, 'css', true);

		if($this->buffering)
		{
			$this->buffering = false;
			ob_end_flush();
		}
		
		if($this->hasMerged)
		{
			if(hw_config('minify_merge')) wp_schedule_single_event(time(), 'mmr_minify');
			
			// https://wordpress.org/support/topic/merge_minify_refresh_done-action/#post-11866992
			do_action('wp2speed_merged'); 
		}
	}
	//@deprecated
	function _delay_js( $code, $file_path) {
		$code = $this->fixOtherJS($code);
		$fn = basename($file_path,'.js');
		/*if(!$this->jqueryInJS($code)) {
			
			#$code = '_HWIO.waitForExist(function(){'.$code.';}.bind(window),["jQuery"],100,100);';
		}*/
		#else $code.= '_HWIO.__readyjs=1;';
		//@deprecated
		if(isset($this->_data['first_js'])) {
			if(hw_config('merge_js') && !file_exists(MMR_CACHE_DIR."/site-{$fn}.js")) {
				#$code= '_HWIO.waitForExist(function(){'.$code;
				//move to main.js
				//$code .= '_HWIO.__readyjs=1;_HWIO._readyjs_.forEach(function(cb){typeof cb=="function"?cb(jQuery):_HWIO.waitForExist(cb[0],cb[1])});';
				#$code.= '}.bind(window),["jQuery"],100,100)';
				$this->save_asset(MMR_CACHE_DIR."/site-{$fn}.js", hqp_fix_encoding( $code));
			}
			if(hw_config('merge_js')) $this->_data['lazy_assets']['hpp-1']=['t'=>'js','l'=>MMR_JS_CACHE_URL.'/site-'.$fn.'.js','deps'=>'hpp-0'];
				
			return false;
		}
		$this->_data['first_js'] = $file_path;

		#$c='!function(t,e){"use strict";t=t||"docReady",e=e||window;var n=[],o=!1,d=!1;function a(){if(!o){o=!0;for(var t=0;t<n.length;t++)n[t].fn.call(window,n[t].ctx);n=[]}}function c(){"complete"===document.readyState&&a()}e[t]=function(t,e){if("function"!=typeof t)throw new TypeError("callback for docReady(fn) must be a function");o?setTimeout(function(){t(e)},1):(n.push({fn:t,ctx:e}),"complete"===document.readyState||!document.attachEvent&&"interactive"===document.readyState?setTimeout(a,1):d||(document.addEventListener?(document.addEventListener("DOMContentLoaded",a,!1),window.addEventListener("load",a,!1)):(document.attachEvent("onreadystatechange",c),window.attachEvent("onload",a)),d=!0))}}("docReady",window);docReady(function(){';
		$c = file_get_contents(file_exists(__DIR__.'/main.min.js')? __DIR__.'/main.min.js': __DIR__.'/main.js');
		#$c.= 'function __firejs(){__firejs=null;_HWIO.load_assets("css");_HWIO.load_on_wakeup(function(){_HWIO.load_assets("js");})}';
		#$c.= '});';
		if(hw_config('merge_js')) $this->_data['lazy_assets']['hpp-0']=['t'=>'js','l'=>MMR_JS_CACHE_URL.'/child-'.$fn.'.js'];
		
		if(hw_config('merge_js') && !file_exists(MMR_CACHE_DIR."/child-{$fn}.js")) {
			#$code = file_get_contents(__DIR__.'/child.js'). $code;
			$this->save_asset(MMR_CACHE_DIR."/child-{$fn}.js", hqp_fix_encoding( $code));
		}
		if(!file_exists(MMR_CACHE_DIR.'/optimize.js')) file_put_contents(MMR_CACHE_DIR.'/optimize.js', $c);

		return true;
	}
	/*function _delay_js1($code) {
		$code = $this->fixOtherJS($code);
		return '_HWIO.waitForExist(function(){jQuery(window).ready(function(){setTimeout(function(){'. $code.';_HWIO.__readyjs=1;}.bind(window), 1000);}.bind(window));}, ["jQuery"],100,100);';
	}*/
	function _delay_asset($file_path, $type='js', array $att=[]) {
		$file_path = hpp_attr_value($file_path);
		if(!$file_path) return;
		if( !(strpos($file_path,'http://')!==false || strpos($file_path,'https://')!==false) && strpos($file_path,'/')!==0) {
			$file_path = MMR_JS_CACHE_URL.'/'.ltrim($file_path,'/');
		}
		if(isset($this->_data['filter_assets'][$file_path])) return;$this->_data['filter_assets'][$file_path]=1;	//duplicate
		if(!isset($this->_data['lazy_assets'])) $this->_data['lazy_assets'] = array();
		//fix
		if(isset($att['id']) && $att['id']=='-'.$type) $att['id'] = basename(basename($file_path,'.min.'.$type),'.'.$type);
		if(empty($att['id']) && $file_path=='/wp-includes/js/jquery/jquery.min.js') $att['id']='jquery';
		if(isset($att['id']) && in_array($att['id'],['jquery-js','jquery-core-js'])) $att['id']=substr($att['id'],0, -3);
		
		//extra asset
		if(!empty($att['id']) && strpos($file_path,'/mmr/')===false) {
			global $wp_scripts, $wp_styles;
			if($type=='js' && !in_array($att['id'], $wp_scripts->done)) $wp_scripts->done[] = $att['id'];
			if($type=='css' && !in_array($att['id'], $wp_styles->done)) $wp_styles->done[] = $att['id'];
		}
		//when merged css, no need load in order
		if($type=='css' && !hw_config('merge_css')) {
			if(!isset($this->_data['last_css_i'])) $this->_data['last_css_i']=0;
			else $att['deps'] = 'hpp-s-'.($this->_data['last_css_i']++);
			if(isset($att['id'])) $att['_id'] = $att['id'];
			$att['id'] = 'hpp-s-'.$this->_data['last_css_i'];	//override id
			//way 2: with css handle name
			/*if(!isset($att['id'])) $att['id'] = hash('adler32',rand());
			if(isset($this->_data['last_css_i'])) $att['deps'] = $this->_data['last_css_i'];else $att['id'] = 'hpp-s-0';
			$this->_data['last_css_i'] = $att['id'];*/
		}
		else if($type=='js' && !hw_config('merge_js')) {
			if(in_array($att['id'], ['jquery-core','jquery-core-js'])) $att['id']='jquery';
			if(in_array($att['id'], ['jquery-migrate','jquery-migrate-js']) && empty($att['deps']) ) $att['deps']='jquery';	//|| strpos($att['deps'],'jquery')===false			
		}
		if(empty($att['id'])) $att['id'] = md5($file_path);	//use md5 for unique
		$att = apply_filters('hpp_delay_asset_att', array_merge($att,['l'=>$file_path]), $type);
		if(isset($this->_data['lazy_assets'][$att['id']])) $att['id'].= '-'.$type;	//unique
		$this->_data['lazy_assets'][$att['id']] = array_filter(array_merge([ 't'=>$type,'l'=>$file_path], $att,['id'=>'']));
		
	}
	function fixOtherJS($code, $ready=0) {
		//$code = str_replace("$(this).data('mediaelementplayer', new _player2.default(this, options));", "if($(this).find('source[data-src]').length)return;$(this).data('mediaelementplayer', new _player2.default(this, options));", $code);
		//conflict lazyload
		if(strpos($code, 'window.lazySizesConfig')!==false) $code = preg_replace('#window\.lazySizesConfig\.(.*?)=(.*?);#si', '1?1: $0', $code);	//if(!window.lazySizesConfig)
		if(hpp_in_str($code, [/*'_HWIO.readyjs(',*/'_HWIO.timeout('])) return $code;	//because readyjs already in wrap
		return $code ;
		//event: ready ->no need
		$jq=[
			'noreg'=>[
				//event 'docReady(',
				'jQuery(document).ready(', '$(document).ready(','jQuery( document ).ready(', '$( document ).ready(',
				'jQuery().ready(','$().ready(','jQuery( ).ready(','$( ).ready(',
				'jQuery(window).load(','$(window).load(','jQuery( window ).load(','$( window ).load(',
			],
			'reg'=>[
				//event
				'#(document|window).addEventListener\s?\(\s?("|\')(DOMContentLoaded|load)("|\')\s?,#',
			]			
		];
		$repl = $ready || hw_config('merge_js')? '_HWIO.readyjs(':'_HWIO.docReady(';
		#if(strpos($code, '_HWIO.')===false) $code = str_replace('docReady(', $repl, $code);	//user defined docReady()
		$code = str_replace($jq['noreg'], $repl, $code);
		foreach($jq['reg'] as $regex) $code = preg_replace($regex, $repl, $code);

		$jq1 = [
			'noreg'=>[
				'jQuery(function','jQuery( function','$(function','$( function',
				//'(function','( function', ->will match all
			]
		];
		//_HWIO.readyjs(function ->no, because merge is exactly
		$repl = $ready || hw_config('merge_js')? '_HWIO.readyjs(function':'_HWIO.timeout(function';
		$code = str_replace($jq1['noreg'], $repl, $code);
		
		return $code;
	}
	function fixCSS($code) {
		//find import rule & put at first of line: (\s+)?
		if(strpos($code, '@import')!==false){
		preg_match_all('#@import(\s+)url\((.*?)\)([\s;]+)?#si', $code, $m);
		if(count($m[0])) {
			foreach($m[0] as $s) $code = str_replace($s, '', $code);
			$code = join("\n", $m[0])."\n". $code;
		}}
		
		//fix font-display if no generate critical
		if(strpos($code, '@font-face')!==false){
		preg_match_all('#@font-face(\s+)?\{(.*?)\}([\s;]+)?#si',  $code, $m);
		foreach($m[0] as $i=>$str) {
			if(strpos($str, 'font-display')===false) $m[2][$i]='font-display:swap;'.$m[2][$i];
			else $m[2][$i] = preg_replace('#font-display(.*?);#s', 'font-display:swap;', $m[2][$i]);

			$code = str_replace($str, '@font-face{'.$m[2][$i].'}', $code);
		}}
		//note: charset alway at top
		if(strpos($code, '@charset "UTF-8";')!==false) {
			$code = str_replace('@charset "UTF-8";','', $code);
			$code = '@charset "UTF-8";'."\n".$code;
		}
		return $code;
	}
	function jqueryInJS($js) {
		return stripos($js, ' jQuery Migrate ')!==false || stripos($js, '/*! jQuery v')!==false;
	}
	function getExtraAssets() {
		return isset($this->_data['lazy_assets'])? $this->_data['lazy_assets']: array();
	}

	/**
	 if detect 1 js in merged has external dep, so not merge it
	*/
	function can_merge_handle(&$scripts, $handle, $ext){
		$deps = $this->get_all_deps($scripts,$handle, $ext);

		foreach($deps as $dep) {
			if($scripts->registered[$dep]->src && !$this->host_match($scripts->registered[$dep]->src)) {
				return false;
			}
		}
		$handles = [];
		foreach($this->_data['active_handles'] as $it) 
			if(isset($it['handles'])) $handles = array_merge($it['handles'], $handles);else if(isset($it['handle'])) $handles[]=$it['handle'];
		return apply_filters('hpp_can_merge_file', true, $handle, $ext, array_unique($handles));
	}
	function get_all_deps($scripts, $handle, $ext) {
		$all=[];
		$all = array_merge($all, $scripts->registered[$handle]->deps);

		foreach($all as $dep) {
			$all = array_merge($all, $this->get_all_deps($scripts, $dep, $ext));
		}
		//custom deps
		$r = apply_filters('hpp_delay_asset_att',['id'=> $handle, 'deps'=>join(',', $all)], $ext);
		if(!empty($r['deps'])) $all = array_unique(array_merge($all, array_filter(explode(',',$r['deps']))));
		//return explode(',',$r['deps']);
		return $all;
	}
	static function inline_text($str) {
		return preg_replace('/(\s+){2,}/',' ', preg_replace("/[\r\n]*/","",$str));
	}
	function save_asset($file, $text) {
		file_put_contents($file, apply_filters('hpp_save_merge_file', $text, $file));
	}
	function ext2local(&$ourList, $handle, $script_path='', $tp='') {
		if(hw_config('merge_'.$tp) && isset($ourList->registered[$handle])) {
			if(!$script_path) {
				#$script_path = parse_url($this->ensure_scheme($ourList->registered[$handle]->src), PHP_URL_PATH);
				#$script_path = $this->fix_wp_subfolder($script_path);
				$script_path = $this->script_path($ourList->registered[$handle]->src);
			}

			$url = !hpp_in_str($ourList->registered[$handle]->src,['http://','https://'])? 'https://'.trim($ourList->registered[$handle]->src,'/'): $ourList->registered[$handle]->src;
			//$file = 'uploads/'.md5($handle).'-'.basename($url);
			$path = dirname($this->root . $script_path);if(!is_dir($path)) @mkdir($path, 0755,true);
			file_put_contents($this->root . $script_path, hpp_curl_get($url,[], false,200));
			$ourList->registered[$handle]->src = $this->home_url().$script_path;	//WP_CONTENT_URL.'/'.$file;
		}
	}
	function if_merge($val, $def='jquery', $tp='js') {
		return hw_config('merge_'.$tp)? $val: $def;
	}
	//no, we move to tool
	function get_css_import($script_path, $text ) {return $text;
	  if(strpos($text, '@import ')!==false) {
	    $home_url = $this->home_url();

	    preg_match_all('#@import(\s+)url\((.*?)\)([\s;]+)?#si', hpp_strip_comment($text,'css'), $m);
	    foreach($m[0] as $i1=>$l) {
	    	if(!$this->host_match($m[2][$i1])) continue;
	      $path = (strpos($m[2][$i1], $home_url)===0)? trim(substr($m[2][$i1], strlen($home_url)),'/'): $m[2][$i1];
	      $path = trim(trim(trim($path),'"'),"'");
	      if(hpp_is_url($path)) {
	      	if(strpos($path, parse_url($home_url,PHP_URL_HOST))===false) continue;
	      	$path = hpp_fix_resource_url($path);
	      	$css = hpp_curl_get($path, [], false, 200);
			$p=parse_url($path);
			$script_path = isset($p['path'])? $p['path']:'';
			$_url = dirname($path);
			#$fpath = $this->root.'/'.$script_path;		
			$css = preg_replace_callback("/url\(\s*['\"]?(?!data:)(?!http)(?![\/'\"])(.+?)['\"]?\s*\)/i", function($m)use($_url){
				$m[1] = trim(trim(trim($m[1]),'"'),"'");
				return "url('" . ($_url) . "/{$m[1]}')";
			}, $css);
			$text = str_replace($l, '/*['.$path.']*/'."\n".$this->get_css_import($script_path, $css), $text);
	      }
	      else {
	      	$fpath = $this->root.'/'.rtrim($script_path,'/').'/'.$path;
		      if(file_exists($fpath)) {
		      	$css = file_get_contents($fpath);
		      	if($path!='') $script_path.='/'.dirname($path);
		      	$_url = $this->fix_resource_url($home_url.$this->fix_path_url($script_path));
		      		//"url('" . $this->fix_resource_url($_url) . "/$1')"
		      	$css = preg_replace_callback("/url\(\s*['\"]?(?!data:)(?!http)(?![\/'\"])(.+?)['\"]?\s*\)/i", function($m)use($_url){
					$m[1] = trim(trim(trim($m[1]),'"'),"'");
					return "url('" . ($_url) . "/{$m[1]}')";
				}, $css);
		      	$text = str_replace($l, '/*['.$fpath.']*/'."\n".$this->get_css_import($script_path, $css), $text);
		    }
	      }
		      
	    }
	  }
	  return $text."\n";
	}

	/**
	 * process_scripts function.
	 * 
	 * @param mixed &$script_list - copy of the global wp list
	 * @param mixed $ext - type of script to check 'css' or 'js' 
	 * @param bool $in_footer (default: false)
	 * @return void
	 */
	private function process_scripts(&$script_list, $ext, $in_footer = false)
	{
		global $blog_id;
		if($script_list)
		{
			$script_line_end = "\n";
			if($ext == 'js')
			{
				$script_line_end = ";\n";
			}
			
			$scripts = clone $script_list;
			if(!isset($this->scripts[$ext]['q'])) $this->scripts[$ext]['q'] = [$scripts->queue];else $this->scripts[$ext]['q'][]=$scripts->queue;
			$scripts->all_deps($scripts->queue);
			
			$handles = $this->get_handles($ext, $scripts, !$in_footer);
			$this->_data['active_handles'] = $handles;#print_r($handles);
			$done = $scripts->done;
			$log = $output = [];#$page_handles=[];	//$output_css = 

			//loop through header scripts and merge + schedule wpcron
			for($i=0,$l=count($handles);$i<$l;$i++)
			{
				if(!isset($handles[$i]['handle']))
				{
					$done = array_merge($done, $handles[$i]['handles']);

					$hash = hash('adler32', $this->home_url() . implode('', $handles[$i]['handles'])); //get_home_url() prevents multisite hash collisions
					if(is_multisite() && $blog_id > 1) $hash = $blog_id.'-'.$hash;
					#$page_handles = array_merge($page_handles, $handles[$i]['handles']);	//hoang
				
					$file_path = '/' . $hash . '-' . $handles[$i]['modified'] . '.' . $ext;
				
					$full_path = MMR_CACHE_DIR . $file_path;
				
					$min_path = '/' . $hash . '-' . $handles[$i]['modified'] . '.min.' . $ext;
				
					$min_exists = file_exists(MMR_CACHE_DIR . $min_path);

					if(1 //!file_exists($full_path) && !$min_exists 
						#&& !file_exists(dirname($full_path).'/site-'.basename($full_path))	//hoang
						)
					{
						$this->hasMerged = true;
						
						$output[$i] = '';
						$log[$i] = "";
						$should_minify = true;
					
						foreach( $handles[$i]['handles'] as $handle)
						{
							// /*!hw_config('merge') ||*/no need open for css for better performance
							if($ext=='js' && !$this->can_merge_handle($scripts, $handle, $ext)) {#hoang
								$this->_delay_asset($scripts->registered[$handle]->src, 'js', array_filter(['id'=>$handle/*.'-'.$ext*/, 'deps'=>join(',',$scripts->registered[$handle]->deps)]));
								continue; 
							}
							$log[$i] .= " - " . $handle . " - " . $scripts->registered[$handle]->src;

							#$script_path = parse_url($this->ensure_scheme($scripts->registered[$handle]->src), PHP_URL_PATH);
							#$script_path = $this->fix_wp_subfolder($script_path);
							$script_path = $this->script_path($scripts->registered[$handle]->src);
							#echo '>>>>'.$this->root . $script_path,"\n";
							//https://wordpress.org/support/topic/php-warning-failed-to-open-stream-no-such-file-or-directory/
							if(0&& !file_exists($this->root . $script_path))
							{
								continue;
							}							
						
							/*if(substr($script_path, -7) == '.min.' . $ext)
							{
								if(count($handles[$i]['handles']) > 1) //multiple files default to not minified
								{ 
									$nomin_path = substr($script_path, 0, -7) . '.' . $ext; 
									if(is_file($this->root . $nomin_path))
									{
										$script_path = $nomin_path;
										$log[$i] .= " - unminified version used";
									}
								}
								else
								{						
									#$should_minify = false; //hoang, single file is already minified
								}
							}*/
							if(!apply_filters('hpp_allow_inline_data', true, $handle,'') && ($key = array_search($handle, $done)) !== false) {
								unset($done[$key]);
							}
						
							$contents = '';
							
							if(0&& $ext == 'js' && isset($scripts->registered[$handle]->extra['before']) && count($scripts->registered[$handle]->extra['before']) > 0)
							{
								$contents .= implode($script_line_end,$scripts->registered[$handle]->extra['before']) . $script_line_end;
							}

							/*
								MMR expects encoding to be UTF-8 or ASCII
								PHP can easily convert ISO-8859-1 so we do that if required.
								It is difficult to detect other encoding types so please make sure UTF-8 files are used.
							*/
							//apply_filters('hpp_script_content', 
							$scriptContent = $this->script_get($this->root . $script_path, $scripts->registered[$handle]->src) ;	//file_get_contents
							if(extension_loaded('mbstring') && mb_detect_encoding($scriptContent, 'UTF-8,ISO-8859-1', true) == 'ISO-8859-1')
							{
								$scriptContent = utf8_encode($scriptContent);
							}

							// Remove the UTF-8 BOM
							$contents .= preg_replace("/^\xEF\xBB\xBF/", '', $scriptContent) . $script_line_end;
							
							if(0&& !empty($scripts->registered[$handle]->extra['after']) && count($scripts->registered[$handle]->extra['after']) > 0)
							{
								$contents .= implode($script_line_end,$scripts->registered[$handle]->extra['after']) . $script_line_end;
							}
							
							if($ext == 'css')
							{ 
								//hoang
								$contents = $this->get_css_import(dirname($script_path), $contents);
								#$_url = strpos($script_path,'wp-includes')!==false || strpos($script_path,'wp-admin')!==false? home_url().dirname($script_path) : WP_CONTENT_URL.dirname($script_path);
								//convert relative paths to absolute & ignore data: or absolute paths (starts with /)
								$contents = preg_replace("/url\(\s*['\"]?(?!data:)(?!http)(?![\/'\"])(.+?)['\"]?\s*\)/i", "url('" . $this->fix_resource_url($this->home_url()./*hoang*/$this->fix_path_url(dirname($script_path))) . "/$1')", $contents);
							}
							
							$output[$i] .= hw_config_val('debug', 1, '/*['.$handle.']*/')."\n".$contents; 
							$this->scripts[$ext]['files'][$handle] = apply_filters('hpp_merge_file', $contents, $handle, $ext, $this->root . $script_path);
							/*if($ext != 'js' || !$this->jqueryInJS($contents)) $output[$i] .= $contents; //hoang
							else {
								if(!isset($GLOBALS['hw-jquery_js']))$GLOBALS['hw-jquery_js']='';
								$GLOBALS['hw-jquery_js'] .= $contents;
							}*/
							$log[$i] .= "\n";

							if( !hw_config('merge_'.$ext) ) {
								$att = array_filter(['id'=>$handle/*.'-'.$ext*/, 'deps'=>join(',',$scripts->registered[$handle]->deps)]);
								if($ext=='css') $att['media'] = !empty($handles[$i]['media'])? $handles[$i]['media']:'all';
								$this->_delay_asset($scripts->registered[$handle]->src, $ext, $att);
							}
						}
						//hoang
						/*if(isset($GLOBALS['hw-jquery_js'])) {
							$output[$i] = $GLOBALS['hw-jquery_js']."\n".$output[$i];
							unset($GLOBALS['hw-jquery_js']);
						}*/
						/*if($ext == 'js') {
							$output = $this->_delay_js($output, $file_path);	//$output = "setTimeout(function(){{$output}},100);";
						}
						if($ext == 'css') $output = $this->fixCSS($output);*/
						//end

						//remove existing expired files
						#array_map('unlink', glob(MMR_CACHE_DIR . '/' . $hash . '-*.' . $ext));	//hoang
					
						/*if($should_minify)
						{
							if($output ) {//hoang
								if($ext=='js' && !isset($this->_data['first_js_enqueue']) && !file_exists(MMR_CACHE_DIR.'/optimize.js')) 
									file_put_contents( MMR_CACHE_DIR.'/optimize.js' , $output);	
								if($ext=='css') file_put_contents( $full_path , $output);
							}
							if(count($handles[$i]['handles']) > 1)
							{
								file_put_contents($full_path . '.log', date('c') . " - MERGED:\n" . $log);
							}
							else
							{
								file_put_contents($full_path . '.log', date('c') . "\n" . $log);
							}
						}
						else
						{
							if($output && !file_exists(substr($full_path, 0, -2) . 'min.' . $ext))file_put_contents(substr($full_path, 0, -2) . 'min.' . $ext , $output);//hoang
							file_put_contents($full_path . '.log', date('c') . " - ORIGINAL FILE USED:\n" . $log);
							$min_exists = true;
						}*/
						if($ext=='css' && !empty($output[$i])) {
							/*if(!isset($output_css[$handles[$i]['media']]) ) $output_css[$handles[$i]['media']] = ['style'=>'','files'=>[]];
							$output_css[$handles[$i]['media']]['style'] .= $output[$i];
							$output_css[$handles[$i]['media']]['files'] = array_merge($output_css[$handles[$i]['media']]['files'], $handles[$i]['handles']);*/

							if(!isset($this->scripts['css']['style'][$handles[$i]['media']])) {
								$this->scripts['css']['style'][$handles[$i]['media']] = '';
							}
							$this->scripts['css']['style'][$handles[$i]['media']] .= $output[$i]."\n";	//hw_config_val('debug', '/*[x]*/', 1).
							#$this->scripts['css']['files'] = array_merge($this->scripts['css']['files'], $handles[$i]['handles']);
						}
					}
					else
					{
						#file_put_contents($full_path . '.accessed', current_time('timestamp'));
						#if(!empty($this->_data['first_js'])) $this->_delay_js($output, $file_path);
					}
	
	
					/*if($ext == 'js')
					{
						#$exist = !isset($this->_data['first_js_enqueue']);$this->_data['first_js_enqueue']=1;//file_exists($full_path);	//hoang
						$data = '';
						foreach( $handles[$i]['handles'] as $handle)
						{
							$js = '';
							if(isset($scripts->registered[$handle]->extra['before']) && count($scripts->registered[$handle]->extra['before']) > 0)
							{
								$js .= implode($script_line_end,$scripts->registered[$handle]->extra['before']) . $script_line_end;
							}
							if(isset($scripts->registered[$handle]->extra['data']))
							{
								$js .= $scripts->registered[$handle]->extra['data'];
							}
							if(!empty($scripts->registered[$handle]->extra['after']) && count($scripts->registered[$handle]->extra['after']) > 0)
							{
								$js .= implode($script_line_end,$scripts->registered[$handle]->extra['after']) . $script_line_end;
							}*/
							#if($js) $data .= hw_config_val('debug', 1, '/*['.$handle.']*/')."\n".apply_filters('hpp_inline_script_part',$js,$handle). $script_line_end;
						#}
						/*if($exist){//hoang
						if($min_exists)
						{
							$this->http2push_reseource(MMR_JS_CACHE_URL . $min_path, 'script');
							wp_register_script('js-' . $this->scriptcount, MMR_JS_CACHE_URL . $min_path, array(), false, $in_footer);
						}
						else
						{
							$this->http2push_reseource(MMR_JS_CACHE_URL . '/optimize.js', 'script');//hoang: MMR_JS_CACHE_URL . $file_path
							//hoang: MMR_JS_CACHE_URL . $file_path
							wp_register_script('js-' . $this->scriptcount, MMR_JS_CACHE_URL . '/optimize.js', array(), false, $in_footer);
						}}*/
	/*
						//set any existing data that was added with wp_localize_script
						if($data != '')
						{							
							$this->inline_scripts[$ext].= $data;
							#else $script_list->registered['js-' . $this->scriptcount]->extra['data'] = $data;
						}
				
						#if($exist){wp_enqueue_script('js-' . $this->scriptcount);}//hoang
					}
					else
					{
						/*if($min_exists)
						{
							$this->http2push_reseource(MMR_CSS_CACHE_URL . $min_path, 'style');
							wp_register_style('css-' . $this->scriptcount, MMR_CSS_CACHE_URL . $min_path, false, false, $handles[$i]['media']);
						}
						else
						{
							$this->http2push_reseource(MMR_CSS_CACHE_URL . $file_path, 'style');#var_dump(MMR_CSS_CACHE_URL . $file_path);
							wp_register_style('css-' . $this->scriptcount, MMR_CSS_CACHE_URL . $file_path, false, false, $handles[$i]['media']);
						}
						wp_enqueue_style('css-' . $this->scriptcount);
						$this->_delay_asset($file_path, 'css',['media'=>$handles[$i]['media'],'id'=>'css-' . $this->scriptcount]);	//hoang
						*/
					#}
					if(!empty($handles[$i]['handles'])) {
						$this->inline_scripts[$ext] = array_merge((array)$this->inline_scripts[$ext], $handles[$i]['handles']);	//inline style,script
					}
					$this->scriptcount++;
				
				}
				else //external
				{ 
					$att = ['id'=>$handles[$i]['handle']/*.'-'.$ext*/];
					$deps = $scripts->registered[$handles[$i]['handle']]->deps;
					if(!empty($deps)) $att['deps'] = join(",",$deps);
					$this->inline_scripts[$ext][] = $handles[$i]['handle'];

					if($ext == 'js')
					{
						wp_dequeue_script($handles[$i]['handle']); //need to do this so the order of scripts is retained
						#wp_enqueue_script($handles[$i]['handle']);
						$this->_delay_asset($scripts->registered[$handles[$i]['handle']]->src, 'js',$att);	//hoang
					}
					else
					{
						$att['media'] = !empty($handles[$i]['media'])? $handles[$i]['media']:'all';

						wp_dequeue_style($handles[$i]['handle']); //need to do this so the order of scripts is retained
						if(isset($_GET['hpp-gen-critical'])) wp_enqueue_style($handles[$i]['handle']);
						$this->_delay_asset($scripts->registered[$handles[$i]['handle']]->src, 'css',$att);	//hoang
					}
				}
			}
			//hoang			
			$output = array_filter($output);
			if($ext =='js' ) {
				/*if( !empty($output)) {
					$this->scripts['js']['files'] = array_merge($this->scripts['js']['files'], $page_handles);
					#$this->scripts['js']['script'] .= join("",$output)."\n";
				}*/

				if(!$in_footer) wp_enqueue_script('hpp-bootstrap' , MMR_JS_CACHE_URL . '/optimize.js',array(), false, false);
				/*sort($page_handles);
				$hash = hash('adler32', get_home_url() . implode('', $page_handles));
				$file_path = '/' . $hash . '.' . $ext;

				$first = $this->_delay_js(join("",$output), $file_path);
				if($first) wp_enqueue_script('hpp-bootstrap' , MMR_JS_CACHE_URL . '/optimize.js',array(), false, false);

				$log_f = MMR_CACHE_DIR . ($first? '/child-':'/site-'). $hash .'.'.$ext. '.log';
				if(hw_config('merge_js') && !file_exists($log_f)) file_put_contents($log_f, date('c') . " - MERGED:\n" . join("\n",$log));
				*/
			}
			/*if($ext=='css' && hw_config('merge_css')) {
				foreach($output_css as $media=> $it) {
					sort($it['files']);
					$hash = hash('adler32', get_home_url() . implode('',$it['files']));
					$file_path = '/' . $hash . '.' . $ext;
					if(!file_exists(MMR_CACHE_DIR . $file_path)) {
						$this->save_asset(MMR_CACHE_DIR . $file_path, $this->fixCSS($it['style']));
						file_put_contents(MMR_CACHE_DIR . '/'.$file_path . '.log', date('c') . " - MERGED:\n" . join("\n",$log));
					}
					//$att = ['media'=>$media,'id'=>'hpp-s-' . $hash ];
					//if(isset($this->_data['last_css'])) $att['deps'] = 'hpp-s-'.$this->_data['last_css'];
					
					wp_enqueue_style('hpp-s-' . $hash, MMR_CSS_CACHE_URL . $file_path, false, false, $media);	//fallback
					$this->_delay_asset(MMR_CSS_CACHE_URL.$file_path, 'css', ['media'=>$media,'id'=>'hpp-s-' . $hash ]);

					#$this->_data['last_css'] = $hash;
				}
			}*/
			if(hw_config('merge_'.$ext)) $this->scripts[$ext]['log'] = array_merge($this->scripts[$ext]['log'], $log);
			
			//end: note handle done will not be enqueue in wp_head, wp_footer:$ext=='js'? $wp_scripts->done:$wp_styles->done
			$script_list->done = array_unique(array_merge($script_list->done, $done));	//array_unique(array_merge($done, $this->_data['all_handles']));
			//hoang@deprecated: move to footer
			/*if(0&& $ext=='js' ) {
				if(!empty($this->_data['lazy_assets'])) {
					//replace with uncritical css
					if(isset($GLOBALS['hpp-uncritical'])) {
						foreach($this->_data['lazy_assets'] as $l=>$it)if($it['t']=='css')unset($this->_data['lazy_assets'][$l]);
					}
					if($in_footer) {
						$this->_data['lazy_assets']['hpp-2']=['t'=>'js','l'=>plugins_url('lib/asset/custom.js',__FILE__),'deps'=>$this->if_merge('hpp-1')];
						if(isset($GLOBALS['hpp-uncritical'])) $this->_data['lazy_assets']['uncritical'] = ['t'=>'css','media'=>'all','l'=>$GLOBALS['hpp-uncritical']];
					}
					$this->inline_scripts[$ext] .= '_HWIO.extra_assets=_HWIO.assign(_HWIO.extra_assets||{},'.json_encode($this->_data['lazy_assets']).');';
				}
				*/	
				//echo "<script>/* <![CDATA[ */\n{$this->inline_scripts[$ext]}\n/* ]]> */</script>";
				/*$this->inline_scripts[$ext]='';$this->_data['lazy_assets']=[];
			}*/
			//inline style
			if($ext=='css' && !empty($this->inline_scripts['css']) 
				//prevent duplicate in critical-css because when generate critical css it will copy from inline style
				#&& apply_filters('hpp_should_inline_style', hw_config('inline_css')?true: false) 
			) {
				$style = '';
				foreach($this->inline_scripts['css'] as $i=>$handle) {
					if(empty($handle) ) continue;
					$output = $this->get_inline_data( $handle, 'css' );
					if ( !empty( $output ) ) {
						$style .= hw_config_val('debug', 1, '/*['.$handle.']*/') ."\n".$output;//self::inline_text( $output );
						#printf( "<style id='%s-inline-css' type='text/css'>\n%s\n</style>\n", esc_attr( $handle ), $output );
					}
					//$this->inline_scripts['css'][$i]=[];
				}
				if($style) {
					try{$style = apply_filters('hpp_inline_style', $style);}catch(Exception $e){hpp_write_log($e->getMessage());}
					printf( "<style type='text/css'>\n%s\n</style>\n", $style );
				}
				$this->inline_scripts['css']=[];
			}
			//inline script > put to footer
		}
	}

	function get_inline_data($handle, $ext) {
		global $wp_scripts, $wp_styles;
		$scripts = $wp_scripts;
		$out = '';
		if($ext=='js') {
			$script_line_end = ";\n";
			if(isset($scripts->registered[$handle]->extra['before']) && count($scripts->registered[$handle]->extra['before']) > 0
				&& apply_filters('hpp_allow_inline_data', true, $handle, 'before'))
			{
				$out .= implode($script_line_end,$scripts->registered[$handle]->extra['before']) . $script_line_end;
			}
			if(isset($scripts->registered[$handle]->extra['data']) && apply_filters('hpp_allow_inline_data', true, $handle, 'data'))
			{
				$out .= $scripts->registered[$handle]->extra['data'];
			}
			if(!empty($scripts->registered[$handle]->extra['after']) && count($scripts->registered[$handle]->extra['after']) > 0
				&& apply_filters('hpp_allow_inline_data', true, $handle, 'after'))
			{
				$out .= implode($script_line_end,$scripts->registered[$handle]->extra['after']) . $script_line_end;
			}
			//if($js) $data .= hw_config_val('debug', 1, '/*['.$handle.']*/')."\n".apply_filters('hpp_inline_script_part',$out,$handle). $script_line_end;
		}
		if($ext=='css') {
			#$out = implode("\n", (array)$wp_styles->get_data( $handle, 'before' ));
			#$out .= implode("\n", (array)$wp_styles->get_data( $handle, 'data' ));
			$out .= implode("\n", (array)$wp_styles->get_data( $handle, 'after' ));
		}
		return $out;
	}
	function get_processed_scripts() {
		return $this->scripts;
	}
	function print_footer() {
		global $blog_id;
		if(!hpp_shouldLazy()) return;
		if(!file_exists(MMR_CACHE_DIR.'/optimize.js')) {
			$c = file_get_contents(file_exists(__DIR__.'/main.min.js')? __DIR__.'/main.min.js': __DIR__.'/main.js');
			file_put_contents(MMR_CACHE_DIR.'/optimize.js', $c);
		}
		if(hw_config('merge_css')) {
			$not_handles = array_diff($this->scripts['css']['q'][0],$this->scripts['css']['q'][1]);
			$handles = array_keys($this->scripts['css']['files']);
			if(count($not_handles)) $handles = array_filter($handles,function($v) use($not_handles){return $v!='' && !in_array($v,$not_handles);});
			//sort($handles);
			$hash = hash('adler32', $this->home_url() . implode('',call_user_func(function(array $a){sort($a);return $a;}, $handles)));
			if(is_multisite() && $blog_id > 1) $hash = $blog_id.'-'.$hash;
			$file_path = '/' . $hash . '.css';
			
			if(!file_exists(MMR_CACHE_DIR . $file_path)) {
				$output = '';
				foreach($this->scripts['css']['style'] as $media => $css) {
					if($media!='all') $output .= '@media '.$media."{\n".$css."\n}\n";
					else $output .= $css;
				}
				$this->save_asset(MMR_CACHE_DIR . $file_path, $this->fixCSS($output));
				file_put_contents(MMR_CACHE_DIR . '/'.$file_path . '.log', date('c') . " - MERGED:\n" . join("\n",$this->scripts['css']['log']));
			}
			if(!isset($GLOBALS['hpp-criticalfile'])) {
				//echo HPP_Lazy::defer_asset_html(,'css')
				printf('<link rel="stylesheet" id="%s" href="%s"/>','hpp-s-0' , MMR_CSS_CACHE_URL . $file_path);
			}
			$this->_delay_asset(MMR_CSS_CACHE_URL.$file_path, 'css',['id'=>'hpp-s-0' ]);// . $hash
		}
		if(hw_config('merge_js')) {
			$not_handles = array_diff($this->scripts['js']['q'][0],$this->scripts['js']['q'][1]);	//if wp_dequeue_script at footer
			$handles = array_keys($this->scripts['js']['files']);
			if(count($not_handles)) $handles = array_filter($handles, function($v) use($not_handles){return $v!='' && !in_array($v,$not_handles);});
			//$_handles = $this->fix_asset_deps($handles, 'js');	//before sort
			//sort($handles);	//no, will sort other libs
			$hash = hash('adler32', $this->home_url() . implode('', call_user_func(function(array $a){sort($a);return $a;}, $handles)));
			if(is_multisite() && $blog_id > 1) $hash = $blog_id.'-'.$hash;
			
			//$this->_delay_js( $this->scripts['js']['script'], $file_path);
			$this->_data['lazy_assets']['hpp-0']=['t'=>'js','l'=>MMR_JS_CACHE_URL.'/'.$hash.'.js'];	//$this->_delay_asset();

			if(!file_exists(MMR_CACHE_DIR."/{$hash}.js")) {	
				//get contents
				$handles = $this->fix_asset_deps($handles, 'js');$body='';
				foreach($handles as $handle) {
					$body.= hw_config_val('debug', 1, '/*['.$handle.']*/')."\n". $this->scripts['js']['files'][$handle];
				}

				$this->save_asset(MMR_CACHE_DIR."/{$hash}.js", hqp_fix_encoding( $this->fixOtherJS($body,1)));
				file_put_contents(MMR_CACHE_DIR .'/'. $hash .'.js.log', date('c') . " - MERGED:\n" . join("\n",$this->scripts['js']['log']));
			}
			
		}
		//inline script
		$script = '';
		if( !empty($this->inline_scripts['js'])) {			
			foreach($this->inline_scripts['js'] as $i=>$handle) {
				$js = $this->get_inline_data($handle, 'js');//$js = $this->fixOtherJS($js)
				if(!empty($js)) $script .= hw_config_val('debug', 1, '/*['.$handle.']*/') ."\n".apply_filters('hpp_inline_script_part', $js,$handle)."\n";
			}
		}
		if(!empty($this->_data['lazy_assets'])) {
			$this->_data['lazy_assets']['hpp-1']=['t'=>'js','l'=>plugins_url('lib/asset/custom.js',__FILE__),'deps'=>$this->if_merge('hpp-0')];
			if(isset($GLOBALS['hpp-uncritical'])) $this->_data['lazy_assets']['hpp_uncritical'] = ['t'=>'css','media'=>'all','l'=>$GLOBALS['hpp-uncritical']];
			
			$script .= '_HWIO.assets=_HWIO.assets||{};_HWIO.extra_assets=_HWIO.assign(_HWIO.assets,'.json_encode(apply_filters('hpp_lazy_assets',$this->_data['lazy_assets'])).');';
		}

		try{$script = apply_filters('hpp_inline_script', $this->fixOtherJS($script,1));}catch(Exception $e){$script = $this->fixOtherJS($script,1);hpp_write_log($e->getMessage());}
		//js:type='text/javascript'
		echo "<script id=\"hqjs\" type=\"".hpp_defer_attr('text/javascript')."\">/* <![CDATA[ */\n{$script}\n/* ]]> */</script>";
		$this->_data['lazy_assets']=[];
	}

	/**
	 *@param $list $scripts->to_do
	 *@param $ext js,css
	*/
	function fix_asset_deps( $list, $ext ) {
		global $wp_scripts;
	  $ignore=[];$track=[];
	  while(true) {
		$r=0;
		foreach($list as $i=> $s) {
		  if(in_array($s, $ignore)) continue;
		  $att = ['id'=> $s, 'deps'=>''];
		  if(!empty($wp_scripts->registered[$s]->deps)) $att['deps'] = join(',',$wp_scripts->registered[$s]->deps);
		  $att = apply_filters('hpp_delay_asset_att', $att, $ext);

		  if(!empty($att['deps'])) {
			$deps = array_unique(array_filter(explode(',',$att['deps'])));
			$max_i = $i;
			foreach($deps as $dep) {
				if(in_array($dep,['jquery','jquery-core']) && !isset($list[$dep])) $dep = $dep==='jquery'?'jquery-core':'jquery';
			  $n = array_search($dep, $list);
			  if($n !==false && $max_i < $n) $max_i=$n;
			  $track[$dep] = $s;
			}
			if($i < $max_i) {//echo $s.':'.$max_i.',';
			  #$list[$i] .= '|__REMOVE__';
			  $ignore[]=$s;$r=1;
			  break;#print_r($list);
			  #return fix($list, $ignore);
			}
		  }
		}
		if(!$r)break;
		else {
		  unset($list[$i]);
		  hpp_array_insert($list, [$s], $max_i);//+1
		  if(isset($track[$s])) {
	        hpp_array_insert($list, [$track[$s]], $max_i+1);
	      }
		}
	  }
	  return $list;
	}
	function script_path($url) {
		//$script_path = parse_url($this->ensure_scheme($url), PHP_URL_PATH);
		#$home = site_url();
		if(0&& strpos($url, $home)===0) $script_path = '/'.ltrim(str_replace($home, '', $url), '/');	//wrong
		else {
			$script_path = parse_url($this->ensure_scheme($url), PHP_URL_PATH);
			$script_path = $this->fix_wp_subfolder($script_path);
		}
		return $script_path;
	}
	function script_get($script_path, $url) {
		if(file_exists( $script_path)) {	//$this->root .
			return file_get_contents( $script_path);
		}
		else {
			$s = hpp_curl_get($url, array(), false, 200);
			hpp_write_log("not exist file {$url} -> {$script_path} -> ".substr($s, 0,50));
			return $s? $s: "/*[failed to fetch {$url}]*/";
		}
	}
	/**
	 * get_handles function.
	 * 	Returns a list of the handles in $ourList in the order and grouping that mmr will need to merge them
	 * @access private
	 * @param mixed $type - type of script to check 'css' or 'js'
	 * @param mixed &$ourList - copy of the global wp list
	 * @param bool $ignoreFooterScripts (default: false) - whether to ignore scripts marked for the footer
	 * @return array() - MMR script handles list
	 */
	private function get_handles($type, &$ourList, $ignoreFooterScripts = false)
	{
		switch($type)
		{
			case 'js':
				$ext = 'js';
				$dontMerge = !$this->mergejs;
				$srcFilter = 'script_loader_src';
				$checkMedia = false;
				$checkForCSSImports = false;
				break;
				
			case 'css':
				$ext = 'css';
				$dontMerge = !$this->mergecss;
				$srcFilter = 'style_loader_src';
				$checkMedia = true;
				$checkForCSSImports = $this->checkcssimports;
				break;
				
			default: 
				return array();
		}
		//hoang
		if(apply_filters('ignore_'.$srcFilter, false)) return;	
		//@deprecated
		/*if(0&& hw_config('fix_js_deps') && $type=='js' && !$ignoreFooterScripts){
			$todo_nodeps = $no_deps=[];
			foreach($ourList->registered as $k=> $it) {
				if(!in_array($k,$ourList->to_do)) continue;
				if(!count($it->deps)) $no_deps[$k]=$it;
				else {
					foreach($it->deps as $k1)if(isset($no_deps[$k1]))unset($no_deps[$k1]);
				}
			}
			foreach( $ourList->to_do as $i=>$k ){
				if(isset($no_deps[$k])) {
					unset($ourList->to_do[$i]);
					$todo_nodeps[]=$k;
				}
			}
			$ourList->to_do = array_merge($todo_nodeps , $ourList->to_do);
		}*/
		//&& has_filter('hpp_delay_asset_att')
		#if(hw_config('merge_'.$type) ) $ourList->to_do = $this->fix_asset_deps(array_values($ourList->to_do), $type, 0);
		if(!$ignoreFooterScripts && !empty($this->_data['head_handles'])) {
			foreach($this->_data['head_handles'] as $handle) if(!in_array($handle, $ourList->to_do)) $ourList->to_do[] = $handle;
			$this->_data['head_handles']=[];
		}
		#if(!isset($this->_data['all_handles'])) $this->_data['all_handles']=['js'=>[],'css'=>[]];
		//end
		#_print($ourList->to_do);
		$handles = array();
		$currentHandle = -1;
		foreach( $ourList->to_do as $handle )
		{
			//if($handle=='jquery')var_dump($ourList->registered[$handle]);
			#$this->_data['all_handles'][$ext][] = $handle;
			if(apply_filters( $srcFilter, $ourList->registered[$handle]->src, $handle) !== false && $this->host_match($ourList->registered[$handle]->src)) //is valid src
			{
				if( $ignoreFooterScripts)	//no, so merge into 1 file only
				{
					$is_footer = isset($ourList->registered[$handle]->extra['group']);
					if($is_footer)
					{
						if(!isset($this->_data['head_handles'])) $this->_data['head_handles']=[];
						$this->_data['head_handles'][] = $handle;
						//ignore this script, so go on to the next one
						continue;
					}
				}
				#$script_path = parse_url($this->ensure_scheme($ourList->registered[$handle]->src), PHP_URL_PATH);
				#$script_path = $this->fix_wp_subfolder($script_path);
				$script_path = $this->script_path($ourList->registered[$handle]->src);

				$extension = pathinfo($script_path, PATHINFO_EXTENSION);
#print_r('-->'.$this->host_match($ourList->registered[$handle]->src)." \n");
				//ie ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js
				if($type=='js' && in_array($handle,['jquery','jquery-core']) && !empty($ourList->registered[$handle]->src) && !$this->host_match($ourList->registered[$handle]->src)) {
					$this->ext2local($ourList, $handle, $script_path, $type);
				}

				if(
					#file_exists($this->root . $script_path) &&
					$extension == $ext && 
					$this->host_match($ourList->registered[$handle]->src) && //is a local script
					#$this->can_merge_handle($ourList, $handle) &&	//hoang
					!in_array($ourList->registered[$handle]->src, $this->ignore) && 
					!isset($ourList->registered[$handle]->extra["conditional"])
				) 
				{
					$mediaMatches = true;
					if($checkMedia)
					{
						$media = isset($ourList->registered[$handle]->args) ? $ourList->registered[$handle]->args : 'all';
						$mediaMatches = $currentHandle != -1 && isset($handles[$currentHandle]['media']) && $handles[$currentHandle]['media'] == $media;
					}
					
					$hasCSSImport = false;
					if($checkForCSSImports)
					{
						$contents = $this->script_get($this->root . $script_path, $ourList->registered[$handle]->src);	//file_get_contents
						$hasCSSImport = strpos($contents, '@import') !== false;
					}
					
					if($hasCSSImport || $dontMerge || $currentHandle == -1 || isset($handles[$currentHandle]['handle']) || !$mediaMatches)
					{
						if($checkMedia)
						{
							#array_push($handles, array('modified'=>0,'handles'=>array(),'media'=>$media));
							if(!isset($handles[$media])) $handles[$media] = array('modified'=>0,'handles'=>array(),'media'=>$media);	//hoang
							$currentHandle = $media;
						}
						else
						{
							#array_push($handles, array('modified'=>0,'handles'=>array()));
							if(!isset($handles['_other_'])) $handles['_other_'] = array('modified'=>0,'handles'=>array());	//hoang
							$currentHandle = '_other_';
						}
						#$currentHandle++;
					}

					$modified = 0;
					
					if(is_file($this->root . $script_path))
					{
						$modified = filemtime($this->root . $script_path);
					}

					array_push($handles[$currentHandle]['handles'], $handle);

					if($modified > $handles[$currentHandle]['modified'])
					{
						$handles[$currentHandle]['modified'] = $modified;
					}
				}
				elseif(!isset($ourList->registered[$handle]->extra["conditional"])) //external script or not able to be processed
				{
					#array_push($handles, array('handle'=>$handle));
					#$currentHandle++;
					$handles['ext-'.md5($handle)] = array('handle'=>$handle);	#hoang
				}
			}
			else {
				$this->_delay_asset($ourList->registered[$handle]->src, $type, array_filter(['id'=>$handle/*.'-'.$type*/, 'deps'=>join(',',$ourList->registered[$handle]->deps)]));
			}
		}
		#if($type=='js')_print($handles);
		return array_values($handles);	//hoang
		#return $handles;
	}
	
	private function compress_css($full_path)
	{	
		if(hw_config('merge_css') && is_file($full_path))
		{
			try {
				$min_path = str_replace('.css', '.min.css', $full_path);
				
				$this->refreshed = true;

				require_once('Minify/src/Minify.php');
				require_once('Minify/src/CSS.php');
				require_once('Minify/ConverterInterface.php');
				require_once('Minify/Converter.php');
				require_once('Minify/src/Exception.php');

				file_put_contents($full_path . '.log', date('c') . " - COMPRESSING CSS\n", FILE_APPEND);

				$file_size_before = filesize($full_path);

				$minifier = new MatthiasMullie\Minify\CSS($full_path);

				$minifier->minify($min_path);
				if($this->gzip_file($min_path))
				{
					file_put_contents($full_path . '.log', date('c') . ' - GZIPPED - ' . $min_path . ".gz\n", FILE_APPEND);
				}

				$file_size_after = filesize($min_path);

				file_put_contents($full_path . '.log', date('c') . " - COMPRESSION COMPLETE - " . $this->human_filesize($file_size_before-$file_size_after) . " saved\n", FILE_APPEND);
				//hoang
				$this->save_asset($full_path, $this->fixCSS(file_get_contents($min_path)));
				unlink($min_path);
			}
			catch(Exception $e)	{
				//echo $e->getMessage();
			}
		}
	}
	
	private function compress_js($full_path)
	{
		if(hw_config('merge_js') && is_file($full_path))
		{
			try {
				$min_path = str_replace('.js', '.min.js', $full_path);
				
				$this->refreshed = true;

				$file_size_before = filesize($full_path);

				if(
					function_exists('exec') &&
					exec('command -v java >/dev/null && echo "yes" || echo "no"') == 'yes' &&
					exec('java -version 2>&1', $jvoutput) &&
					preg_match("/version\ \"(1\.[7-9]{1}+|[7-9]|[0-9]{2,})/", $jvoutput[0]))
				{
					file_put_contents($full_path . '.log', date('c') . " - COMPRESSING JS WITH CLOSURE\n", FILE_APPEND);

					$cmd = 'java -jar \'' . WP_PLUGIN_DIR . '/wp2speed/closure-compiler.jar\' --warning_level QUIET --js \'' . $full_path . '\' --js_output_file \'' . $full_path . '.tmp\'';

					exec($cmd . ' 2>&1', $output);

					if(is_countable($output) && count($output) != 0)
					{
						ob_start();
						var_dump($output);
						$error=ob_get_contents();
						ob_end_clean();

						file_put_contents($full_path . '.log', date('c') . " - COMPRESSION FAILED\n" . $error, FILE_APPEND);
						unlink($full_path . '.tmp');
						return;
					}
					rename($full_path . '.tmp', $min_path);
				}
				else
				{
					require_once('Minify/src/Minify.php');
					require_once('Minify/src/JS.php');
					
					file_put_contents($full_path . '.log', date('c') . " - COMPRESSING WITH MINIFY (PHP exec not available or java not found)\n", FILE_APPEND);
					
					$minifier = new MatthiasMullie\Minify\JS($full_path);

					$minifier->minify($min_path);
				}
				
				if($this->gzip_file($min_path))
				{
					file_put_contents($full_path . '.log', date('c') . ' - GZIPPED - ' . $min_path . ".gz\n", FILE_APPEND);
				}
				$file_size_after = filesize($min_path);
				file_put_contents($full_path . '.log', date('c') . " - COMPRESSION COMPLETE - " . $this->human_filesize($file_size_before-$file_size_after) . " saved\n", FILE_APPEND);

				//hoang
				$this->save_asset($full_path, file_get_contents($min_path));
				unlink($min_path);
			}
			catch(Exception $e)	{
				//echo $e->getMessage();
			}
		}
	}
	
	public function minify_action()
	{
		if(!hw_config('minify_merge') || isset($_GET['hpp-gen-critical'])) return;

		if($this->cssmin)
		{
			foreach($this->get_files_to_minify('css') as $path)
			{
				$this->compress_css($path);
			}
		}
		if($this->jsmin)
		{
			foreach($this->get_files_to_minify('js') as $path)
			{
				$this->compress_js($path);
			}
		}
		if(function_exists('hpp_purge_cache')) hpp_purge_cache();	//hoang
	}
	
	private function get_files_to_minify($ext)
	{
		return array_filter(glob(MMR_CACHE_DIR . '/*.' . $ext), function($file) use ($ext)
		{
		    if(strpos($file, '.min.' . $ext))
		    {
			    return false;
		    }
			//return !file_exists(str_replace('.' . $ext, '.min.' . $ext, $file));
			return strpos(file_get_contents($file.'.log'),'COMPRESSION COMPLETE')===false;//hoang
		});
	}
	
	//thanks to http://php.net/manual/en/function.filesize.php#106569
	private function human_filesize($bytes, $decimals = 2)
	{
		$sz = 'BKMGTP';
		$factor = floor((strlen($bytes) - 1) / 3);
		return sprintf('%.' . $decimals . 'f', $bytes / pow(1024, $factor)) . @$sz[$factor];
	}
	
	//thanks to Marcus Svensson
	private function gzip_file($path)
	{
		$gzipped = false;
		if($this->gzip && function_exists('exec') && exec('command -V gzip >/dev/null && echo "yes" || echo "no"') == 'yes')
		{
			exec("gzip -9 < '" . $path . "' > '" . $path . ".gz'", $output, $return);
			if($return == 0) //gzip worked
			{
				$gzipped = true; 
			}
		}
		return $gzipped;
	}
	
	/* thanks to @lucasbustamante */
	public function refreshed()
	{
		// only fire action if css or js compression has occured
		if($this->refreshed === true)
		{ 
			do_action('wp2speed_done'); 
		} 
	}
	
	/*public function showUpgradeNotification($data, $response)
	{
		if(isset($data['upgrade_notice']))
		{
			echo '<br/><strong style="color: red;">' . strip_tags($data['upgrade_notice']) . '</strong>';
		}
	}*/
}

global $wp2speed;
$wp2speed = new WP2Speed();
#endif;
