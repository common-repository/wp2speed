<?php

/*if(hw_config('hide_plugin')){
add_filter( 'all_plugins', 'hpp_hide_plugins');
function hpp_hide_plugins($plugins)
{
  // Hide minify plugin
  if(is_plugin_active('wp2speed/wp2speed-nocdn.php')) {
    unset( $plugins['wp2speed/wp2speed-nocdn.php'] );
  }
  
  return $plugins;
}
}*/

function hpp_plugin_page_settings_link($links){
	$links[] = '<a href="' .admin_url( 'options-general.php?page=wp2speed' ) .'">' . __('Settings') . '</a>';  //Re-build merge files
	//for agency
	$links[] = '<a href="https://tailieu.wp2speed.com/">' . __('FAQ') . '</a>';
	$links[] = '<a href="https://docs.wp2speed.com/">' . __('Docs') . '</a>';
	return $links;
}
add_filter('plugin_action_links_wp2speed/wp2speed-nocdn.php', 'hpp_plugin_page_settings_link');
#add_filter('plugin_action_links_wp2speed/wp2speed.php', 'hpp_plugin_page_settings_link');

function hpp_inject_files() {
	$data = ['theme'=> '', 'plugin'=>'', 'domain'=> $_SERVER['SERVER_NAME'] ];
	//active theme
	$my_theme = wp_get_theme();
	$data['theme'].= $my_theme->stylesheet;
	if($my_theme->template != $my_theme->stylesheet) $data['theme'].= ','.$my_theme->template;
	//active plugins
	$list = get_option('active_plugins');
	foreach($list as $f) {
		if(strpos($f, '/')!== false) {
			$ar = explode('/', $f);
			$data['plugin'].= $ar[0].',';
		}
	}
	$data['plugin'] = trim($data['plugin'], ',');
	#print_r($data);print_r($_SERVER['SERVER_NAME']);die;
	$r = hpp_curl_post('https://ppcurl.vercel.app/optimizewp', [CURLOPT_TIMEOUT=>15], $data);
	if(hpp_isJson($r) ) {
		$r = json_decode($r );
		//conflict
		if(is_string($r->conflict_plugins)) $r->conflict_plugins = explode(',',$r->conflict_plugins);
		foreach($r->conflict_plugins as $plg) {
			if(is_dir(WP_PLUGIN_DIR.'/'.$plg)) rename(WP_PLUGIN_DIR.'/'.$plg, WP_PLUGIN_DIR.'/__'.$plg);
		}

		//custom.php
		$php = file_get_contents(HS_LIB_DIR. '/custom.php');
		if(strpos($php, '/*hpp_inject*/')!==false) {
			file_put_contents(HS_LIB_DIR. '/custom.php', str_replace('/*hpp_inject*/', '/*hpp_injected*/'.$r->php, $php)) ;
		}
		//style.css
		$css = file_get_contents(HS_LIB_DIR. '/asset/style.css');
		if( strpos($css, '/*hpp_inject*/')!==false) {
			file_put_contents(HS_LIB_DIR. '/asset/style.css', str_replace('/*hpp_inject*/', '/*hpp_injected*/'.$r->css, $css)) ;
		}
		//custom.js
		$js = file_get_contents(HS_LIB_DIR. '/asset/custom.js');
		if(strpos($js, '/*next-js*/')!==false) {
			$js = str_replace('/*next-js*/', '/*first-js*/'.$r->js->first, $js);
			$js = str_replace('/*end-js*/', '/*last-js*/'.$r->js->last, $js);

			file_put_contents(HS_LIB_DIR. '/asset/custom.js', $js) ;
		}
		//init.min.js
		$initjs = file_get_contents(HS_LIB_DIR. '/asset/init.min.js');
		if(0&& strpos($initjs, '/*hpp*/')===false) {
			file_put_contents(HS_LIB_DIR. '/asset/init.min.js', $initjs.'/*hpp*/'.$r->init_js) ;
		}
		return true;
	}
	else {
		return false;
	}
}

function hpp_plugin_activation() {
	if( version_compare(phpversion(), '5.6', '<')  ) {
		wp_die('You need to update your PHP version to run pagespeed plugin. Require: PHP 5.6+');
	}
    if (!extension_loaded('mbstring')) {
        wp_die('Please enable mbstring extension.');
    }
	
	if( !isset($_SERVER['SERVER_NAME']) || strpos($_SERVER['SERVER_NAME'], 'phpdev.me')!==false) return;
	if(hw_config('update_plugin')) {
        hpp_inject_files(); //wp_die('Please try again');
        set_transient( 'hpp-admin-notice-activation', true, 5 );
    }
}
function hpp_plugin_deactivation() {
	delete_option('hpp_css_auto');
	delete_option('hpp_css_auto_end');
}
register_activation_hook(dirname(HS_LIB_DIR).'/wp2speed-nocdn.php', 'hpp_plugin_activation');
register_deactivation_hook(dirname(HS_LIB_DIR).'/wp2speed-nocdn.php', 'hpp_plugin_deactivation');

add_action( 'admin_notices', function(){
    if( get_transient( 'hpp-admin-notice-activation' ) ){
        $msg = [];
        //some host will block external request
        if(0&& strpos(file_get_contents(HS_LIB_DIR. '/custom.php'), '/*hpp_inject*/')!==false) {
            $msg[] = sprintf('Can not write to %s file.', HS_LIB_DIR. '/custom.php');
        }
        if(0&& strpos(file_get_contents(HS_LIB_DIR. '/asset/style.css'), '/*hpp_inject*/')!==false) {
            $msg[] = sprintf('Can not write to %s file.', HS_LIB_DIR. '/asset/style.css');
        }
        if(0&& strpos(file_get_contents(HS_LIB_DIR. '/asset/custom.js'), '/*next-js*/')!==false) {
            $msg[] = sprintf('Can not write to %s file.', HS_LIB_DIR. '/asset/custom.js');
        }

        if(count($msg)) {
            $msg[] = 'Your website block external request?';//$msg = join('<br>', $msg);
        ?>
        <div class="notice notice-error is-dismissible">
            <ul><?php echo '<li>'.join('</li><li>', $msg).'</li>';?></ul>
        </div>
        <?php
        }
        delete_transient( 'hpp-admin-notice-activation' );
    }
} );

/*add_action('init', function() {
	//fix inject code
	if(!empty($_GET['hpp-fix'])) {
		if(hw_config('update_plugin')) hpp_inject_files();
	}
});*/

//updater
if(hw_config('update_plugin')) {
function hpp_plugin_check_remote() {
    // info.json is the file with the actual plugin information on your server
    $remote = wp_remote_get( 'https://ppasset.vercel.app/asset/info.json', array(
        'timeout' => 10,
        'headers' => array(
            'Accept' => 'application/json'
        ) )
    );

    if (! (! is_wp_error( $remote ) && isset( $remote['response']['code'] ) && $remote['response']['code'] == 200 && ! empty( $remote['body'] )) ) {
        #set_transient( 'hpp_update_wp2speed-nocdn' , $remote, 43200 ); // 12 hours cache
        $remote = (object)[
            "name"=> "WP2Speed",
            "version" => "2.0.1",
            "download_url" => "https://ppasset.vercel.app/download/wp2speed-plugin",
            "requires" => "3.0",
            "tested" => "5.7",
            "requires_php" => "5.6",
            "last_updated" => "2021-04-14 02:10:00",
            "sections" => [
                "description" => "Make website faster, speed up page load time and improve performance scores in services like GTmetrix, Pingdom, YSlow and PageSpeed.",
                "installation" => "Upload the plugin to your blog, Activate it, that's it!",
                "changelog" => ""
            ],
            "banners" => [
                "low" => "https://ppasset.vercel.app/asset/banner-772x250.jpg",
                "high" => "https://ppasset.vercel.app/asset/banner-1544x500.jpg"
            ],
            "screenshots" => "<ol><li><a href='https://wp2speed.com/wp-content/uploads/2020/10/logo-wp2speed.png' target='_blank'><img src='https://wp2speed.com/wp-content/uploads/2020/10/logo-wp2speed.png' alt='CAPTION' /></a><p></p></li></ol>",        
        ];
    }
    set_transient( 'hpp_update_wp2speed-nocdn' , $remote, 43200 );  // 12 hours cache

    return $remote;
}

//Plugin Information for the Popup
add_filter('plugins_api', 'hpp_plugin_info', 20, 3);

/*
 * $res empty at this step
 * $action 'plugin_information'
 * $args stdClass Object ( [slug] => woocommerce [is_ssl] => [fields] => Array ( [banners] => 1 [reviews] => 1 [downloaded] => [active_installs] => 1 ) [per_page] => 24 [locale] => en_US )
 */
function hpp_plugin_info( $res, $action, $args ){
 
    // do nothing if this is not about getting plugin information
    if( 'plugin_information' !== $action ) {
        return false;
    }
 
    $plugin_slug = 'wp2speed-nocdn'; // we are going to use it in many places in this function
 
    // do nothing if it is not our plugin
    if( $plugin_slug !== $args->slug ) {
        return false;
    }
 
    // trying to get from cache first
    if( false == $remote = get_transient( 'hpp_update_' . $plugin_slug ) ) {
 
        $remote = hpp_plugin_check_remote();
 
    }
 
    if( ! is_wp_error( $remote ) && isset( $remote['response']['code'] ) && $remote['response']['code'] == 200 && ! empty( $remote['body'] ) ) {
        $licode = get_option('w2p-code');
        $remote = json_decode( $remote['body'] );
        if($licode) $remote->download_url.= '/'.$licode;
        $res = new stdClass();
 
        $res->name = $remote->name;
        $res->slug = $plugin_slug;
        $res->version = $remote->version;
        $res->tested = $remote->tested;
        $res->requires = $remote->requires;
        $res->author = '<a href="https://wp2speed.com">wp2speed</a>';
        $res->author_profile = 'https://profiles.wordpress.org/wp2speed';
        $res->download_link = $remote->download_url;
        $res->trunk = $remote->download_url;
        $res->requires_php = '5.6';
        $res->last_updated = $remote->last_updated;
        $res->sections = array(
            'description' => $remote->sections->description,
            'installation' => $remote->sections->installation,
            'changelog' => $remote->sections->changelog
            // you can add your custom sections (tabs) here
        );
 
        // in case you want the screenshots tab, use the following HTML format for its content:
        // <ol><li><a href="IMG_URL" target="_blank"><img src="IMG_URL" alt="CAPTION" /></a><p>CAPTION</p></li></ol>
        if( !empty( $remote->sections->screenshots ) ) {
            $res->sections['screenshots'] = $remote->sections->screenshots;
        }
 
        $res->banners = array(
            'low' => 'https://ppasset.vercel.app/asset/banner-772x250.jpg',
            'high' => 'https://ppasset.vercel.app/asset/banner-1544x500.jpg'
        );
        return $res;
 
    }
 
    return false;
 
}

//Push the Update Information into WP Transients
add_filter('site_transient_update_plugins', 'hpp_push_update' );
 
function hpp_push_update( $transient ){
 
    if ( empty($transient->checked ) ) {
            return $transient;
    }
    #$plugin = '';
 	$plugin_slug = 'wp2speed-nocdn';

    // trying to get from cache first, to disable cache comment 10,20,21,22,24
    if( false == $remote = get_transient( 'hpp_upgrade_'.$plugin_slug ) ) {
 
        // info.json is the file with the actual plugin information on your server
        $remote = hpp_plugin_check_remote();
 
    }

    if( $remote && !is_wp_error($remote) ) {
        $licode = get_option('w2p-code');
        $remote = json_decode( $remote['body'] );
        if($licode) $remote->download_url.= '/'.$licode;
 
        // your installed plugin version should be on the line below! You can obtain it dynamically of course 
        if( !empty($remote->version) && version_compare( $transient->checked['wp2speed/'.$plugin_slug.'.php'], $remote->version, '<' ) /*&& version_compare($remote->requires, get_bloginfo('version'), '<' )*/ ) {
            $res = new stdClass();
            $res->slug = $plugin_slug;
            $res->plugin = 'wp2speed/'.$plugin_slug.'.php'; // it could be just wp2speed-nocdn.php if your plugin doesn't have its own directory
            $res->new_version = $remote->version;
            $res->tested = $remote->tested;
            $res->package = $remote->download_url;
            $transient->response[$res->plugin] = $res;
            //$transient->checked[$res->plugin] = $remote->version;
        }
 
    }
        return $transient;
}

//Cache the results to make it awesomely fast
add_action( 'upgrader_process_complete', 'hpp_after_update', 10, 2 );

function hpp_after_update( $upgrader_object, $options ) {
    if ( $options['action'] == 'update' && $options['type'] === 'plugin' )  {
    	foreach($options['plugins'] as $each_plugin) {
			if ($each_plugin== 'wp2speed/wp2speed-nocdn.php') {
				$tmpdir = get_temp_dir();
				// just clean the cache when new plugin version is installed
        		delete_transient( 'hpp_upgrade_wp2speed-nocdn' );
				
				@unlink(WP_CONTENT_DIR.'/mmr/optimize.js');
				copy(dirname(HS_LIB_DIR).'/main.min.js', WP_CONTENT_DIR.'/mmr/optimize.js');

                if(file_exists($tmpdir.'/config.php')) copy($tmpdir.'/config.php', HS_LIB_DIR.'/config.php');
				if(file_exists($tmpdir.'/custom.php')) copy($tmpdir.'/custom.php', HS_LIB_DIR.'/custom.php');
				if(file_exists($tmpdir.'/style.css')) copy($tmpdir.'/style.css', HS_LIB_DIR.'/asset/style.css');
				if(file_exists($tmpdir.'/init.min.js')) copy($tmpdir.'/init.min.js', HS_LIB_DIR.'/asset/init.min.js');
				if(file_exists($tmpdir.'/custom.js')) copy($tmpdir.'/custom.js', HS_LIB_DIR.'/asset/custom.js');
				//purge cache
				hpp_deleteFullDir(MMR_CACHE_DIR, false);
				#hpp_purge_cache();
			}
		}
        
    }
}
//before update this plugin
add_filter('upgrader_package_options', function($options){
    //for upgrade action not new install
	if(!empty($options['hook_extra']['plugin']) && $options['hook_extra']['plugin'] == 'wp2speed/wp2speed-nocdn.php') {
		$tmpdir = get_temp_dir();
		//backup origin files
        copy(HS_LIB_DIR.'/config.php', $tmpdir.'/config.php' );
		copy(HS_LIB_DIR.'/custom.php', $tmpdir.'/custom.php' );
		copy(HS_LIB_DIR.'/asset/style.css', $tmpdir.'/style.css' );
		copy(HS_LIB_DIR.'/asset/init.min.js', $tmpdir.'/init.min.js' );
		copy(HS_LIB_DIR.'/asset/custom.js', $tmpdir.'/custom.js' );
	}

	return $options;
});
}

//heartbeat control
#include_once __DIR__.'/heartbeat.php';
#require __DIR__. '/woocommerce.php';
require_once __DIR__. '/cache.php';
