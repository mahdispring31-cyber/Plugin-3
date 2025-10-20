<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BKJA_Jobs {

    // جستجوی پیشرفته مشاغل برای پنل مدیریت و API
    public static function get_jobs($per_page=20, $page_number=1, $city_filter='', $income_min=0, $income_max=0) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_jobs';
        $sql = "SELECT * FROM {$table} WHERE 1=1";
        $params = [];
        if ($city_filter) {
            $sql .= " AND city LIKE %s";
            $params[] = "%$city_filter%";
        }
        if ($income_min) {
            $sql .= " AND income >= %f";
            $params[] = $income_min;
        }
        if ($income_max) {
            $sql .= " AND income <= %f";
            $params[] = $income_max;
        }
        $sql .= " ORDER BY created_at DESC";
        $offset = ($page_number - 1) * $per_page;
        $sql .= " LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    // گرفتن دسته‌ها
    public static function get_categories() {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_categories';
        return $wpdb->get_results("SELECT id, name FROM {$table} ORDER BY id ASC");
    }

    // لیست عناوین شغل در یک دسته (Distinct by title as job_title, همراه با id)
    public static function get_jobs_by_category($cat_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_jobs';
        // لاگ برای بررسی category_id
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('BKJA get_jobs_by_category called with category_id: ' . print_r($cat_id, true));
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT MIN(id) AS id, title AS job_title 
             FROM {$table} 
             WHERE category_id = %d 
             GROUP BY title 
             ORDER BY title ASC",
            $cat_id
        ));
    }
    // خلاصه شغل
    public static function get_job_summary($job_title) {
        return BKJA_Database::get_job_summary($job_title);
    }

    // رکوردهای شغل (برای نمایش جزئیات کاربران)
    public static function get_job_records($job_title, $limit = 5, $offset = 0) {
        return BKJA_Database::get_job_records($job_title, $limit, $offset);
    }

    // گرفتن جزئیات کامل یک شغل (برای ارسال به JS)
    public static function get_job_detail($job_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_jobs';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, title, income, investment, city, gender, advantages, disadvantages, details, created_at, category_id
                 FROM {$table}
                 WHERE id = %d LIMIT 1",
                $job_id
            )
        );
        if ( ! $row ) return null;

        return array(
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
            'category_id'   => (int)$row->category_id,
        );
    }
}

