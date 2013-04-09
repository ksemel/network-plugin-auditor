<?php
/*
Plugin Name: Network Plugin Auditor
Plugin URI: http://bonsaibudget.com/wordpress/network-plugin-auditor/
Description: Adds columns to your Network Admin on the Sites, Themes and Plugins pages to show which of your sites have each plugin and theme activated.  Now you can easily determine which plugins and themes are used on your network sites and which can be safely removed.
Version: 1.4
Author: Katherine Semel
Author URI: http://bonsaibudget.com/
Network: true
*/

class NetworkPluginAuditor {

    CONST optionprefix = 'auditor_';
    CONST use_transient = true;

    function NetworkPluginAuditor( ) {

        global $wpdb;
        if ( ! is_string( $wpdb->base_prefix ) || '' === $wpdb->base_prefix ) {
            if ( is_network_admin() ) {
                add_action( 'network_admin_notices', array( $this, 'unsupported_prefix_notice' ) );
            }

        } else {

            // On the network plugins page, show which blogs have this plugin active
            add_filter( 'manage_plugins-network_columns', array( $this, 'add_plugins_column' ), 10, 1);
            add_action( 'manage_plugins_custom_column', array( $this, 'manage_plugins_custom_column' ), 10, 3);

            // On the network theme list page, show each blog next to its active theme
            add_filter( 'manage_themes-network_columns', array( $this, 'add_themes_column' ), 10, 1);
            add_action( 'manage_themes_custom_column', array( $this, 'manage_themes_custom_column' ), 10, 3);

            // On the blog list page, show the plugins and theme active on each blog
            add_filter( 'manage_sites-network_columns', array( $this, 'add_sites_column' ), 10, 1);
            add_action( 'manage_sites_custom_column', array( $this, 'manage_sites_custom_column' ), 10, 3);
        }

        // Clear the transients when plugins are activated or deactivated
        add_action( 'activated_plugin', array( $this, 'clear_transients' ), 10, 2 );
        add_action( 'deactivated_plugin', array( $this, 'clear_transients' ), 10, 2 );
    }

    function unsupported_prefix_notice() {
        // The plugin does not support a blank database prefix at this time
        echo '<div class="error"><p style="color: red; font-size: 14px; font-weight: bold;">Network Plugin Auditor</p><p>Your <code>wp-config.php</code> file has an empty database table prefix, which is not supported at this time. Please disable the Network Plugin Auditor to avoid error messages.</p><p>If you feel you have received this message in error, please <a href="http://wordpress.org/support/plugin/network-plugin-auditor">visit the support forum</a> for more assistance.</div>';
    }

/* Plugins Page Functions *****************************************************/

    function add_plugins_column( $column_details ) {
        $column_details['active_blogs'] = _x( '<nobr>Active Blogs</nobr>', 'column name' );
        return $column_details;
    }

    function manage_plugins_custom_column( $column_name, $plugin_file, $plugin_data ) {
        if ( $column_name != 'active_blogs' ) {
            return;
        }

        // Is this plugin network activated
        if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
            require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
        }
        $active_on_network = is_plugin_active_for_network( $plugin_file );

        if ( $active_on_network ) {
            // We don't need to check any further for network active plugins
            $output = '<strong>Network Activated</strong>';

        } else {
            // Is this plugin Active on any blogs in this network?
            $active_on_blogs = self::is_plugin_active_on_blogs( $plugin_file );
            if ( is_array( $active_on_blogs ) ) {
                $output = '<ul>';

                // Loop through the blog list, gather details and append them to the output string
                foreach ( $active_on_blogs as $blog_id ) {
                    $blog_id = trim($blog_id);
                    if ( ! isset( $blog_id ) || $blog_id == '' ) {
                        continue;
                    }

                    $blog_details = get_blog_details( $blog_id, true );

                    if ( isset( $blog_details->siteurl ) && isset( $blog_details->blogname ) ) {
                        $blog_url  = $blog_details->siteurl;
                        $blog_name = $blog_details->blogname;

                        $output .= '<li><nobr><a title="Manage plugins on '. esc_attr($blog_name) .'" href="'.esc_url($blog_url).'/wp-admin/plugins.php">' . esc_html($blog_name) . '</a></nobr></li>';
                    }

                    unset($blog_details);
                }
                $output .= '</ul>';
            }
        }
        echo $output;
    }


/* Themes Page Functions ******************************************************/

    function add_themes_column( $column_details ) {
        $column_details['active_blogs'] = _x( '<nobr>Active Blogs</nobr>', 'column name' );
        return $column_details;
    }

    function manage_themes_custom_column( $column_name, $theme_key, $theme ) {
        if ( $column_name != 'active_blogs' ) {
            return;
        }

        $output = '';

        // Is this theme Active on any blogs in this network?
        $active_on_blogs = self::is_theme_active_on_blogs( $theme, $theme_key );

        // Loop through the blog list, gather details and append them to the output string
        if ( is_array($active_on_blogs) ) {
            $output .= '<ul>';

            foreach ( $active_on_blogs as $blog_id ) {
                $blog_id = trim($blog_id);
                if ( ! isset( $blog_id ) || $blog_id == '' ) {
                    continue;
                }

                $output .= '<li>' . self::get_theme_link( $blog_id, 'blog' ) . '</li>';
            }

            $output .= '</ul>';
        }
        echo $output;

    }


/* Sites Page Functions *******************************************************/

    function add_sites_column( $column_details ) {
        $column_details['active_plugins'] = _x( '<nobr>Active Plugins <span title="Excludes network-active and must-use plugins">[?]</span></nobr>', 'column name' );
        $column_details['active_theme'] = _x( '<nobr>Active Theme</nobr>', 'column name' );

        return $column_details;
    }

    function manage_sites_custom_column( $column_name, $blog_id ) {
        if ( $column_name != 'active_plugins' && $column_name != 'active_theme' ) {
            return;
        }

        $output = '';

        if ( $column_name == 'active_plugins' ) {

            // Get the active plugins for this blog_id
            $plugins_active_here = self::get_active_plugins( $blog_id );
            $plugins_active_here = maybe_unserialize( $plugins_active_here );

            if ( is_array( $plugins_active_here ) && count($plugins_active_here) > 0 ) {
                $output .= '<ul>';
                foreach ( $plugins_active_here as $plugin ) {
                    $plugin_path = WP_PLUGIN_DIR . '/' . $plugin ;

                    // Fetch the plugin's data from the file
                    if ( file_exists( $plugin_path ) && function_exists( 'get_plugin_data' ) ) {
                        $plugin_data = get_plugin_data( $plugin_path );

                        if ( isset( $plugin_data['Name'] ) ) {
                            $plugin_name = $plugin_data['Name'];
                        }
                        if ( isset( $plugin_data['PluginURI'] ) ) {
                            $plugin_url  = $plugin_data['PluginURI'];
                        }

                        if ( isset($plugin_url) ) {
                            $output .= '<li><a href="' . esc_url($plugin_url) . '" title="Visit the plugin url: ' . esc_attr($plugin_url) . '">' . esc_html($plugin_name) . '</a></li>';

                        } else {
                            $output .= '<li>' . esc_html($plugin_name) . '</li>';
                        }

                    } else {
                        // Could not determine anything from this plugin's data block, just print the path
                        $output .= '<li>' . esc_html($plugin) . '</li>';
                    }
                }
                $output .= '</ul>';

            } else {
                $output .= '<ul><li>No Plugins Active</li></ul>';
            }

        } else {

            // Get the active theme for this blog_id
            $output .= '<ul><li>' . self::get_theme_link( $blog_id, 'theme' ) . '</li></ul>';

        }

        echo $output;
    }

/* Helper Functions ***********************************************************/

    // Get the database prefix
    function get_blog_prefix( $blog_id ) {
        global $wpdb;

        if ( null === $blog_id ) {
            $blog_id = $wpdb->blogid;
        }
        $blog_id = (int) $blog_id;

        if ( defined( 'MULTISITE' ) && ( 0 == $blog_id || 1 == $blog_id ) ) {
            return $wpdb->base_prefix;
        } else {
            return $wpdb->base_prefix . $blog_id . '_';
        }
    }

    // Get the list of blogs
    function get_network_blog_list( ) {
        global $wpdb;

        // Fetch the list from the transient cache if available
        $blog_list = get_transient( self::optionprefix.'blog_list' );
        if ( self::use_transient !== true || $blog_list === false ) {

            $blog_list = $wpdb->get_results( "SELECT blog_id, domain FROM " . $wpdb->base_prefix . "blogs" );

            // Store for one hour
            set_transient( self::optionprefix.'blog_list', $blog_list, 3600 );
        }
        return $blog_list;
    }

/* Plugin Helpers */

    // Determine if the given plugin is active on a list of blogs
    function is_plugin_active_on_blogs( $plugin_file ) {
        // Get the list of blogs
        $blog_list = self::get_network_blog_list( );

        if ( isset($blog_list) && $blog_list != false ) {
            // Fetch the list from the transient cache if available
            $transient_name = substr($plugin_file, 0, strpos($plugin_file, '/'));
            if ($transient_name == false) {
                $transient_name = $plugin_file;
            }
            $transient_name = esc_sql(self::optionprefix.'plugin_'.$transient_name);
            if (strlen($transient_name) >= 45) {
                $transient_name = substr($transient_name, 0, 44);
            }

            $active_on = get_transient( $transient_name );
            if ( self::use_transient !== true || $active_on === false ) {

                $active_on = array();

                foreach ( $blog_list as $blog ) {
                    // If the plugin is active here then add it to the list
                    if ( self::is_plugin_active( $blog->blog_id, $plugin_file ) ) {
                        array_push( $active_on, $blog->blog_id );
                    }
                }

                if ( count($active_on) > 0 ) {
                    // Store for one hour
                    $store_active_on = implode(',', $active_on);
                    set_transient( $transient_name, $store_active_on, 3600 );
                } else {
                    // Store for one hour
                    set_transient( $transient_name, false, 3600 );
                }

                return $active_on;

            } else {

                if ( strpos($active_on, ',') !== false ) {
                    $active_on = explode(',', $active_on);
                    $active_on = array_values($active_on);
                } else {
                    $active_on = array( $active_on );
                }

                return $active_on;
            }
        }

        return false;
    }

    // Given a blog id and plugin path, determine if that plugin is active.
    function is_plugin_active( $blog_id, $plugin_file ) {
        // Get the active plugins for this blog_id
        $plugins_active_here = self::get_active_plugins( $blog_id );

        // Is this plugin listed in the active blogs?
        if ( isset( $plugins_active_here ) && strpos( $plugins_active_here, $plugin_file ) > 0 ) {
            return true;
        } else {
            return false;
        }
    }

    // Get the list of active plugins for a single blog
    function get_active_plugins( $blog_id ) {
        global $wpdb;

        $blog_prefix = self::get_blog_prefix( $blog_id );

        $active_plugins = $wpdb->get_var( "SELECT option_value FROM " . $blog_prefix . "options WHERE option_name = 'active_plugins'" );

        return $active_plugins;
    }

/* Theme Helpers */

    // Determine if the given theme is active on a list of blogs
    function is_theme_active_on_blogs( $theme, $theme_key ) {
        // Get the list of blogs
        $blog_list = self::get_network_blog_list( );

        if ( isset($blog_list) && $blog_list != false ) {

            // Fetch the list from the transient cache if available
            $transient_name = esc_sql(self::optionprefix.'theme_'.$theme_key);
            if (strlen($transient_name) >= 45) {
                $transient_name = substr($transient_name, 0, 44);
            }

            $active_on = get_transient( $transient_name );
            if ( self::use_transient !== true || $active_on === false ) {

                $active_on = array();

                foreach ( $blog_list as $blog ) {
                    // If the theme is active here then add it to the list
                    if ( self::is_theme_active( $blog->blog_id, $theme ) ) {
                        array_push( $active_on, $blog->blog_id );
                    }
                }

                if ( count($active_on) > 0 ) {
                    // Store for one hour
                    set_transient( $transient_name, implode(',', $active_on), 3600 );
                } else {
                    // Store for one hour
                    set_transient( $transient_name, false, 3600 );
                }

                return $active_on;

            } else {

                if ( strpos($active_on, ',') !== false ) {
                    $active_on = explode(',', $active_on);
                    $active_on = array_values($active_on);
                } else {
                    $active_on = array( $active_on );
                }

                return $active_on;
            }
        }
        return false;
    }

    // Given a blog id and theme object, determine if that theme is used on a this blog.
    function is_theme_active( $blog_id, $theme ) {
        // Get the active theme for this blog_id
        $active_theme = self::get_active_theme( $blog_id );

        // Is this theme listed in the active blogs?
        if ( isset( $active_theme ) && ( $active_theme == $theme['Name'] ) ) {
            return true;
        } else {
            return false;
        }
    }

    // Get the active theme for a single blog
    function get_active_theme( $blog_id ) {
        global $wpdb;

        $blog_prefix = self::get_blog_prefix( $blog_id );

        $active_theme = $wpdb->get_var( "SELECT option_value FROM " . $blog_prefix . "options WHERE option_name = 'current_theme'" );

        return $active_theme;
    }

    function get_theme_link( $blog_id, $display='blog_name' ) {
        $output = '';

        $blog_details = get_blog_details( $blog_id, true );

        if ( isset( $blog_details->siteurl ) && isset( $blog_details->blogname ) ) {
            $blog_url  = $blog_details->siteurl;
            $blog_name = $blog_details->blogname;

            if ( $display == 'blog' ) {
                $output .= '<nobr><a title="Manage themes on '. esc_attr($blog_name) .'" href="'. esc_url($blog_url).'/wp-admin/themes.php">' . esc_html($blog_name) . '</a></nobr>';
            } else {
                $output .= '<nobr><a title="Manage themes on '. esc_attr($blog_name) .'" href="'. esc_url($blog_url).'/wp-admin/themes.php">' . self::get_active_theme($blog_id) . '</a></nobr>';
            }
        }

        unset($blog_details);

        return $output;
    }

    function clear_transients( $plugin, $network_deactivating ) {
        $transient_name = substr($plugin, 0, strpos($plugin, '/'));
        if ($transient_name == false) {
            $transient_name = $plugin;
        }
        $transient_name = esc_sql(self::optionprefix.'plugin_'.$transient_name);
        if (strlen($transient_name) >= 45) {
            $transient_name = substr($transient_name, 0, 44);
        }
        delete_transient( $transient_name );
    }
}

$NetworkPluginAuditor = new NetworkPluginAuditor();

?>
