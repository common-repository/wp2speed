<?php
/**
 *@class
*/
class HPP_Optimize {

  function __construct() {
    /*@deprecated
    add_filter('ignore_style_loader_src', array($this,'hpp_ignore_asset_loader_src'));
    add_filter('ignore_script_loader_src', array($this,'hpp_ignore_asset_loader_src'));*/

    add_action( 'init', array($this,'hw_disable_wp_emojicons' ));
    //also remove the DNS prefetch.
    add_filter( 'emoji_svg_url', '__return_false' );
    add_action('init', array($this,'hw_speed_stop_loading_wp_embed'));
    #if(hw_config('disable_comment')) add_action('init', array($this,'hw_comments_clean_header_hook'));
    add_action( 'pre_ping', array($this, 'hw_no_self_ping' ));

    #if(hw_config('hoangweb_brand')) add_action('wp_footer', array($this,'hw_wp_footer'));
    //add_filter('body_class', array($this, 'body_class'));

    /**
      REST
    */
    add_action( 'rest_api_init', array($this,'_hw_rest_api_init'));

    //add_action('init', array($this,'hw_reset_user'));
  }

  //disable emojicons
  function hw_disable_wp_emojicons() {

    // all actions related to emojis
    remove_action( 'admin_print_styles', 'print_emoji_styles' );
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );

    // filter to remove TinyMCE emojis
    add_filter( 'tiny_mce_plugins', array($this,'hw_disable_emojicons_tinymce') );
  }

  function hw_disable_emojicons_tinymce( $plugins ) {
    if ( is_array( $plugins ) ) {
      return array_diff( $plugins, array( 'wpemoji' ) );
    } else {
      return array();
    }
  }

  //Disable Embeds
  // Remove WP embed script. lot of people don’t use this feature
  function hw_speed_stop_loading_wp_embed() {
    if (!is_admin()) {
      wp_deregister_script('wp-embed');
    }
  }

  //Disable Self Pingbacks
  //run SQL: `UPDATE wp_posts SET ping_status="closed"`
  function hw_no_self_ping( &$links ) {
      $home = get_option( 'home' );
      foreach ( $links as $l => $link )
          if ( 0 === strpos( $link, $home ) )
              unset($links[$l]);
  }
  
  function rest_permission_check($data=null) {
    $token = 'hw837939@code140390';
    if(!$data) $data = $_GET;
    return isset($data['token']) && $data['token']==$token;
  }
  
  function _hw_rest_api_init() {
    register_rest_route( 'hw', '/verify', array(
      'methods' => 'GET',
      'callback' => array($this,'_is_working'),
      'permission_callback' => '__return_true'
    ) );
    
    //purge cache
    register_rest_route( 'hw', '/purge_cache', array(
      'methods' => 'GET',
      'callback' => array($this,'_hw_purge_cache'),
      'permission_callback' => array($this, 'rest_permission_check')
    ) );
    //optimize img
    register_rest_route( 'hw', '/find_attachment', array(
      'methods' => 'GET',
      'callback' => array($this,'_find_attachment'),
      'permission_callback' => array($this, 'rest_permission_check')
    ) );
    
    register_rest_route( 'hw', '/find_merge_asset', array(
      'methods' => 'GET',
      'callback' => array($this,'_find_merge_asset'),
      #'permission_callback' => array($this, 'rest_permission_check')
      'permission_callback' => '__return_true'
    ) );

    register_rest_route( 'hw', '/list_criticalcss', array(
      'methods' => 'GET',
      'callback' => array($this,'_list_criticalcss'),
      'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'hw', '/upload_criticalcss', array(
      'methods' => 'POST',
      'callback' => array($this,'_upload_criticalcss'),
      'permission_callback' => '__return_true'
    ) );

    register_rest_route( 'hw', '/unique_urls', array(
      'methods' => 'GET',
      'callback' => array($this,'_list_urls'),
      'permission_callback' => '__return_true'
    ) );

    register_rest_route( 'hw', '/list_components', array(
      'methods' => 'GET',
      'callback' => array($this,'_list_components'),
      'permission_callback' => '__return_true'
    ) );

    register_rest_route( 'hw', '/trial', array(
      'methods' => 'POST',
      'callback' => array($this,'_trial'),
      'permission_callback' => array($this, 'rest_permission_check')
    ) );

    remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
    add_filter( 'rest_pre_serve_request', array($this, 'rest_send_cors'));
    
  }
  
  function _is_working() {
    //disk_free_space ?
    return new WP_REST_Response( ['by'=>'hoangweb'], 200 );
  }

  function rest_send_cors($value) {
    header( 'Access-Control-Allow-Origin: *' );
    header( 'Access-Control-Allow-Methods: GET' );
    header( 'Access-Control-Allow-Credentials: true' );
    header( 'Access-Control-Expose-Headers: Link', false );
 
    return $value;
  }

  function _hw_purge_cache() {  
    ignore_user_abort(TRUE);
    header('Content-Type: application/json');
    if($this->rest_permission_check()) {
      hpp_purge_cache();
      return 'success';
    }
    return 'error';
  }

  /**
  SELECT distinct DATE_FORMAT(post_date, "%Y-%m-%d") as dates FROM `wp_posts` where post_type='attachment' order by dates ASC
  */
  function _find_attachment(WP_REST_Request $req ) {
    $date_start = $req->get_param( 'start' );
    $date_end = $req->get_param( 'end' );
    $range = $req->get_param( 'range' );
    $result = ['urls'=>[]];

    if($date_start=='auto') {
      global $wpdb;
      $row = $wpdb->get_row("SELECT min(post_date) as min_date, max(post_date) as max_date FROM `{$wpdb->posts}` where post_type='attachment'");
      $date_start = date('Y-m-d', strtotime($row->min_date));
      $result['min_date'] = $date_start;
      $result['max_date'] = date('Y-m-d', strtotime($row->max_date));
    }
    if($date_start=='today') $date_start = $date_end = date('Y-m-d');
    if(!$date_end && $range) $date_end = date('Y-m-d', strtotime("+{$range} days",strtotime($date_start)));

    if(!$date_start && !$date_end) {
      return new WP_Error( 'missing_param', 'Missing parameter', array( 'status' => 500 ) );
    }

    $args = array(
      'post_type'=>'attachment',
      'post_mime_type' => 'image/jpeg,image/gif,image/jpg,image/png',
      'post_status' => 'any',//inherit,publish
      'date_query' => array(
            array(
                //'after'     => $date_start,
                //'before'    => date('Y-m-d', strtotime('+0 day')),
                'inclusive' => true,
            ),
        ),
      'posts_per_page'=> -1
    );
    if($date_start) $args['date_query'][0]['after'] = $date_start;
    if($date_end) $args['date_query'][0]['before'] = $date_end;
    #print_r($args);
    $upload_dir = wp_upload_dir();

    $q = new WP_Query($args);
    while($q->have_posts()) {
      $q->the_post();
      $mt = wp_get_attachment_metadata(get_the_ID());
      $result['urls'][] = $upload_dir['baseurl'].'/'.$mt['file'];  //wp_get_attachment_url(get_the_ID());
      $path = dirname($mt['file']);

      foreach($mt['sizes'] as $k=> $it) {
        $result['urls'][] = $upload_dir['baseurl'].'/'.$path.'/'. $it['file'];
      }
    }
    $result['urls']= array_values(array_unique($result['urls']));
    $result['count'] = count($result['urls']);

    return new WP_REST_Response($result, 200 );
  }

  /**
   *@WP_Rest /hw/find_merge_asset
  */
  function _find_merge_asset(WP_REST_Request $req ) {
    $css_list = glob(MMR_CACHE_DIR . '/*.css');
    $js_list = glob(MMR_CACHE_DIR . '/*.js');
    $result = [];

    $result['css'] = array_filter(array_map(function($v){
      //$v_min = str_replace('.css', '.min.css',$v);
      //check every file if has newline. If no exclude it
      if(file_exists($v.'.bak') && filesize($v.'.bak') > filesize($v)) return '';
      #if(strpos(trim(file_get_contents($v)), "\n")===false) return '';
      return MMR_CACHE_URL.str_replace(MMR_CACHE_DIR, '', $v);
    }, $css_list));

    $result['js'] = array_filter(array_map(function($v){
      //check exist minify
      if(file_exists($v.'.bak') && filesize($v.'.bak') > filesize($v)) return '';
      //if(strpos(trim(file_get_contents($v)), "\n")===false) return '';
      return strpos($v,'optimize.js')===false? MMR_CACHE_URL.str_replace(MMR_CACHE_DIR, '', $v):'';
    }, $js_list));
    
    //print_r($result);
    return new WP_REST_Response($result, 200 ); //json_encode(
  }

  /**
   *@WP_Rest /hw/list_criticalcss
  */
  function _list_criticalcss(WP_REST_Request $req ) {
    $result = [];
    $upload_dir = wp_upload_dir();  //WP_CONTENT_DIR.'/uploads
    $css_files = is_dir($upload_dir['basedir'].'/critical-css')? glob($upload_dir['basedir'].'/critical-css/*.css'): [];
    $result['files'] = array_map(function($v) {
      return basename($v, '.css');
    }, $css_files);
    return new WP_REST_Response($result, 200 );
  }

  /**
   *@WP_Rest /hw/upload_criticalcss
  */
  function _upload_criticalcss(WP_REST_Request $req ) {
    //permission
    if(!$this->rest_permission_check($_REQUEST)) {
      return new WP_Error( 'not_permision', 'Not permission', array( 'status' => 500 ) );
    }
    if(empty($_FILES['file'])) {
      return new WP_Error( 'missing_file', 'Not found file', array( 'status' => 500 ) );
    }
    if($_FILES['file']['size'] > 50000000) {
      return new WP_Error( 'big_size', 'File is too big', array( 'status' => 500 ) );
    }
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

    // check's valid format
    if(!in_array($ext, ['css'])) {
      return new WP_Error( 'invalid', "file extension {$ext} not allow", array( 'status' => 500 ) );
    }
    $full = isset($_POST['full'])? (int)$_POST['full']: 0;
    $name = isset($_POST['name'])? $_POST['name']: basename($_FILES['file']['name'],'.css');
    $name0 = explode('-',$name);$md5 = str_replace('.mobile','',array_pop($name0));
    $name0=join('-',$name0);if(strpos($name, '.mobile')!==false) $name0.='.mobile';

    $json = [];
    //provide full critical name
    if(!(strlen($md5) == 32 && ctype_xdigit($md5))) {
      return new WP_Error( 'invalid', "wrong filename {$name}", array( 'status' => 500 ) );
    }
    $updir = (object)wp_get_upload_dir();
    $path = $updir->basedir.'/critical-css/';
    if(!is_dir($path)) @mkdir($path, 0755, true);
    //check exist
    /*if((!$full && strpos($name,'page-')===false && file_exists($path.$name0.'.css')) || file_exists($path.$name.'.css') ) {
      return new WP_Error( 'exist', "exist {$name} ", array( 'status' => 500 ) );
    }*/
    
    $save_path = $path . (!$full && strpos($name,'page-')===false? $name0: $name).'.css';
    
    if(move_uploaded_file($_FILES['file']['tmp_name'], $save_path)) {
      $json['error'] = 0;
      #$json['url'] = $updir->baseurl.'/critical-css/'.$name;
      if(/*!$full && strpos($name, 'page-')!==false &&*/strpos($save_path, $name)!==false && !file_exists($path.$name0.'.css')) {
        copy($save_path, $path.$name0.'.css');
      }
      #if(!$full && strpos($name,'page-')===false) unlink($save_path);
    } else{
      $json['error'] = 1;
    }
    return new WP_REST_Response($json, 200 );

  }

  /**
   *@WP_Rest /hw/unique_urls
  */
  function _list_urls(WP_REST_Request $req ) {
    global $wpdb;

    $urls = [home_url()];
    //post type
    $rows = $wpdb->get_results("SELECT ID,post_type FROM {$wpdb->posts} where post_type not in ('attachment','product_variation','nav_menu_item','wpcf7_contact_form','customize_changeset') group by post_type order by ID ASC");

    foreach($rows as $row) {
      $l= get_permalink($row->ID);  //get_post_permalink,get_the_permalink
      if(is_string($l) && $l) $urls[] = $l;
      #print_r($row->post_type.': '.$l);

      //archive link
      $l = get_post_type_archive_link($row->post_type);
      if(is_string($l) && $l) $urls[] = $l;
    }
    //taxonomy
    $rows = $wpdb->get_results("SELECT tx.taxonomy, tr.object_id,t.slug FROM `{$wpdb->terms}` t left join `wp_term_taxonomy` tx on t.term_id = tx.term_id left join `wp_term_relationships` tr on tr.term_taxonomy_id=tx.term_taxonomy_id where tx.taxonomy not in ('nav_menu','product_type','product_visibility') and tx.taxonomy not like 'pa_%' and tr.object_id is not null group by tx.taxonomy");

    foreach($rows as $row) {
      if($row->object_id) {
        $t = get_term_by('slug', $row->slug, $row->taxonomy);
        if(!$t) { continue;}
        $l = get_term_link($t, $row->taxonomy);
        if(is_string($l) && $l) $urls[] = $l;
        #else print_r($t);
      }
    }

    $urls = array_unique($urls);
    return new WP_REST_Response(array_values($urls), 200 );
  }

  /**
   *@WP_Rest /hw/list_components
  */
  function _list_components(WP_REST_Request $req ) {
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
    ;
    return new WP_REST_Response($data, 200 );
  }

  public function _trial(WP_REST_Request $req ) {
    $time = $req->get_param('time');
    $time = isset($_POST['time'])? $_POST['time']: '';
    if(is_numeric($time)) {
      update_option('hpp_trial', $time);
      hpp_purge_cache();
    }
    return new WP_REST_Response(['status'=>0, 'value'=>$time], 200 );
  }
}

if(isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-json/hw/optiz')!==false ) {
  global $wp_filter;  
  foreach($wp_filter['init']->callbacks as $i=>$list){
    foreach($list as $k=>$it) if($k!='rest_api_init') unset($wp_filter['init']->callbacks[$i][$k]);
  }
  #remove_all_actions('init');
  add_filter('pre_handle_404', '__return_true');
}

if(!is_admin()) new HPP_Optimize;