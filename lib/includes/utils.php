<?php

function _get_global($key, $defVal='') {
	if(!isset($GLOBALS['hw_data'])) $GLOBALS['hw_data'] = array();
	if(isset($GLOBALS['hw_data'][$key])) return $GLOBALS['hw_data'][$key];
	return $defVal;
}

function _set_global($key, $val) {
	if(!isset($GLOBALS['hw_data'])) $GLOBALS['hw_data'] = array();
	$GLOBALS['hw_data'][$key] = $val;
}

function hw_config($key, $defVal='') {
	static $data;
	if(!$data) $data = include HS_LIB_DIR. '/config.php';
	if(isset($data[$key])) $defVal = $data[$key];
	return apply_filters('hpp_config', $defVal, $key);
}
function hw_config_val($key, $cmp, $val1, $val2='') {
  return hw_config($key) == $cmp? $val1: $val2;
}
function hpp_is_init($key) {
    $v = _get_global('init-'.$key, false);
    if(!$v) _set_global('init-'.$key, true);
    return $v;
}

function hpp_serialize($arr) {
    return base64_encode(serialize($arr));
}
function hpp_unserialize($arr) {
    return unserialize(call_user_func('base'.'64_decode', $arr));
}

function hpp_array_exclude_keys($arr, $keys) {
  foreach($keys as $k) if(isset($arr[$k])) unset($arr[$k]);
  return $arr;
}

function hpp_array_keep_keys($arr, $keys) {
  $dt = [];
  foreach($keys as $k) if(isset($arr[$k])) $dt[$k] = $arr[$k];
  return $dt;
}

function hpp_isJson($string) {
  if(!is_string($string)) return is_array($string);
  $string = trim($string);
  if(strpos($string,'"')!==false && (hpp_startsWith($string, '{') || hpp_startsWith($string, '[')) && (hpp_endsWith($string,'}') || hpp_endsWith($string,']'))) {
    @json_decode($string);
    return (json_last_error() == JSON_ERROR_NONE);
  }
  return false;
}
function hpp_find_duplicates($args) {
    $raw = array();
    $i = 1;
    foreach($args as $arg) {
        if(is_array($arg)) {
            foreach($arg as $value) {
                $raw[$value][] = "array $i";
            }
            $i++;
        }
    }

    $out = array();
    foreach($raw as $key => $value) {
        if(count($value)>1)
            $out[$key] = $value;
    }
    return $out;
}

function hpp_startsWith ($string, $startString) 
{ 
    $len = strlen($startString); 
    return (substr($string, 0, $len) === $startString); 
}
function hpp_endsWith($string, $endString) 
{ 
    $len = strlen($endString); 
    if ($len == 0) { 
        return true; 
    } 
    return (substr($string, -$len) === $endString); 
}
if(!function_exists('_print')):
function _print($s, $att=''){
	printf ('<textarea %s>', $att);
	print_r($s);
	echo '</textarea>'.PHP_EOL;
}
endif;

if(!function_exists('hpp_scan_files')):
function hpp_scan_files($root, $callback=null){
  if(!file_exists($root)) return ;//call_user_func_array($callback, ['','']);
  try{
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
        RecursiveIteratorIterator::CATCH_GET_CHILD // Ignore "Permission denied"
    );
    $files=array();
    //$paths = array($root);

    foreach ($iter as $path => $dir) {
      if(is_callable($callback)) call_user_func_array($callback, array($path, $dir));
    }
  }
  catch(Exception $e){}   
}
endif;

if(!function_exists('hpp_chmod_uploads')):
function hpp_chmod_uploads($path, $dir) {
	if ($dir? $dir->isDir(): is_dir($path)) {
        chmod($path, 0755);
    }
}
endif;

if(!function_exists('hpp_chmod_755_644')):
function hpp_chmod_755_644($path, $dir) {
	if ($dir? $dir->isDir(): is_dir($path)) {
        chmod($path, 0755);
    }
    else chmod($path, 0644);
}
endif;

if(!function_exists('hpp_chmod_555_444')):
function hpp_chmod_555_444($path, $dir) {
	if ($dir? $dir->isDir(): is_dir($path)) {
        chmod($path, 0555);
    }
    else chmod($path, 0444);
}
endif;

if(!function_exists('hpp_deleteFullDir')):
function hpp_deleteFullDir($dir, $self=true) {
  if(!is_dir($dir)) return;
  try {
    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it,
                 RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) {
        if ($file->isDir()){
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    if($self) rmdir($dir);
  }
  catch(\Exception $e){}
}
endif;

//copy the entire contents of the directory to another location
if(!function_exists('hpp_recurse_copy')):
function hpp_recurse_copy($src,$dst) { 
  if(!is_dir($src)) {echo "[not-found-path: {$src}]";return ;}
    $dir = opendir($src); 
    @mkdir($dst); 
    while(false !== ( $file = readdir($dir)) ) { 
        if (( $file != '.' ) && ( $file != '..' )) { 
            if ( is_dir($src . '/' . $file) ) { 
                hpp_recurse_copy($src . '/' . $file,$dst . '/' . $file); 
            } 
            else { 
                copy($src . '/' . $file,$dst . '/' . $file); 
            } 
        } 
    } 
    closedir($dir); 
}
endif;

function hpp_var($id, $val='') {
  if($id=='script-type') return ['application/ld+json','application/json','text/template','text/x-template','text/html'];
  if($id=='heavy-js') return ['jQuery(','$(','jQuery.' ,'$.', 'document','dispatchEvent','= new '];//'(function',
  return $val;
}

function hpp_server_home() {
  // Cannot use $_SERVER superglobal since that's empty during UnitUnishTestCase
  // getenv('HOME') isn't set on Windows and generates a Notice.
  $home = getenv('HOME');
  if (!empty($home)) {
    // home should never end with a trailing slash.
    $home = rtrim($home, '/');
  }
  elseif (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
    // home on windows
    $home = $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
    // If HOMEPATH is a root directory the path can end with a slash. Make sure
    // that doesn't happen.
    $home = rtrim($home, '\\/');
  }
  return empty($home) ? dirname($_SERVER['DOCUMENT_ROOT']) : $home;
}
//some hosting not support
function hpp_image2base64($url) {
  if(!$url) return '';
  $host = parse_url(WP_CONTENT_URL,PHP_URL_HOST);//if(strpos($url, '//')===0) $url = $schema.':'.$url;
  if(strpos( $url, $host)===false) return $url;
  $ar=explode($host, WP_CONTENT_URL);$ar1=explode($host, $url);  
  $file = WP_CONTENT_DIR. str_ireplace($ar[1],'', $ar1[1]);
  if(!file_exists($file) || filesize($file) >= 41921) return $url; //1*1000000

  $is = getimagesize($file);
  $img = file_get_contents($file);
  // Encode the image string data into base64 
  $data = base64_encode($img);
  return 'data:'.$is['mime'].';base64,'.$data;
}

function hpp_defer_img_b64($html, $bg='' ){
  if(!hpp_shouldlazyload() || apply_filters('hpp_disallow_lazyload', false, $html)) return $html;
  $html1 = html_entity_decode($html);
    preg_match('#<img(.*?)>#si', $html1, $m);$img0=$img=$m[0];
    if(strpos($html1, ' data-src=')!==false) {
      #$img = str_replace(' src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="','', $img);
      $img = str_replace(' src=',' data-holder-src=', $img);
      $img = str_replace(' data-src=', ' src=', $img);
    }
    $img = str_replace(' loading="lazy"', '', $img);
    $img = str_replace(hw_config('lazy_class'), '', $img);
    $img = preg_replace('# (data-srcset|data-sizes)=(.+?)(\'|")#', '', $img);
    preg_match('#(src)=("|\')(.+?)("|\')#', $img, $m);// |'.md5('hw-attr-src').'
    if($bg) return '<div class="'.$bg.'" style="background:url("'.hpp_image2base64($m[3]).'") no-repeat;"></div>';  //$bg=bg-logo
#print_r($m);die;
    $src = hpp_image2base64($m[3]);
    if($src!=$m[3]) {
      $img = str_replace($m[0], 'src='.$m[2]. $src.$m[2], $img);
      return str_replace($img0, $img, $html1);
    }
    return $html;
}

function hpp_get_embed_video_url($str) {
  $r = array();
  $size = hw_config('yt_thumb_size');
  //youtube
  if(strpos($str, 'youtube.com/watch?v=')!==false) {
    preg_match('#youtube.com\/watch\?v=(.+?)("|\')#', $str, $m);
    $m=explode('&',$m[1]);$id = $m[0];
    $r['url'] = 'https://www.youtube.com/embed/'.$id;
    $s = hpp_curl_get($r['url']);
    /*preg_match('#ytimg.com\/(\w+)\/#', $s, $m);
    $lang = isset($m[1])? $m[1]: 'en';
    $r['thumb'] = 'https://img.youtube.com/'.$lang.'/'.$id.'/'.$size.'.jpg'; //hqdefault|sddefault|maxresdefault*/
    preg_match_all('#(https:\/\/(.*?).ytimg.com/(.*?))\"#', $s, $m);
    $r['thumb'] = trim($m[1][count($m[1])-1],'\\');
  }
  elseif(strpos($str, 'youtube.com/embed/')!==false) {
    preg_match('#youtube.com/embed/(.+?)("|\')#', $str, $m);
    $m1=explode('?',$m[1]);$id = $m1[0];$lang='en';
    if($id=='videoseries') {
      $s = hpp_curl_get('https://www.youtube.com/embed/'.$m[1]);
      preg_match_all('#"VIDEO_ID":"(.*?)"|ytimg.com\/(\w+?)\/#', $s, $m); //VIDEO_ID(((?!").)*)"(.*?)"
      if(!empty($m[1][1])) $id = $m[1][1];
      if(!empty($m[2][0])) $lang = $m[2][0];      
    }
    else {
      $s = hpp_curl_get('https://www.youtube.com/embed/'.$id);
    }
    $r['url'] = 'https://www.youtube.com/embed/'.$id;
    preg_match_all('#(https:\/\/(.*?).ytimg.com/(.*?))\"#', $s, $m);
    $r['thumb'] = trim($m[1][count($m[1])-1],'\\');
    #$r['thumb'] = 'https://img.youtube.com/'.$lang.'/'.$id.'/'.$size.'.jpg';//maxresdefault
  }
  if(isset($r['thumb']) && strpos($r['thumb'],'"')!==false) {
    $tmp = explode('"',$r['thumb']);$r['thumb']=$tmp[0];
  }
  return $r;
}

function hpp_strip_comment($str, $type='css') {
  if($type=='css') $str = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!' , '' , $str );
  if($type=='html') {
    $str = preg_replace_callback('/<!--.*?-->/s', function ($matches) {
        return '';
    }, $str);
  }
  if($type=='js') {
    $pattern = '/(?:(?:\/\*(?:[^*]|(?:\*+[^*\/]))*\*+\/)|(?:(?<!\:|\\\|\')\/\/.*))/';
    $str = preg_replace($pattern, '', $str);
  }
  if($type=='php') {
    ;
  }
  return $str;
}
if(!function_exists('hpp_shouldlazy')):
function hpp_shouldlazy() {
  static $val=[];static $trial = null;
  if(!isset($_GET['nooptizpp']) ) {
    $optiz=1;
    if($trial==null) $trial = (float)hw_config('trial', get_option('hpp_trial'));
    if($trial && ($trial <= strtotime('-1 days') /*|| time()-$trial<=0*/)) $optiz=0;
    else if( !empty($GLOBALS['hpp-criticalfile']) && !file_exists($GLOBALS['hpp-criticalfile']) && hpp_gen_critical_context(false,true) ) $optiz=0;
    else if(function_exists('wp_doing_ajax')? wp_doing_ajax() : defined('DOING_AJAX') && DOING_AJAX) $optiz=0;
    if(!$optiz) {
      $_GET['nooptizpp']=1;$val=[false,false];#die;
    }
  }
  if(count($val)<2 && function_exists('is_user_logged_in')) $val[count($val)] = !is_user_logged_in() 
    && (!isset($GLOBALS['pagenow']) || !in_array($GLOBALS['pagenow'], array('wp-login.php', 'wp-register.php')) ) 
    && empty($_GET['nooptizpp']) //&& hw_config('lazyload')
    && !hpp_is_amp();

  return apply_filters('hpp_should_lazy', in_array(true, $val));
}
endif;
function hpp_shouldlazyload() {
  return hpp_shouldlazy() && hw_config('lazyload') ;//&& hpp_gen_critical_context(false, true);
}
function hpp_gen_critical_context($val='', $def='') {
  return isset($_GET['hpp-gen-critical'])? $val: $def;
}
//@deprecated
function load_dynamic_by_js($name, $func ) {//$toFile = 0
  if(!isset($GLOBALS['hppjs'])) $GLOBALS['hppjs'] = array();
  if(is_callable($func)) {
    ob_start();$out = call_user_func_array($func, []);$out .= ob_get_clean();
  }
  else $out = $func;
  if(!hw_config('dynamic_content')) return $out;
  $id = hash('adler32', hpp_current_url().$name);#substr(base_convert(md5($name), 16,32), 0, 12);'hpp'.

  /*if($toFile && !file_exists(WP_CONTENT_DIR.'/uploads/pages/'.$id.'.json')) {
    if(!is_dir(WP_CONTENT_DIR.'/uploads/pages')) @mkdir(WP_CONTENT_DIR.'/uploads/pages', 0755, true);
    file_put_contents(WP_CONTENT_DIR.'/uploads/pages/'.$id.'.json', json_encode(['text'=>$out]));    
  }*/
  /*elseif(!$toFile)*/ $GLOBALS['hppjs'][$id] = [ 'text'=>trim(preg_replace('/\s+/', ' ', $out))];//$toFile? ['id'=>$id ] : 
  //if($toFile) $GLOBALS['hppjs-ajax']=1;

  return sprintf('<div data-id="%s"></div>', $id);
}

function hpp_criticalcss_extract_fonts($css) {
  $fonts = [];
  if(strpos($css, '@font-face')!==false) {
    preg_match_all('#@font-face(\s+)?\{(.*?)\}#si', $css, $m);
    
    foreach($m[2] as $str) {
      preg_match_all('#url(\s+)?\((.*?)\)#', $str, $m1); 
      foreach($m1[2] as &$url) $url = hpp_attr_value(preg_replace('#\#.+#', '',trim($url)));
      $fonts = array_merge($fonts, $m1[2]);
    }
    $fonts = array_unique($fonts);
  }
    
  return $fonts;
}
function hpp_save_criticalcss( $out, $name, $name0='', $path='') {
  if(!$name0) {
    $name0 = explode('-',$name);array_pop($name0);$name0=join('-',$name0);
  }
  if(empty($out)) return false;
  $upload_dir = wp_upload_dir();
  //save css
  if(!is_dir($upload_dir['basedir'].'/critical-css')) mkdir($upload_dir['basedir'].'/critical-css', 0755);
  if(strpos($name, 'page-')!==false) file_put_contents($upload_dir['basedir'].'/critical-css/'.$name.'.css', $out);  //full file
  if(!file_exists($upload_dir['basedir'].'/critical-css/'.$name0.'.css')) {//$name = $name0;
    file_put_contents($upload_dir['basedir'].'/critical-css/'.$name0.'.css', $out);
  }
  //result.json
  if($path) {
    $list = file_exists($upload_dir['basedir'].'/critical-css/result.json')? file_get_contents($upload_dir['basedir'].'/critical-css/result.json'):'{}';
    $list = @json_decode($list,1);if(!is_array($list))$list=[];
    if( !isset($list[$path]) ) {
      $list[$path] = $name;
      file_put_contents($upload_dir['basedir'].'/critical-css/result.json', json_encode($list, JSON_PRETTY_PRINT));
    }
  }
  return true;
}

//HPP_Cache::flush_cache($id)
function hpp_purge_cache() {
  static $done = false;if ($done) return;$done = true;
  if(!headers_sent()) {
    header('x-HTML-Edge-Cache: purgeall');
  }
  $url = apply_filters( 'hpp_home_url', home_url());
  //my cache
  /*foreach((array)glob( WP_CONTENT_DIR.'/uploads/hp-*.js' ) as $f) @unlink($f);
  if(is_dir(WP_CONTENT_DIR.'/uploads/pages')) {
    foreach(array_diff(scandir(WP_CONTENT_DIR.'/uploads/pages'), array('..', '.')) as $f) @unlink(WP_CONTENT_DIR.'/uploads/pages/'.$f);
  }*/
  //purge all transients
  global $wpdb;
  if((int)hw_config('purge_transient')) {
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name like '_transient_%'"); //don't update, get_transient not return null
    #$wpdb->query("UPDATE {$wpdb->options} SET option_value=NULL WHERE option_name like '_site_transient_%'");
  }
  //cache files
  if(is_dir(WP_CONTENT_DIR.'/cache')) {//foreach(glob( WP_CONTENT_DIR.'/cache/*' , GLOB_ONLYDIR) as $dir) 
    hpp_deleteFullDir(WP_CONTENT_DIR.'/cache', false);
  }
  //opcache
  if(function_exists('opcache_reset')) opcache_reset();
  
  //litespeed
  $homedir = hpp_server_home();
  $uris = explode(DIRECTORY_SEPARATOR, $homedir);
  while(count($uris)) {   
    $homedir = join(DIRECTORY_SEPARATOR,$uris );
    if(is_dir($homedir.'/.lscache') ) {
      hpp_deleteFullDir($homedir.'/.lscache', false);
    }
    if(is_dir($homedir.'/lscache') ) {
      hpp_deleteFullDir($homedir.'/lscache', false);
    }
    array_pop($uris);
  }
  //varnish
  $p = wp_parse_url($url);
  wp_remote_request($url, array(
    array(
      'method'  => 'CLEANFULLCACHE',
      'headers' => array(
        'host'=> $p['host'].(isset($p['port'])? ':'.$p['port']:''),
      )
    )
  ));

  do_action(__FUNCTION__);
}
//array $dt=[]
function hpp_treat_tag($code, &$dt=array(), $tags=[]) {
  if(!in_array('noscript',$tags)) $tags[] = 'noscript';
    if(!empty($dt) ) {
      //php: since never found php tag in render html
      $code=str_replace('[hw_php]', '<?php', $code);
      $code=str_replace('[/hw_php]', '?>', $code);
      
      //resume noscript
      foreach($tags as $tag) {
        if(!empty($dt[$tag]))foreach($dt[$tag] as $k=>$v) $code = str_replace($k, $v, $code);
      }
      //ie tag
      if(!empty($dt['ie']))foreach($dt['ie'] as $k=>$v) $code = str_replace($k, $v, $code);

      unset($dt);
    }
    else {
      //php tag   $dt = array();
      $code=str_replace('<?php','[hw_php]', $code);
      $code=str_replace('?>','[/hw_php]', $code);
      
      //find noscript tag. /s for multi-line
      foreach($tags as $tag)
      if(stripos($code, '<'.$tag.'')!==false) {
        preg_match_all('#<'.$tag.'(((?!>).)*)?>(.*?)<\/'.$tag.'>#si',$code, $m);$dt[$tag] = [];
        foreach($m[0] as $i=>$s) {
          $dt[$tag]["[{$tag}_{$i}]"] = $s;
          $code = str_replace($s, "[{$tag}_{$i}]", $code);
        }
      }
        
      //ie condition
      if(strpos($code, '[endif]')!==false) {
        preg_match_all('#<\!--\[if .*?\].*?\[endif\]-->#si', $code, $m);$dt['ie'] = [];
        foreach($m[0] as $i=>$s) {
          $dt['ie']["[[ie_{$i}]]"] = $s;
          $code = str_replace($s, "[[ie_{$i}]]", $code);
        }
      }
      
      #$dt['noscripts'] = $noscripts;
      $dt['code'] = $code;
    }
        
    return $code;
}

function hpp_lazy_video($str, $mt=2, $fallback=1) {
  if(!hpp_shouldlazyload() || stripos($str, '<iframe')===false || !apply_filters('hpp_allow_lazy_video', 1,$str)) {
    return $str;
  }
  #$str = html_entity_decode($str);
  preg_match_all('#<iframe(((?!>).)*)(\s+?)(src|data-src)=.*?>(.*?)<\/iframe>#si', $str, $m); #_print($m);die; //<iframe(.*?)>(.*)?<\/iframe>|(((?!hqp'.rand().').)*?)>(((?!>).)*)
  $class = hw_config('lazy_class');
  foreach($m[0] as $txt) {
    //way 2
    if($mt==2) {
      $v = hpp_get_embed_video_url( $txt);
        if(!empty($v)) {
          $at = hpp_getAttr($txt, ['width'=>'0', 'height'=>'0']);
          //&autoplay=1&enablejsapi=1
          $replace = '<div class="yt-video-place embed-responsive embed-responsive-4by3" data-yt-url="'.$v['url'].'?rel=0&showinfo=0"><img src="'.hpp_b64holder('"',$at['width'], $at['height']).'" data-src="'.$v['thumb'].'" async class=" '.$class.' play-yt-video"><a class="start-video"><img width="64" src="'.hpp_b64holder('"', 64, 64).'" data-src="'.HS_PLUGIN_URL.'/lib/asset/play-btn.png" class=" '.$class.' " ></a></div>';
          $str = str_ireplace($txt, $replace, $str);
        }
        elseif($fallback) $mt=1;
    }
    //method 1
    if($mt==1) {
      $str = str_ireplace($txt, hpp_defer_imgs($txt), $str);
    }
  }
  return $str;
}
function hpp_b64holder($wrap='"', $width='0', $height='0') {
  if($width=='') $width='0';
  if($height=='') $height='0';
  #if(wp_is_mobile()) $width = $height = '0';
  if(!hw_config('lazy_svg')) return 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
  if($wrap=='"') {
    return "data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20".$width."%20".$height."'%3E%3C/svg%3E";
  }
  else return 'data:image/svg+xml,%3Csvg%20xmlns="http://www.w3.org/2000/svg"%20viewBox="0%200%20'.$width.'%20'.$height.'"%3E%3C/svg%3E';
}
function hpp_getAttr($tag, $attr, $val='') {
  if(is_array($attr)) {
    $atts=[];
    foreach($attr as $k=>$v) $atts[$k] = hpp_getAttr($tag, $k, $v);
    return $atts;
  }
  if(strpos($tag, " {$attr}=")!==false) {
    $ar = explode(' '.$attr.'=', $tag);
    $ar = explode('"',$ar[1]);
    $ar = explode("'",trim($ar[0])? $ar[0]: $ar[1]);
    return trim($ar[0]);
  }
  return $val;
}

/*
echo preg_replace_callback('#<(img|iframe)(((?!>).)*)\s+?class=(\'|")(.*?)>#s',function($m){
  $q = $m[4];
  $ar = explode($q, $m[5]);
  if(hpp_in_str($ar[0].$q,[$q.'lazy'.$q, $q.'lazy ',' lazy'.$q,' lazy '])) {
    return sprintf('<%s%s class=%s%s>', $m[1],$m[2], $m[4], $m[5]);
  }
  return sprintf('<%s%s class=%slazy %s>', $m[1],$m[2], $m[4], $m[5]);
}, $s);
*/
function hpp_defer_imgs($str ) {
  if(!hpp_shouldlazyload() ) return $str;
  $str0 = $str;#static $base64;
  //src: .*(>)? | )* ) -> )*) data:image > because wp_kses() remove `data:image`
  #if(!$base64) $base64 = apply_filters('hpp_defer_src_holder', ';base64,'); //#remove_all_filter(..)
  #$b64 = (strpos($str, $base64)===false)? ';base64,': $base64; //prevent duplicate data-src
  #$str = preg_replace('#<(img|iframe)(((?!>).)*)\s+?src=(((?!'.$b64.').)*?)>#si', '<$1$2 src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" data-src=$4>',$str);
  if(!isset($GLOBALS['hpp_tags'])) $GLOBALS['hpp_tags']=[];$tags = &$GLOBALS['hpp_tags'];
  $str = preg_replace_callback('#<(img|iframe|audio)(((?!>).)*)\s+?src=(((?!;base64,).)*?)>#si', function($m) use(&$tags){
    #$tags[$m[0]] = 1;
    if(strpos($m[0], ' data-src=')!==false || (isset($tags[$m[0]]) && !$tags[$m[0]]) ) return $m[0];
    $tag = '<'.$m[1].$m[2].' src='.$m[4].'>';
    $tags[$m[0]] = !apply_filters('hpp_disallow_lazyload', false, $tag);
    $w = hpp_getAttr($m[4].' '.$m[2], 'width',0);
    $h = hpp_getAttr($m[4].' '.$m[2], 'height',0);
    return $tags[$m[0]]? '<'.$m[1].$m[2].($m[1]=='img'? ' src="'.hpp_b64holder('"',$w,$h).'"':'').' data-src='.$m[4].'>': $tag;
  },$str);
  if($str===null) return $str0;
  $class = hw_config('lazy_class');
    //attr class: 
    #$str = preg_replace('#<(img|iframe)(((?!>).)*)\s+?class=(\'|")(((?! '.$class.' ).)*?)>#si','<$1$2 class=$4 '.$class.' $5>', $str);  //exist class:(.*?)  |iframe
    $str = preg_replace_callback('#<(img)(((?!>).)*)\s+?class=(\'|")(((?! '.$class.' ).)*?)>#si', function($m) use($class, &$tags){
      if(isset($tags[$m[0]]) && !$tags[$m[0]]) return $m[0];
      $tag = '<'.$m[1].$m[2].' class='.$m[4].' '.$m[5].'>';
      #$tags[$m[0]] = !empty($tags[$m[0]])? true: apply_filters('hpp_allow_lazyload', true, $tag);
      return 1||$tags[$m[0]]? '<'.$m[1].$m[2].' class='.$m[4].' '.$class.' '.$m[5].'>' : $tag;
    }, $str);
    
    #$str = preg_replace('#<(img|iframe)((?!.* class=).*?)>#', '<$1 class="lazy"$2>',$str);
    #$str = preg_replace('#<(img|iframe)(((?! class=).)*?)>#si', '<$1 class=" '.$class.' "$2>',$str);  //not exist `class`. important: add space 'lazy ' to prevent duplicate with above regex: |iframe
    $str = preg_replace_callback('#<(img)(((?! class=).)*?)>#si', function($m) use($class, &$tags){
      if(isset($tags[$m[0]]) && !$tags[$m[0]]) return $m[0];
      $tag = '<'.$m[1].' '.$m[2].'>';
      #$tags[$m[0]] = !empty($tags[$m[0]])? true: apply_filters('hpp_allow_lazyload', true, $tag);
      return 1||$tags[$m[0]]? '<'.$m[1].' class=" '.$class.' "'.$m[2].'>' : $tag;
    },$str);
    #$str = preg_replace('#<(img)(((?! loading=).)*?)>#si', '<$1 loading="lazy"$2>',$str);  //|iframe -> will break connect in iframe. no need
    //alt
    #$str = preg_replace('#<(img)((?!.* alt=).*?)>#', '<$1 alt=" "$2>',$str);
    #$str = str_replace(['alt=""',"alt=''"], 'alt=" "', $str);
  
  #$str = preg_replace('# srcset="(.+?)"#',' data-srcset="$1"', $str);
  $str = preg_replace_callback('#<(img|iframe)(((?!>).)*)\s+?srcset=(((?!;base64,).)*?)>#si', function($m) use(&$tags){
    if(isset($tags[$m[0]]) && !$tags[$m[0]]) return $m[0];
    return '<'.$m[1].$m[2].' data-srcset='.$m[4].'>';
  }, $str);
  #$str = str_replace([' srcset="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="'," srcset='data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=='"],'', $str);
    
  return $str;
}
//@deprecated: use for single img
function hpp_defer_img($img) {
  if(stripos($img, '<img ')===false || !hpp_shouldlazyload() || apply_filters('hpp_disallow_lazyload', false, $img)) return $img;
  //$img = str_replace(['data-src=""',"data-src=''"],'', $img); //fix
  //src
  if(strpos($img, ' data-src')===false) {
    $at = hpp_getAttr($img, ['width'=>'0', 'height'=>'0']);
    $img = str_replace(' src=', ' src="'.hpp_b64holder('"',$at['width'], $at['height']).'" data-src=', $img);
  }
  $class = hw_config('lazy_class');
  //class
  if(strpos($img, 'class=" '.$class)===false ) {
    if(strpos($img, ' class="')!==false) $img = str_replace(' class="', ' class=" '.$class.' ', $img);
    else $img = str_ireplace('<img ', '<img class=" '.$class.' " ', $img);
  }
  //alt
  #if(stripos($img, ' alt=')===false) $img = str_replace('<img ', '<img alt=" " ', $img);
  #else $img = str_replace(['alt=""',"alt=''"], 'alt=" "', $img);

  return $img;
}

function hpp_defer_media( $str){
    if((stripos($str, '<img')!==false || stripos($str, '<iframe')!==false || stripos($str, '<audio')!==false) && apply_filters('hpp_should_defer_media_in_text', true, $str ) 
    #&& !preg_match( '#<(img|iframe)(.+?)data-src#',$str)
    ) 
    {
        //lazy: foreach(['img','iframe'] as $tag) if(strpos($str, $tag)!==false) 
      /*preg_match_all('#<iframe(((?!>).)*)(\s+?)src=(((?!hqp'.rand().').)*?)>(.*)?<\/iframe>#s', $str, $m);
      if(hpp_in_str($s, ['youtube','vimeo'])) {*/        
      $dt = [];$str = hpp_treat_tag( $str, $dt,['script']); #print_r($dt);//because it rendered
      $str = hpp_defer_imgs($str );
      //should after to prevent duplicate lazy
      if(stripos($str, '<iframe')!==false) {
        $str = hpp_lazy_video($str, 2, 0) ;
      }
      $str = hpp_treat_tag( $str, $dt, ['script']);
    }
    return $str;
}

function hpp_defer_content( $str) {
  if(is_string($str) && hpp_isJson($str)) {
    return json_encode(hpp_defer_option(json_decode($str,true)));
  }
  $str = hpp_defer_media($str);
  #$str = hpp_delay_assets($str, 0);  //move to buffer output
  return $str;
}
function hpp_defer_option($value) {
  //$v1=$value;
  if(is_array($value) || is_object($value)) {
      array_walk_recursive($value, function(&$v) {
          if(is_string($v)) $v = hpp_defer_content( html_entity_decode($v));
        });
    }
    else if(is_string($value)) $value = hpp_defer_content($value) ;
    //if($v1!=$value){if(!isset($GLOBALS['ii'])) $GLOBALS['ii']=0;_print($v1);_print($value);echo '<br>---------------<br>';if($GLOBALS['ii']++==4)return $v1;}
    return $value;
}
function hpp_defer_media_large($str) {
  $dt = [];$str = hpp_treat_tag( $str, $dt,['script']);
  #$base64 = apply_filters('hpp_defer_src_holder', 'data:image/gif;base64,');

  //$chunk = hpp_split_tag($str, '<img ');
  $chunk=preg_split('#<(img|iframe|audio) #si', $str, -1,PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);array_shift($chunk);
  foreach($chunk as $i=>$s1) {
    if( $i%2==0) continue;//$i>=2 &&
    $tg = substr('<'.$chunk[$i-1].' '.$s1,0, 1200);
    $class = hw_config('lazy_class');
    if(strpos($tg, ' '.$class.' ')===false /*|| strpos($tg, (strpos($str, $base64)===false)? 'data:image/gif;base64,': $base64)===false*/) {
      //should after to prevent duplicate lazy
      if(stripos($tg, '<iframe')!==false && hpp_get_embed_video_url($tg)) {
        $str = str_replace($tg, hpp_lazy_video($tg, 2, 0), $str);
      }
      else $str = str_replace($tg, hpp_defer_imgs($tg ), $str);
      #$str = str_replace($tg, hpp_defer_media($tg), $str);
      #print_r($tg);echo "\n\n------\n\n";
    }
  }
  $str = hpp_treat_tag( $str, $dt, ['script']);
  return $str;
}
function hpp_html_attrs($input, $exclude=array()) {
    if( ! preg_match('#^(<)([a-z0-9\-._:]+)((\s)+(.*?))?((>)([\s\S]*?)((<)\/\2(>))|(\s)*\/?(>))$#im', $input, $matches)) return false;
    $matches[5] = preg_replace('#(^|(\s)+)([a-z0-9\-]+)(=)(")(")#i', '$1$2$3$4$5<attr:value>$6', $matches[5]);
    $results = array(
        'element' => $matches[2],
        'attributes' => null,
        'content' => isset($matches[8]) && $matches[9] == '</' . $matches[2] . '>' ? $matches[8] : null
    );
    if(preg_match_all('#([a-z0-9\-]+)((=)(")(.*?)("))?(?:(\s)|$)#i', $matches[5], $attrs)) {
        $results['attributes'] = array();
        foreach($attrs[1] as $i => $attr) {
            if(!in_array($attr, $exclude)) $results['attributes'][$attr] = isset($attrs[5][$i]) && ! empty($attrs[5][$i]) ? ($attrs[5][$i] != '<attr:value>' ? $attrs[5][$i] : "") : $attr;
        }
    }
    return $results['attributes'];
}
function hpp_dom_attr($str, $exclude=array(), $tag='') {
  if(!class_exists('\DOMDocument')) {
    $result = hpp_html_attrs($str, $exclude);
    return $result;
  }
  libxml_use_internal_errors(true);
  $dom = new \DOMDocument();
  $dom->loadHTML($str);
  libxml_clear_errors();

  $result = array();

  $e = $dom->getElementsByTagName($tag)->item(0);
  if ($e && $e->hasAttributes()) {
    foreach ($e->attributes as $attr) {
      $name = $attr->nodeName;
      $value = hpp_attr_value( $attr->nodeValue);//trim(trim(trim(trim($attr->nodeValue),'\\'),'"'),"'");
      if(!in_array($name, $exclude)) $result[$name] = $value;
    }
  }
  return $result;
}

function hpp_attr_value($s) {
  $sub=substr($s,0,4);
  $s= str_replace($sub, str_replace(['"',"'",'\\'], '', $sub), $s) ;
  $sub1=substr($s,-4);
  $s= str_replace($sub1, str_replace(['"',"'",'\\'], '', $sub1), $s) ;

  return $s;
}
/*function hpp_split_tag($str, $tag){
  if(strpos($str, $tag)===false) return [$str];
  $chunk=explode($tag, $str);$first=array_shift($chunk);
  $chunk = array_map(function($s) use($tag){return $tag.$s;}, $chunk );
  array_unshift($chunk, $first);
  return $chunk;
}*/
/*
function hpp_wp_head() {
  ob_start();wp_head();$out = ob_get_contents();ob_end_clean();
  echo hpp_delay_assets($out);
}
function hpp_wp_footer() {
  ob_start();wp_footer();$out = ob_get_contents();ob_end_clean();#_print($out);
  echo hpp_delay_assets($out,1);
}
*/
//move to buffer output
function hpp_delay_assets($out, $footer=0) {
  
  global $wp2speed;
  if(!$wp2speed || !hpp_shouldlazy() || hpp_gen_critical_context(1,0)) return $out;
  //debug for: no, edit criticalcss
  #if(file_exists(WP_CONTENT_DIR.'/uploads/critical-css/extra.css')) 
  #  $out = '<link rel="stylesheet" media="all" href="'.WP_CONTENT_URL.'/uploads/critical-css/extra.css">'."\n". $out;
  $dt=[];$out = hpp_treat_tag( $out, $dt); //because it rendered
  #preg_match_all('#\[if .*?\].*?\[endif\]#si', $out, $m); apply_filters('hpp_cache_url',WP_CONTENT_URL.'/mmr/')
  $path_me = trim(str_replace([WP_SITEURL,'http://','https://'],'', MMR_CACHE_URL),'/');

  //find stylesheet:  (((?!hqp'.rand().').)*?)
  if(hpp_in_str($out, ['<link ','</script>','</style>'])) {
    preg_match_all('#<link(((?!>).)* )rel=(.*?)>|<script(((?!>).)*)?>(.*?)<\/script>|<style(((?!>).)*)?>(.*?)<\/style>#si', $out, $m);
    foreach($m[0] as $i=>$l) {
      //stylesheet
      if(stripos($l, '<link ')!==false && stripos($l, '<noscript>')===false && hpp_in_str($l, ['stylesheet','text/css']) && strpos($l, $path_me)===false) {
        #preg_match('#href=(\'|")(.*?)(\'|")#s', $l, $m1);
        #if(!empty($m1[2])) { //$m1[2]
          $att = hpp_dom_attr($l, [/*'href',*/'rel'], 'link');
          if(!empty($att['href'])) {
            if(!apply_filters('hpp_allow_delay_asset', true, $att['href'])) {
              $out = str_replace($l, HPP_Lazy::defer_asset_html($l,'css'), $out);
            }
            else {
              if(empty($att['id'])) $att['id'] = md5($att['href']);
              $wp2speed->_delay_asset($att['href'], "css", array_merge(array('id'=>$att['id'],'extra'=>1), hpp_array_exclude_keys($att,['href'])) );
              $out = str_replace($l, '', $out);
            }
          }
        #}
      }

      //script tag
      if(stripos($l, '</script>')!==false && !hpp_in_str($m[4][$i], hpp_var('script-type'))) {
        if(strpos($m[4][$i], ' src=')!==false && strpos($m[4][$i],$path_me)===false) {
          #preg_match('#src=(\'|")(.*?)(\'|")#s', $m[4][$i], $m1);  //$m1[2]
          #if(!empty($m1[2])) {
          $att = hpp_dom_attr($l, [/*'src',*/'type'], 'script');
            if(apply_filters('hpp_allow_delay_asset', true, $att['src'])) {
              if(empty($att['id'])) $att['id'] = md5($att['src']);
              $wp2speed->_delay_asset($att['src'], "js", array_merge(array('id'=>$att['id'], 'deps'=>'hpp-0','extra'=>1), hpp_array_exclude_keys($att,['src'])) );
              $out = str_replace($l, '', $out);
            }
            else $out = str_replace($l, HPP_Lazy::defer_asset_html($l,'js'), $out); //str_replace(' src=', ' data-src=', $l)
          #}
        }
        else if(strpos($m[4][$i], ' src=')===false && trim($m[6][$i])!='' && !hpp_in_str($m[6][$i], ['var _HWIO','_HWIO.extra_assets='])) {
          
          //$out = str_ireplace($l, hpp_fix_script($l), $out);| !hpp_in_str($m[6][$i], ['function ','function('])
          if( apply_filters('hpp_allow_readyjs', true, $m[6][$i])) {
            $js = hpp_fix_script_variables($m[6][$i]);
            if(!hpp_in_str($m[6][$i], ['_HWIO.readyjs('])) {
              $open = !apply_filters('hpp_delay_it_script', false, $js)? '_HWIO.readyjs(function(){': '_HWIO.readyjs(null,function(){';
              $close = '})';
              if($m[6][$i]!= $js && !hpp_in_str($js,hpp_var('heavy-js',[]))) {
                $open = hw_config_val('debug', 1, "/*{$open}*/",'');$close = hw_config_val('debug', 1, "/*{$close}*/",'');
                $js = $m[6][$i];  //use origin
              }
              $js = hpp_fix_script($js);
              /*else*/ $js = "{$open}{$js}{$close}";
            }
            //'<script'.$m[4][$i].'>'.$m[6][$i].'</script>'
            #$js = apply_filters('hpp_inline_script', $wp2speed->fixOtherJS($js));
            #if($js!=$m[6][$i]) $out = str_replace($l, '<script'.$m[4][$i].'>'."{$js}</script>", $out);
          }
          else $js = $m[6][$i];
          #else {
            try{$js = apply_filters('hpp_inline_script', $wp2speed->fixOtherJS($js) );}catch(Exception $e){echo $e->getMessage();}#$js = apply_filters('hpp_inline_script', $m[6][$i]);
            if($js!=$m[6][$i]) $out = str_replace('<script'.$m[4][$i].'>'.$m[6][$i].'</script>', '<script'.$m[4][$i].'>'.$js.'</script>', $out);
          #}
        }
      }

      //style tag
      if(stripos($l, '</style>')!==false && strpos($m[7][$i],'media="not all"')===false && trim($m[9][$i])) {
         //|| strpos($l, 'id="critical-css"')!==false
        #if(!trim($m[9][$i]) || strpos($l, hpp_gen_critical_context('','media="not all"'))!==false ) continue;
        
        //$l1 = hpp_fix_stylesheet(str_ireplace(['</style>', $m[1][$i] ], '', $l));
        $css = hpp_fix_stylesheet(apply_filters('hpp_inline_style', $m[9][$i]));
        
        if($m[9][$i]!= $css) {
          #if(hw_config('debug')) $out = str_replace($l, str_replace('<style', '<style data-parse="1"',$l), $out);
          $out = str_replace( '<style'.$m[7][$i].'>'.$m[9][$i].'</style>', '<style'.$m[7][$i].'>'.$css.'</style>', $out); //no need, remove <style when empty. css? $m[9][$i]: $l
        }
      }
    }
  }
  
  //find script: '#<script(((?!>).)* )src=(((?!hqp'.rand().').)*?)>#s'
  
  if($footer && $out) {#_print($out);
    if(!empty($GLOBALS['hpp-lazycss'])) {
      $out = '<style '.hpp_gen_critical_context('','media="not all"').'>'.apply_filters('hpp_lazycss',$GLOBALS['hpp-lazycss']).'</style>'.$out;
      $GLOBALS['hpp-lazycss']='';
    }
    //str_replace('_HWIO.assets={};',_HWIO.assign(_HWIO.assets,
    #$out .= '_HWIO.assets='.json_encode($wp2speed->getExtraAssets()).';' ; //no need,duplicate
  }
  $out = hpp_treat_tag( $out, $dt);
  return $out;
}

function hpp_css_properties(array $it, $newLine="\n") {
  $css='';
  foreach($it as $k1=> $v1) $css.= "{$k1}:{$v1};";//\n
  return $css;
}
function hpp_css_background(array $data, &$critical) {
  $media = '';
  foreach($data as $k=>$it) {
    //$keys = array_keys($it);
    if(count(array_intersect_key($it, ['background'=>'','background-image'=>''])) && (isset($it['background'])? strpos($it['background'], 'url(')!==false : strpos($it['background-image'], 'url(')!==false)) {
      $media.= "{$k}{";//\n
      $critical.= "{$k}{";
      foreach($it as $proper => $val) {
        if(strpos($proper, 'background')!==false) $media.= "{$proper}: {$val};";//\n
        else $critical.= "{$proper}:{$val};";//\n
      }
      $media.= "}";//\n
      $critical.= "}";
    }
    else {
      $critical.= "{$k}{".hpp_css_properties($it). "}";//\n
    }
  }
  return $media;
}

function hpp_fix_stylesheet($str) {
  global $wp2speed;
  if(!$wp2speed || !hpp_shouldlazy() || empty($str)) return $str;
  if(!class_exists('hpp_csstidy')) return hpp_fix_stylesheet_1($str);
  try{
    $csstidy = new hpp_csstidy();
    $csstidy->parse($str);
    $css='';$critical='';
    if(!isset($GLOBALS['hpp-lazycss'])) $GLOBALS['hpp-lazycss'] = '';
    #if(!empty($csstidy->log)) return hpp_fix_stylesheet_1($str);
    /**find import statement*/
    if(strpos($str, '@import')!==false) {
      preg_match_all('#@import(\s+)url\((.*?)\)([\s;]+)?#si', $str, $m);// (\s+)?;
      foreach($m[0] as $i=>$l) {
        if(strpos($str, $l)===false) continue;
        $str = str_replace($l, '', $str);
        $css.= $l; //.';'
      }
    }
    //so nerver find error syntax css for inline style. solution: check css syntax before in css tool
    foreach($csstidy->css as $s => $data) {
      if(!is_array($data)) continue;
      //media
      if(strpos($s, '@media')===0) {
        $b = str_repeat('}',substr_count($s,'{')+1);
        $critical .= "{$s}{";//\n
        $media = hpp_css_background($data, $critical);
        if($media) $css.= "{$s}{".$media.$b;//"}" \n
        $critical.= $b;//"}";//\n
      }
      else {
        //font-face
        if(trim($s)=='') {
          foreach($data as $k=>$it) {
            $css.= "{$k}{".hpp_css_properties($it)."}";//\n
          }
        }
        //other
        else {
          $css.= hpp_css_background($data, $critical);
        }
      }
    }
    $GLOBALS['hpp-lazycss'].= $css;//hpp_strip_comment($css,'css');
    if(0||$critical) $str = $critical;
    return $str;
  }
  catch(Exception $e){
    return hpp_fix_stylesheet_1($str);
  }
}

function hpp_fix_stylesheet_1($str) {
  global $wp2speed;
  if(!$wp2speed || !hpp_shouldlazy()) return $str;
  $css='';
  $str1 = hpp_strip_comment($str, 'css');
  /**find font*/
  if(!isset($GLOBALS['hpp-lazycss'])) $GLOBALS['hpp-lazycss'] = '';
  if(strpos($str1, '@font-face')!==false) {
    //don't regex $str1
    preg_match_all('#@font-face(\s+)?\{(.*?)\}([\s;]+)?#si', $str, $m);$m[0] = apply_filters('hpp_filter_font_face', $m[0], $m);
    foreach($m[0] as $l) {
      if(strpos($str1, $l)===false) continue;
      $str = str_replace($l, '', $str);
      $css.= $l;
    }
  }

  /**find import statement*/
  if(strpos($str1, '@import')!==false) {
    preg_match_all('#@import(\s+)url\((.*?)\)([\s;]+)?#si', $str, $m);// (\s+)?;
    foreach($m[0] as $i=>$l) {
      if(strpos($str1, $l)===false) continue;
      $str = str_replace($l, '', $str);
      $css.= $l; //.';'
      //$wp2speed->_delay_asset($m[2][$i], 'css', array('id'=>md5($m[2][$i]) ));  //duplicate
    }
  }
  //fix url(): (.*?data:image.*?) . no, " or not will fix by tool
  if(0&& hpp_in_str($str1, ['"data:image/',"'data:image/"])) {
    preg_match_all('#url(\s+)?\((.*?)\)#si', $str, $m);
    foreach($m[0] as $i=>$s) {
      if(strpos($m[2][$i],'data:image/')!==false && in_array(substr(trim($m[2][$i]),0,1),['"',"'"]) ) {
        $str = str_replace($m[2][$i], trim(trim(trim($m[2][$i]),'"'),"'"), $str);
      }
    }
  }
  
  /**delay bg image*/
  //if(!isset($GLOBALS['hpp-bgimg'])) $GLOBALS['hpp-bgimg'] = array();
  //preg_match_all('#url\((.*?)\)#s', $str, $m);
  if(strpos($str1, 'url(')!==false) {$str2=preg_replace('#@media .*?\{#', '',$str);
  preg_match_all('#(((?!}).)*)\{(.*?)\}#s', $str2, $m);$rpl=[];
  if(!empty($m[0])){
  foreach($m[0] as $i=>$l) {
    if(strpos($l, 'url(')!==false && trim($m[1][$i])!='@font-face' && hpp_in_str($l, [';base64,','data:image/'])==false && strpos($str1,$l)!==false) {
      #$GLOBALS['hpp-bgimg'][] = trim($m[1][$i]);
      #$str = str_replace($l, str_replace('url(', '_url(', $l), $str);$rpl1=[];
      $m[3][$i] = preg_replace_callback('#url\((.*?)\)#', function($m1) use(&$rpl, &$l, &$str){
        $k = md5($m1[1]);
        $rpl[$k] = $m1[1];
        $l = str_replace($m1[1], $k, $l);$str = str_replace($m1[1], $k, $str);
        return 'url('.$k.')';
      }, $m[3][$i]);

      $ll = explode("\n", $m[3][$i]);$rm=[];
      foreach($ll as $s) {
        $ll1= array_filter(explode(";", $s));$c1=count($ll1)-1;
        foreach($ll1 as $j=>$s1) {
          //background-size,..
          if(strpos($s1, 'url(')!==false || strpos($s1, 'background-')!==false) $rm[]=$s1;//.($c1>$j? ';':'');background-image
        }
        #count($ll1),count($rm)
        #$m3 = str_replace($s, str_replace($rm, '', $s), $m[3][$i]);        
      }
      $m3 = str_replace($rm, '',$m[3][$i]);
      if(trim($m3)!='') $str = str_replace($l, str_replace($m[3][$i], $m3, $l), $str);
      else $str = str_replace($l, '', $str);

      $css.= $m[1][$i]."{\n".join("\n",array_map(function($v){return $v.(hpp_endsWith(trim($v),';')?'':';');},$rm))."\n}";
      #$css.= $l; //$m[4][$i]
      #$str = str_replace($l, '', $str);
    }
  }
  foreach($rpl as $f=>$r) $css = str_replace($f, $r, $css);
  }}
  #if(trim(hpp_strip_comment($str,'css'))=='') {$GLOBALS['hpp-lazycss'].= $str;$str='';}//if comment only
  $GLOBALS['hpp-lazycss'].= hpp_strip_comment($css,'css');//str_replace(['/*','*/'],'',$css);
  return $str;
}

//_it exist js
function hpp_delay_it_script($js) {
  if(apply_filters('hpp_delay_it_script', false, $js)) {
    $js = str_replace('_HWIO.readyjs(function(){','_HWIO.readyjs(null,function(){', $js);
  }
  return $js;
}

//@deprecated from pagespeed/hqp_inject_lazy_phpfile
function _hpp_fix_script($code) {
    //find noscript tag. /s for multi-line
    /*preg_match_all('#<noscript>(.*?)<\/noscript>#si',$code, $m);$noscripts = [];
    foreach($m[0] as $i=>$s) {
      $noscripts["[noscript_{$i}]"] = $s;
      $code = str_replace($s, "[noscript_{$i}]", $code);
    }*/
    //find <script 
    if(strpos($code, '</script>')!==false  ) {
      $scripts = hpp_find_script_tag($code);

      if(count($scripts) ) {          
          foreach($scripts as $it) {
            if(strpos($it['js'], '_HWIO.readyjs(')===false) {
              $js = hpp_fix_script_variables($it['js']);
              $code = str_replace($it['tag'].$it['js'].'</script>', $it['tag']."_HWIO.readyjs(function(){{$js}})</script>", $code);
            }
          }        
      }
      
    }
    //resume noscript
    #foreach($noscripts as $k=>$v) $code = str_replace($k, $v, $code);
    return $code;
}
//@deprecated
function hpp_find_script_tag($code ) {
    //validate
    $exclude = hpp_var('script-type');
    $chunk = [];
    preg_match_all('#(<script(.*?)>)(.*?)</script>#si', $code, $m);
    
    foreach($m[0] as $i=>$js) {
        if(strpos($m[1][$i], ' src=')!==false || hpp_in_str($m[1][$i], $exclude)) continue;
        $chunk[] = ['js'=> $m[3][$i], 'tag'=> $m[1][$i]];
    }
    return $chunk;
}
function hpp_find_script_tag_v1($code ) {
    //validate
    $exclude = hpp_var('script-type');
    $chunk = [];$warn=0;
    if(stripos($code, '</script>')===false) return $chunk;
    preg_match_all('#(<script(.*?)>)(.*?)</script>#si', $code, $m);#print_r($m);
    
    foreach($m[0] as $i=>$js) {
        $ignore = 0;
        //fix if found <script in js code
        if(strpos($m[3][$i],'<script')!==false) {
            $ar = explode('<script', $m[3][$i]);
            preg_match('#(<script(.*?)>)(.*?)</script>#si', '<script'.$ar[1].'</script>', $m1);
            foreach($m1 as $j=>$s) {
                $m[$j][$i]=$s;if($j==0) $js = $s;
            }
            $warn=$ignore=1;
        }
        
        /*strpos($m[1][$i], ' src=')!==false ||*/
        if( hpp_in_str($m[1][$i], $exclude)) continue;
        $chunk[] = ['js'=> $m[3][$i], 'tag'=> $m[1][$i], 'ignore'=>$ignore];
        if(strpos($m[1][$i], ' src=')!==false) {
            $att = hpp_dom_attr('<script '.$m[1][$i].'</script>',[], 'script');
            if(empty($att['src'])) {preg_match('# src=(\'|")(.*?)(\'|")#', $m[1][$i], $m2);$att['src'] = $m2[2];}

            if(!trim($m[3][$i]) && !hpp_in_str($att['src'], ['<?php ','?>','"',"'",'()', !empty($_GET['host'])? $_GET['host']:$_SERVER['HTTP_HOST']])) {
                $chunk[count($chunk)-1]['src'] = array_filter(array_merge(['l'=>$att['src']],$att, ['src'=>'','type'=>'','defer'=>'','async'=>''] ));
            }
            else unset($chunk[count($chunk)-1]);
        }
    }
    #if(count($chunk)) print_r("found script tag in {$file}", $warn? 'red':'blue');
    return $chunk;
}
function hpp_fix_script($js) {
  if(hpp_startsWith(trim($js), '<!--')) {
    $js = trim($js);
    $js = substr($js, 4);
    if(hpp_endsWith($js, '-->')) $js = preg_replace('#(\/{2,})?-->$#','',$js);//substr(trim($js), 0,-3);
  }
  return $js;
}
//var a=1 -> window.a=1
function hpp_fix_script_variables($str, $repl=1) {
  #if(!$force && hpp_in_str($str, hpp_var('heavy-js',[]))) return $str;
  if(1||!$repl) $res=[ 'list'=>[]];  //'found'=>[], 'js0'=>$str
  if(stripos($str, 'var ')!==false) {
    #if(strpos($str, '}var ')!==false) $str = str_replace('}var ', '};var ', $str);
    #$str1 = hpp_strip_comment($str,'js');
    $str = preg_replace_callback('#(^|\s+|\'|"|;)var(\s+?)([a-zA-Z0-9_\s]+?)=(((?!;).)*)#si', function($m) use($str, &$res){
      if(0&& strpos($m[4], ',')!==false) { //var a,b;       
        return $m[0];
      }
      if(substr_count($m[0], 'var ')>1) { //var x var y;
        #$m[0]=str_replace('var ', ' var ',$m[0]);
        #$m[0] .= ';';//str_replace('var ', ';var ',$m[0]);
        return $m[0];
      }
      if(apply_filters('hpp_disallow_global_var',strlen($m[3])<4, $m[0])) return $m[0]; //var e=
      $m[3] = trim($m[3]);
      $res['list'][] = $m[3];
        return $m[0];
      #$m[4] = trim($m[4]);
      $ar=explode('||',$m[4]);
      if(trim($ar[0]) == $m[3]) $m[4] = str_replace($ar[0].'||',' window.'.$m[3].'||', $m[4]);#.';';#if(strpos($m[4], $m[3])!==false) ;
      #if(!hpp_endsWith(trim($m[4]), ';')) $m[4].= ';';  #file_put_contents('g:/tmp/2.txt',$m[4]);
      #if(!$repl) {        
        #$res['found'][] = [$m[0], ";window.{$m[3]}={$m[4]};"];        
      #}
      return ";window.{$m[3]}={$m[4]};";  // var {$m[3]}=, don't `\n;window.`
    },$str );
  }
  
  if(stripos($str, 'function ')!==false) {
    #if(!isset($str1)) $str1 = hpp_strip_comment($str,'js');
    $str = preg_replace_callback('#(^|\s+|\'|"|;)function ([a-zA-Z0-9_\s]+?)\(#si', function($m) use(&$res ){
      if(apply_filters('hpp_disallow_global_var',strlen($m[2])<4, $m[0])) return $m[0];
      $m[2] = trim($m[2]);
      #if(!$repl) {
        $res['list'][] = $m[2];
        #$res['found'][] = [$m[0], ";window.{$m[2]}={$m[2]};{$m[0]}"];
        return $m[0];
      #}
      return ";window.{$m[2]}={$m[2]};{$m[0]}"; //don't: "\n;window." if in comment line
    },$str );
  }
  if(count($res['list'])) {//setTimeout
    $str.= ';(function(w){var a="'.join(',',$res['list']).'".split(","),i;for(i=0;i<a.length;i++)if(void 0!=eval("try{"+a[i]+"}catch(e){}"))try{w[a[i]]=eval(a[i]);}catch(e){} })(window);';
  }
  /*if(!$repl) {
    $res['js'] = $str;
    return $res;
  }*/
  return $str;
    /*preg_match_all('#(^|\s+|\'|"|;)var(\s+?)([a-zA-Z0-9_\s]+?)=#si', $str,$m);
    #print_r($m);
    foreach($m[0] as $i=>$var) {
        if(substr_count($str, $m[3][$i])>=2) continue;  //ignore `var Tawk_API=Tawk_API||{}`
        $ar = explode($var, $str);
        if(mb_strpos($ar[0], 'function(')===false) $str = str_replace($var, preg_replace('#var(\s+)#', 'window.', $var), $str);
    }
    return $str;*/
}

function hpp_lazy_script($code ) {
  global $wp2speed;
    //find <script 
    if(stripos($code, '</script>')!==false  ) {
        $scripts = hpp_find_script_tag_v1($code );

        if(count($scripts) ) {
            //$code = str_replace(['<script>','<script type="text/javascript">',"<script type='text/javascript'>"],'<script>_HWIO.readyjs(function(){', $code);
            
            foreach($scripts as $it) {
                if(strpos($it['js'], '_HWIO.readyjs(')===false ) { //!hpp_in_str($it['js'], ['function ','function('])
                    if(!empty($it['src'])) {
                        $js = '_HWIO.readyjs(function(){_HWIO._addjs('.json_encode($it['src']).');})';
                        $code = str_replace($it['tag'].$it['js'].'</script>', "<script>{$js}</script>", $code);
                        continue;
                    }
                    if(apply_filters('hpp_allow_readyjs',true,$it['js'] )){
                        $js = hpp_fix_script_variables($it['js']);
                        $open = !apply_filters('hpp_delay_it_script', false, $js )? '_HWIO.readyjs(function(){':'_HWIO.readyjs(null,function(){';
                        $close = '})';
                        if(($it['js']!==$js && !hpp_in_str($js,hpp_var('heavy-js',[]))) || !empty($it['ignore'])) {
                            $open=hw_config_val('debug', 1, "/*{$open}*/",'');$close=hw_config_val('debug', 1, "/*{$close}*/",'');
                            $js = $it['js']; //use original
                        }
                        $js = hpp_fix_script($js);
                        /*else*/ $js = "{$open}{$js}{$close}";                        
                    }
                    else $js = $it['js'];
                    try{$js = apply_filters('hpp_inline_script', $wp2speed->fixOtherJS($js) );}catch(Exception $e){$js = $wp2speed->fixOtherJS($js);hpp_write_log($e->getMessage());}
                    if($it['js']!= $js) $code = str_replace($it['tag'].$it['js'].'</script>', $it['tag']."{$js}</script>", $code);
                }
            }
        }
            
    }
    return $code;
}

function hpp_reorder_hooks($pirority, $hook, $func, $pos=0, $before=0) {
  global $wp_filter;
//&& count($wp_filter[$hook]->callbacks[$pirority]) > 1
  if(!empty($wp_filter[$hook]->callbacks[$pirority]) ) {
    $callbacks = &$wp_filter[$hook]->callbacks[$pirority];
    $insert=[]; $i=0;
    foreach($callbacks as $k=>$it) {
      $fun = is_array($it['function']) ? $it['function'][1] : (is_string($it['function'])? $it['function']: '');
      if($fun==$func ) {
        unset($callbacks[$k]);
        $insert[$k]=$it;
        #break;
      }
      if(!is_numeric($pos) && $pos==$fun) $pos=$i+($before? -1:0);
      $i++;
    }
    if(is_numeric($pos)) $callbacks = hpp_array_insert_assoc($callbacks, $insert, $pos);
    #if($pos===0) $callbacks = $insert + $callbacks;
  }
}
function hpp_array_insert(&$original, $inserted, $pos=0) {
    array_splice( $original, $pos, 0, $inserted );
}

function hpp_array_insert_assoc($array, $inserted, $pos=0) {
    return array_slice($array, 0, $pos, true) + $inserted + array_slice($array, $pos, count($array)-$pos, true);
}
function hpp_in_str($str, $in, $all=0) {
    foreach((array)$in as $p) {
      if(!$all && mb_stripos($str, $p)!==false ) return true;
      if($all && mb_stripos($str, $p)===false) return false;
    }
    return $all? true: false;
}
function hpp_current_url() {
  global $wp;
  return home_url( $wp->request );
}
//plugin accelerated-mobile-pages
function hpp_is_amp() {
  //global $wp_query
  //function_exists('is_amp_endpoint')? is_amp_endpoint(): #will err
  return isset($GLOBALS['hpp-criticalname'])? false: ( isset($_GET['amp']) || (($p=parse_url($_SERVER['REQUEST_URI'])) && substr(trim($p['path'],'/'), -strlen('/amp')) === '/amp' )  ) ;//:true|/amp/
}
function hpp_if_access_hostv1() {
  $v1 = hw_config('server_cache');
  return $v1 && !(isset($_SERVER['HTTP_X_VARNISH']) || isset($_SERVER['HTTP_X_UA_DEVICE']) || in_array($_SERVER['HTTP_USER_AGENT'], ['pc','mobile']));
  // isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], $v1.'.')===0 && (!isset($_SERVER['HTTP_REFERER']) || substr($_SERVER['HTTP_HOST'],strlen($v1)+1)!= parse_url($_SERVER['HTTP_REFERER'],PHP_URL_HOST));
}
//validate string encoding
function hqp_fix_encoding($str) {
  //$tmp = iconv('CP1257',"UTF-8", $str);if($tmp!='') return $tmp;
  $str = MMR_Encoding::removeBOM(hqp_clean_bom($str));
  $str = hqp_remove_latin($str);
  return $str;
}

function hqp_clean_bom($string) {
    $string = str_replace(['�','¦','▒','','•'], '', $string);
    $string = preg_replace('#[ ​​]+​#', '', trim($string)); //special space? we copy this char from translate string
    return $string;
}
//mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')
function hqp_str2utf8($str) {
    $str = MMR_Encoding::removeBOM(hqp_clean_bom($str));
    if(!hqp_has_latin($str)) return $str;

    $arr=preg_split('#[\s]+#', $str);$c=0;
    foreach($arr as $i=> $w) {
        if(hqp_has_latin($w)) {
            if(mb_detect_encoding($w)) $arr[$i]=mb_convert_encoding($w, "LATIN1", "auto");
            else $arr[$i]=MMR_Encoding::fixUTF8($w);
            
            $c=1;
        }
    }
    if($c) $str = join(' ', $arr);
    //$str = str_replace(['�'], 'ã', $str);
    return $str;
}
function hqp_has_latin($str) {
    foreach(hqp_latin_characters() as $c=> $c1) {
        if(strpos($str, $c)!==false) {
            #_setArray('latin_characters', $c);
            #hqp_print("* latin: $c\n");
            return true;
        }
    }
}
function hqp_remove_latin($str) {
  return str_replace(array_keys(hqp_latin_characters()), '', $str);
}
function hqp_latin_characters() {
    $chars = array(
        ''=> '',
        #'»'=>'',
        #'»'=>'',
        '§'=>'',
            // Decompositions for Latin-1 Supplement
            'ª' => 'a',
            'º' => 'o',
            /*'À' => 'A',
            'Á' => 'A',
            'Â' => 'A',
            'Ã' => 'A',*/
            'Ä' => 'A',
            'Å' => 'A',
            'Æ' => 'AE',
            'Ç' => 'C',
            /*'È' => 'E',
            'É' => 'E',
            'Ê' => 'E',*/
            'Ë' => 'E',
            /*'Ì' => 'I',
            'Í' => 'I',*/
            'Î' => 'I',
            'Ï' => 'I',
            #'Ð' => 'D',
            'Ñ' => 'N',
            /*'Ò' => 'O',
            'Ó' => 'O',
            'Ô' => 'O',
            'Õ' => 'O',*/
            'Ö' => 'O',
            /*'Ù' => 'U',
            'Ú' => 'U',*/
            'Û' => 'U',
            'Ü' => 'U',
            #'Ý' => 'Y',
            'Þ' => 'TH',
            'ß' => 's',
            /*'à' => 'a',
            'á' => 'a',
            'â' => 'a',
            'ã' => 'a',*/
            'ä' => 'a',
            'å' => 'a',
            'æ' => 'ae',
            'ç' => 'c',
            #'è' => 'e',
            #'é' => 'e',
            #'ê' => 'e',
            'ë' => 'e',
            #'ì' => 'i',
            #'í' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ð' => 'd',
            'ñ' => 'n',
            #'ò' => 'o',
            #'ó' => 'o',
            #'ô' => 'o',
            #'õ' => 'o',
            'ö' => 'o',
            'ø' => 'o',
            #'ù' => 'u',
            #'ú' => 'u',
            'û' => 'u',
            'ü' => 'u',
            #'ý' => 'y',
            'þ' => 'th',
            'ÿ' => 'y',
            'Ø' => 'O',
            // Decompositions for Latin Extended-A
            'Ā' => 'A',
            'ā' => 'a',
            #'Ă' => 'A',
            #'ă' => 'a',
            'Ą' => 'A',
            'ą' => 'a',
            'Ć' => 'C',
            'ć' => 'c',
            'Ĉ' => 'C',
            'ĉ' => 'c',
            'Ċ' => 'C',
            'ċ' => 'c',
            'Č' => 'C',
            'č' => 'c',
            'Ď' => 'D',
            'ď' => 'd',
            #'Đ' => 'D',
            #'đ' => 'd',
            'Ē' => 'E',
            'ē' => 'e',
            'Ĕ' => 'E',
            'ĕ' => 'e',
            'Ė' => 'E',
            'ė' => 'e',
            'Ę' => 'E',
            'ę' => 'e',
            'Ě' => 'E',
            'ě' => 'e',
            'Ĝ' => 'G',
            'ĝ' => 'g',
            'Ğ' => 'G',
            'ğ' => 'g',
            'Ġ' => 'G',
            'ġ' => 'g',
            'Ģ' => 'G',
            'ģ' => 'g',
            'Ĥ' => 'H',
            'ĥ' => 'h',
            'Ħ' => 'H',
            'ħ' => 'h',
            /*'Ĩ' => 'I',
            'ĩ' => 'i',*/
            'Ī' => 'I',
            'ī' => 'i',
            'Ĭ' => 'I',
            'ĭ' => 'i',
            'Į' => 'I',
            'į' => 'i',
            'İ' => 'I',
            'ı' => 'i',
            'Ĳ' => 'IJ',
            'ĳ' => 'ij',
            'Ĵ' => 'J',
            'ĵ' => 'j',
            'Ķ' => 'K',
            'ķ' => 'k',
            'ĸ' => 'k',
            'Ĺ' => 'L',
            'ĺ' => 'l',
            'Ļ' => 'L',
            'ļ' => 'l',
            'Ľ' => 'L',
            'ľ' => 'l',
            'Ŀ' => 'L',
            'ŀ' => 'l',
            'Ł' => 'L',
            'ł' => 'l',
            'Ń' => 'N',
            'ń' => 'n',
            'Ņ' => 'N',
            'ņ' => 'n',
            'Ň' => 'N',
            'ň' => 'n',
            'ŉ' => 'n',
            'Ŋ' => 'N',
            'ŋ' => 'n',
            'Ō' => 'O',
            'ō' => 'o',
            'Ŏ' => 'O',
            'ŏ' => 'o',
            'Ő' => 'O',
            'ő' => 'o',
            'Œ' => 'OE',
            'œ' => 'oe',
            'Ŕ' => 'R',
            'ŕ' => 'r',
            'Ŗ' => 'R',
            'ŗ' => 'r',
            'Ř' => 'R',
            'ř' => 'r',
            'Ś' => 'S',
            'ś' => 's',
            'Ŝ' => 'S',
            'ŝ' => 's',
            'Ş' => 'S',
            'ş' => 's',
            'Š' => 'S',
            'š' => 's',
            'Ţ' => 'T',
            'ţ' => 't',
            'Ť' => 'T',
            'ť' => 't',
            'Ŧ' => 'T',
            'ŧ' => 't',
            'Ũ' => 'U',
            'ũ' => 'u',
            'Ū' => 'U',
            'ū' => 'u',
            'Ŭ' => 'U',
            'ŭ' => 'u',
            'Ů' => 'U',
            'ů' => 'u',
            'Ű' => 'U',
            'ű' => 'u',
            'Ų' => 'U',
            'ų' => 'u',
            'Ŵ' => 'W',
            'ŵ' => 'w',
            'Ŷ' => 'Y',
            'ŷ' => 'y',
            'Ÿ' => 'Y',
            'Ź' => 'Z',
            'ź' => 'z',
            'Ż' => 'Z',
            'ż' => 'z',
            'Ž' => 'Z',
            'ž' => 'z',
            'ſ' => 's',
        );
    return $chars;
}
function hpp_is_url($l) {
  return filter_var($l, FILTER_VALIDATE_URL) && (strpos($l, 'http://')!==false || strpos($l, 'https://')!==false || strpos($l, '//')===0);
}
function hpp_fix_resource_url($path) {
  $sm='';
  if(strpos($path, 'http://')==0) {$path = substr($path, strlen('http://'));$sm='http://';}
  else if(strpos($path, 'https://')==0) {$path = substr($path, strlen('https://'));$sm='https://';}
  else if(strpos($path, '//')==0) {$path = substr($path, strlen('//'));$sm='//';}
  if($sm) $path = $sm.preg_replace('#\/{2,}#','/', $path);
  else $path = preg_replace('#\/{2,}#','/', $path);
  return $path;
}
//use wp_remote_get instead
function hpp_curl_get($url, $opts = array() ,$refresh_cookie = false, $code=0){
  if(isset($opts[CURLOPT_HTTPHEADER])) {
    $opts[CURLOPT_HTTPHEADER] = [
      'User-Agent: '.hpp_user_agent()
    ];
  }
     $ch = curl_init($url);//hpp_fix_resource_url($url)
     curl_setopt($ch, CURLOPT_URL, $url);
     curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
     curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
     //curl_setopt($ch, CURLOPT_HTTPHEADER, array( 'Authorization: Client-ID ' . client_id ));
     curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
     curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_REFERER, home_url('/')); //important for embed youtube
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 540); //timeout in seconds

     if(is_array($opts) && count($opts)) curl_setopt_array($ch, $opts);
     //cookie
     if($refresh_cookie) {
          curl_setopt($ch, CURLOPT_COOKIESESSION, true);
     }
    
     $resp = curl_exec($ch);
     if($code && curl_getinfo($ch, CURLINFO_HTTP_CODE)!=200) {
      $resp = '';#echo "[error({$code}) fetch {$url}]";
     }
     curl_close($ch);
     return $resp;
}
function hpp_curl_post($url, $opts = array(), $data = array()) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_URL, $url);    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_USERAGENT, hpp_user_agent());
    curl_setopt($ch, CURLOPT_POST, TRUE);
    //if(count($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data)? http_build_query($data): $data);
    //}
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 540);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

    if(is_array($opts) && count($opts)) curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
     curl_close($ch);
     return $resp;
}

function hpp_user_agent(){
  $agents=array(
    'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.2 (KHTML, like Gecko) Chrome/22.0.1216.0 Safari/537.2',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/57.0.2987.133 Safari/537.36',
    'Mozilla/1.22 (compatible; MSIE 10.0; Windows 3.1)',
    'Mozilla/5.0 (Windows NT 6.3; Trident/7.0; rv:11.0) like Gecko',
    'Opera/9.80 (Windows NT 6.0) Presto/2.12.388 Version/12.14',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_9_3) AppleWebKit/537.75.14 (KHTML, like Gecko) Version/7.0.3 Safari/7046A194A'
  );

    $chose=rand(0,5);
    return $agents[$chose];
}
function hpp_randstr($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyz';//ABCDEFGHIJKLMNOPQRSTUVWXYZ
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}
//http://codepad.org/mMGJc733
function hpp_strip_js_comment($output) {
  $pattern = '/(?:(?:\/\*(?:[^*]|(?:\*+[^*\/]))*\*+\/)|(?:(?<!\:|\\\|\')\/\/.*))/';
  return preg_replace($pattern, '', $output);
}

function hpp_getClientIP() {
  if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
      $ip = $_SERVER['HTTP_CLIENT_IP'];
  } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
  } elseif(isset($_SERVER['REMOTE_ADDR'])) {
      $ip = $_SERVER['REMOTE_ADDR'];
  }
  else $ip='127.0.0.1';
  return $ip;
}
//WP_DEBUG=1
function hpp_write_log ( $log )  {
    if ( is_array( $log ) || is_object( $log ) ) {
       $log = print_r( $log, true ) ;
    }
    if(0&& defined('WP_DEBUG') && WP_DEBUG) error_log( $log );
    else {
      $f = WP_CONTENT_DIR . '/debug.log';
      if(is_file($f) && number_format(filesize($f) / 1048576, 2)>=5) {
        fclose(fopen($f,'w'));
      }
      else if(is_file($f)) {
        if(strpos(file_get_contents($f), $log)!==false) return ;
      }
      $fh = fopen($f, 'a+');      
      fwrite($fh, '['.gmdate('Y-m-d H:i:s').'][w2p] '.$log."\n");
      fclose($fh);
    }
}
function hpp_defer_attr($value) {
  //md5()
  return hw_config('defer_js') && wp_is_mobile()? hpp_randstr(10).'.'.$value: $value;
}
