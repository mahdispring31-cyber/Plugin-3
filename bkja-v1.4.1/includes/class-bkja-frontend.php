<?php if ( ! defined( 'ABSPATH' ) ) exit;

class BKJA_Frontend {
    public static function init(){
        add_shortcode('bkja_assistant', array(__CLASS__,'render_chatbox'));
        add_action('wp_enqueue_scripts', array(__CLASS__,'enqueue_assets'));
        add_action('wp_ajax_bkja_send_message', array(__CLASS__,'ajax_send_message'));
        add_action('wp_ajax_nopriv_bkja_send_message', array(__CLASS__,'ajax_send_message'));
        add_action('wp_ajax_bkja_get_history', array(__CLASS__,'ajax_get_history'));
        add_action('wp_ajax_nopriv_bkja_get_history', array(__CLASS__,'ajax_get_history'));
        add_action('wp_ajax_bkja_feedback', array(__CLASS__,'ajax_feedback'));
        add_action('wp_ajax_nopriv_bkja_feedback', array(__CLASS__,'ajax_feedback'));
    }

    public static function enqueue_assets(){
        wp_enqueue_style('bkja-frontend', BKJA_PLUGIN_URL.'assets/css/bkja-frontend.css', array(), '1.3.2');
        wp_enqueue_script('bkja-frontend', BKJA_PLUGIN_URL.'assets/js/bkja-frontend.js', array('jquery'), '1.3.2', true);
        $data = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bkja_nonce'),
            'is_logged_in' => is_user_logged_in() ? 1 : 0,
            'free_limit' => (int)get_option('bkja_free_messages_per_day',5),
            'enable_feedback' => get_option('bkja_enable_feedback','0') === '1' ? 1 : 0,
            'enable_quick_actions' => get_option('bkja_enable_quick_actions','0') === '1' ? 1 : 0,
        );
        wp_localize_script('bkja-frontend','bkja_vars',$data);
        wp_localize_script('bkja-frontend','BKJA',$data);
    }

    public static function render_chatbox($atts=array()){
        $atts = shortcode_atts(
            array('title'=>__('دستیار شغلی','bkja-assistant')),
            $atts,
            'bkja_assistant'
        );
        ob_start();
        include BKJA_PLUGIN_DIR.'templates/chatbox.php';
        return ob_get_clean();
    }

    public static function ajax_send_message(){
        check_ajax_referer('bkja_nonce','nonce');
        $message         = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        $category        = isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '';
        $session         = isset($_POST['session']) ? sanitize_text_field(wp_unslash($_POST['session'])) : '';
        $job_title_hint  = isset($_POST['job_title']) ? sanitize_text_field(wp_unslash($_POST['job_title'])) : '';
        $job_slug        = isset($_POST['job_slug']) ? sanitize_text_field(wp_unslash($_POST['job_slug'])) : '';

        if ( empty($message) ) {
            wp_send_json_error(array('error'=>'empty_message'),400);
        }

        $user_id = get_current_user_id() ?: 0;
    $free_limit = (int)get_option('bkja_free_messages_per_day',5);
    // تعیین آدرس ورود/عضویت ووکامرس اگر فعال است
    $login_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url();

        // اگر کاربر مهمان است، تعداد پیام‌های ارسالی را بررسی کن
        if(!$user_id){
            // session_id باید مقدار داشته باشد و معتبر باشد
            if(empty($session) || strpos($session, 'guest_') !== 0){
                wp_send_json_error(array('error'=>'invalid_session','msg'=>'جلسه مهمان معتبر نیست.'),400);
            }
            global $wpdb;
            $table = $wpdb->prefix . 'bkja_chats';
            // فقط پیام‌های واقعی کاربر مهمان را بشمار (response باید NULL باشد)
            $msg_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE session_id = %s AND message IS NOT NULL AND response IS NULL", $session));
            if($msg_count >= $free_limit){
                wp_send_json_error(array('error'=>'guest_limit','msg'=>'برای ادامه گفتگو باید عضو سایت شوید.','login_url'=>$login_url),403);
            }
        }

        // save user message
        BKJA_Database::insert_chat(array(
            'user_id'      => $user_id ?: null,
            'session_id'   => $session,
            'job_category' => $category,
            'message'      => $message,
            'response'     => null
        ));

        $selected_model = get_option('bkja_model', '');
        $resolved_model = BKJA_Chat::resolve_model($selected_model);

        $ai_response = BKJA_Chat::call_openai($message, array(
            'session_id'     => $session,
            'user_id'        => $user_id,
            'category'       => $category,
            'model'          => $resolved_model,
            'job_title_hint' => $job_title_hint,
            'job_slug'       => $job_slug,
        ));

        $suggestions    = array();
        $reply_meta     = null;
        $from_cache     = false;
        $meta_payload   = array();
        $normalized_message = BKJA_Chat::normalize_message($message);
        if (is_wp_error($ai_response)) {
            $reply = 'خطا یا کلید API تنظیم نشده. '.$ai_response->get_error_message();
        } else {
            $reply = isset($ai_response['text']) ? (string)$ai_response['text'] : '';
            $suggestions = !empty($ai_response['suggestions']) && is_array($ai_response['suggestions']) ? $ai_response['suggestions'] : array();
            $from_cache = !empty($ai_response['from_cache']);
            $meta_payload = array(
                'suggestions'        => $suggestions,
                'context_used'       => !empty($ai_response['context_used']),
                'from_cache'         => $from_cache,
                'source'             => isset($ai_response['source']) ? $ai_response['source'] : 'openai',
                'model'              => isset($ai_response['model']) ? $ai_response['model'] : $resolved_model,
                'category'           => $category,
                'normalized_message' => $normalized_message,
                'job_title'          => !empty($ai_response['job_title']) ? $ai_response['job_title'] : '',
            );

            if (isset($ai_response['meta']) && is_array($ai_response['meta'])) {
                $meta_payload = array_merge($meta_payload, $ai_response['meta']);
            }

            if (!isset($meta_payload['category']) || $meta_payload['category'] === '') {
                $meta_payload['category'] = $category;
            }

            if (!isset($meta_payload['job_title']) || $meta_payload['job_title'] === '') {
                if (!empty($ai_response['job_title'])) {
                    $meta_payload['job_title'] = $ai_response['job_title'];
                } elseif (!empty($job_title_hint)) {
                    $meta_payload['job_title'] = $job_title_hint;
                }
            }

            if (!isset($meta_payload['job_slug'])) {
                if (isset($ai_response['job_slug'])) {
                    $meta_payload['job_slug'] = $ai_response['job_slug'];
                } elseif (!empty($job_slug)) {
                    $meta_payload['job_slug'] = $job_slug;
                }
            }

            $reply_meta = wp_json_encode($meta_payload);
        }

        // save bot reply
        BKJA_Database::insert_chat(array(
            'user_id'      => $user_id ?: null,
            'session_id'   => $session,
            'job_category' => $category,
            'message'      => null,
            'response'     => $reply,
            'meta'         => $reply_meta
        ));

        wp_send_json_success(array(
            'reply'       => $reply,
            'suggestions' => $suggestions,
            'from_cache'  => $from_cache,
            'meta'        => $meta_payload,
        ));
    }

    public static function ajax_feedback(){
        check_ajax_referer('bkja_nonce','nonce');

        $vote = isset($_POST['vote']) ? intval($_POST['vote']) : 0;
        if (!in_array($vote, array(1,-1), true)) {
            wp_send_json_error(array('error'=>'invalid_vote'),400);
        }

        $session  = isset($_POST['session']) ? sanitize_text_field(wp_unslash($_POST['session'])) : '';
        $message  = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        $response = isset($_POST['response']) ? wp_kses_post(wp_unslash($_POST['response'])) : '';
        $tags     = isset($_POST['tags']) ? sanitize_text_field(wp_unslash($_POST['tags'])) : '';
        $comment  = isset($_POST['comment']) ? sanitize_textarea_field(wp_unslash($_POST['comment'])) : '';
        $category = isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '';
        $model    = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
        $job_title = isset($_POST['job_title']) ? sanitize_text_field(wp_unslash($_POST['job_title'])) : '';
        $job_slug  = isset($_POST['job_slug']) ? sanitize_text_field(wp_unslash($_POST['job_slug'])) : '';

        if ( '' === $job_title && '' !== $job_slug ) {
            $job_title = $job_slug;
        }

        $normalized_message = BKJA_Chat::normalize_message($message);
        $resolved_model     = BKJA_Chat::resolve_model($model);

        if ('' === $normalized_message) {
            wp_send_json_error(array('error'=>'empty_message'),400);
        }

        if ( class_exists('BKJA_Database') ) {
            BKJA_Database::ensure_feedback_table();
            BKJA_Database::insert_feedback(array(
                'session_id' => $session,
                'user_id'    => get_current_user_id() ?: 0,
                'message'    => $normalized_message,
                'response'   => $response,
                'vote'       => $vote,
                'tags'       => $tags,
                'comment'    => $comment,
            ));
        }

        if ( -1 === $vote ) {
            BKJA_Chat::delete_cache_for( $normalized_message, $category, $resolved_model, $job_title );
        } else {
            BKJA_Chat::extend_cache_ttl( $normalized_message, $category, $resolved_model, 3 * HOUR_IN_SECONDS, $job_title );
        }

        wp_send_json_success(array('success'=>true));
    }

    public static function ajax_get_history(){
        check_ajax_referer('bkja_nonce','nonce');

        $session = isset($_POST['session']) ? sanitize_text_field(wp_unslash($_POST['session'])) : '';
        $user_id = get_current_user_id() ?: 0;

        if ($user_id) {
            $rows = BKJA_Database::get_user_history($user_id,200);
        } else {
            if (empty($session)) {
                wp_send_json_error(array('error'=>'no_session'),400);
            }
            $rows = BKJA_Database::get_history_by_session($session,200);
        }

        $items = array();
        if ($rows) {
            foreach ($rows as $r) {
                $items[] = array(
                    'message'    => $r->message,
                    'response'   => $r->response,
                    'created_at' => $r->created_at
                );
            }
        }

        wp_send_json_success(array('items'=>$items));
    }
}