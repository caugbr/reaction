<?php
/**
 * Plugin name: Reaction
 * Description: Add reaction links to posts and comments
 * Version: 1.0
 * Author: Cau Guanabara
 * Author URI: https://cauguanabara.com.br/dev
 * Text Domain: reaction
 * Domain Path: /langs
 * License: Wordpress
 */

if (!defined('ABSPATH')) {
    exit;
}

define('REACTION_PATH', str_replace("\\", "/", plugin_dir_path(__FILE__)));
define('REACTION_URL', str_replace("\\", "/", plugin_dir_url(__FILE__)));

class Reaction {

    public $reactions = [];
    public $extension = '';
    public $active = [];
    public $types = [];
    public $post_position = '';
    public $comment_position = '';
    public $set = '';
    public $sets = [];
    
    public function __construct() {
        load_plugin_textdomain('reaction', false, dirname(plugin_basename(__FILE__)) . '/langs');
        add_action('init', [$this, 'add_scripts']);
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('wp_ajax_improve_reaction', [$this, 'respond']);
        add_action('wp_ajax_nopriv_improve_reaction', [$this, 'respond']);
        add_action('admin_enqueue_scripts', [$this, 'add_admin_scripts']);
        register_activation_hook(__FILE__, [$this, 'create_table']);

        $this->get_options();
        $this->define_set();
        $this->get_sets();

        add_filter('the_content', [$this, 'add_to_post'], 10, 2);
        if (in_array('comment', $this->types)) {
            add_filter('comment_text', [$this, 'add_to_comment'], 10, 2);
        }
    }

    /**
     * Get all options
     *
     * @return void
     */
    private function get_options() {
        $this->types = get_option('reaction_types', []);
        $this->post_position = get_option('reaction_post_position', 'before');
        $this->comment_position = get_option('reaction_comment_position', 'before');
        $this->active = get_option('reaction_reactions', []);
        $this->set = get_option('reaction_image_set', '');
        if (!empty($this->set)) {
            $this->define_set($this->set);
        }
    }

    /**
     * Hooked. Add admin scripts
     *
     * @param string $hook_suffix
     * @return void
     */
    public function add_admin_scripts($hook_suffix) {
        if ($hook_suffix == 'settings_page_reaction') {
            wp_enqueue_script('jquery-ui-sortable');
            wp_enqueue_script('reaction-admin-js', REACTION_URL . 'assets/js/reaction-admin.js');
        }
    }

    /**
     * Hooked. Add front side scripts
     *
     * @return void
     */
    public function add_scripts() {
        wp_enqueue_script('reaction-js', REACTION_URL . 'assets/js/reaction.js');
        wp_localize_script('reaction-js', 'reactionStr', [ 
            "hash" => COOKIEHASH ?? '',
            "ajaxurl" => admin_url('admin-ajax.php'),
            "loggedIn" => is_user_logged_in() ? 'yes' : 'no',
            "askName" => __("Please inform your name", 'reaction'),
            "rejectNoName" => __("To react you must inform your name", 'reaction'),
        ]);
        wp_enqueue_style('reaction-css', REACTION_URL . 'assets/css/reaction.css');
    }

    /**
     * Get reactions based on reaction images
     *
     * @return void
     */
    public function get_reactions() {
        $img_base = REACTION_PATH . 'assets/img/' . $this->set;
        $files = scandir($img_base);
        $img_names = [];
        $ext = '';
        foreach ($files as $file) {
            if ($file[0] === '.' || is_dir($img_base . $file)) {
                continue;
            }
            $fileInfo = pathinfo($file);
            $ext = strtolower($fileInfo['extension']);
            $img_names[] = $fileInfo['filename'];
        }
        $this->reactions = $img_names;
        $this->extension = $ext;
    }

    /**
     * Define the images set
     *
     * @param string $set_name
     * @return void
     */
    public function define_set($set_name = '') {
        $this->set = '';
        if (!empty($set_name)) {
            $set_name = preg_replace("@/*$@", "", $set_name);
            $this->set = $set_name . '/';
        }
        $this->get_reactions();
    }

    /**
     * Get image sets
     *
     * @return array
     */
    public function get_sets() {
        $img_base = REACTION_PATH . 'assets/img/';
        $files = scandir($img_base);
        $sets = [];
        foreach ($files as $file) {
            if ($file[0] != '.' && is_dir($img_base . $file)) {
                $sets[] = $file;
            }
        }
        return $this->sets = $sets;
    }

    /**
     * Add reaction to a publication or comment
     * Responding to an ajax call
     *
     * @return void
     */
    public function respond() {
        $user = '';
        if (is_user_logged_in()) {
            global $current_user;
            $user = $current_user->ID;
        }
        if (!empty($_POST['user'])) {
            $user = $_POST['user'];
        }
        $this->handle_reaction($_POST['type'], $_POST['id'], $user, $_POST['reaction']);
        $this->print($_POST['id'], $_POST['type'], false);
        wp_die();
    }

    /**
     * Add links to publications
     *
     * @param string $content
     * @return void
     */
    public function add_to_post($content) {
        global $post;
        if (in_array($post->post_type, $this->types)) {
            $html = $this->print($post->ID, $post->post_type, true, true);
            $before = $this->post_position == 'before' ? $html : '';
            $after = $this->post_position == 'after' ? $html : '';
            $content = $before . $content . $after;
        }
        return $content;
    }

    /**
     * Add links to comments
     *
     * @param string $comment_text
     * @param object $comment
     * @return void
     */
    public function add_to_comment($comment_text, $comment) {
        $html = $this->print($comment->comment_ID, 'comment', true, true);
        $before = $this->comment_position == 'before' ? $html : '';
        $after = $this->comment_position == 'after' ? $html : '';
        return $before . $comment_text . $after;
    }

    /**
     * Get a reaction name and returns an image tag
     *
     * @param string $imgid
     * @return void
     */
    public function img($imgid) {
        $img_base = REACTION_URL . 'assets/img/' . $this->set;
        $url = "{$img_base}{$imgid}.{$this->extension}";
        return "<img src='{$url}'>";
    }

    /**
     * Print or return the full HTML for reaction links
     *
     * @param int $id
     * @param string $type
     * @param boolean $wrap
     * @param boolean $ret
     * @return void
     */
    public function print($id, $type= 'post', $wrap = true, $ret = false) {
        $html = "";
        $count = $this->get_count($type, $id);
        $users = $this->get_users($type, $id);
        foreach ($this->active as $imgid) {
            $title = count($users[$imgid]) ? join("\n", $users[$imgid]) : __("No clicks yet", 'reaction');
            $badge = $count[$imgid] > 0 ? "<span class='badge'>" . $count[$imgid] . "</span>" : "";
            $html .= "<a data-reaction='{$imgid}' title='{$title}'>{$badge}" . $this->img($imgid) . "</a> ";
        }
        if ($wrap) {
            $html = "<div data-id='{$id}' data-type='{$type}' class='reaction'>\n" . $html . "</div> ";
        }
        if ($ret) {
            return $html;
        }
        print $html;
    }

    /**
     * Hooked. Add the admin page
     *
     * @return void
     */
    public function add_admin_page() {
        add_submenu_page(
            'options-general.php',
            'Reaction',
            'Reaction',
            'manage_options',
            'reaction',
            [$this, 'admin_page']
        );
    }

    /**
     * Return all post type names, but 'attachment'
     *
     * @return void
     */
    public function get_post_types() {
        $post_types = get_post_types(['public' => true], 'names');
        unset($post_types['attachment']);
        $post_types['comment'] = 'comment';
        return $post_types;
    }

    /**
     * Admin page
     *
     * @return void
     */
    public function admin_page() {
        $msg = "";
        if (isset($_POST['reaction_save'])) {
            check_admin_referer('reaction_nonce_action', 'reaction_nonce_field');
            update_option('reaction_types', $_POST['types']);
            update_option('reaction_reactions', $_POST['active_order'] ?? []);
            update_option('reaction_post_position', $_POST['post_position']);
            update_option('reaction_comment_position', $_POST['comment_position']);
            update_option('reaction_image_set', $_POST['image_set']);
            $msg = __('Configuration successfully saved!', 'reaction');
        }
        $this->get_options();
        $ptypes = (array) $this->get_post_types();
        ?>
        <h1 class="wp-heading-inline"><?php _e('Reaction options', 'reaction'); ?></h1>
        <?php if (!empty($msg)) { print "<div class='updated'><p>{$msg}</p></div>"; } ?>
        <form method="post" action="" class="reaction-form">
            <?php wp_nonce_field('reaction_nonce_action', 'reaction_nonce_field'); ?>
            <div class="line pub-types">
                <h4><?php _e('Publication types', 'reaction'); ?></h4>
                <?php foreach ($ptypes as $ptype) { ?>
                    <label>
                        <input type="checkbox" name="types[]" id="types_<?php print $ptype; ?>" value="<?php print $ptype; ?>" <?php 
                            if (in_array($ptype, $this->types)) { print ' checked'; } 
                        ?>>
                        <?php _e($ptype, 'reaction'); ?>
                    </label>
                <?php } ?>
                <p class="description">
                    <?php _e("Select the post types to add the reaction links.", 'reaction'); ?>
                </p>
            </div>
            <div class="line" id="post_pos">
                <h4><?php _e('Position in posts', 'reaction'); ?></h4>
                <select name="post_position" id="post_position">
                    <option value="before"<?php if ($this->post_position == 'before') print ' selected'; ?>>
                        <?php _e("Before content", 'reaction'); ?>
                    </option>
                    <option value="after"<?php if ($this->post_position == 'after') print ' selected'; ?>>
                        <?php _e("After content", 'reaction'); ?>
                    </option>
                </select>
                <p class="description">
                    <?php _e("Define the position to reaction links, relative to the post content.", 'reaction'); ?>
                </p>
            </div>
            <div class="line" id="comment_pos">
                <h4><?php _e('Position in comments', 'reaction'); ?></h4>
                <select name="comment_position" id="comment_position">
                    <option value="before"<?php if ($this->comment_position == 'before') print ' selected'; ?>>
                        <?php _e("Before comment", 'reaction'); ?>
                    </option>
                    <option value="after"<?php if ($this->comment_position == 'after') print ' selected'; ?>>
                        <?php _e("After comment", 'reaction'); ?>
                    </option>
                </select>
                <p class="description">
                    <?php _e("Define the position to reaction links, relative to the comment text.", 'reaction'); ?>
                </p>
            </div>
            <?php if (is_array($this->sets) && count($this->sets)) { ?>
            <div class="line">
                <h4><?php _e('Image set', 'reaction'); ?></h4>
                <div class="sets-list">
                    <select name="image_set" id="image_set">
                        <option value=""><?php _e("Default", 'reaction'); ?></option>
                        <?php foreach ($this->sets as $set) { ?>
                            <option value="<?php print $set; ?>"<?php if ($this->set == $set . '/') print ' selected'; ?>><?php print $set; ?></option>
                        <?php } ?>
                    </select>
                </div>
                <p class="description">
                    <?php _e('If you make any change here, the two fields below will be cleared.', 'reaction'); ?>
                </p>
            </div>
            <?php } ?>
            <div class="line">
                <h4><?php _e('Reaction types', 'reaction'); ?></h4>
                <div class="reaction-list">
                    <?php foreach ($this->reactions as $imgid) { ?>
                        <label>
                            <input type="checkbox" name="reactions[]" id="reactions_<?php print $imgid; ?>" value="<?php print $imgid; ?>" <?php 
                                if (is_array($this->active) && in_array($imgid, $this->active)) { print ' checked'; } 
                            ?>> 
                            <?php print $this->img($imgid); ?>
                        </label>
                    <?php } ?>
                </div>
                <p class="description">
                    <?php _e("Mark the reactions you want to use.", 'reaction'); ?>
                </p>
            </div>
            <div class="line">
                <h4><?php _e('Reactions order', 'reaction'); ?></h4>
                <div class="reactions-order">
                    <?php 
                    if (is_array($this->active)) {
                        foreach ($this->active as $imgid) {
                            print '<label>' . $this->img($imgid) . '</label>';
                        }
                    }
                    ?>
                </div>
                <p class="description">
                    <?php _e("Drag and drop to organize the order of reaction links.", 'reaction'); ?>
                </p>
                <div class="reactions-order-inputs">
                    <?php if (is_array($this->active)) { ?>
                        <?php foreach ($this->active as $imgid) { ?>
                            <input type="hidden" name="active_order[]" id="active_order_<?php print $imgid; ?>" value="<?php print $imgid; ?>">
                        <?php } ?>
                    <?php } ?>
                </div>
            </div>
            <p>
                <?php submit_button('Salvar', 'primary', 'reaction_save'); ?>
            </p>
        </form>
        <style>
            .reaction-form .line {
                margin: 1rem auto;
            }
            .reaction-form h4 {
                margin-top: 0;
            }
            .reaction-form label {
                margin-right: 1rem;
            }
            .reaction-form img {
                width: 20px;
                height: auto;
                vertical-align: bottom;
            }
            .reactions-order label {
                cursor: grab;
            }

            .reactions-order label:active {
                cursor: grabbing;
            }
        </style>
        <?php
    }

    /**
     * Create the plugin table in database
     *
     * @return void
     */
    public function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'reactions';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            obj_type VARCHAR(50) NOT NULL,
            obj_id BIGINT(20) UNSIGNED NOT NULL,
            user VARCHAR(120) NOT NULL,
            reaction VARCHAR(50) NOT NULL,
            PRIMARY KEY (id),
            INDEX (obj_id),
            INDEX (user),
            INDEX (reaction)
        ) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Get the names of users that reacted for a post or comment
     *
     * @param string $obj_type
     * @param int $obj_id
     * @return void
     */
    public function get_users($obj_type, $obj_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'reactions';
        $count = [];
        foreach ($this->active as $react) {
            $sql = $wpdb->prepare(
                "SELECT user FROM {$table_name} WHERE obj_type = %s AND obj_id = %d AND reaction = %s", 
                $obj_type, $obj_id, $react
            );
            $users = $wpdb->get_col($sql);
            $users = array_map(function($e) {
                if (preg_match("/^[0-9]+$/", $e)) {
                    $user_data = get_userdata($e);
                    return $user_data->display_name;
                }
                return $e;
            }, $users);
            $count[$react] = $users;
        }
        return $count;
    }

    /**
     * Get the amount of reactions for a post or comment
     *
     * @param string $obj_type
     * @param int $obj_id
     * @return void
     */
    public function get_count($obj_type, $obj_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'reactions';
        $count = [];
        foreach ($this->active as $react) {
            $rc = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} 
                        WHERE obj_type = %s AND obj_id = %d AND reaction = %s",
                    $obj_type, $obj_id, $react
                )
            );
            $count[$react] = $rc ? (int) $rc : 0;
        }
        return $count;
    }

    /**
     * Increment or decrement a reaction in database
     *
     * @param string $obj_type
     * @param int $obj_id
     * @param string $user
     * @param string $reaction
     * @return void
     */
    public function handle_reaction($obj_type, $obj_id, $user, $reaction) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'reactions';
        $sql = "SELECT id, reaction FROM {$table_name} WHERE obj_type = %s AND obj_id = %d";
        if (!empty($user)) {
            $sql .= " AND user = %s";
        }
        $existing_reaction = $wpdb->get_row($wpdb->prepare($sql, $obj_type, $obj_id, $user), ARRAY_A);
        if ($existing_reaction) {
            if ($existing_reaction['reaction'] === $reaction) {
                $wpdb->delete($table_name, [ 'id' => $existing_reaction['id'] ], [ '%d' ]);
            } else {
                $wpdb->update($table_name, [ 'reaction' => $reaction ], [ 'id' => $existing_reaction['id'] ], [ '%s' ], [ '%d' ]);
            }
        } else {
            $wpdb->insert(
                $table_name,
                [ 'obj_type' => $obj_type, 'obj_id' => $obj_id, 'user' => empty($user) ? '' : $user, 'reaction' => $reaction ],
                [ '%s', '%d', '%s', '%s' ]
            );
        }
    }

    /**
     * Get the most reacted publications
     *
     * @param string $post_type
     * @param array $reactions
     * @param integer $limit
     * @return void
     */
    public function reacted_posts($post_type = 'post', $reactions = ['any'], $limit = 0) {
        global $wpdb;
        $table_name = esc_sql($wpdb->prefix . 'reactions');
        $reaction_condition = '';
        if (!in_array('any', $reactions)) {
            $reaction_list = implode("','", array_map('esc_sql', $reactions));
            $reaction_condition = "AND r.reaction IN ('$reaction_list')";
        }
        $limit = $limit > 0 ? "LIMIT {$limit}" : "";
        $query = "
            SELECT 
                {$wpdb->posts}.*,
                COUNT(r.reaction) AS reaction_count
            FROM 
                {$wpdb->posts}
            LEFT JOIN 
                {$table_name} r
            ON 
                {$wpdb->posts}.ID = r.obj_id
            WHERE 
                {$wpdb->posts}.post_type = %s
                AND {$wpdb->posts}.post_status = 'publish'
                {$reaction_condition}
            GROUP BY 
                {$wpdb->posts}.ID
            HAVING 
                reaction_count > 0
            ORDER BY 
                reaction_count DESC
            {$limit}
        ";
        $query = $wpdb->prepare($query, $post_type, $limit);
        $results = $wpdb->get_results($query);
    
        return array_map(function($post) use($reactions) {
            $wp_post = get_post($post->ID);
            $wp_post->reaction_type = join(",", $reactions);
            $wp_post->reaction_count = $post->reaction_count;
            return $wp_post;
        }, $results);
    }
    
    
}

global $reaction;
$reaction = new Reaction();