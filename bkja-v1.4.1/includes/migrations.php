<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BKJA_Migrations {
    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        // جدول دسته‌بندی مشاغل
        $table_categories = $prefix . 'bkja_categories';
        $sql1 = "CREATE TABLE IF NOT EXISTS `{$table_categories}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) NOT NULL,
            PRIMARY KEY (`id`)
        ) {$charset_collate};";

        // جدول مشاغل
                $table_jobs = $prefix . 'bkja_jobs';
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

                // --- متدهای جدید هماهنگ با class-bkja-database.php ---
                // این متدها فقط برای اطلاع توسعه‌دهنده است و در migrations.php اجرا نمی‌شوند، بلکه باید در class-bkja-database.php باشند.
                // اینجا فقط ساختار جدول و داده نمونه را هماهنگ نگه می‌داریم.

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql1 ); 
        dbDelta( $sql2 );

        // افزودن داده نمونه
        $cat_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_categories}");
        if (0 == (int)$cat_count) {
            // دسته‌بندی‌های جدید
            $categories = [
                'خدماتی',
                'فنی',
                'اداری',
                'پزشکی',
                'آموزشی',
                'بازرگانی',
                'کشاورزی',
                'هنر و رسانه',
                'تکنولوژی و استارتاپ',
                'صنعت و تولید',
                'مشاوره و خدمات تخصصی',
                'مشاغل خارجی'
            ];

            $category_ids = [];
            foreach ($categories as $cat_name) {
                $wpdb->insert($table_categories, ['name' => $cat_name]);
                $category_ids[$cat_name] = $wpdb->insert_id;
            }

            // مثال مشاغل نمونه
            $wpdb->insert($table_jobs, [
                'category_id'=>$category_ids['تکنولوژی و استارتاپ'],
                'title'=>'استارتاپ فناوری',
                'income'=>'متغیر',
                'investment'=>'۵۰ میلیون تومان',
                'city'=>'تهران',
                'gender'=>'both',
                'advantages'=>'رشد سریع',
                'disadvantages'=>'ریسک بالا',
                'details'=>'این شغل نیازمند تیم قوی و سرمایه اولیه مناسب است.'
            ]);

            $wpdb->insert($table_jobs, [
                'category_id'=>$category_ids['تکنولوژی و استارتاپ'],
                'title'=>'طراحی وب فریلنس',
                'income'=>'۱۰–۵۰ میلیون',
                'investment'=>'یک لپ‌تاپ',
                'city'=>'هر شهر',
                'gender'=>'both',
                'advantages'=>'انعطاف کاری',
                'disadvantages'=>'نیاز به مشتری‌گیری',
                'details'=>'برای موفقیت نیاز به مهارت بازاریابی و نمونه کار قوی دارید.'
            ]);
        }
    }
}
