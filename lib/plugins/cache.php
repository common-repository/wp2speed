<?php
/**
 *@class
 https://wordpress.org/plugins/edge-cache-html-_-workers/
*/
class HPP_Cache {
    /**
     * List of URLs to be purged
     *
     * (default value: array())
     *
     * @var array
     * @access protected
     */
    protected $purge_urls = array();

	#https://github.com/_/worker-examples/blob/master/examples/edge-cache-html/WordPress%20Plugin/cloudflare-page-cache/cloudflare-page-cache.php
	function __construct() {
		$this->define_hooks();
	}

	function define_hooks() {
		/*
        add_action('wp_trash_post', array($this, 'update_post'), 0);
        add_action('publish_post', array($this, 'update_post'), 0);
        add_action('edit_post', array($this, 'update_post'), 0);
        add_action('delete_post', array($this, 'update_post'), 0);
        add_action('publish_phone', array($this, '_page_cache_purge1'), 0);
        // Coment ID is received
        add_action('trackback_post', array($this, '_page_cache_purge2'), 99);
        add_action('pingback_post', array($this, '_page_cache_purge2'), 99);
        add_action('comment_post', array($this, '_page_cache_purge2'), 99);
        add_action('edit_comment', array($this, '_page_cache_purge2'), 99);
        add_action('wp_set_comment_status', array($this, '_page_cache_purge2'), 99, 2);
        
        //term
        add_action('edit_term', array($this, 'edit_term'));

        // No post_id is available
        add_action('switch_theme', array($this, '_page_cache_purge1'), 99);
        add_action('edit_user_profile_update', array($this, '_page_cache_purge1'), 99);
        add_action('wp_update_nav_menu', array($this, '_page_cache_purge0'));
        add_action('clean_post_cache', array($this, '_page_cache_purge1'));
        add_action('transition_post_status', array($this, '_page_cache_post_transition'), 10, 3);
*/
        //purge cache
	    #add_action('hpp_purgeall', 'hpp_purge_cache');
        add_action('option_blog_public', array($this, 'opt_blog_public'), PHP_INT_MAX);
        add_action('template_redirect', array($this, 'exclude_pages_from_caching'));

	    add_action('init', array($this, 'init'));
	}
    //https://docs.wp-rocket.me/article/494-how-to-clear-cache-via-cron-job
    static function flush_cache($post_id=null) {
        if($post_id) {
            //rocket
            if(function_exists('rocket_clean_post')) rocket_clean_post($post_id);   //defined('WP_ROCKET_VERSION') 
        }
        else {
            //rocket: clear the cache for the whole site
            if ( function_exists( 'rocket_clean_domain' ) ) {
                rocket_clean_domain();
            }
            hpp_purge_cache();
        }
    }
    /**
     * Registered Events
     * These are when the purge is triggered
     *
     * @since 1.0
     * @access protected
     */
    protected function get_register_events() {

        // Define registered purge events.
        $actions = array(
            'delete_attachment',              // Delete an attachment - includes re-uploading.
            
            'save_post',                      // Save a post.
            'edit_post',                      // Edit a post - includes leaving comments.
            'wp_trash_post',
            'deleted_post',                   // Delete a post.
            'delete_post',
            'trashed_post',                   // Empty Trashed post.
            //'comment_post',

            /*'import_start',                   // When importer starts
            'import_end',                     // When importer ends
            
            'switch_theme',                   // After a theme is changed.
            */
        );

        // send back the actions array, filtered.
        // @param array $actions the actions that trigger the purge event.
        return apply_filters( 'varnish_http_purge_events', $actions );
    }
    
	/*function update_post($id) {
		static $done = false;if ($done) return;$done = true;
		
		HPP_Lazy::flush_dynamic_content($id, 'post');
		$this->purge_cache();
	}

	function edit_term($id) {
		static $done = false;if ($done) return;$done = true;

		HPP_Lazy::flush_dynamic_content($id, 'term');
		$this->purge_cache();
	}*/

    // Callbacks that something changed
    function init()
    {
        global $blog_id, $wp_db_version;
        //static $done = false;if ($done) return;        $done = true;
        if(0&& hpp_if_access_hostv1()) {
            wp_redirect(home_url(), 301);exit;            
        }
        // If the DB version we detect isn't the same as the version core thinks we will fush DB cache. This may cause double dumping in some cases but
        // should not be harmful.
        if ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) && (int) get_option( 'db_version' ) !== $wp_db_version ) {
            wp_cache_flush();
        }

        // Add the edge-cache headers
        if (is_user_logged_in()) {
            header('x-HTML-Edge-Cache: nocache');
            #header('x-HTML-Edge-Cache: cache,bypass-cookies=wp-|wordpress|comment_|woocommerce_');
        } 

        // get my events.
        $events       = $this->get_register_events();

        // make sure we have events and they're in an array.
        if ( ! empty( $events ) ) {
            // Add the action for each event.
            foreach ( (array) $events as $event ) {
                add_action( $event, array( $this, 'purge_post' ), 10, 2 );                
            }
        }

        add_action( 'shutdown', array( $this, 'execute_purge' ) );

        // Success: Admin notice when purging.
        if ( ( isset( $_GET['hpp_flush_all'] ) && check_admin_referer( 'hpp-flush-all' ) ) ||
            ( isset( $_GET['hpp_flush_do'] ) && check_admin_referer( 'hpp-flush-do' ) ) ) {
            
            add_action( 'admin_notices', array( $this, 'admin_message_purge' ) );            
        }

        // Add Admin Bar.
        if(hw_config('server_cache')) add_action( 'admin_bar_menu', array( $this, 'varnish_rightnow_adminbar' ), 100 );
    }

    /**
     * Purge Message
     * Informs of a succcessful purge
     *
     * @since 4.6
     */
    public function admin_message_purge() {
        echo '<div id="message" class="notice notice-success fade is-dismissible"><p><strong>' . esc_html__( 'Server cache emptied!' ) . '</strong></p></div>';
    }

    /**
     * Purge Button in the Admin Bar
     *
     * @access public
     * @param mixed $admin_bar - data passed back from admin bar.
     * @return void
     */
    public function varnish_rightnow_adminbar( $admin_bar ) {
        global $wp, $blog_id;

        $can_purge    = false;
        // translators: %s is the state of cache.
        $cache_titled = __( 'Server Cache' );

        if ( ( ! is_admin() && get_post() !== false && current_user_can( 'edit_published_posts' ) ) || current_user_can( 'activate_plugins' ) ) {
            // Main Array.
            $args      = array(
                array(
                    'id'    => 'purge-varnish-cache',
                    'title' => '<span class="ab-icon" style="background-image: url(' . self::get_icon_svg() . ') !important;"></span><span class="ab-label">' . $cache_titled . '</span>',
                    'meta'  => array(
                        'class' => 'varnish-http-purge',
                    ),
                ),
            );
            $can_purge = true;
        }

        // Checking user permissions for who can and cannot use the all flush.
        if (
            // SingleSite - admins can always purge.
            ( ! is_multisite() && current_user_can( 'activate_plugins' ) ) ||
            // Multisite - Network Admin can always purge.
            current_user_can( 'manage_network' ) ||
            // Multisite - Site admins can purge UNLESS it's a subfolder install and we're on site #1.
            ( is_multisite() && current_user_can( 'activate_plugins' ) && ( SUBDOMAIN_INSTALL || ( ! SUBDOMAIN_INSTALL && ( BLOG_ID_CURRENT_SITE !== $blog_id ) ) ) )
            ) {

            $args[] = array(
                'parent' => 'purge-varnish-cache',
                'id'     => 'purge-varnish-cache-all',
                'title'  => __( 'Purge Cache (All Pages)'),
                'href'   => wp_nonce_url( add_query_arg( 'hpp_flush_do', 'all' ), 'hpp-flush-do' ),
                'meta'   => array(
                    'title' => __( 'Purge Cache (All Pages)'),
                ),
            );

            // If a memcached file is found, we can do this too.
            if ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
                $args[] = array(
                    'parent' => 'purge-varnish-cache',
                    'id'     => 'purge-varnish-cache-db',
                    'title'  => __( 'Purge Database Cache'),
                    'href'   => wp_nonce_url( add_query_arg( 'hpp_flush_do', 'object' ), 'hpp-flush-do' ),
                    'meta'   => array(
                        'title' => __( 'Purge Database Cache'),
                    ),
                );
            }

            // If we're on a front end page and the current user can edit published posts, then they can do this.
            if ( ! is_admin() && get_post() !== false && current_user_can( 'edit_published_posts' ) ) {
                $page_url = esc_url( $this->the_home_url( $wp->request ) );
                $args[]   = array(
                    'parent' => 'purge-varnish-cache',
                    'id'     => 'purge-varnish-cache-this',
                    'title'  => __( 'Purge Cache (This Page)'),
                    'href'   => wp_nonce_url( add_query_arg( 'hpp_flush_do', $page_url . '/' ), 'hpp-flush-do' ),
                    'meta'   => array(
                        'title' => __( 'Purge Cache (This Page)'),
                    ),
                );
            }

            // If Devmode is in the config, don't allow it to be disabled.
            
            // Populate enable/disable cache button.

            /*$args[] = array(
                'parent' => 'purge-varnish-cache',
                'id'     => 'purge-varnish-cache-devmode',
                'title'  => 'Restart Cache',
                'href'   => wp_nonce_url( add_query_arg( array('hpp_flush_do'=> 'devmode','hpp_set_devmode' => 'activate') ), 'hpp-flush-do' ),
                'meta'   => array(
                    'title' => 'Restart Cache',
                ),
            );
            $args[] = array(
                'parent' => 'purge-varnish-cache',
                'id'     => 'purge-varnish-cache-nodevmode',
                'title'  => 'Pause Cache (24h)',
                'href'   => wp_nonce_url( add_query_arg( array('hpp_flush_do'    => 'devmode','hpp_set_devmode' => 'dectivate') ), 'hpp-flush-do' ),
                'meta'   => array(
                    'title' => 'Pause Cache (24h)',
                ),
            );*/
            
        }

        if ( $can_purge ) {
            foreach ( $args as $arg ) {
                $admin_bar->add_node( $arg );
            }
        }
    }

    /**
     * Get the icon as SVG.
     *
     * Forked from Yoast SEO
     *
     * @access public
     * @param bool $base64 (default: true) - Use SVG, true/false?
     * @param string $icon_color - What color to use.
     * @return string
     */
    public static function get_icon_svg( $base64 = true, $icon_color = false ) {
        global $_wp_admin_css_colors;

        $fill = ( false !== $icon_color ) ? sanitize_hex_color( $icon_color ) : '#82878c';

        if ( is_admin() && false === $icon_color ) {
            $admin_colors  = json_decode( wp_json_encode( $_wp_admin_css_colors ), true );
            $current_color = get_user_option( 'admin_color' );
            $fill          = $admin_colors[ $current_color ]['icon_colors']['base'];
        }

        // Flat
        $svg = '<svg version="1.1" xmlns="http://www.w3.org/2000/svg" xml:space="preserve" width="100%" height="100%" style="fill:' . $fill . '" viewBox="0 0 36.2 34.39" role="img" aria-hidden="true" focusable="false"><g id="Layer_2" data-name="Layer 2"><g id="Layer_1-2" data-name="Layer 1"><path fill="' . $fill . '" d="M24.41,0H4L0,18.39H12.16v2a2,2,0,0,0,4.08,0v-2H24.1a8.8,8.8,0,0,1,4.09-1Z"/><path fill="' . $fill . '" d="M21.5,20.4H18.24a4,4,0,0,1-8.08,0v0H.2v8.68H19.61a9.15,9.15,0,0,1-.41-2.68A9,9,0,0,1,21.5,20.4Z"/><path fill="' . $fill . '" d="M28.7,33.85a7,7,0,1,1,7-7A7,7,0,0,1,28.7,33.85Zm-1.61-5.36h5V25.28H30.31v-3H27.09Z"/><path fill="' . $fill . '" d="M28.7,20.46a6.43,6.43,0,1,1-6.43,6.43,6.43,6.43,0,0,1,6.43-6.43M26.56,29h6.09V24.74H30.84V21.8H26.56V29m2.14-9.64a7.5,7.5,0,1,0,7.5,7.5,7.51,7.51,0,0,0-7.5-7.5ZM27.63,28V22.87h2.14v2.95h1.81V28Z"/></g></g></svg>';

        if ( $base64 ) {
            return 'data:image/svg+xml;base64,' . base64_encode( $svg );
        }

        return $svg;
    }

    // Add the response header to purge the cache. send_headers isn't always called
    // so set it immediately when something changes.
    function purge_cache()
    {
        static $purged = false;
        if (!$purged) {
            $purged = true;
            header('x-HTML-Edge-Cache: purgeall');
            #header('x-HTML-Edge-Cache: purge');
            do_action('hpp_purgeall');
        }
    }

    /**
     * Purge Post
     * Flush the post
     *
     * @since 1.0
     * @param array $post_id - The ID of the post to be purged.
     * @access public
     */
    public function purge_post( $post_id ) {
        static $check = [];
        if( isset($check[$post_id]) ) return ; $check[$post_id]=1;

        /**
         * Future Me: You may need this if you figure out how to use an array
         * further down with versions of WP and their json versions.
         * Maybe use global $wp_version;
         * If this is a valid post we want to purge the post,
         * the home page and any associated tags and categories
         */
        $valid_post_status = array( 'publish', 'private', 'trash' );
        $this_post_status  = get_post_status( $post_id );

        // Not all post types are created equal.
        $invalid_post_type   = array( 'nav_menu_item', 'revision' );
        $noarchive_post_type = array( 'post', 'page' );
        $this_post_type      = get_post_type( $post_id );

        /**
         * Determine the route for the rest API
         * This will need to be revisted if WP updates the version.
         * Future me: Consider an array? 4.7-?? use v2, and then adapt from there?
         */
        /*if ( version_compare( get_bloginfo( 'version' ), '4.7', '>=' ) ) {
            $rest_api_route = 'wp/v2';
        }*/

        // array to collect all our URLs.
        $listofurls = array();

        // Verify we have a permalink and that we're a valid post status and a not an invalid post type.
        if ( false !== get_permalink( $post_id ) && in_array( $this_post_status, $valid_post_status, true ) && ! in_array( $this_post_type, $invalid_post_type, true ) ) {

            // Post URL.
            array_push( $listofurls, get_permalink( $post_id ) );

            /**
             * JSON API Permalink for the post based on type
             * We only want to do this if the rest_base exists
             * But we apparently have to force it for posts and pages (seriously?)
             */
            /*if ( isset( $rest_api_route ) ) {
                $post_type_object = get_post_type_object( $post_id );
                $rest_permalink   = false;
                if ( isset( $post_type_object->rest_base ) ) {
                    $rest_permalink = get_rest_url() . $rest_api_route . '/' . $post_type_object->rest_base . '/' . $post_id . '/';
                } elseif ( 'post' === $this_post_type ) {
                    $rest_permalink = get_rest_url() . $rest_api_route . '/posts/' . $post_id . '/';
                } elseif ( 'page' === $this_post_type ) {
                    $rest_permalink = get_rest_url() . $rest_api_route . '/pages/' . $post_id . '/';
                }
            }

            if ( $rest_permalink ) {
                array_push( $listofurls, $rest_permalink );
            }*/

            // Add in AMP permalink for offical WP AMP plugin:
            // https://wordpress.org/plugins/amp/
            if ( function_exists( 'amp_get_permalink' ) ) {
                array_push( $listofurls, amp_get_permalink( $post_id ) );
            }

            // Regular AMP url for posts if ant of the following are active:
            // https://wordpress.org/plugins/accelerated-mobile-pages/
            if ( defined( 'AMPFORWP_AMP_QUERY_VAR' ) ) {
                array_push( $listofurls, get_permalink( $post_id ) . 'amp/' );
            }

            // Also clean URL for trashed post.
            if ( 'trash' === $this_post_status ) {
                $trashpost = get_permalink( $post_id );
                $trashpost = str_replace( '__trashed', '', $trashpost );
                array_push( $listofurls, $trashpost/*, $trashpost . 'feed/'*/ );
            }

            // Category purge based on Donnacha's work in WP Super Cache.
            $categories = get_the_category( $post_id );
            if ( $categories ) {
                foreach ( $categories as $cat ) {
                    array_push(
                        $listofurls,
                        get_category_link( $cat->term_id )
                        //get_rest_url() . $rest_api_route . '/categories/' . $cat->term_id . '/'
                    );
                }
            }

            // Tag purge based on Donnacha's work in WP Super Cache.
            $tags = get_the_tags( $post_id );
            if ( $tags ) {
                $tag_base = get_site_option( 'tag_base' );
                if ( '' === $tag_base ) {
                    $tag_base = '/tag/';
                }
                foreach ( $tags as $tag ) {
                    array_push(
                        $listofurls,
                        get_tag_link( $tag->term_id )
                        //get_rest_url() . $rest_api_route . $tag_base . $tag->term_id . '/'
                    );
                }
            }
            // Custom Taxonomies: Only show if the taxonomy is public.
            $taxonomies = get_post_taxonomies( $post_id );
            if ( $taxonomies ) {
                foreach ( $taxonomies as $taxonomy ) {
                    $features = (array) get_taxonomy( $taxonomy );
                    if ( $features['public'] ) {
                        $terms = wp_get_post_terms( $post_id, $taxonomy );
                        foreach ( $terms as $term ) {
                            array_push(
                                $listofurls,
                                get_term_link( $term )
                                //get_rest_url() . $rest_api_route . '/' . $term->taxonomy . '/' . $term->slug . '/'
                            );
                        }
                    }
                }
            }

            // If the post is a post, we have more things to flush
            // Pages and Woo Things don't need all this.
            if ( $this_post_type && 'post' === $this_post_type ) {
                // Author URLs:
                $author_id = get_post_field( 'post_author', $post_id );
                array_push(
                    $listofurls,
                    get_author_posts_url( $author_id )
                    //get_author_feed_link( $author_id )
                    //get_rest_url() . $rest_api_route . '/users/' . $author_id . '/'
                );

                // Feeds:
                /*array_push(
                    $listofurls,
                    get_bloginfo_rss( 'rdf_url' ),
                    get_bloginfo_rss( 'rss_url' ),
                    get_bloginfo_rss( 'rss2_url' ),
                    get_bloginfo_rss( 'atom_url' ),
                    get_bloginfo_rss( 'comments_rss2_url' ),
                    get_post_comments_feed_link( $post_id )
                );*/
            }

            // Archives and their feeds.
            if ( $this_post_type && ! in_array( $this_post_type, $noarchive_post_type, true ) ) {
                array_push(
                    $listofurls,
                    get_post_type_archive_link( get_post_type( $post_id ) )
                    //get_post_type_archive_feed_link( get_post_type( $post_id ) )
                    // Need to add in JSON?
                );
            }

            // Home Pages and (if used) posts page.
            array_push(
                $listofurls,
                //get_rest_url(),
                $this->the_home_url() . '/'
            );
            if ( 'page' === get_site_option( 'show_on_front' ) ) {
                // Ensure we have a page_for_posts setting to avoid empty URL.
                if ( get_site_option( 'page_for_posts' ) ) {
                    array_push( $listofurls, get_permalink( get_site_option( 'page_for_posts' ) ) );
                }
            }
        } else {
            // We're not sure how we got here, but bail instead of processing anything else.
            return;
        }

        // If the array isn't empty, proceed.
        if ( ! empty( $listofurls ) ) {
            // Strip off query variables
            foreach ( $listofurls as $url ) {
                $url = strtok( $url, '?' );
            }

            // Make sure each URL only gets purged once, eh?
            $purgeurls = array_unique( $listofurls, SORT_REGULAR );

            // Flush all the URLs
            foreach ( $purgeurls as $url ) {
                array_push( $this->purge_urls, $url );
            }
        }
//file_put_contents('g:/tmp/1.txt',print_r($this->purge_urls,1));
        /*
         * Filter to add or remove urls to the array of purged urls
         * @param array $purge_urls the urls (paths) to be purged
         * @param int $post_id the id of the new/edited post
         */
        $this->purge_urls = apply_filters( 'hpp_purge_urls', $this->purge_urls, $post_id );
    }

    public static function the_home_url() {
        $home_url = apply_filters( 'hpp_home_url', home_url() );
        return $home_url;
    }

    /**
     * Purge URL
     * Parse the URL for proxy proxies
     *
     * @since 1.0
     * @param array $url - The url to be purged.
     * @access protected
     */
    public static function purge_url( $url ) {
        $p = wp_parse_url( $url );

        // Bail early if there's no host since some plugins are weird.
        if ( ! isset( $p['host'] ) ) {
            return;
        }
        $pregex         = '';
        $x_purge_method = 'default';

        if ( isset( $p['query'] ) && ( 'hpp-regex' === $p['query'] ) ) {
            $pregex         = '.*';
            $x_purge_method = 'regex';
        }

        // Determine the path.
        $path = '';
        if ( isset( $p['path'] ) ) {
            $path = $p['path'];
        }

        $schema = apply_filters( 'varnish_http_purge_schema', (isset($p['scheme'])? $p['scheme']:'http' ).'://' );
        $host = $p['host'];
        $parsed_url = $url;
        
        /**
         * Allow setting of ports in host name
         * Credit: davidbarratt - https://github.com/Ipstenu/varnish-http-purge/pull/38/
         *
         * (default value: $p['host'])
         *
         * @var string
         * @access public
         * @since 4.4.0
         */
        $host_headers = $p['host'];
        if ( isset( $p['port'] ) ) {
            $host_headers .= ':' . $p['port'];
        }

        // Create path to purge.
        $purgeme = $schema . $host . $path . $pregex;

        // Check the queries...
        if ( ! empty( $p['query'] ) && 'hpp-regex' !== $p['query'] ) {
            $purgeme .= '?' . $p['query'];
        }

        $headers  = apply_filters(
            'varnish_http_purge_headers',
            array(
                'host'           => $host_headers,
                'X-Purge-Method' => $x_purge_method,
            )
        );
        $response = wp_remote_request(
            $purgeme,
            array(
                'method'  => 'PURGE',
                'headers' => $headers,
            )
        );

        do_action( 'after_purge_url', $parsed_url, $purgeme, $response, $headers );
    }

    function purge_all($url) {
        $p = wp_parse_url( $url );

        // Bail early if there's no host since some plugins are weird.
        if ( ! isset( $p['host'] ) ) {
            return;
        }
        $schema = (isset($p['scheme'])? $p['scheme']:'http' ).'://';
        $purgeme = $schema . $p['host'];
        
        $response = wp_remote_request(
            $purgeme,
            array(
                'method'  => 'CLEANFULLCACHE',
                'headers' => array(
                    'host'=> $p['host'].(isset($p['port'])? ':'.$p['port']:''),
                ),
            )
        );
        return $response;
    }
    /**
     * Execute Purge
     * Run the purge command for the URLs. Calls $this->purge_url for each URL
     *
     * @since 1.0
     * @access protected
     */
    public function execute_purge() {
        $purge_urls = array_unique( $this->purge_urls );//file_put_contents('g:/tmp/1.txt',file_get_contents('g:/tmp/1.txt'). print_r($purge_urls,1));

        if ( empty( $purge_urls ) && isset( $_GET ) ) {
            if ( isset( $_GET['hpp_flush_all'] ) && check_admin_referer( 'hpp-flush-all' ) ) {
                // Flush Cache recursize.
                #$this->purge_url( $this->the_home_url() . '/?hpp-regex' );
                $this->purge_all($this->the_home_url());

            } elseif ( isset( $_GET['hpp_flush_do'] ) && check_admin_referer( 'hpp-flush-do' ) ) {
                if ( 'object' === $_GET['hpp_flush_do'] ) {
                    // Flush Object Cache (with a double check).
                    if ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
                        wp_cache_flush();
                    }
                } elseif ( 'all' === $_GET['hpp_flush_do'] ) {
                    // Flush Cache recursize.
                    #$this->purge_url( $this->the_home_url() . '/?hpp-regex' );
                    $this->purge_all($this->the_home_url());

                } else {
                    // Flush the URL we're on.
                    $p = wp_parse_url( esc_url_raw( wp_unslash( $_GET['hpp_flush_do'] ) ) );
                    if ( ! isset( $p['host'] ) ) {
                        return;
                    }
                    $this->purge_url( esc_url_raw( wp_unslash( $_GET['hpp_flush_do'] ) ) );
                }
            }
        } else {
            foreach ( $purge_urls as $url ) {
                $this->purge_url( $url );
            }
        }
    }

    /*function _page_cache_purge0()
    {
        $this->purge_cache();
    }

    function _page_cache_purge1($param1)
    {
        $this->purge_cache();
    }

    function _page_cache_purge2($param1, $param2 = "")
    {
        $this->purge_cache();
    }

    function _page_cache_post_transition($new_status, $old_status, $post)
    {
        if ($new_status != $old_status) {
            $this->purge_cache();
        }
    }*/

    /**
     * Ensure browser (and Varnish) do not cache the following pages:
     * - Partial Gravity Form fill ("Save and Continue Later")
     * - Password-protected pages
     */
    function exclude_pages_from_caching() {
        global $post;
        //!empty($_GET['gf_token']) or 
        if ( (!empty($post) and post_password_required($post->ID)) ) {
            // The "Expires" header is set as well as "Cache-Control" so that Apache mod_expires
            // directives in .htaccess are ignored and don't overwrite/append-to these headers.
            // See http://httpd.apache.org/docs/current/mod/mod_expires.html
            $seconds = 0;
            header("Expires: ". gmdate('D, d M Y H:i:s', time() + $seconds). ' GMT');
            header("Cache-Control: max-age=". $seconds.',private');    //.',private'
            return;
        }
    }

    function opt_blog_public() {
        return hpp_if_access_hostv1()? '0':'1';
    }
}
if(php_sapi_name() != "cli" && hw_config('server_cache')) new HPP_Cache;
