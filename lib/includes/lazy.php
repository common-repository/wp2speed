<?php

/**
 *@class
*/
class HPP_Lazy 
{
  private $buffer;
  
  private $htmlCommentTokens = array();

  public function __construct() {
    $this->buffer = new HPP_OutputBuffer();
    //shortcode
    add_shortcode('load_dynamic_by_js', array($this, 'load_dynamic_by_js'));
    
    $this->_setup_hooks();
  }

  function _setup_hooks() {
    //detect template name for critical css
    add_filter('template_include', array($this, 'template_include'), PHP_INT_MAX);

    add_action('wp_head', array($this, 'hpp_print_head'), 0);
    add_action('wp_head', array($this, 'hpp_print_critical'), apply_filters('hpp_print_critical_priority',5) );
    #add_action('wp_head', array($this, 'hpp_print_head_end'), PHP_INT_MAX);
    #add_action('wp_footer', array($this,'hpp_print_footer'),0);
    add_action('wp_footer', array($this,'hpp_print_footer_end'),PHP_INT_MAX);

    add_action('wp_print_scripts', array($this, 'hpp_print_scripts'),0);
    add_action('admin_enqueue_scripts', array($this, 'hpp_print_scripts'),0);
    add_action('wp_enqueue_scripts', array($this, 'enqueue_asset'),PHP_INT_MAX);
    //wp_ajax_hpp_dyna_content,hpp_dyna_content
    #add_action("wp_ajax_hpp_generate_css", array($this, "ajax_generate_css"));
    #add_action("wp_ajax_nopriv_hpp_generate_css", array($this, "ajax_generate_css"));
    
    add_filter('get_image_tag_class', array($this, 'add_image_class'), PHP_INT_MAX);
    add_filter( 'wp_get_attachment_image_attributes', array($this, 'filter_get_attachment_image_attributes'), PHP_INT_MAX, 3);
    #add_filter('wp_get_attachment_image', array($this, 'wp_get_attachment_image'), PHP_INT_MAX, 5);
    add_filter('wp_get_attachment_image_src', array($this, 'wp_get_attachment_image_src'), PHP_INT_MAX, 4);
    add_filter( 'wp_kses_allowed_html', array( $this, 'add_lazy_load_attributes' ) );

    if(1||is_dir(WP_PLUGIN_DIR.'/wp2speed')) {
      add_filter('script_loader_tag', array($this, 'script_loader_tag'), PHP_INT_MAX,4);
      add_filter('style_loader_tag', array($this,'style_loader_tag'), PHP_INT_MAX,4);
      
      add_filter('option_mmr-http2push-css', function($v) {return 1;});
      add_filter('option_mmr-http2push-js', function($v) {return 1;});
      add_filter('option_mmr-outputbuffering', function($v) {return 0;});
      add_filter('option_mmr-gzip', function($v) {return 0;});
      add_filter('option_mmr-nocheckcssimports', function($v) {return 0;});

      add_filter( 'should_mmr', 'hpp_shouldlazy');
    }

    add_action('init', array($this, '_init'), PHP_INT_MAX);  //init, wp_loaded
    add_action('admin_init', array($this, 'admin_init'));
    add_filter('hpp_prepare_output', array($this, 'wordpress_prepare_output'), PHP_INT_MAX,1);
    add_filter('hpp_lazycss', array($this, 'lazy_css'));
    /**
     * post content: still lazy because lazy in buffer html is not full & huge performance
    */
    add_filter('the_content', 'hpp_defer_media',PHP_INT_MAX); //hpp_defer_content
    add_filter('the_excerpt', 'hpp_defer_media',PHP_INT_MAX);
    if(hw_config('logo_base64')) add_filter('get_custom_logo', 'hpp_defer_img_b64', PHP_INT_MAX,2);  //some hosting not support: hpp_image2base64
    add_filter('get_avatar', 'hpp_defer_imgs');
    #add_filter('widget_text', 'hpp_defer_content', PHP_INT_MAX); //filter in sidebar

    add_filter('walker_nav_menu_start_el', 'hpp_defer_imgs',PHP_INT_MAX);
    #add_filter( 'alloptions', array($this, '_all_options'), PHP_INT_MAX);  //heavy no need, scan in whole html
    /*
     * embed video
    */
    add_filter( 'wp_video_shortcode', 'hpp_lazy_video', 10);
    add_filter('oembed_result', array($this,'oembed_result'), 10, 3);

    //add_filter( 'auto_update_plugin', '__return_false',PHP_INT_MAX );
    //add_filter( 'auto_update_theme', '__return_false', PHP_INT_MAX);
    
    //widget
    add_action( 'dynamic_sidebar_before', array( $this, 'filter_sidebar_content_start' ), 0 );
    add_action( 'dynamic_sidebar_after', array( $this, 'filter_sidebar_content_end' ), PHP_INT_MAX );

    $this->_plugin_hooks();
    
    ;
  }

  function _init() {
    //fix
    hpp_reorder_hooks(0, 'wp_head', 'hpp_print_head', 0);
    #hpp_reorder_hooks(PHP_INT_MAX, 'wp_head', 'hpp_print_head_end', PHP_INT_MAX);
    #hpp_reorder_hooks(0, 'wp_footer', 'hpp_print_footer', 0);
    hpp_reorder_hooks(PHP_INT_MAX, 'wp_footer', 'hpp_print_footer_end', PHP_INT_MAX);
    hpp_reorder_hooks(0, 'wp_print_scripts', 'hpp_print_scripts', 0);
    hpp_reorder_hooks(0, 'admin_enqueue_scripts', 'hpp_print_scripts', 0);
  }

  function _plugin_hooks() {
    /**
     * woocommerce
    */
    //add_filter('wc_get_template_part', array($this,'wc_get_template_part'),PHP_INT_MAX);
    add_filter('woocommerce_single_product_image_html', array($this, 'woocommerce_single_product_image_html'), PHP_INT_MAX, 2);
    add_filter('woocommerce_single_product_image_thumbnail_html', array($this,'woocommerce_single_product_image_thumbnail_html'), PHP_INT_MAX, 2);
    //add_filter('woocommerce_product_get_image', array($this, 'woocommerce_product_get_image'), PHP_INT_MAX, 6);
    add_filter('woocommerce_short_description', 'hpp_defer_media');
    add_filter('acf/format_value', array($this, 'acf_load_value'), PHP_INT_MAX, 3);
  }

  public function tokenizeHtmlComments($matches) {

        $index = count($this->htmlCommentTokens);

        $this->htmlCommentTokens[$index] = $matches[0];

        return '<!--TOKEN' . $index . '-->';
  }
  public function restoreHtmlComments($matches) {
      return $this->htmlCommentTokens[$matches[1]];
  }
  function fix_script($js) {
    if(strpos($js, '<!--TOKEN')!==false) {
      preg_match_all('#<!--TOKEN(\d+)-->#s', $js, $m);
      foreach($m[0] as $i=>$s) {
        if(isset($this->htmlCommentTokens[$m[1][$i]]) ) {
          $str = trim($this->htmlCommentTokens[$m[1][$i]]);
          if(hpp_startsWith( $str, '<!--')) {
            $str = substr($str, 4);
            if(hpp_endsWith( $str, '-->')) $str = preg_replace('#(\/{2,})?-->$#','',$str);//substr($str,0, -3)."\n";
            $js = str_replace($s, $str, $js);
          }
        }
      }
      #file_put_contents('g:/tmp/10.txt', $js);
    }
    return $js;
  }

  /**
    fix for inject in <head tag by plugin nextend-smart-slider3-pro
  */
  function wordpress_prepare_output($buffer){
    /*if(strpos($buffer, md5('hw-attr-src'))!==false) {
      $buffer = str_replace(' '.md5('hw-attr-data-src').'=', ' data-src=', $buffer);
      $buffer = str_replace(' '.md5('hw-attr-src').'=', ' src=', $buffer);
    }*/

    if(!hpp_shouldlazy()) return $buffer;
    global $wp2speed;
    //file_get_contents(ABSPATH.'/1.txt')."\n".
    $my_asset = explode('_HWIO.extra_assets=_HWIO.assign(_HWIO.assets,', $buffer);if(!isset($my_asset[1])) return $buffer;
    $my_asset = explode('}});', $my_asset[1]);
    $my_asset = str_replace('\/', '/',$my_asset[0]).'}}';
    preg_match_all('#"hpp-s-\d+"#s', $my_asset, $m);
    $last_css_id = trim(str_replace(',"hpp-s-', '', $m[0][count($m[0])-1]),'"');if(!is_numeric($last_css_id)) $last_css_id=20;
    //fix
    $inline = ['js'=>[],'css'=>[],'_js'=>[]];$new_assets = [];#$extra_assets = json_decode($my_asset,true); #file_put_contents(ABSPATH.'/1.txt',json_encode($extra_assets));
    $merged = $wp2speed->get_processed_scripts(); 
    $merged['js']['log'] = join("\n",$merged['js']['log']);$merged['css']['log'] = join("\n",$merged['css']['log']);
    $buffer = apply_filters('hpp_prepare_buffer_html', $buffer, $merged);
    $parts = preg_split('/<\/head[\s]*>/i', $buffer, 2);
    if (count($parts) < 2) return $buffer;
    #$head_0 = $parts[0];$head = apply_filters('hpp_prepare_output_html', $head_0, $buffer);apply_filters('hpp_cache_url',WP_CONTENT_URL.'/mmr/')
    $path_me = trim(str_replace([WP_SITEURL,'http://','https://'],'', MMR_CACHE_URL),'/');  //if exist mean processed before
    #$footer = explode('_HWIO.extra_assets=', $buffer);$footer=$footer[1];
    #$dt=[];$head = hpp_treat_tag( $head, $dt);
    // strpos($parts[0],'<style id="critical-css"')!==false?'<style id="critical-css" ': '<meta name="critical-css-name" '
    $head = explode( '{{hpp_critical}}',$parts[0]);$scripts = [];
    if(strpos($head[0], '<script')!==false) {
      $head_0 = preg_replace_callback('#<script(((?!>).)*)?>(.*?)<\/script>#si', function($m) use(&$scripts){
        $scripts[] = $m[0];
        return '';
      }, $head[0]);
      
      $buffer = str_replace($parts[0], str_replace($head[0], $head_0, $parts[0]), $buffer);
      /*if(strpos($head[0], '<head>')===false) {
        preg_match('#<head .*?>#', $head[0],$m);
        $head = explode($m[0], $head[0]);
      }
      else $head = explode('<head>', $head[0]); #file_put_contents('g:/tmp/1.txt', $head[1]);
      $buffer = str_replace($parts[0], str_replace($head[1], "\n", $parts[0])."\n".$head[1], $buffer);
      */
    }
    #$buffer = str_replace('<style id="critical-css" name=""></style>','', $buffer);
    $buffer = str_replace('{{hpp_critical}}', $GLOBALS['hpp-head-critical'].$GLOBALS['hpp-head-js'].join('',$scripts), $buffer);

    /**
     * We must tokenize the HTML comments in the head to prepare for condition CSS/scripts
     * Eg.: <!--[if lt IE 9]><link rel='stylesheet' href='ie8.css?ver=1.0' type='text/css' media='all' /> <![endif]-->
     */
    $buffer = preg_replace_callback('/<!--.*?-->/s', array(
      $this,
      'tokenizeHtmlComments'
    ), $buffer);

    //fix syntax in noscript
    #$noscripts = [];
    
    //prevent limit: |<noscript>|<\/noscript>, array_pop($chunk);#if huge content in tag style, script (no need noscript) will break to this tag when use preg_match_all: |<script>|<script |</script>
    $chunk = preg_split('#(<style>|<style |<\/style>)#si', $buffer, -1,PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);$tg1=$tg='';
    foreach($chunk as $tag) {
      if(strpos($tag, '<style')===0) {$tg='style';$tg1=$tag;continue;}
      #if(strpos($tag, '<script')===0) {$tg='script';$tg1=$tag;continue;}
      #if(strpos($tag, '<noscript>')===0) {$tg='noscript';continue;}
      if(in_array($tg, ['style','script'])) {
        $m4 = hpp_endsWith($tg1,'>')? '':substr($tag,0,strpos($tag,'>'));
        $m6 = $m4? substr($tag, strlen($m4)+1): $tag;
      }
      //script @deprecated: may <script tag in js it' sophiticate
      if(0&& $tg=='script' && !hpp_in_str($m4,hpp_var('script-type'))) {
        if(strpos($m4, ' src=')!==false && strpos($m4,$path_me)===false) {
          $tag = '<script '.$tag.'</script>';
          $att = hpp_dom_attr($tag, [], 'script');
          if(1||strpos($my_asset, $att['src'])===false) {
            if(empty($att['id'])) $att['id'] = md5($att['src']);else if(hpp_endsWith($att['id'],'-js')) $att['id'] = substr($att['id'],0,-3);
            
            if(strpos($merged['js']['log'],$att['src'] )!==false) $buffer = str_replace($tag, '', $buffer);
            elseif(apply_filters('hpp_allow_delay_asset', true, $att['src'])) {
              $new_assets[$att['id']] = array_merge(['t'=>'js','l'=> $att['src'],'extra'=>1], hpp_array_exclude_keys(apply_filters('hpp_delay_asset_att', array_merge($att,['l'=>$att['src']]), 'js'),['src','id','type']));
              $buffer = str_replace($tag, '', $buffer);
            }
            else $buffer = str_replace($tag, HPP_Lazy::defer_asset_html($tag, 'js'), $buffer);
          }
          
        }
        else if(strpos($m4, ' src=')===false && trim($m6)!='' && !hpp_in_str($m6, ['var _HWIO=','_HWIO.extra_assets='])) {
          if( apply_filters('hpp_allow_readyjs', true, $m6)) {
            $js = hpp_fix_script_variables($m6, 1);
            if(!hpp_in_str($m6, ['_HWIO.readyjs('])) {
              $open = !apply_filters('hpp_delay_it_script', false, $js)? '_HWIO.readyjs(function(){': '_HWIO.readyjs(null,function(){';
              $close = '})';
              if($m6 != $js && !hpp_in_str($js,hpp_var('heavy-js',[]))) {
                $open = hw_config_val('debug', 1, "/*{$open}*/",'');$close = hw_config_val('debug', 1, "/*{$close}*/",'');
                $js = $m6;  //use origin
              }
              $js = $this->fix_script($js);
              /*else*/ $js = "{$open}{$js}{$close}";
            }
          }
          else $js = $m6;
          
            try{$js = apply_filters('hpp_inline_script', $wp2speed->fixOtherJS($js) );}catch(Exception $e){$js = $wp2speed->fixOtherJS($js);hpp_write_log($e->getMessage());}
            if($js!=$m6) $buffer = str_replace($tg1.$m4.($m4?'>':'').$m6.'</script>', $tg1.$m4.($m4?'>':'').$js.'</script>', $buffer);
          
        }
      }
      //style
      if($tg=='style' && strpos($tag,'media="not all"')===false && trim($m6)) {
        //to custom, no need media='not all'
        try{$css = hpp_fix_stylesheet(apply_filters('hpp_inline_style', $m6) );}catch(Exception $e){$css = hpp_fix_stylesheet($m6);hpp_write_log($e->getMessage());}
        if($css != $m6) {
          $buffer = str_replace($tg1.$m4.($m4?'>':'').$m6.'</style>', $tg1.$m4.($m4?'>':'').$css.'</style>', $buffer);
        }
      }
      if($tg) {$tg='';} //clear
    }
    //scan other asset & fix syntax in noscript: this tag should not huge content vs <style
    preg_match_all('#<link(((?!>).)* )rel=(.*?)>|<script(((?!>).)*)?>(.*?)<\/script>|<noscript>(.*?)<\/noscript>#si', $buffer, $m);/*<script(((?!>).)*)?>(.*?)<\/script>|<style(((?!>).)*)?>(.*?)<\/style>|*/
    /*foreach($m[0] as $i=>$tag) {
      //script
      if( stripos($tag, '</script>')!==false && !hpp_in_str($m[4][$i],hpp_var('script-type') )) {
        if(strpos($m[4][$i], ' src=')===false && trim($m[6][$i])!='' && !hpp_in_str($m[6][$i], ['var _HWIO=','_HWIO.extra_assets='])) {
          ;
        }
      }
    }*/
    foreach($m[0] as $i=>$tag) {
      //if(hpp_in_str($l, ['<link','</script>']) && strpos($l,$path_me)===false) break;
      //stylesheet
      if(stripos($tag, '<link ')!==false && stripos($tag,'<noscript>')===false && hpp_in_str($tag, ['stylesheet','text/css']) && strpos($tag,$path_me)===false) {
        //hpp_add_new_extra_asset($tag, 'css', $new_assets);
        $att = hpp_dom_attr($tag, [], 'link');
        if(!empty($att['href'])) {
          if(empty($att['id'])) $att['id'] = md5($att['href']);else if(hpp_endsWith($att['id'],'-css')) $att['id'] = substr($att['id'],0,-4);  //basename($att['href'],'.css') >no, should be unique ID by md5
          if(!isset($att['media'])) $att['media'] = 'all';

          if(strpos($merged['css']['log'],$att['href'] )!==false) $buffer = str_replace($tag, '', $buffer);
          elseif(apply_filters('hpp_allow_delay_asset', true, $att['href'])) {
            $last_css_id++;
            $new_assets['hpp-s-'.$last_css_id] = array_merge(['t'=>'css','l'=> $att['href'],'_id'=>$att['id'],'extra'=>1], hpp_array_exclude_keys(apply_filters('hpp_delay_asset_att', array_merge($att,['l'=>$att['href']]),'css'),['href','rel','id']));
            $buffer = str_replace($tag, '', $buffer);
          }
          else $buffer = str_replace($tag, HPP_Lazy::defer_asset_html($tag, 'css'), $buffer);
        }
      }

      //script
      if( stripos($tag, '</script>')!==false && !hpp_in_str($m[4][$i],hpp_var('script-type') )) {
        if(strpos($m[4][$i], ' src=')!==false && strpos($m[4][$i],$path_me)===false) {
          $att = hpp_dom_attr($tag, [], 'script');
          if( 1||strpos($my_asset, $att['src'])===false) {// !empty($att['src']) &&
            if(empty($att['id'])) $att['id'] = md5($att['src']);else if(hpp_endsWith($att['id'],'-js')) $att['id'] = substr($att['id'],0,-3);//else $att['id'].'-js'
            
            if(strpos($merged['js']['log'],$att['src'] )!==false) $buffer = str_replace($tag, '', $buffer);
            elseif(apply_filters('hpp_allow_delay_asset', true, $att['src'])) {
              #if(isset($new_assets[$att['id']])) $att['id'].= '-js';
              $new_assets[$att['id']] = array_merge(['t'=>'js','l'=> $att['src'],'extra'=>1], hpp_array_exclude_keys(apply_filters('hpp_delay_asset_att', array_merge($att,['l'=>$att['src']]), 'js'),['src','id','type']));
              $buffer = str_replace($tag, '', $buffer);
            }
            else $buffer = str_replace($tag, HPP_Lazy::defer_asset_html($tag, 'js'), $buffer);
          }
          
        }
        else if(strpos($m[4][$i], ' src=')===false && trim($m[6][$i])!='' && !hpp_in_str($m[6][$i], ['var _HWIO=','_HWIO.extra_assets='])) {
          if(apply_filters('hpp_allow_merge_inline', true, $m[6][$i], 'js')) {
            try{$js = apply_filters('hpp_inline_script',$wp2speed->fixOtherJS($m[6][$i]));}catch(Exception $e){$js = $wp2speed->fixOtherJS($m[6][$i]);hpp_write_log($e->getMessage());}
            $inline['js'][] = hw_config_val('test', 1, '/*[script'.$m[4][$i].']*/',''). 'try{'.$js.'}catch(e){console.log(e)}';
            $buffer = str_replace('<script'.$m[4][$i].'>'.$m[6][$i].'</script>', '', $buffer);
          }
          else {
          if( apply_filters('hpp_allow_readyjs', true, $m[6][$i])) {
            $js = hpp_fix_script_variables($m[6][$i] );#$js = $fix['js'];//$m[6][$i];
            //$js = !hpp_in_str($js, ['_HWIO.readyjs('])? '_HWIO.readyjs(function(){'.$m[6][$i].'});' : $m[6][$i];
            if(!hpp_in_str($m[6][$i], ['_HWIO.readyjs(']) ) {
              $open = !apply_filters('hpp_delay_it_script', false, $js)? '_HWIO.readyjs(function(){': '_HWIO.readyjs(null,function(){';
              $close = '})';
              if($m[6][$i]!= $js && !hpp_in_str($js,hpp_var('heavy-js',[]))) {
                $open = hw_config_val('debug', 1, "/*{$open}*/",'');$close = hw_config_val('debug', 1, "/*{$close}*/",'');
                $js = $m[6][$i];  //use origin
              }
              $js = $this->fix_script($js);
              $js = "{$open}{$js}{$close}"; // else
              #$fix['readyjs'] = [$open, $close];
            }
            //to extend modify, no need full as hpp_delay_assets
            #$js = apply_filters('hpp_inline_script', $wp2speed->fixOtherJS($js) );
            #if($js!=$m[6][$i]) $buffer = str_replace($m[6][$i], $js, $buffer);
            #$fix['tag']= '<script'.$m[4][$i].'>';
            #$inline['_js'][$i] = $fix;
          }
          else {
            $js = $m[6][$i];            
          }
            try{$js = apply_filters('hpp_inline_script', $wp2speed->fixOtherJS($js) );}catch(Exception $e){hpp_write_log($e->getMessage());$js = $wp2speed->fixOtherJS($js);}//$js = apply_filters('hpp_inline_script', $m[6][$i]);
            if(hw_config('defer_js') || $js!=$m[6][$i]) {
              $att = $m[4][$i];
              if(hw_config('defer_js') && strpos($att,'application/ld+json')===false && wp_is_mobile() ) {
                if(strpos($att, 'text/javascript')!==false) $att=str_replace('text/javascript', hpp_randstr(10).'.text/javascript', $att);
                else $att.= ' type="'.hpp_randstr(10).'.text/javascript"'; $att=' '.trim($att);
              }
              $buffer = str_replace('<script'.$m[4][$i].'>'.$m[6][$i].'</script>', '<script'.$att.'>'.$js.'</script>', $buffer);
              #$buffer = str_replace('<script'.$m[4][$i].'>'.$m[6][$i].'</script>', '{{w2p-script'.$i.'}}', $buffer);
            }
            #$inline['_js']['{{w2p-script'.$i.'}}'] = '<script'.$m[4][$i].'>'.$js.'</script>'; //md5($js)          
          }
        }
      }
      
      //style
      if(0&& stripos($tag, '</style>')!==false && strpos($tag,'media="not all"')===false && trim($m[9][$i])/*&& strpos($tag, ' id="critical-css"')===false*/) {
        //to custom, no need media='not all'
        $css = hpp_fix_stylesheet(apply_filters('hpp_inline_style', $m[9][$i]) );
        if($css != $m[9][$i]) {
          #if(hw_config('debug')) $buffer = str_replace($tag, str_replace('<style', '<style data-parse="1"',$tag), $buffer);
          $buffer = str_replace($m[9][$i], $css, $buffer);
        }
      }

      //noscript
      if(stripos($tag, '</noscript>')!==false) {
        $noscript_0 = $noscript_1 = $m[7][$i]; //$m[10][$i]
        if((substr_count($noscript_0, '<link ')>1 || hpp_in_str($noscript_0, ['<link','<style'],1))) {

        /* <script(((?!>).)*)?>(.*?)<\/script>| */
        preg_match_all('#<link(((?!>).)* )rel=(.*?)>|<style(((?!>).)*)?>(.*?)<\/style>#si', $noscript_0, $m1);
        #file_put_contents(ABSPATH.'/1.txt',file_get_contents(ABSPATH.'/1.txt')."\n".print_r($m1,1));
        if(count($m1[0])>1) {
          foreach($m1[0] as $_tag) {
            //## stylesheet
            if(stripos($_tag, '<link ')!==false) {
              #preg_match('#href=(\'|")(.*?)(\'|")#s', $_tag, $m2);
              $att = hpp_dom_attr($_tag, [], 'link');
              //strpos($att['rel'], 'stylesheet')!==false &&
              if( strpos($my_asset, $att['href'])===false) {  //if not exist in delay asset
                if(!isset($att['id'])) $att['id'] = md5($att['href']);
                if(!isset($att['media'])) $att['media'] = 'all';

                if(strpos($merged['css']['log'],$att['href'] )!==false) $noscript_1 = str_replace($_tag, '', $noscript_1);
                elseif(apply_filters('hpp_allow_delay_asset', true, $att['href'])) {
                  $last_css_id++;
                  $new_assets['hpp-s-'.$last_css_id] = array_merge(['t'=>'css','l'=> $att['href'],'_id'=>$att['id'],'extra'=>1], hpp_array_exclude_keys(apply_filters('hpp_delay_asset_att', array_merge($att,['l'=>$att['href']]),'css'),['href','rel','id']));

                  $noscript_1 = str_replace($_tag, '', $noscript_1);
                }
                else $noscript_1 = str_replace($_tag, HPP_Lazy::defer_asset_html($_tag, 'css'), $noscript_1);
              }
              else if(strpos($noscript_1, '</noscript>')===false) {
                $noscript_1 = str_replace($_tag, $_tag.'[/noscript]', $noscript_1); //for first
              }
              else $noscript_1 = str_replace($_tag, '', $noscript_1);/*else*/
            }

            //## style tag
            if(stripos($_tag, '</style>')!==false && stripos($m1[4][$i], ' media="not all"')===false) {
              try{$css = hpp_fix_stylesheet(apply_filters('hpp_inline_style', $m1[6][$i]));}catch(Exception $e){$css = hpp_fix_stylesheet($m1[6][$i]);hpp_write_log($e->getMessage());}
              #$_tag = str_replace(['<style ','<style'], '<style '.hpp_gen_critical_context('','media="not all"'), $_tag);  //simple way
              if($css != $m1[6][$i]) $noscript_1 = str_replace('<style'.$m1[4][$i].'>'.$m1[6][$i].'</style>', '<style'.$m1[4][$i].'>'.$css.'</style>', $noscript_1);
            }
            //not script tag in noscript tag
            /*if(strpos($_tag, '</script>')!==false) {}*/
          }

          if(strpos($noscript_1, '[/noscript]')!==false) {
            $buffer = str_replace($tag, str_replace('</noscript>','', $tag), $buffer);
            $noscript_1 = str_replace('[/noscript]', '</noscript>', $noscript_1);
          }
          $buffer = str_replace($noscript_0, $noscript_1, $buffer);
          #$noscripts["[noscript_{$i}]"] = $noscript_1;
          #$buffer = str_replace($noscript_0, "[noscript_{$i}]", $buffer);
        }
       }
      }
    }

    #$dt=[];$buffer = hpp_treat_tag( $buffer, $dt);
    //lazy media: PREG_JIT_STACKLIMIT_ERROR
    /*$tmp = hpp_defer_media($buffer);
    if(!$tmp  ) {//&& preg_last_error()==PREG_JIT_STACKLIMIT_ERROR
      foreach(str_split($buffer, 10000) as $s) {
        $buffer = str_replace($s,hpp_defer_media($s), $buffer);
      }
    }
    else $buffer = $tmp;*/
    $buffer = hpp_defer_media_large($buffer);

    //resume tag
    #foreach($noscripts as $k=> $v) $buffer = str_replace($k, $v, $buffer);
    #$buffer = hpp_treat_tag( $buffer, $dt);
    /**
     * Restore HTML comments
     */
    $buffer = preg_replace_callback('/<!--TOKEN([0-9]+)-->/', array(
      $this,
      'restoreHtmlComments'
    ), $buffer);

    if(!empty($GLOBALS['hpp-lazycss'])) {
      //$head .=
      $style = '<style '.hpp_gen_critical_context('','media="not all"').'>'.apply_filters('hpp_lazycss',$GLOBALS['hpp-lazycss']).'</style>';
      $buffer = str_replace('</head>', $style.'</head>', $buffer);
      $GLOBALS['hpp-lazycss']='';
    }
    //update <head
    #if($head !== $head_0) $buffer = str_replace($head_0, $head, $buffer);
    //inline js
    if(count($inline['js'])) {
      $ar = explode('<script id="hqjs"', $buffer);
      $buffer = $ar[0].'<script type="'.hpp_defer_attr('text/javascript').'">'.apply_filters('hpp_merge_inline', ''.join("\n",$inline['js']).'','js').'</script><script id="hqjs"'.$ar[1];
    }
    //@deprecated
    if( count($inline['_js'])) {
      $list=[];
      foreach($inline['_js'] as $i=> $it) {
        #$buffer = str_replace($tag, $js, $buffer);
        $list[] = $it['list'];
      }
      $r=hpp_find_duplicates($list);//array_keys();
      foreach($inline['_js'] as $i=> $it) {
        $js0 = $js = $it['js0'];
        foreach($it['list'] as $i => $name) {
          //detect share var across script tag
          if(isset($r[$name])) {
            $js = str_replace($js, $it['found'][$i][0], $it['found'][$i][1]);
          }
        }
        if($js0 != $js) str_replace();
      }
    }
    //update extra assets
    if(count($new_assets)) {
      $my_asset = str_replace('/', '\/', $my_asset);
      $ar=explode('custom.js",',$my_asset);$f='custom.js",'.array_pop($ar).');';
      //$my_asset.');'|'custom.js","deps":"hpp-0"}});'
      $buffer = str_replace($f, $f.'_HWIO.extra_assets=_HWIO.assign(_HWIO.extra_assets,'.json_encode(apply_filters('hpp_lazy_assets',$new_assets)).');', $buffer);
    }
    $buffer = apply_filters('hpp_after_buffer_html', $buffer);
    return $buffer;
  }

  function enqueue_asset() {
    if(hpp_shouldlazy()) wp_enqueue_style('hpp-style', HS_PLUGIN_URL.'/lib/asset/style.css');
  }
  function lazy_css($css) {
    global $wp2speed;
    return $wp2speed->fixCSS($css);
  }

  /**
   *@hook
  */
  function _all_options($alloptions) {
    //static $invoking;    
    //scan all options
    if(function_exists('is_user_logged_in') && !isset($GLOBALS['hpp-init-options'])) {
      $GLOBALS['hpp-init-options'] = 1;
      if(!hpp_shouldlazy()) return $alloptions;
      
      $defOpts = 'siteurl,';
      $keys = array_keys($alloptions);  #_print($keys);
      foreach($keys as $k) {
        if(!hpp_in_str($k, array('mmr-','_transient', 'action_scheduler', 'ActionScheduler')) ) {
          add_filter('option_'.$k, array($this,'hpp_fix_option_value'), PHP_INT_MAX, 2);
        }        
      }
      
      remove_filter('alloptions', array($this, __FUNCTION__));
    }
    
    return $alloptions;
  }

  /**
   *@hook
  */
  function hpp_fix_option_value($value, $option) {
    static $cache = array();
    if(isset($cache[$option])) return $cache[$option];

    /*if(is_array($value) || is_object($value)) {  #echo $option."<br>";return $value;
      array_walk_recursive($value, function(&$v) {
        if(is_string($v)) $v = hpp_defer_content( $v);
      });
      //$value = hpp_defer_content(json_encode($value));
      $cache[$option] = $value;//json_decode( $value, true);
    }
    else*/ $cache[$option] = hpp_defer_option( $value);

    return $cache[$option];
  }

  //@hook acf/load_value
  function acf_load_value($value, $post_id, $field){
    static $cache = [];
    $key = $post_id.$field['name'];
    if(isset($cache[$key])) return $cache[$key];

    $cache[$key] = hpp_defer_option($value);
    return $cache[$key];
  }

  function get_criticalcss_path() {
    static $file;
    if(!$file ) {
      global $blog_id;
      $id = (( is_multisite() && $blog_id > 1 ) ? $blog_id.'-':'');
      $upload_dir = wp_upload_dir();
      //critical css
      $file = $upload_dir['basedir'].'/critical-css/'.$id.$GLOBALS['hpp-tplname'];  
      #if(!empty($GLOBALS['hpp-tpl-extra'])) $critical_css.= '-'.$GLOBALS['hpp-tpl-extra'];die($critical_css);
      if(is_tax() || is_category() || is_tag()) $file.= '-'.get_queried_object()->taxonomy;
      else $file.= '-'.get_post_type();
      if(is_home()||is_front_page()) $file.= '-home';

      $GLOBALS['hpp-criticalname'] = basename($file);
      //by post url
      $uri = $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']; if(strpos($uri,'?')!==false) {$uri=explode('?',$uri);$uri=$uri[0];} #preg_replace('#\?.+#','',);
      if(hw_config('same_css_lang')) $uri = preg_replace('#\/('.apply_filters('hpp_url_langs','en|vi').')\/#', '/', $uri);
      #$file1 = WP_CONTENT_DIR.'/uploads/critical-css/'.md5($uri).'.css';      
      $suffix = array('');
      if(wp_is_mobile() ) array_unshift($suffix, '.mobile');  //@deprecated
      
      foreach($suffix as $sf){
        if(file_exists($file.'-'.md5($uri).$sf.'.css')) {
          $file .= '-'.md5($uri).$sf;
          if(file_exists($file.'-font.css')) $file_font = $file.'-font.css';
          $file .='.css';
          break;
        }
        elseif(file_exists($file.$sf.'.css')) {
          $file .= $sf;
          if(file_exists($file.'-font.css')) $file_font = $file.'-font.css';
          $file .='.css';
          break;
        }
      }
      //$file .= '.css';
      
      $GLOBALS['hpp-criticalname'] .= '-'.md5($uri);
      if(isset($file_font)) $GLOBALS['hpp-criticalfont'] = $file_font;

      $GLOBALS['hpp-criticalfile'] = $file = apply_filters(__FUNCTION__, $file, $uri); //['uri'=>, 'tpl'=>$file];|!$check || file_exists($file)? 
      //uncritical
      $main_css = $upload_dir['basedir'].'/critical-css/'.basename($file,'.css').'-main.css';
      if( file_exists($main_css)) $GLOBALS['hpp-uncritical']=$upload_dir['baseurl'].'/critical-css/'.basename($file,'.css').'-main.css';
    }

    return hpp_gen_critical_context('', $file);//!isset($_GET['hpp-gen-critical'])? $file: '';
  }

  /**
   *@shortcode
  */
  function load_dynamic_by_js($atts, $content=null) {
    $name = isset($atts['name'])? $atts['name']: '';
    //$file = isset($atts['tofile'])? (int)$atts['tofile']: 0;
    if($name) return load_dynamic_by_js($name, function() use($content){echo $content;});//, $file
  }

  /**
   * Make sure WordPress does not filter out img elements with lazy load attributes.
   *
   * @since 3.2.0
   *
   * @param array $allowedposttags  Allowed post tags.
   *
   * @return mixed
   */
  public function add_lazy_load_attributes( $allowedposttags ) {
    if ( ! isset( $allowedposttags['img'] ) ) {
      return $allowedposttags;
    }

    $attributes = array(
      'data-src'    => true,
      'data-srcset' => true,
    );

    $img_attributes = array_merge( $allowedposttags['img'], $attributes );

    $allowedposttags['img'] = $img_attributes;

    return $allowedposttags;
  }
  
  /**
   *@deprecated
  */
  function template_include($file){
    $GLOBALS['hpp-tplname']= basename($file, '.php');
    if(isset($_GET['hpp_next'])) {
      $file = dirname(__DIR__).'/template/tpl-dynamic-content.php';
    }
    #$this->get_criticalcss_path();  //pre-check
    return $file;
  }
  /*function wc_get_template_part($template ){
    if(!isset($GLOBALS['hpp-tpl-extra'])) $GLOBALS['hpp-tpl-extra'] = basename($template,'.php');
    
      return $template;
  }*/
  //@deprecated
  /*function hpp_print_head_end() {
    $out = ob_get_contents();ob_end_clean();echo hpp_delay_assets($out);
  }*/
  function hpp_print_critical() {
    if(hpp_shouldlazy()) echo '{{hpp_critical}}';
  }
  /**
   * or insert directly in header.php
   *@hook 
  */
  function hpp_print_head() {
    if(!hpp_shouldlazy()) return;#ob_start();
    //critical css
    $critical_css = $this->get_criticalcss_path(); 
    #$nooptiz = apply_filters('ignore_style_loader_src',false);
    //very first in head tag
    $hppdt =[ 'ajax_url'=>admin_url( 'admin-ajax.php' )];  //'cacheUrl'=> WP_CONTENT_URL.'/uploads',
    ob_start();
    if($critical_css && file_exists($critical_css) && hpp_shouldlazy()) {
      $css = file_get_contents($critical_css);  //hpp_fix_stylesheet(); do in hpp_delay_assets
      $GLOBALS['hpp-criticalfile'] = $critical_css;
      #if(!empty($GLOBALS['hpp-bgimg'])) $hppdt['bgimg'] = $GLOBALS['hpp-bgimg'];
      #foreach(apply_filters('hpp_preload_fonts',hpp_criticalcss_extract_fonts($css)) as $i=>$font_url) 
      # if($i<5 ) printf('<link rel="preload" as="font" href="%s" crossorigin>', $font_url);//&& stripos($font_url,$_SERVER['HTTP_HOST'])!==false
      /*$cls = hw_config('cls_selector');
      if(is_array($cls)) {  //base on critical context
        $k = join('-',array_slice(explode('-', $GLOBALS['hpp-criticalname']),0,-1));
        if(isset($cls[$k])) $cls = $cls[$k];else $cls='';
      }
      if($cls) $css = $cls.'{visibility:hidden!important}'. $css;*/
      do_action('print_critical_css', $css); 

      if(isset($GLOBALS['hpp-criticalfont'])) {
        echo '<style media="not all">'.file_get_contents($GLOBALS['hpp-criticalfont']).'</style>';
      }
      $attr = 'id="critical-css" name="'.$GLOBALS['hpp-criticalname'].(wp_is_mobile()? '.mobile':'').'"';
      if(strpos($GLOBALS['hpp-criticalname'], 'page-')!==false ) {
        $upload_dir = wp_upload_dir();
        $uniq = (!wp_is_mobile()? file_exists( $upload_dir['basedir'].'/critical-css/'.$GLOBALS['hpp-criticalname'].'.css') : file_exists( $upload_dir['basedir'].'/critical-css/'.$GLOBALS['hpp-criticalname'].'.mobile.css'));
        $attr.= ' data-unique="'.($uniq? 1:0).'"';
      }
      print_r('<style '.$attr.'>'.apply_filters('hpp_critical_css', $css, $critical_css).'</style>');   
    }
    else if(isset($GLOBALS['hpp-criticalname'])) {
      do_action('print_critical_css', ''); 
      printf('<meta name="critical-css-name" content="%s"/>', $GLOBALS['hpp-criticalname']);
    }
    $GLOBALS['hpp-head-critical'] = ob_get_clean();#echo '<style id="critical-css" name=""></style>';
    #echo $GLOBALS['hpp-head-critical'];
    //init js
    $js = file_get_contents(file_exists(dirname(__DIR__).'/asset/init.min.js')? dirname(__DIR__).'/asset/init.min.js': dirname(__DIR__).'/asset/init.js');
    if('lazy'!=hw_config('lazy_class')) $js = str_replace('"lazy"', '"'.hw_config('lazy_class').'"', $js);
    if(hw_config('test')) $js.= '_HWIO.data.__debug=1;';

    ob_start();do_action('hpp_print_initjs');$initjs = ob_get_clean(); //'.hpp_defer_attr('text/javascript').'
    if(hw_config('defer_js') && wp_is_mobile()) {
      //_HWIO.addEvent=function(obj,evt,fn){if(obj.addEventListener) {obj.addEventListener(evt, fn, { passive: false })}else if (obj.attachEvent){obj.attachEvent("on" + evt, fn)}};eval(e.textContent);
      $js.= '_HWIO._fn=function(){lazySizesConfig.init=0;document.querySelectorAll(\'script[type*=".text/javascript"]\').forEach(function(e){e.setAttribute("type", e.getAttribute("type").split(".")[1]);e.parentNode.replaceChild(e.cloneNode(true),e)});var e=document.getElementById("w2phq");e.setAttribute("src",e.getAttribute("data-src"));};_HWIO.addEvent(document, "mousemove", _HWIO._fn);_HWIO.addEvent(window, "scroll", _HWIO._fn);_HWIO.docReady(_HWIO._fn,1000);';
    }
    $GLOBALS['hpp-head-js'] = '<script type="text/javascript">'.$js.' _HWIO.ajax='.json_encode($hppdt).';'.$initjs.'</script>';
    #echo $GLOBALS['hpp-head-js'];
    ?>
  <?php #if(!empty($cls)) echo '_HWIO.docReady(function(){document.querySelectorAll("'.$cls.'").forEach(function(e){e.style.setProperty(\'visibility\',\'visible\',\'important\')})})';//window.dispatchEvent(new CustomEvent("resize")) ?>
    
    <?php
    
    //fix admin: is_user_logged_in() || apply_filters('ignore_script_loader_src',false)
    if(0&& !hpp_shouldlazy() ) {//_HWIO.__readyjs=1;
      echo '<script>_HWIO.__readyjs=1;_HWIO._addjs=function(t,e){if(_HWIO.data._script_||(_HWIO.data._script_={},document.querySelectorAll("script[src]").forEach(function(t){var e=t.getAttribute("src");e&&(_HWIO.data._script_[e]=1)})),_HWIO.data._script_[t])return console.log("%c exist js "+t,"color:red");var r,c,a,n,s;r=document,c="script",a=e||Math.random(),window,s=(s=r.getElementsByTagName(c))[s.length-1],r.getElementById(a)||((n=r.createElement(c)).id=a,n.async=0,n.src=t,s.parentNode.insertBefore(n,s))};';//_HWIO.readyjs=function(cb,c){try{if(typeof jQuery!="undefined")jQuery(document).ready(cb);else cb()}catch(e){console.log(e)}};_HWIO.readydoc=function(cb){};

      if(defined('WP_ROCKET_ASSETS_JS_URL')) {
          echo 'window.lazyLoadOptions={elements_selector:"img[data-lazy-src],.rocket-lazyload",data_src:"lazy-src",data_srcset:"lazy-srcset",data_sizes:"lazy-sizes",skip_invisible:!1,class_loading:"lazyloading",class_loaded:"lazyloaded",threshold:300,callback_load:function(a){"IFRAME"===a.tagName&&"fitvidscompatible"==a.dataset.rocketLazyload&&a.classList.contains("lazyloaded")&&void 0!==window.jQuery&&jQuery.fn.fitVids&&jQuery(a).parent().fitVids()}},window.addEventListener("LazyLoad::Initialized",function(a){var e=a.detail.instance;if(window.MutationObserver){new MutationObserver(function(a){a.forEach(function(a){a.addedNodes.forEach(function(a){"function"==typeof a.getElementsByTagName&&(imgs=a.getElementsByTagName("img"),iframes=a.getElementsByTagName("iframe"),rocket_lazy=a.getElementsByClassName("rocket-lazyload"),0===imgs.length&&0===iframes.length&&0===rocket_lazy.length||e.update())})})}),document.getElementsByTagName("body")[0];0}},!1);';

          echo ('_HWIO._addjs("'.WP_ROCKET_ASSETS_JS_URL.'/lazyload/12.0/lazyload.min.js", "rocket-lazyload");');  //11.0.6
        }
      //else {
        echo ('_HWIO._addjs("'.HS_PLUGIN_URL.'/lib/asset/js.js","lazysizes");');
      //}
      echo '</script>';
    }
    //debug cls
    if(isset($_GET['cls'])) {
      echo <<<EOF
      <script>
      !function(){var i,t,n=document.attachEvent,o=!1;if(!n){var e=(t=window.requestAnimationFrame||window.mozRequestAnimationFrame||window.webkitRequestAnimationFrame||function(e){return window.setTimeout(e,20)},function(e){return t(e)}),r=(i=window.cancelAnimationFrame||window.mozCancelAnimationFrame||window.webkitCancelAnimationFrame||window.clearTimeout,function(e){return i(e)});function a(e){var i=e.__resizeTriggers__,t=i.firstElementChild,r=i.lastElementChild,s=t.firstElementChild;r.scrollLeft=r.scrollWidth,r.scrollTop=r.scrollHeight,s.style.width=t.offsetWidth+1+"px",s.style.height=t.offsetHeight+1+"px",t.scrollLeft=t.scrollWidth,t.scrollTop=t.scrollHeight}function _(i){var t=this;a(this),this.__resizeRAF__&&r(this.__resizeRAF__),this.__resizeRAF__=e(function(){var e;(e=t).offsetWidth==e.__resizeLast__.width&&e.offsetHeight==e.__resizeLast__.height||(t.__resizeLast__.width=t.offsetWidth,t.__resizeLast__.height=t.offsetHeight,t.__resizeListeners__.forEach(function(e){e.call(t,i)}))})}var s=!1,d="",l="animationstart",c="Webkit Moz O ms".split(" "),m="webkitAnimationStart animationstart oAnimationStart MSAnimationStart".split(" "),g="",h=document.createElement("fakeelement");if(void 0!==h.style.animationName&&(s=!0),!1===s)for(var f=0;f<c.length;f++)if(void 0!==h.style[c[f]+"AnimationName"]){g=c[f],d="-"+g.toLowerCase()+"-",l=m[f],s=!0;break}var z="resizeanim",v="@"+d+"keyframes "+z+" { from { opacity: 0; } to { opacity: 0; } } ",u=d+"animation: 1ms "+z+"; "}window.addResizeListener=function(i,e){var t,r,s;n?i.attachEvent("onresize",e):(i.__resizeTriggers__||("static"==getComputedStyle(i).position&&(i.style.position="relative"),o||(t=(v||"")+".resize-triggers { "+(u||"")+'visibility: hidden; opacity: 0; } .resize-triggers, .resize-triggers > div, .contract-trigger:before { content: " "; display: block; position: absolute; top: 0; left: 0; height: 100%; width: 100%; overflow: hidden; } .resize-triggers > div { background: #eee; overflow: auto; } .contract-trigger:before { width: 200%; height: 200%; }',r=document.head||document.getElementsByTagName("head")[0],(s=document.createElement("style")).type="text/css",s.styleSheet?s.styleSheet.cssText=t:s.appendChild(document.createTextNode(t)),r.appendChild(s),o=!0),i.__resizeLast__={},i.__resizeListeners__=[],(i.__resizeTriggers__=document.createElement("div")).className="resize-triggers",i.__resizeTriggers__.innerHTML='<div class="expand-trigger"><div></div></div><div class="contract-trigger"></div>',i.appendChild(i.__resizeTriggers__),a(i),i.addEventListener("scroll",_,!0),l&&i.__resizeTriggers__.addEventListener(l,function(e){e.animationName==z&&a(i)})),i.__resizeListeners__.push(e))},window.removeResizeListener=function(e,i){n?e.detachEvent("onresize",i):(e.__resizeListeners__.splice(e.__resizeListeners__.indexOf(i),1),e.__resizeListeners__.length||(e.removeEventListener("scroll",_),e.__resizeTriggers__=!e.removeChild(e.__resizeTriggers__)))}}();
      addEventListener('load', () => {
        new PerformanceObserver(function(list){for (const entry of list.getEntries()) {console.log(entry.value,entry)}}).observe({type: 'layout-shift', buffered: true});
        /*var track=[];
  document.querySelectorAll('body *').forEach((el)=>{
    addResizeListener(el, function (e) {
      if(1 && track.indexOf(this)===-1) track.push(this);
      else console.log(this)
    });
  })*/
      })
</script>
EOF;

    }
  }
  //@deprecated
  /*function hpp_print_footer() {
    ob_start();
  }*/

  /**
   *@hook 
  */
  function hpp_print_footer_end(){
    #$out = ob_get_contents();ob_end_clean();
      /*if(!hpp_shouldlazy()) {
        echo '<script>_HWIO.__readyjs=1;</script>';
        return;
      }*/
      if(!isset($GLOBALS['hppjs'])) $GLOBALS['hppjs'] = array();
      $ajx = array();
      //@deprecated
      if(!empty($GLOBALS['hppjs']) ) { //!apply_filters('ignore_script_loader_src',false)
      
        $hppjs = $GLOBALS['hppjs'];$json = array();$refresh=0;
        
        if(is_singular()) {
          global $post;
          $dt = get_post_meta($post->ID, 'hpp_lazy', true);
          $refresh=(!$dt || !empty($_GET['hpp_sync']));
          if($refresh) update_post_meta($post->ID, 'hpp_lazy', hpp_serialize($hppjs));
          $json['post_id'] = $post->ID;
        }
        else if(is_tax() || is_category() || is_tag()) {
          $id = get_queried_object()->term_id;
          $dt = get_term_meta($id, 'hpp_lazy', 1);
          $refresh = !$dt || !empty($_GET['hpp_sync']);
          if($refresh) update_term_meta($id, 'hpp_lazy', hpp_serialize($hppjs));
          $json['term_id'] = $id;
        }
        else if(isset($_SERVER['REQUEST_URI'])) {
          $id = md5($_SERVER['REQUEST_URI']);
          $upload_dir = wp_upload_dir();
          $f = $upload_dir['basedir'].'/pages/'.$id.'.json';
          $refresh = !file_exists($f) || !empty($_GET['hpp_sync']);
          if($refresh) file_put_contents($f, hpp_serialize($hppjs));
          $json['id'] = $id;
        }
        $ajx['info'] = $json;
        #$text = '_HWIO.ajax.info='.json_encode($json).';';  //_HWIO.hppjs='.json_encode($json).';
        
        printf('<link rel="next" href="%s">', hpp_current_url().'?hpp_next=1&'.http_build_query($json));
      }
      #echo hpp_delay_assets($out,1);
      //echo hpp_delay_assets('',1);  //prevent not load wp_footer.php

      if(!empty($ajx)) echo "<script>_HWIO.ajax=_HWIO.assign(_HWIO.ajax,".json_encode($ajx).")</script>";  //_HWIO.readyjs(function(){      
      /*if($refresh) {
        //hpp_purge_cache();
      }*/
  }

  static function flush_dynamic_content($id, $type='post') {
    global $wpdb;
    if($type=='post') {
      $wpdb->update($wpdb->prefix.'postmeta',['meta_value'=>''], ['meta_key'=>'hpp_lazy', 'post_id'=>$id]);//update_post_meta($id, 'hpp_lazy', '');
    }
    if($type=='term') {
      $wpdb->update($wpdb->prefix.'termmeta',['meta_value'=>''], ['meta_key'=>'hpp_lazy', 'term_id'=>$id]);
    }
    hpp_purge_cache();
  }

  /**
   *@hook admin
  */
  function hpp_print_scripts() {
    static $fired=0;return;
    if((!is_admin() && !in_array($GLOBALS['pagenow'], array('wp-login.php', 'wp-register.php'))) || $fired)return ;$fired=1;
    echo '<style id="hqcss-admin">'.file_get_contents(HS_LIB_DIR.'/asset/admin.css').'</style>';//( 'hpp-admin', HS_PLUGIN_URL.'/' );

    echo <<<EOF
  <script>var _HWIO={
    readyjs: function(cb,cb1){try{(typeof cb=='function'?cb:cb1)()}catch(e){console.log(e)}},
    docReady:function(cb){cb()}
  };
EOF;

    printf('setTimeout(function(){
        (function(d, s, id,w) {
        var js, fjs = d.getElementsByTagName(s)[0];
        if (d.getElementById(id)) return;
        js = d.createElement(s); js.id = id;js.async = 0;
        js.src = "%s";
        fjs.parentNode.insertBefore(js, fjs);
        }(document, "script", "lazysizes", window));
        },1000);</script>', HS_PLUGIN_URL.'/lib/asset/js.js');
  }

  /**
   *@ajax @deprecated
  */
  function hpp_dyna_content() {
    $dt = '';
    if(isset($_GET['term_id'])) {
      $dt = get_term_meta($_GET['term_id'], 'hpp_lazy',true);
    }
    elseif(isset($_GET['post_id'])) {
      $dt = get_post_meta($_GET['post_id'], 'hpp_lazy', true);
      
    }
    else if(isset($_GET['id'])) {
      $upload_dir = wp_upload_dir();
      $file = $upload_dir['basedir'].'/pages/'.$_GET['id'].'.json';
      $dt = file_exists($file)? file_get_contents($file): '';
    }
    
    $dt = $dt? hpp_unserialize($dt): [];

    wp_send_json_success($dt);die();
  }
  
  /**
    Layzyload images
  */
  function add_image_class($class){
    $lazyclass = hw_config('lazy_class');
      if(hpp_shouldlazyload() && strpos($class, $lazyclass)===false) $class = ' '.$lazyclass.' '.$class;//.= ' lazy';
      return $class;
  }
  //@deprecated
  function wp_get_attachment_image($html, $attachment_id, $size, $icon, $attr ) {
    #_print($html);die;
    return $html;
  }
  function wp_get_attachment_image_src($image, $attachment_id, $size, $icon ) {
    $this->attachments[$attachment_id] = $image;
    return $image;
  }
  private $attachments = [];
  /**
    Remove srcset attr, since we use 1-2 image
  */
  // Clean the up the image from wp_get_attachment_image()
  function filter_get_attachment_image_attributes( $attr, $attachment, $size)
  {
      if(!hpp_shouldlazyload() || apply_filters('hpp_disallow_lazyload_attr',false, $attr)) return $attr; //because we insert libs.js for lazyload in admin
      //if( $size_w!==null) $size_w=get_option('medium_size_w');
      //$transparent_srcset = !empty($attr['srcset']);
      $transparent_srcset = 0;

      /*if(!$size_w) {
        if( isset( $attr['sizes'] ) )
          unset( $attr['sizes'] );

        if( isset( $attr['srcset'] ) )
            unset( $attr['srcset'] );
      }*/
      $base64 = apply_filters('hpp_defer_src_holder', ';base64,');
      $class = hw_config('lazy_class');
      #if(!isset($attr['itemprop'])) $attr['itemprop'] = 'image';
      #if(empty($attr['alt'])) $attr['alt'] = ' ';

      //lazyload
      if(!isset($attr['class'])) $attr['class']='';
      if(strpos($attr['class'], ' '.$class.' ')===false) {
        //$attr['class'].= $attr['class']? ' lazy': 'lazy';
        $attr['class'] = ' '.$class.' '.$attr['class'];
      }
      //note: with LQIP (Low Quality Image Placeholder) can use `src`
      if(isset($attr['src']) && strpos($attr['src'], $base64)===false && strpos($attr['src'],';base64,')===false) $src = $attr['src'];
      elseif( isset($attr['data-src']) ) $src = $attr['data-src'];
      else return $attr;
      #_print($this->attachments[$attachment->ID]);die;
      $w = isset($this->attachments[$attachment->ID][1])? $this->attachments[$attachment->ID][1]: 0;
      $h = isset($this->attachments[$attachment->ID][2])? $this->attachments[$attachment->ID][2]: 0;

      $attr['src']= hpp_b64holder('"', $w, $h); //'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
      $attr['data-src'] = $src;
/*
      $attr[md5('hw-attr-src')]='data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
      if(!isset($attr['data-src']) ) { //&& !$transparent_srcset
        // keep ori attr because other can override
        $attr[md5('hw-attr-data-src')] = $attr['src'];      //'data-src'        
      }
      else {
        $attr[md5('hw-attr-data-src')] = $attr['data-src'];
      }
      if(!hw_config('lazy_fix') && isset($attr['src'])) unset($attr['src']);  //unknown why, but don't remove src attr because some theme need present src attr
      if(isset($attr['data-src'])) unset($attr['data-src']);
*/
      if(isset($attr['srcset'])) {  //srcset
        $attr['data-srcset'] = $attr['srcset'];
        
        //if($transparent_srcset) {
          //comment for bur style effect without black image as bellow -> wrong
          //transparent srcset: will lazy load without loading `src`, if crash lazy > fallback to `src`
          #$attr['srcset'] = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
        //}
        //else 
          unset($attr['srcset']);
      }

      if(isset($attr['sizes'])) { //sizes
        $attr['data-sizes'] = $attr['sizes'];
        unset($attr['sizes']);
      }
      else if(isset($attr['data-srcset'])) $attr['data-sizes']='auto';

      return $attr;

  }

  /**
   *@hook admin_init
  */
  function admin_init(){
    if((isset($_GET['action']) && in_array($_GET['action'], array('purge_cache')))
      || (isset($_GET['page']) && $_GET['page'] == 'wpsupercache' && !empty($_GET['wp_delete_cache']))  //supercache
      || (isset($_GET['page']) && $_GET['page']=='w3tc_dashboard' && isset($_GET['w3tc_flush_all']) ) //w3t
    ) {
      hpp_purge_cache();
    }
  }

  /**
   *@hook 
  */
  function oembed_result($iframe_html, $video_url, $frame_attributes){
    return hpp_lazy_video($iframe_html, 2);
  }

  /**
   *@hook 
  */
  function woocommerce_single_product_image_html($img, $post_id){
    if(!hpp_shouldlazyload()) return $img;
    //$img = str_replace('data-src=""','', $img); //fixed in hpp_defer_img
    return hpp_defer_img($img);
  }

  /**
   *@hook 
  */
  function woocommerce_single_product_image_thumbnail_html($html, $post_thumbnail_id ){
    if(!hpp_shouldlazyload()) return $html;
    return hpp_defer_img($html);
  }

  /**
   *@hook 
  */
  /*function woocommerce_product_get_image($image, $obj, $size, $attr, $placeholder, $image_=null) {
    if( ( !$image || stripos($image, 'woocommerce-placeholder.png')!==false)) {
      $thumb =  get_post_meta( $obj->post->ID, '_thumbnail_ext_url', TRUE );
      $class = hw_config('lazy_class');
      if(hpp_shouldlazyload() ) {
        $image = ('<img alt="'.(!empty($obj->post->post_title)? esc_attr($obj->post->post_title) :' ').'" class=" '.$class.' " src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" data-src="'.$thumb.'" />');
      }else if(is_admin()){
        $screen    = get_current_screen();
        if($screen->base == 'post' && $screen->post_type == 'shop_order') {
          $image = '<img src="'.$thumb.'" data-src="'.$thumb.'" class="'.$class.'"/>';  //htmlspecialchars($image);alt=" " 
        }
      }
    }
    return $image;
  }*/

  /**
   *@hook 
  */
  function script_loader_tag($tag, $handle, $src) {
    if(!hpp_shouldlazy() ) return $tag; #|| apply_filters('ignore_script_loader_src',false)
      //external url: never occur since we use _delay_asset()
    /*if((strpos($src, 'http://')!==false || strpos($src, 'https://')!==false) && strpos($src, $_SERVER['HTTP_HOST'])===false) {
      $tag = '<script>_HWIO.readyjs(function(){_HWIO._addjs("'.$src.'");})</script>';
      return $tag;
    }*/
      if(strpos($src, '/mmr/')!==false) {
        return '<script id="w2phq" '.(hw_config('defer_js') && wp_is_mobile()? 'data-src':'src').'="' . $src . '" async defer ></script>' . "\n";  //type="text/javascript"
        //'<link rel="preload" as="script" href="' . $src . '">';
      }
      if(strpos($src, ' defer ')===false && apply_filters('hpp_allow_async_js', true, $tag)) {
        $tag = str_replace(' src=', ' async defer src=', $tag); //self::defer_asset_html( $tag, 'js');
      }
      return $tag;
  }
  /**
   *@hook 
  */
  function style_loader_tag($tag, $handle, $src, $media){
    if(!hpp_shouldlazy() ) return $tag; #|| apply_filters('ignore_style_loader_src',false)
      $css_file = $this->get_criticalcss_path();
    if( !file_exists($css_file)) {
      /*preg_match_all('#<link(((?!>).)* )rel=(.*?)>|<style(((?!>).)*)?>(.*?)<\/style>#si', $tag, $m);*/
      //modern browser:this.media='all'
      $_tag = "<noscript>{$tag}</noscript>";    
      $tag = self::defer_asset_html( $tag, 'css');
      
      //old way, don't use
      #return "<link rel=\"stylesheet\" href=\"$src\" media=\"nope!\" onload=\"this.media='all'\">";
      $tag .= $_tag;
      
    }
    else $tag = "<noscript>{$tag}</noscript>";  //since we use new way
    
    return $tag;
  }
  public static function defer_asset_html($tag, $tp) {
    if($tp=='css') {
      if(strpos($tag, ' as=')===false) $tag = str_replace('<link ', '<link as="style" onload="this.rel=\'stylesheet\';" ', $tag);
      #$tag = "<link rel=\"stylesheet preload prefetch\" href=\"{$src}\" as=\"style\" onload=\"this.rel='stylesheet';\">";
      if(strpos($tag, 'stylesheet preload')===false ) $tag = preg_replace('# rel=(\'|")(.+?)(\'|")#', ' rel="stylesheet preload prefetch"',$tag);
    }
    if($tp=='js' && apply_filters('hpp_allow_async_js', true, $tag)) {
      if(strpos($tag, ' async')===false) $tag = str_replace(' src=', ' async src=', $tag);
      if(strpos($tag, ' defer')===false) $tag = str_replace(' src=', ' defer src=', $tag);
    }
    return apply_filters('hpp_defer_html_tag', $tag, $tp);
  }

  /**
   * Buffer sidebar content.
   * @deprecated still use
   * @since 3.2.0
   */
  public function filter_sidebar_content_start() {
    ob_start();
  }

  /**
   * Process buffered content.
   * @deprecated still use
   * @since 3.2.0
   */
  public function filter_sidebar_content_end() {
    $content = ob_get_clean();

    echo hpp_defer_content( $content );

    unset( $content );
  }
}


/*if(get_option('medium_size_w')=='0') :  

// Override the calculated image sizes
add_filter( 'wp_calculate_image_sizes', '__return_empty_array',  PHP_INT_MAX );

// Override the calculated image sources
add_filter( 'wp_calculate_image_srcset', '__return_empty_array', PHP_INT_MAX );

// Remove the reponsive stuff from the content
remove_filter( 'the_content', 'wp_make_content_images_responsive' );

add_filter( 'wp_calculate_image_srcset_meta', '__return_empty_array' );

endif;*/
if(!is_admin()) new HPP_Lazy;