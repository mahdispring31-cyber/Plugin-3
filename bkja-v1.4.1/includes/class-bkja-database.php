<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BKJA_Database
 * - مدیریت جداول (activate)
 * - ثبت چت
 * - توابع پایه برای درج شغل (insert_job)
 * - توابع تاریخچه چت
 * - توابع جدید برای دریافت اطلاعات شغل (خلاصه + رکوردها)
 */
class BKJA_Database {

    // ایجاد جدول‌ها (محافظت‌شده، dbDelta idempotent)
    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_chats = $wpdb->prefix . 'bkja_chats';
        $sql1 = "CREATE TABLE IF NOT EXISTS {$table_chats} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NULL,
            session_id VARCHAR(191) NULL,
            job_category VARCHAR(120) NULL,
            message LONGTEXT NULL,
            response LONGTEXT NULL,
            meta LONGTEXT NULL,
            status ENUM('active','closed') DEFAULT 'active',
            feedback ENUM('like','dislike','none') DEFAULT 'none',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_idx (user_id),
            KEY session_idx (session_id)
        ) {$charset_collate};";

        $table_jobs = $wpdb->prefix . 'bkja_jobs';
        $sql2 = "CREATE TABLE IF NOT EXISTS `{$table_jobs}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `category_id` BIGINT UNSIGNED NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `income` VARCHAR(255) DEFAULT NULL,
            `investment` VARCHAR(255) DEFAULT NULL,
            `city` VARCHAR(255) DEFAULT NULL,
            `gender` ENUM('male','female','both') DEFAULT 'both',
            `advantages` TEXT DEFAULT NULL,
            `disadvantages` TEXT DEFAULT NULL,
            `details` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX (`category_id`),
            INDEX (`gender`)
        ) {$charset_collate};";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql1 );
        dbDelta( $sql2 );

        $table_feedback = $wpdb->prefix . 'bkja_feedback';
        $sql3 = "CREATE TABLE IF NOT EXISTS {$table_feedback} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(64) NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            message TEXT NULL,
            response LONGTEXT NULL,
            vote TINYINT NOT NULL DEFAULT 0,
            tags VARCHAR(255) NULL,
            comment TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_idx (session_id),
            KEY user_idx (user_id),
            KEY vote_idx (vote)
        ) {$charset_collate};";

        dbDelta( $sql3 );

        // مقدار پیش‌فرض برای تعداد پیام رایگان در روز
        if ( false === get_option( 'bkja_free_messages_per_day' ) ) {
            update_option( 'bkja_free_messages_per_day', 5 );
        }

        if ( false === get_option( 'bkja_enable_cache', false ) ) {
            update_option( 'bkja_enable_cache', '1' );
        }

        if ( false === get_option( 'bkja_enable_quick_actions', false ) ) {
            update_option( 'bkja_enable_quick_actions', '0' );
        }

        if ( false === get_option( 'bkja_enable_feedback', false ) ) {
            update_option( 'bkja_enable_feedback', '0' );
        }
    }

    public static function deactivate(){}

    /**
     * insert_chat
     * ذخیره یک پیام/پاسخ در جدول چت‌ها
     */
    public static function insert_chat( $data = array() ){
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_chats';
        $defaults = array(
            'user_id'=>null,
            'session_id'=>'',
            'job_category'=>'',
            'message'=>'',
            'response'=>'',
            'meta'=>null,
            'status'=>'active',
            'feedback'=>'none'
        );
        $row = wp_parse_args( $data, $defaults );
        $row = array_map( 'wp_slash', $row ); // محافظت از داده‌ها
        $wpdb->insert( $table, $row );
        return $wpdb->insert_id;
    }

    /**
     * insert_job
     * درج یک رکورد شغلی (هر رکورد متعلق به یک کاربر/مشاهده است)
     */
    public static function insert_job( $data = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_jobs';
        $row = [
            'category_id'   => isset($data['category_id']) ? sanitize_text_field($data['category_id']) : 0,
            'title'         => isset($data['title']) ? sanitize_text_field($data['title']) : '',
            'income'        => isset($data['income']) ? sanitize_text_field($data['income']) : '',
            'investment'    => isset($data['investment']) ? sanitize_text_field($data['investment']) : '',
            'city'          => isset($data['city']) ? sanitize_text_field($data['city']) : '',
            'gender'        => isset($data['gender']) ? sanitize_text_field($data['gender']) : 'both',
            'advantages'    => isset($data['advantages']) ? sanitize_textarea_field($data['advantages']) : '',
            'disadvantages' => isset($data['disadvantages']) ? sanitize_textarea_field($data['disadvantages']) : '',
            'details'       => isset($data['details']) ? sanitize_textarea_field($data['details']) : '',
        ];
        $wpdb->insert($table, $row);
        $insert_id = $wpdb->insert_id;

        if ( class_exists( 'BKJA_Chat' ) ) {
            BKJA_Chat::flush_cache_prefix();
        }

        return $insert_id;
    }

    public static function insert_feedback( $data = array() ) {
        global $wpdb;

        $table = $wpdb->prefix . 'bkja_feedback';

        $defaults = array(
            'session_id' => '',
            'user_id'    => 0,
            'message'    => '',
            'response'   => '',
            'vote'       => 0,
            'tags'       => '',
            'comment'    => '',
            'created_at' => current_time( 'mysql' ),
        );

        $row = wp_parse_args( $data, $defaults );

        $row['session_id'] = sanitize_text_field( $row['session_id'] );
        $row['user_id']    = (int) $row['user_id'];
        $row['message']    = sanitize_textarea_field( $row['message'] );
        $row['response']   = wp_kses_post( $row['response'] );
        $row['vote']       = (int) $row['vote'];
        $row['tags']       = sanitize_text_field( $row['tags'] );
        $row['comment']    = sanitize_textarea_field( $row['comment'] );
        $row['created_at'] = sanitize_text_field( $row['created_at'] );

        $wpdb->insert( $table, $row );

        return $wpdb->insert_id;
    }

    public static function get_latest_feedback( $normalized_message, $session_id = '', $user_id = 0 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'bkja_feedback';

        $where  = array();
        $params = array();

        if ( $user_id ) {
            $where[]  = 'user_id = %d';
            $params[] = (int) $user_id;
        }

        if ( $session_id ) {
            $where[]  = 'session_id = %s';
            $params[] = $session_id;
        }

        if ( $normalized_message ) {
            $where[]  = 'message = %s';
            $params[] = $normalized_message;
        }

        if ( empty( $where ) ) {
            return null;
        }

        $where_sql = implode( ' AND ', $where );
        $sql       = "SELECT id, vote, tags, comment, created_at FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT 1";

        return $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    public static function ensure_feedback_table() {
        global $wpdb;

        $table  = $wpdb->prefix . 'bkja_feedback';
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );

        if ( $exists !== $table ) {
            self::activate();
        }
    }

    /**
     * تبدیل رشته به عدد صحیح (تومان) - فقط ارقام را نگه می‌دارد و اعداد فارسی را انگلیسی می‌کند
     */
    private static function bkja_parse_number($value) {
        if(!$value) return '';
        // تبدیل اعداد فارسی به انگلیسی
        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $english = ['0','1','2','3','4','5','6','7','8','9'];
        $value = str_replace($persian, $english, $value);
        // حذف هر چیزی غیر از رقم
        $value = preg_replace('/[^0-9]/', '', $value);
        return $value;
    }

    /**
     * get_user_history
     */
    public static function get_user_history( $user_id, $limit = 200 ){
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_chats';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, session_id, job_category, message, response, created_at 
             FROM {$table} 
             WHERE user_id = %d 
             ORDER BY created_at DESC LIMIT %d",
            $user_id, (int)$limit
        ) );
    }

    /**
     * get_history_by_session
     */
    public static function get_history_by_session( $session_id, $limit = 200 ){
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_chats';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, session_id, job_category, message, response, created_at 
             FROM {$table} 
             WHERE session_id = %s 
             ORDER BY created_at DESC LIMIT %d",
            $session_id, (int)$limit
        ) );
    }

    /**
     * get_recent_conversation
     * بر اساس شناسه جلسه یا کاربر، آخرین پیام‌ها را برمی‌گرداند (به ترتیب زمانی)
     */
    public static function get_recent_conversation( $session_id = '', $user_id = 0, $limit = 10 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_chats';

        $where  = '';
        $params = array();
        if ( $user_id ) {
            $where    = 'user_id = %d';
            $params[] = (int) $user_id;
        } elseif ( ! empty( $session_id ) ) {
            $where    = 'session_id = %s';
            $params[] = $session_id;
        } else {
            return array();
        }

        $params[] = (int) $limit;

        $sql = "SELECT message, response, created_at, id FROM {$table}"
             . " WHERE {$where}"
             . " AND ((message IS NOT NULL AND message <> '')"
             . "      OR (response IS NOT NULL AND response <> ''))"
             . " ORDER BY created_at DESC, id DESC LIMIT %d";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        if ( empty( $rows ) ) {
            return array();
        }

        $history = array();
        foreach ( array_reverse( $rows ) as $row ) {
            if ( ! empty( $row->message ) ) {
                $history[] = array(
                    'role'    => 'user',
                    'content' => $row->message,
                );
            } elseif ( ! empty( $row->response ) ) {
                $history[] = array(
                    'role'    => 'assistant',
                    'content' => $row->response,
                );
            }
        }

        return $history;
    }

    /**
     * helper: get_category_id_by_name
     */
    public static function get_category_id_by_name($name) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_categories';
        return $wpdb->get_var( $wpdb->prepare("SELECT id FROM {$table} WHERE name = %s LIMIT 1", $name) );
    }

    /**
     * جدید: خلاصه شغل (میانگین و ترکیب داده‌ها)
     */
    public static function get_job_summary($job_title) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_jobs';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    AVG(NULLIF(income, '')) AS avg_income,
                    AVG(NULLIF(investment, '')) AS avg_investment,
                    GROUP_CONCAT(DISTINCT city ORDER BY city SEPARATOR ', ') AS cities,
                    GROUP_CONCAT(DISTINCT gender ORDER BY gender SEPARATOR ', ') AS genders,
                    GROUP_CONCAT(DISTINCT advantages SEPARATOR ' | ') AS all_advantages,
                    GROUP_CONCAT(DISTINCT disadvantages SEPARATOR ' | ') AS all_disadvantages
                 FROM {$table}
                 WHERE title = %s",
                $job_title
            )
        );

        if ( ! $row ) return null;

        return array(
            'job_title'     => $job_title,
            'income'        => $row->avg_income ? round($row->avg_income, 0) . " میلیون (میانگین)" : 'نامشخص',
            'investment'    => $row->avg_investment ? round($row->avg_investment, 0) . " میلیون (میانگین)" : 'نامشخص',
            'cities'        => $row->cities,
            'genders'       => $row->genders,
            'advantages'    => $row->all_advantages,
            'disadvantages' => $row->all_disadvantages,
        );
    }

    /**
     * جدید: رکوردهای واقعی کاربران برای یک شغل
     */
    public static function get_job_records($job_title, $limit = 5, $offset = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_jobs';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, title, income, investment, city, gender, advantages, disadvantages, details, created_at
                 FROM {$table}
                 WHERE title = %s
                 ORDER BY created_at DESC
                 LIMIT %d OFFSET %d",
                $job_title, $limit, $offset
            )
        );

        $records = array();
        foreach ( $results as $row ) {
            $records[] = array(
                'id'            => (int)$row->id,
                'job_title'     => $row->title,
                'income'        => $row->income,
                'investment'    => $row->investment,
                'city'          => $row->city,
                'gender'        => $row->gender,
                'advantages'    => $row->advantages,
                'disadvantages' => $row->disadvantages,
                'details'       => $row->details,
                'created_at'    => $row->created_at,
            );
        }
        return $records;
    }
}