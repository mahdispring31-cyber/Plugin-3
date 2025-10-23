<?php 
if ( ! defined( 'ABSPATH' ) ) exit;

class BKJA_Chat {

    protected static $allowed_models = array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4', 'gpt-3.5-turbo', 'gpt-5' );

    // گرفتن API Key
    public static function get_api_key(){
        return trim(get_option('bkja_openai_api_key',''));
    }

    public static function normalize_message( $message ) {
        if ( ! is_string( $message ) ) {
            $message = (string) $message;
        }

        $message = preg_replace( '/\s+/u', ' ', $message );
        return trim( (string) $message );
    }

    protected static function truncate_text( $text, $limit = 180 ) {
        $text = is_string( $text ) ? trim( $text ) : '';

        if ( '' === $text ) {
            return '';
        }

        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
        if ( $length <= $limit ) {
            return $text;
        }

        $slice = function_exists( 'mb_substr' )
            ? mb_substr( $text, 0, $limit - 1, 'UTF-8' )
            : substr( $text, 0, $limit - 1 );

        return rtrim( $slice ) . '…';
    }

    protected static function normalize_lookup_text( $text ) {
        $text = self::normalize_message( $text );

        if ( '' === $text ) {
            return '';
        }

        $replacements = array(
            'ي' => 'ی',
            'ك' => 'ک',
            'ة' => 'ه',
            'ۀ' => 'ه',
            'ؤ' => 'و',
            'إ' => 'ا',
            'أ' => 'ا',
            'آ' => 'ا',
        );

        $text = strtr( $text, $replacements );
        $text = str_replace(
            array( '‌', "\xE2\x80\x8C", '-', '–', '—', '_', '/', '\\', '(', ')', '[', ']', '{', '}', '«', '»', '"', '\'', ':' ),
            ' ',
            $text
        );
        $text = preg_replace( '/\s+/u', ' ', $text );

        return trim( (string) $text );
    }

    protected static function build_job_lookup_phrases( $normalized_message ) {
        $text = self::normalize_lookup_text( $normalized_message );

        if ( '' === $text ) {
            return array();
        }

        $phrases = array( $text );

        $stopwords = array(
            'در','برای','به','از','که','چی','چیه','چه','چطور','چگونه','چقدر','چقد','چقدره','درآمد','درامد','درآمدش','درامدش','سرمایه','حقوق','میخوام','می‌خوام','میخواهم','میخواستم','میخوای','میخواید','میشه','می','من','کنم','کن','کردن','کرد','شروع','قدم','بعدی','منطقی','بیشتر','تحقیق','موضوع','حرفه','حوزه','شغل','کار','رشته','درمورد','درباره','اطلاعات','را','با','و','یا','اگر','آیا','ایا','است','نیست','هست','هستن','هستش','کج','کجاست','چیکار','چکار','بگو','بگید','نیاز','دارم','داریم','مورد','برا','برام','براش','براشون','توضیح','لطفا','لطفاً','معرفی','چند','چندتا','چندمه','پول','هزینه','هزینه‌','چیا','سود','درآمدزایی'
        );

        $words = preg_split( '/[\s،,.!?؟]+/u', $text );
        $words = array_filter( array_map( 'trim', $words ), function ( $word ) use ( $stopwords ) {
            if ( '' === $word ) {
                return false;
            }

            $check = function_exists( 'mb_strtolower' )
                ? mb_strtolower( $word, 'UTF-8' )
                : strtolower( $word );

            if ( in_array( $check, $stopwords, true ) ) {
                return false;
            }

            if ( function_exists( 'mb_strlen' ) ) {
                return mb_strlen( $word, 'UTF-8' ) >= 2;
            }

            return strlen( $word ) >= 2;
        } );

        $words = array_values( $words );
        $count = count( $words );

        if ( $count > 0 ) {
            $max_chunk = min( 4, $count );
            for ( $len = $max_chunk; $len >= 1; $len-- ) {
                for ( $i = 0; $i <= $count - $len; $i++ ) {
                    $chunk = implode( ' ', array_slice( $words, $i, $len ) );
                    $chunk = trim( $chunk );
                    if ( '' === $chunk ) {
                        continue;
                    }

                    if ( function_exists( 'mb_strlen' ) ) {
                        if ( mb_strlen( $chunk, 'UTF-8' ) < 2 ) {
                            continue;
                        }
                    } elseif ( strlen( $chunk ) < 2 ) {
                        continue;
                    }

                    $phrases[] = $chunk;
                }
            }
        }

        $phrases = array_values( array_unique( $phrases ) );

        usort( $phrases, function ( $a, $b ) {
            $len_a = function_exists( 'mb_strlen' ) ? mb_strlen( $a, 'UTF-8' ) : strlen( $a );
            $len_b = function_exists( 'mb_strlen' ) ? mb_strlen( $b, 'UTF-8' ) : strlen( $b );

            if ( $len_a === $len_b ) {
                return 0;
            }

            return ( $len_a < $len_b ) ? 1 : -1;
        } );

        return $phrases;
    }

    protected static function resolve_job_title_from_message( $normalized_message, $table, $title_column ) {
        global $wpdb;

        static $cache = array();

        $cache_key = md5( $normalized_message . '|' . $table . '|' . $title_column );
        if ( isset( $cache[ $cache_key ] ) ) {
            return $cache[ $cache_key ];
        }

        $job_title = '';
        $phrases   = self::build_job_lookup_phrases( $normalized_message );

        foreach ( $phrases as $phrase ) {
            $like = '%' . $wpdb->esc_like( $phrase ) . '%';
            $row  = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT {$title_column} AS job_title FROM {$table} WHERE {$title_column} LIKE %s ORDER BY CHAR_LENGTH({$title_column}) ASC LIMIT 1",
                    $like
                )
            );

            if ( $row && ! empty( $row->job_title ) ) {
                $job_title = $row->job_title;
                break;
            }
        }

        if ( '' === $job_title ) {
            $compact = preg_replace( '/\s+/u', '', self::normalize_lookup_text( $normalized_message ) );
            if ( '' !== $compact ) {
                $like = '%' . $wpdb->esc_like( $compact ) . '%';
                $row  = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT {$title_column} AS job_title FROM {$table} WHERE REPLACE(REPLACE(REPLACE({$title_column}, '‌', ''), ' ', ''), '-', '') LIKE %s LIMIT 1",
                        $like
                    )
                );

                if ( $row && ! empty( $row->job_title ) ) {
                    $job_title = $row->job_title;
                }
            }
        }

        $cache[ $cache_key ] = $job_title;

        return $job_title;
    }

    public static function resolve_model( $maybe = '' ) {
        $maybe = is_string( $maybe ) ? trim( $maybe ) : '';
        if ( $maybe && in_array( $maybe, self::$allowed_models, true ) ) {
            return $maybe;
        }

        $stored = trim( (string) get_option( 'bkja_model', '' ) );
        if ( $stored && in_array( $stored, self::$allowed_models, true ) ) {
            return $stored;
        }

        return 'gpt-4o-mini';
    }

    public static function build_cache_key( $message, $category = '', $model = '', $job_title = '' ) {
        $normalized = self::normalize_message( $message );
        $category   = is_string( $category ) ? trim( $category ) : '';
        $model      = self::resolve_model( $model );
        $job_title  = is_string( $job_title ) ? trim( $job_title ) : '';

        $parts = array(
            'msg:' . $normalized,
            'cat:' . $category,
            'm:' . $model,
        );

        if ( '' !== $job_title ) {
            $parts[] = 'job:' . self::normalize_message( $job_title );
        }

        return 'bkja_cache_' . md5( implode( '|', $parts ) );
    }

    protected static function is_cache_enabled() {
        return '1' === (string) get_option( 'bkja_enable_cache', '1' );
    }

    protected static function get_cache_ttl( $model ) {
        $model = self::resolve_model( $model );

        $custom_mini   = absint( get_option( 'bkja_cache_ttl_mini' ) );
        $custom_others = absint( get_option( 'bkja_cache_ttl_others' ) );

        if ( 'gpt-4o-mini' === $model ) {
            return $custom_mini > 0 ? $custom_mini : HOUR_IN_SECONDS;
        }

        if ( in_array( $model, array( 'gpt-4o', 'gpt-4', 'gpt-5' ), true ) ) {
            $ttl = 2 * HOUR_IN_SECONDS;
            return $custom_others > 0 ? $custom_others : $ttl;
        }

        return $custom_others > 0 ? $custom_others : HOUR_IN_SECONDS;
    }

    protected static function should_accept_cached_payload( $normalized_message, $payload ) {
        if ( empty( $normalized_message ) || empty( $payload ) ) {
            return false;
        }

        if ( is_array( $payload ) ) {
            $source = isset( $payload['source'] ) ? $payload['source'] : '';
            if ( empty( $source ) && isset( $payload['meta'] ) && is_array( $payload['meta'] ) ) {
                $source = isset( $payload['meta']['source'] ) ? $payload['meta']['source'] : '';
            }

            if ( in_array( $source, array( 'database', 'job_context' ), true ) ) {
                $api_key = self::get_api_key();
                if ( ! empty( $api_key ) ) {
                    return false;
                }
            }

            $text = isset( $payload['text'] ) ? $payload['text'] : '';
        } else {
            $text = (string) $payload;
        }

        $text = (string) $text;

        $keywords = array( 'درآمد', 'حقوق', 'سرمایه' );
        $haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $normalized_message, 'UTF-8' ) : strtolower( $normalized_message );

        foreach ( $keywords as $keyword ) {
            $keyword_check = function_exists( 'mb_strpos' ) ? mb_strpos( $haystack, $keyword ) : strpos( $haystack, $keyword );
            if ( false !== $keyword_check ) {
                if ( ! preg_match( '/[0-9۰-۹]+/u', $text ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    protected static function cache_payload( $enabled, $cache_key, $payload, $model ) {
        if ( ! $enabled ) {
            return;
        }

        if ( '' === $cache_key || empty( $payload ) || ! is_array( $payload ) ) {
            return;
        }

        set_transient( $cache_key, $payload, self::get_cache_ttl( $model ) );
    }

    protected static function extract_payload_job_title( $payload ) {
        if ( empty( $payload ) || ! is_array( $payload ) ) {
            return '';
        }

        if ( ! empty( $payload['meta'] ) && is_array( $payload['meta'] ) && ! empty( $payload['meta']['job_title'] ) ) {
            return (string) $payload['meta']['job_title'];
        }

        if ( ! empty( $payload['job_title'] ) ) {
            return (string) $payload['job_title'];
        }

        return '';
    }

    protected static function clamp_history( $history, $limit = 3 ) {
        if ( ! is_array( $history ) || $limit <= 0 ) {
            return array();
        }

        if ( count( $history ) <= $limit ) {
            return $history;
        }

        return array_slice( $history, -1 * $limit );
    }

    protected static function get_feedback_hint( $normalized_message, $session_id, $user_id ) {
        if ( empty( $normalized_message ) || ! class_exists( 'BKJA_Database' ) ) {
            return '';
        }

        $row = BKJA_Database::get_latest_feedback( $normalized_message, $session_id, (int) $user_id );
        if ( empty( $row ) || (int) $row['vote'] !== -1 ) {
            return '';
        }

        $message = 'پاسخ قبلی برای این کاربر رضایت‌بخش نبود؛ لطفاً کوتاه‌تر، دقیق‌تر و عدد-محورتر پاسخ بده و در صورت وجود داده‌های داخلی، منبع را اعلام کن.';

        $tags = array();
        if ( ! empty( $row['tags'] ) ) {
            $parts = explode( ',', $row['tags'] );
            foreach ( $parts as $part ) {
                $part = trim( $part );
                if ( $part ) {
                    $tags[] = $part;
                }
            }
        }

        if ( $tags ) {
            $message .= ' نکات اعلام‌شده کاربر: ' . implode( ', ', $tags ) . '.';
        }

        if ( ! empty( $row['comment'] ) ) {
            $message .= ' توضیح کاربر: ' . trim( $row['comment'] ) . '.';
        }

        return $message;
    }

    // دریافت خلاصه و رکوردهای شغل مرتبط با پیام
    public static function get_job_context($message, $job_title_hint = '', $job_slug = '') {
        global $wpdb;

        $normalized = self::normalize_message( $message );
        $job_title_hint = is_string( $job_title_hint ) ? trim( $job_title_hint ) : '';
        $job_slug = is_string( $job_slug ) ? trim( $job_slug ) : '';

        if ( '' === $normalized && '' === $job_title_hint && '' === $job_slug ) {
            return array();
        }

        $table = $wpdb->prefix . 'bkja_jobs';

        static $title_column = null;
        if ( null === $title_column ) {
            $columns = $wpdb->get_col( "DESC {$table}", 0 );
            if ( is_array( $columns ) && in_array( 'job_title', $columns, true ) ) {
                $title_column = 'job_title';
            } else {
                $title_column = 'title';
            }
        }

        $job_title = '';

        if ( '' !== $normalized ) {
            $job_title = self::resolve_job_title_from_message( $normalized, $table, $title_column );
        }

        if ( '' === $job_title && '' !== $job_title_hint ) {
            $exact = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT {$title_column} AS job_title FROM {$table} WHERE {$title_column} = %s LIMIT 1",
                    $job_title_hint
                )
            );

            if ( $exact && ! empty( $exact->job_title ) ) {
                $job_title = $exact->job_title;
            } else {
                $hint_normalized = self::normalize_lookup_text( $job_title_hint );
                if ( '' !== $hint_normalized ) {
                    $job_title = self::resolve_job_title_from_message( $hint_normalized, $table, $title_column );
                    if ( '' === $job_title ) {
                        $exact_hint = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT {$title_column} AS job_title FROM {$table} WHERE {$title_column} = %s LIMIT 1",
                                $hint_normalized
                            )
                        );
                        if ( $exact_hint && ! empty( $exact_hint->job_title ) ) {
                            $job_title = $exact_hint->job_title;
                        }
                    }
                }
            }
        }

        if ( '' === $job_title ) {
            return array();
        }

        $summary = class_exists('BKJA_Database') ? BKJA_Database::get_job_summary($job_title) : null;
        $records = class_exists('BKJA_Database') ? BKJA_Database::get_job_records($job_title, 5, 0) : [];
        return [
            'job_title' => $job_title,
            'summary'   => $summary,
            'records'   => $records,
            'job_slug'  => '' !== $job_slug ? $job_slug : null
        ];
    }

    protected static function build_context_prompt( $context ) {
        if ( empty( $context['job_title'] ) ) {
            return '';
        }

        $title     = $context['job_title'];
        $lines     = array();
        $lines[]   = "داده‌های داخلی ساخت‌یافته درباره شغل «{$title}»:";
        $max_lines = 14;

        $append_line = static function( &$lines, $text ) use ( $max_lines ) {
            $text = is_string( $text ) ? trim( $text ) : '';
            if ( '' === $text ) {
                return;
            }

            if ( count( $lines ) >= $max_lines ) {
                return;
            }

            $lines[] = $text;
        };

        if ( ! empty( $context['summary'] ) && is_array( $context['summary'] ) ) {
            $summary = $context['summary'];

            if ( ! empty( $summary['income'] ) ) {
                $income_line = 'تحلیل درآمد: ' . self::truncate_text( $summary['income'], 90 );
                if ( ! empty( $summary['income_reports'] ) ) {
                    $income_line .= ' (بر اساس ' . number_format_i18n( (int) $summary['income_reports'] ) . ' گزارش)';
                }
                $append_line( $lines, $income_line );
            } else {
                $append_line( $lines, 'تحلیل درآمد: نامشخص/تقریبی' );
            }

            if ( ! empty( $summary['income_top_samples'] ) && is_array( $summary['income_top_samples'] ) ) {
                $append_line( $lines, 'مقادیر پرتکرار درآمد: ' . implode( '، ', array_slice( $summary['income_top_samples'], 0, 3 ) ) );
            }

            if ( ! empty( $summary['investment'] ) ) {
                $investment_line = 'تحلیل سرمایه لازم: ' . self::truncate_text( $summary['investment'], 90 );
                if ( ! empty( $summary['investment_reports'] ) ) {
                    $investment_line .= ' (بر اساس ' . number_format_i18n( (int) $summary['investment_reports'] ) . ' گزارش)';
                }
                $append_line( $lines, $investment_line );
            } else {
                $append_line( $lines, 'تحلیل سرمایه لازم: نامشخص/تقریبی' );
            }

            if ( ! empty( $summary['investment_top_samples'] ) && is_array( $summary['investment_top_samples'] ) ) {
                $append_line( $lines, 'مقادیر پرتکرار سرمایه: ' . implode( '، ', array_slice( $summary['investment_top_samples'], 0, 3 ) ) );
            }

            if ( ! empty( $summary['cities'] ) ) {
                $append_line( $lines, 'شهرهای پرتکرار تجربه‌شده: ' . self::truncate_text( $summary['cities'], 80 ) );
            }
            if ( ! empty( $summary['genders'] ) ) {
                $append_line( $lines, 'مخاطبان مناسب: ' . self::truncate_text( $summary['genders'], 80 ) );
            }
            if ( ! empty( $summary['genders'] ) ) {
                $lines[] = 'مخاطبان مناسب: ' . $summary['genders'];
            }
            if ( ! empty( $summary['advantages'] ) ) {
                $append_line( $lines, 'مزایای پرتکرار: ' . self::truncate_text( $summary['advantages'], 120 ) );
            }
            if ( ! empty( $summary['disadvantages'] ) ) {
                $append_line( $lines, 'چالش‌های پرتکرار: ' . self::truncate_text( $summary['disadvantages'], 120 ) );
            }
            if ( ! empty( $summary['records_count'] ) ) {
                $append_line( $lines, 'تعداد کل رکوردهای داخلی برای این عنوان: ' . number_format_i18n( (int) $summary['records_count'] ) );
            }
            if ( ! empty( $summary['records_count'] ) ) {
                $lines[] = 'تعداد کل رکوردهای داخلی برای این عنوان: ' . number_format_i18n( (int) $summary['records_count'] );
            }
        }

        if ( ! empty( $context['records'] ) && is_array( $context['records'] ) ) {
            $records = array_slice( $context['records'], 0, 2 );
            $index   = 1;
            foreach ( $records as $record ) {
                if ( ! is_array( $record ) ) {
                    continue;
                }
                $parts = array();
                $parts[] = 'درآمد: ' . ( ! empty( $record['income'] ) ? $record['income'] : 'نامشخص' );
                $parts[] = 'سرمایه: ' . ( ! empty( $record['investment'] ) ? $record['investment'] : 'نامشخص' );
                if ( ! empty( $record['city'] ) ) {
                    $parts[] = 'شهر: ' . $record['city'];
                }
                if ( ! empty( $record['advantages'] ) ) {
                    $parts[] = 'مزایا: ' . self::truncate_text( $record['advantages'], 80 );
                }
                if ( ! empty( $record['disadvantages'] ) ) {
                    $parts[] = 'معایب: ' . self::truncate_text( $record['disadvantages'], 80 );
                }
                $append_line( $lines, 'نمونه تجربه ' . $index . ': ' . implode( ' | ', array_filter( array_map( 'trim', $parts ) ) ) );
                if ( ! empty( $record['details'] ) ) {
                    $append_line( $lines, 'خلاصه تجربه: ' . self::truncate_text( $record['details'], 140 ) );
                }
                $index++;
            }
        }

        $append_line( $lines, 'پاسخ نهایی باید مرحله‌به‌مرحله، عدد-محور و بر اساس همین داده‌ها باشد و اگر داده‌ای وجود ندارد حتماً «نامشخص/تقریبی» اعلام شود. موضوع گفتگو را تغییر نده.' );

        return implode( "\n", array_filter( array_map( 'trim', $lines ) ) );
    }

    protected static function format_job_context_reply( $context ) {
        if ( empty( $context['job_title'] ) ) {
            return '';
        }

        $title   = $context['job_title'];
        $summary = ( ! empty( $context['summary'] ) && is_array( $context['summary'] ) ) ? $context['summary'] : array();
        $records = ( ! empty( $context['records'] ) && is_array( $context['records'] ) ) ? $context['records'] : array();

        $sections = array();

        $sections[] = "📌 خلاصه سریع درباره «{$title}»:";
        if ( ! empty( $summary ) ) {
            $intro = '• داده‌های داخلی کاربران BKJA برای این شغل در دسترس است و اعداد زیر از همان داده‌ها استخراج شده است.';
            if ( ! empty( $summary['records_count'] ) ) {
                $intro .= ' (' . number_format_i18n( (int) $summary['records_count'] ) . ' تجربه ثبت شده)';
            }
            $sections[] = $intro;
            if ( ! empty( $summary['cities'] ) ) {
                $sections[] = '• شهرهای پرتکرار: ' . self::truncate_text( $summary['cities'], 80 );
            }
            if ( ! empty( $summary['genders'] ) ) {
                $sections[] = '• مناسب برای: ' . self::truncate_text( $summary['genders'], 80 );
            }
            if ( ! empty( $summary['advantages'] ) ) {
                $sections[] = '• مهم‌ترین مزایا: ' . self::truncate_text( $summary['advantages'], 120 );
            }
            if ( ! empty( $summary['disadvantages'] ) ) {
                $sections[] = '• چالش‌های رایج: ' . self::truncate_text( $summary['disadvantages'], 120 );
            }
            if ( ! empty( $summary['advantages'] ) ) {
                $sections[] = '• مهم‌ترین مزایا: ' . $summary['advantages'];
            }
            if ( ! empty( $summary['disadvantages'] ) ) {
                $sections[] = '• چالش‌های رایج: ' . $summary['disadvantages'];
            }
        } else {
            $sections[] = '• هنوز داده‌ای در پایگاه ما ثبت نشده؛ بنابراین برآوردها باید با احتیاط بررسی شوند.';
        }

        $sections[] = '';
        $sections[] = '💵 درآمد تقریبی:';
        $income_lines = array();
        if ( ! empty( $summary['income'] ) && 'نامشخص' !== $summary['income'] ) {
            $income_lines[] = '• ' . self::truncate_text( $summary['income'], 100 );
        }
        if ( ! empty( $summary['income_reports'] ) ) {
            $income_lines[] = '• تعداد گزارش‌های درآمد: ' . number_format_i18n( (int) $summary['income_reports'] );
        }
        if ( ! empty( $summary['income_top_samples'] ) && is_array( $summary['income_top_samples'] ) ) {            $income_lines[] = '• رایج‌ترین اعداد کاربران: ' . implode( '، ', array_slice( $summary['income_top_samples'], 0, 3 ) );
        }
        $income_samples = array();
        foreach ( array_slice( $records, 0, 3 ) as $record ) {
            if ( empty( $record['income'] ) ) {
                continue;
            }
            $value = trim( (string) $record['income'] );
            if ( '' !== $value && ! in_array( $value, $income_samples, true ) ) {
                $income_samples[] = $value;
            }
        }
        if ( ! empty( $income_samples ) ) {
            $income_lines[] = '• نمونه گزارش کاربران: ' . implode( '، ', array_slice( $income_samples, 0, 3 ) );
        }
        if ( empty( $income_lines ) ) {
            $income_lines[] = '• نامشخص (داده‌ی معتبری ثبت نشده است).';
        }
        $sections = array_merge( $sections, $income_lines );

        $sections[] = '';
        $sections[] = '💰 سرمایه و ملزومات راه‌اندازی:';
        $investment_lines = array();
        if ( ! empty( $summary['investment'] ) && 'نامشخص' !== $summary['investment'] ) {
            $investment_lines[] = '• ' . self::truncate_text( $summary['investment'], 100 );
        }
        if ( ! empty( $summary['investment_reports'] ) ) {
            $investment_lines[] = '• تعداد گزارش‌های سرمایه: ' . number_format_i18n( (int) $summary['investment_reports'] );
        }
        if ( ! empty( $summary['investment_top_samples'] ) && is_array( $summary['investment_top_samples'] ) ) {
            $investment_lines[] = '• سرمایه‌های پرتکرار: ' . implode( '، ', array_slice( $summary['investment_top_samples'], 0, 3 ) );
        }
        $investment_samples = array();
        foreach ( array_slice( $records, 0, 3 ) as $record ) {
            if ( empty( $record['investment'] ) ) {
                continue;
            }
            $value = trim( (string) $record['investment'] );
            if ( '' !== $value && ! in_array( $value, $investment_samples, true ) ) {
                $investment_samples[] = $value;
            }
        }
        if ( ! empty( $investment_samples ) ) {
            $investment_lines[] = '• سرمایه‌های گزارش‌شده: ' . implode( '، ', array_slice( $investment_samples, 0, 3 ) );
        }
        if ( empty( $investment_lines ) ) {
            $investment_lines[] = '• نامشخص (کاربران هنوز سرمایه لازم را ثبت نکرده‌اند).';
        }
        $sections = array_merge( $sections, $investment_lines );

        $sections[] = '';
        $sections[] = '🛠 مهارت‌های کلیدی و شرایط کاری:';
        if ( ! empty( $summary['advantages'] ) ) {
            $sections[] = '• مزایا: ' . self::truncate_text( $summary['advantages'], 120 );
        }
        if ( ! empty( $summary['disadvantages'] ) ) {
            $sections[] = '• چالش‌های رایج: ' . self::truncate_text( $summary['disadvantages'], 120 );
        }
        if ( empty( $summary['advantages'] ) && empty( $summary['disadvantages'] ) ) {
            $sections[] = '• برای شناخت مهارت‌های ضروری با فعالان این حوزه گفتگو کن یا به دوره‌های تخصصی مراجعه کن.';
        }

        if ( ! empty( $records ) ) {
            $sections[] = '';
            $sections[] = '🧪 چند تجربه واقعی کاربران:';
            foreach ( array_slice( $records, 0, 2 ) as $record ) {
                if ( ! is_array( $record ) ) {
                    continue;
                }
                $parts = array();
                if ( ! empty( $record['income'] ) ) {
                    $parts[] = 'درآمد: ' . self::truncate_text( $record['income'], 60 );
                }
                if ( ! empty( $record['investment'] ) ) {
                    $parts[] = 'سرمایه: ' . self::truncate_text( $record['investment'], 60 );
                }
                if ( ! empty( $record['city'] ) ) {
                    $parts[] = 'شهر: ' . $record['city'];
                }
                if ( ! empty( $record['details'] ) ) {
                    $parts[] = 'تجربه: ' . self::truncate_text( $record['details'], 120 );
                }
                if ( ! empty( $parts ) ) {
                    $sections[] = '• ' . implode( ' | ', $parts );
                }
            }
        }

        $sections[] = '';
        $sections[] = '🚀 قدم بعدی پیشنهادی:';
        $sections[] = '• یک فهرست کوتاه از مهارت‌ها و ابزار لازم تهیه کن و هزینه‌ی واقعی هر کدام را برآورد کن.';
        $sections[] = '• با دو نفر از فعالان «' . $title . '» مصاحبه کوتاه انجام بده تا برآورد درآمد و سرمایه را تأیید یا اصلاح کنی.';
        $sections[] = '• اگر رقم سرمایه مشخصی در ذهن داری (مثلاً ۵۰۰ میلیون یا یک میلیارد تومان)، بگو تا سناریوهای مناسب همان بودجه را ارائه کنم.';

        return implode( "\n", array_filter( array_map( 'trim', $sections ), function ( $line ) {
            return $line !== '' || $line === '0';
        } ) );
    }

    protected static function build_context_fallback_payload( $context, $message, $model, $resolved_category, $normalized_message, $job_title_hint = '', $job_slug = '' ) {
        if ( empty( $context ) || ! is_array( $context ) ) {
            return null;
        }

        $job_title = ! empty( $context['job_title'] ) ? $context['job_title'] : $job_title_hint;
        $slug      = '';

        if ( isset( $context['job_slug'] ) && '' !== $context['job_slug'] ) {
            $slug = $context['job_slug'];
        } elseif ( '' !== $job_slug ) {
            $slug = $job_slug;
        }

        return self::build_response_payload(
            self::format_job_context_reply( $context ),
            $context,
            $message,
            false,
            'job_context',
            array(
                'model'              => $model,
                'category'           => $resolved_category,
                'job_title'          => $job_title,
                'job_slug'           => $slug,
                'normalized_message' => $normalized_message,
            )
        );
    }

    protected static function build_followup_suggestions( $message, $context = array(), $answer = '' ) {
        $suggestions = array();
        $push = function( $text ) use ( &$suggestions ) {
            $text = trim( (string) $text );
            if ( $text && ! in_array( $text, $suggestions, true ) ) {
                $suggestions[] = $text;
            }
        };

        $job_title = '';
        if ( ! empty( $context['job_title'] ) ) {
            $job_title = trim( (string) $context['job_title'] );
        }

        $normalize = function( $text ) {
            if ( ! is_string( $text ) ) {
                $text = (string) $text;
            }

            if ( function_exists( 'mb_strtolower' ) ) {
                $text = mb_strtolower( $text, 'UTF-8' );
            } else {
                $text = strtolower( $text );
            }

            return trim( preg_replace( '/\s+/u', ' ', $text ) );
        };

        $message_norm = $normalize( $message );
        $answer_norm  = $normalize( $answer );

        $topics = array(
            'income'      => array( 'درآمد', 'حقوق', 'دستمزد' ),
            'investment'  => array( 'سرمایه', 'هزینه', 'بودجه', 'تجهیز' ),
            'skills'      => array( 'مهارت', 'آموزش', 'یادگیری', 'دوره' ),
            'market'      => array( 'بازار', 'تقاضا', 'استخدام', 'فرصت' ),
            'risk'        => array( 'چالش', 'ریسک', 'مشکل', 'دغدغه', 'سختی' ),
            'growth'      => array( 'پیشرفت', 'رشد', 'مسیر', 'نقشه راه' ),
            'tools'       => array( 'ابزار', 'گواهی', 'مدرک', 'تجهیزات' ),
            'personality' => array( 'شخصیت', 'تیپ', 'روحیه' ),
            'compare'     => array( 'مقایسه', 'جایگزین', 'مشابه', 'دیگر' ),
        );

        $topic_state = array();
        foreach ( $topics as $topic => $keywords ) {
            $topic_state[ $topic ] = array(
                'message' => false,
                'answer'  => false,
            );

            foreach ( $keywords as $keyword ) {
                $keyword = trim( $keyword );
                if ( '' === $keyword ) {
                    continue;
                }

                $found_in_message = function_exists( 'mb_strpos' )
                    ? mb_strpos( $message_norm, $keyword )
                    : strpos( $message_norm, $keyword );
                $found_in_answer  = function_exists( 'mb_strpos' )
                    ? mb_strpos( $answer_norm, $keyword )
                    : strpos( $answer_norm, $keyword );

                if ( false !== $found_in_message ) {
                    $topic_state[ $topic ]['message'] = true;
                }
                if ( false !== $found_in_answer ) {
                    $topic_state[ $topic ]['answer'] = true;
                }
            }
        }

        $job_fragment = $job_title ? "«{$job_title}»" : 'این حوزه';

        $topic_prompts = array(
            'income'     => "حدود درآمد {$job_fragment} در سطوح مختلف تجربه چقدر است؟",
            'investment' => "برای شروع {$job_fragment} چه مقدار سرمایه و تجهیزات لازم است؟",
            'skills'     => "چه مهارت‌های نرم و سختی برای موفقیت در {$job_fragment} ضروری است؟",
            'market'     => "چشم‌انداز بازار کار {$job_fragment} در یک تا سه سال آینده چگونه است؟",
            'risk'       => "مهم‌ترین چالش‌ها و ریسک‌های {$job_fragment} چیست و چطور باید مدیریت‌شان کرد؟",
            'growth'     => "یک نقشه راه مرحله‌به‌مرحله برای پیشرفت در {$job_fragment} پیشنهاد بده.",
            'tools'      => "کدام ابزار، گواهی یا دوره برای شروع {$job_fragment} توصیه می‌شود؟",
        );

        foreach ( $topic_prompts as $topic => $prompt ) {
            if ( empty( $topic_state[ $topic ] ) ) {
                continue;
            }

            $was_asked   = ! empty( $topic_state[ $topic ]['message'] );
            $was_answered = ! empty( $topic_state[ $topic ]['answer'] );

            if ( $was_asked && ! $was_answered ) {
                $push( $prompt );
            }
        }

        if ( $job_title ) {
            if ( empty( $topic_state['skills']['answer'] ) ) {
                $push( "برای موفقیت در {$job_fragment} چه مهارت‌هایی را باید از همین حالا تمرین کنم؟" );
            }
            if ( empty( $topic_state['market']['answer'] ) ) {
                $push( "بازار کار {$job_fragment} در ایران و خارج چه تفاوت‌هایی دارد؟" );
            }
            if ( empty( $topic_state['risk']['answer'] ) ) {
                $push( "بزرگ‌ترین اشتباهات رایج در مسیر {$job_fragment} چیست و چطور از آن‌ها دوری کنم؟" );
            }
            if ( empty( $topic_state['compare']['message'] ) ) {
                $push( "شغل‌های جایگزین نزدیک به {$job_fragment} که ارزش بررسی دارند را معرفی کن." );
            }
        }

        if ( empty( $suggestions ) ) {
            if ( empty( $topic_state['personality']['message'] ) ) {
                if ( $job_title ) {
                    $push( "آیا {$job_fragment} با ویژگی‌های شخصیتی من هماهنگ است؟ اگر لازم است سوال بپرس." );
                } else {
                    $push( 'اگر بخوای بررسی کنی این حوزه با شخصیت من هماهنگ است از چه سوالاتی شروع می‌کنی؟' );
                }
            }
            $push( 'به من کمک کن بدانم قدم بعدی منطقی برای تحقیق بیشتر درباره این موضوع چیست.' );
        }

        $capital_keywords = '/سرمایه|بودجه|سرمایه‌گذاری|پول|سرمایه گذاری/u';
        if ( preg_match( $capital_keywords, $message_norm ) ) {
            $capital_prompt = '';
            if ( preg_match( '/([0-9۰-۹]+[0-9۰-۹\.,]*)\s*(میلیارد|میلیون|هزار)?\s*(تومان|تومن|ریال)?/u', $message_norm, $amount_match ) ) {
                $amount_text = trim( $amount_match[0] );
                if ( $amount_text ) {
                    $capital_prompt = 'برای سرمایه ' . $amount_text . ' چه مسیرهای شغلی مطمئن و قابل راه‌اندازی پیشنهاد می‌کنی؟';
                }
            }

            if ( '' === $capital_prompt ) {
                $capital_prompt = 'اگر سرمایه مشخصی دارم چطور انتخاب کنم کدام شغل با آن بودجه قابل شروع است؟';
            }

            $capital_prompt = trim( $capital_prompt );
            if ( $capital_prompt && ! in_array( $capital_prompt, $suggestions, true ) ) {
                array_unshift( $suggestions, $capital_prompt );
            }
        }

        return array_slice( $suggestions, 0, 3 );
    }

    protected static function try_answer_from_db( $original_message, &$context = null, $model = '', $category = '', $normalized_message = null, $job_title_hint = '', $job_slug = '' ) {
        if ( null === $normalized_message ) {
            $normalized_message = self::normalize_message( $original_message );
        }

        if ( null === $context ) {
            $context = self::get_job_context( $normalized_message, $job_title_hint, $job_slug );
        }

        if ( empty( $context['job_title'] ) ) {
            return null;
        }

        $reply = self::format_job_context_reply( $context );
        if ( '' === trim( (string) $reply ) ) {
            return null;
        }

        return self::build_response_payload(
            $reply,
            $context,
            $original_message,
            false,
            'database',
            array(
                'model'              => self::resolve_model( $model ),
                'category'           => is_string( $category ) ? $category : '',
                'job_title'          => ! empty( $context['job_title'] ) ? $context['job_title'] : '',
                'job_slug'           => isset( $context['job_slug'] ) ? $context['job_slug'] : '',
                'normalized_message' => $normalized_message,
            )
        );
    }

    protected static function build_response_payload( $text, $context, $message, $from_cache = false, $source = 'openai', $extra = array() ) {
        $context_used = ! empty( $context['job_title'] );

        $payload = array(
            'text'         => (string) $text,
            'suggestions'  => self::build_followup_suggestions( $message, $context, $text ),
            'context_used' => $context_used,
            'from_cache'   => (bool) $from_cache,
            'source'       => $source,
            'job_title'    => ! empty( $context['job_title'] ) ? $context['job_title'] : '',
            'job_slug'     => isset( $context['job_slug'] ) ? $context['job_slug'] : '',
        );

        if ( ! empty( $extra ) && is_array( $extra ) ) {
            $payload = array_merge( $payload, $extra );
        }

        $resolved_category = null;
        if ( isset( $payload['category'] ) && '' !== $payload['category'] ) {
            $resolved_category = $payload['category'];
        } elseif ( isset( $extra['category'] ) && '' !== $extra['category'] ) {
            $resolved_category = $extra['category'];
        }

        $resolved_job_title = null;
        if ( ! empty( $context['job_title'] ) ) {
            $resolved_job_title = $context['job_title'];
        } elseif ( isset( $payload['job_title'] ) && '' !== $payload['job_title'] ) {
            $resolved_job_title = $payload['job_title'];
        }

        $resolved_job_slug = null;
        if ( isset( $context['job_slug'] ) && '' !== $context['job_slug'] ) {
            $resolved_job_slug = $context['job_slug'];
        } elseif ( isset( $payload['job_slug'] ) && '' !== $payload['job_slug'] ) {
            $resolved_job_slug = $payload['job_slug'];
        }

        $payload['meta'] = array(
            'context_used' => $context_used,
            'from_cache'   => (bool) $from_cache,
            'source'       => $source,
            'category'     => $resolved_category,
            'job_title'    => $resolved_job_title,
            'job_slug'     => $resolved_job_slug,
        );

        return $payload;
    }

    public static function delete_cache_for( $message, $category = '', $model = '', $job_title = '' ) {
        $key = self::build_cache_key( $message, $category, $model, $job_title );
        delete_transient( $key );

        if ( '' !== $job_title ) {
            $legacy_key = self::build_cache_key( $message, $category, $model );
            delete_transient( $legacy_key );
        }
    }

    public static function extend_cache_ttl( $message, $category = '', $model = '', $ttl = 0, $job_title = '' ) {
        if ( ! self::is_cache_enabled() ) {
            return;
        }

        $key      = self::build_cache_key( $message, $category, $model, $job_title );
        $payload  = get_transient( $key );
        if ( false === $payload && '' !== $job_title ) {
            $legacy_key = self::build_cache_key( $message, $category, $model );
            $legacy     = get_transient( $legacy_key );
            if ( false !== $legacy ) {
                $key     = $legacy_key;
                $payload = $legacy;
            }
        }
        if ( false === $payload ) {
            return;
        }

        $ttl = (int) $ttl;
        if ( $ttl <= 0 ) {
            $ttl = 3 * HOUR_IN_SECONDS;
        }

        set_transient( $key, $payload, $ttl );
    }

    public static function flush_cache_prefix( $prefix = 'bkja_cache_' ) {
        global $wpdb;

        if ( empty( $wpdb ) || empty( $wpdb->options ) ) {
            return;
        }

        $like          = $wpdb->esc_like( $prefix ) . '%';
        $transient_sql = $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_' . $like );
        $timeout_sql   = $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_' . $like );

        $wpdb->query( $transient_sql );
        $wpdb->query( $timeout_sql );
    }

    public static function call_openai( $message, $args = array() ) {
        if ( empty( $message ) ) {
            return new WP_Error( 'empty_message', 'Message is empty' );
        }

        if ( class_exists( 'BKJA_Database' ) ) {
            BKJA_Database::ensure_feedback_table();
        }

        $defaults = array(
            'system'         => 'شما یک دستیار شغلی فارسی و عدد-محور هستید. پاسخ را در پنج بخش تیتر‌دار (خلاصه سریع، درآمد تقریبی، سرمایه و ملزومات، مهارت‌ها و مسیر رشد، قدم‌های بعدی و گزینه‌های جایگزین) با حداکثر سه بولت فشرده برای هر بخش ارائه کن. اعداد را دقیق یا با برچسب «نامشخص/تقریبی» بیان کن، اگر سرمایه‌ای مطرح شد سناریوی متناسب با همان رقم بده، به داده‌های داخلی با ذکر منبع اشاره کن و موضوع گفتگو را تغییر نده. لحن باید طبیعی اما حرفه‌ای باشد و در پایان یک اقدام عملی برای ادامه تحقیق پیشنهاد بده.',
            'model'          => '',
            'session_id'     => '',
            'user_id'        => 0,
            'category'       => '',
            'job_title_hint' => '',
            'job_slug'       => '',
        );
        $args              = wp_parse_args( $args, $defaults );
        $model             = self::resolve_model( $args['model'] );
        $system            = ! empty( $args['system'] ) ? $args['system'] : $defaults['system'];
        $resolved_category = is_string( $args['category'] ) ? $args['category'] : '';
        $job_title_hint    = is_string( $args['job_title_hint'] ) ? trim( $args['job_title_hint'] ) : '';
        $job_slug          = is_string( $args['job_slug'] ) ? trim( $args['job_slug'] ) : '';

        $normalized_message = self::normalize_message( $message );
        $context            = self::get_job_context( $normalized_message, $job_title_hint, $job_slug );

        $cache_job_title = '';
        if ( ! empty( $context['job_title'] ) ) {
            $cache_job_title = $context['job_title'];
        } elseif ( '' !== $job_title_hint ) {
            $cache_job_title = $job_title_hint;
        }

        $api_key = self::get_api_key();

        $cache_enabled   = self::is_cache_enabled();
        if ( '' === $cache_job_title ) {
            if ( ! empty( $context['job_title'] ) ) {
                $cache_job_title = $context['job_title'];
            } elseif ( '' !== $job_title_hint ) {
                $cache_job_title = $job_title_hint;
            }
        }

        $cache_key        = self::build_cache_key( $normalized_message, $resolved_category, $model, $cache_job_title );
        $legacy_cache_key = '';
        if ( $cache_enabled && '' !== $cache_job_title ) {
            $legacy_cache_key = self::build_cache_key( $normalized_message, $resolved_category, $model );
        }
        $fallback_payload = self::build_context_fallback_payload(
            $context,
            $message,
            $model,
            $resolved_category,
            $normalized_message,
            $cache_job_title,
            $job_slug
        );
        if ( $cache_enabled ) {
            $cached = get_transient( $cache_key );
            if ( false === $cached && '' !== $legacy_cache_key ) {
                $cached = get_transient( $legacy_cache_key );
            }
            if ( false !== $cached && self::should_accept_cached_payload( $normalized_message, $cached ) ) {
                if ( is_array( $cached ) ) {
                    $cached['from_cache']        = true;
                    $cached['model']             = isset( $cached['model'] ) ? $cached['model'] : $model;
                    $cached['category']          = $resolved_category;
                    $cached_job_title = '';
                    if ( ! empty( $context['job_title'] ) ) {
                        $cached_job_title = $context['job_title'];
                    } elseif ( ! empty( $cached['job_title'] ) ) {
                        $cached_job_title = $cached['job_title'];
                    }
                    if ( '' !== $cached_job_title ) {
                        $cached['job_title'] = $cached_job_title;
                    } else {
                        $cached['job_title'] = '';
                    }
                    $cached['normalized_message'] = $normalized_message;
                    if ( ! isset( $cached['meta'] ) || ! is_array( $cached['meta'] ) ) {
                        $cached['meta'] = array();
                    }
                    $cached['meta']['category'] = $resolved_category;
                    $cached['meta']['job_title'] = $cached_job_title;
                    $job_slug_value = '';
                    if ( ! empty( $context['job_slug'] ) ) {
                        $job_slug_value = $context['job_slug'];
                    } elseif ( '' !== $job_slug ) {
                        $job_slug_value = $job_slug;
                    }

                    if ( '' !== $job_slug_value ) {
                        $cached['job_slug']            = $job_slug_value;
                        $cached['meta']['job_slug']    = $job_slug_value;
                    } else {
                        $cached['job_slug']         = '';
                        $cached['meta']['job_slug'] = '';
                    }
                    if ( ! empty( $context ) ) {
                        $cached['meta']['category']   = $context['category'] ?? ( $cached['meta']['category'] ?? null );
                        $cached['meta']['job_title']  = $context['job_title'] ?? ( $cached['meta']['job_title'] ?? null );
                        $cached['meta']['job_slug']   = $context['job_slug'] ?? ( $cached['meta']['job_slug'] ?? null );
                    }
                    return $cached;
                }

                return self::build_response_payload(
                    $cached,
                    $context,
                    $message,
                    true,
                    'cache',
                    array(
                        'model'              => $model,
                        'category'           => $resolved_category,
                        'job_title'          => ! empty( $context['job_title'] ) ? $context['job_title'] : $cache_job_title,
                        'job_slug'           => ! empty( $context['job_slug'] ) ? $context['job_slug'] : $job_slug,
                        'normalized_message' => $normalized_message,
                    )
                );
            }
        }

        if ( empty( $api_key ) ) {
            $db_payload = self::try_answer_from_db( $message, $context, $model, $resolved_category, $normalized_message, $job_title_hint, $job_slug );
            if ( $db_payload ) {
                $db_payload['model']              = $model;
                $db_payload['category']           = $resolved_category;
                $db_payload['normalized_message'] = $normalized_message;

                self::cache_payload( $cache_enabled, $cache_key, $db_payload, $model );

                return $db_payload;
            }

            if ( $fallback_payload ) {
                self::cache_payload( $cache_enabled, $cache_key, $fallback_payload, $model );

                return $fallback_payload;
            }

            $job_title_for_meta = '';
            if ( ! empty( $context['job_title'] ) ) {
                $job_title_for_meta = $context['job_title'];
            } elseif ( '' !== $cache_job_title ) {
                $job_title_for_meta = $cache_job_title;
            }

            $job_slug_value = '';
            if ( ! empty( $context['job_slug'] ) ) {
                $job_slug_value = $context['job_slug'];
            } elseif ( '' !== $job_slug ) {
                $job_slug_value = $job_slug;
            }

            $local_payload = self::build_response_payload(
                'برای دریافت پاسخ دقیق‌تر لازم است مدیر سایت کلید API را در تنظیمات افزونه وارد کند. تا آن زمان می‌توانم صرفاً راهنمایی‌های کلی ارائه دهم.',
                ! empty( $context ) ? $context : array(),
                $message,
                false,
                'local_fallback',
                array(
                    'model'              => $model,
                    'category'           => $resolved_category,
                    'job_title'          => $job_title_for_meta,
                    'job_slug'           => $job_slug_value,
                    'normalized_message' => $normalized_message,
                )
            );

            self::cache_payload( $cache_enabled, $cache_key, $local_payload, $model );

            return $local_payload;
        }

        $messages = array(
            array(
                'role'    => 'system',
                'content' => $system,
            ),
        );

        if ( ! empty( $context ) ) {
            $context_prompt = self::build_context_prompt( $context );
            if ( $context_prompt ) {
                $messages[] = array(
                    'role'    => 'system',
                    'content' => $context_prompt,
                );
            }
        }

        $feedback_hint = self::get_feedback_hint( $normalized_message, $args['session_id'], (int) $args['user_id'] );
        if ( $feedback_hint ) {
            $messages[] = array(
                'role'    => 'system',
                'content' => $feedback_hint,
            );
        }

        if ( class_exists( 'BKJA_Database' ) ) {
            $history = BKJA_Database::get_recent_conversation( $args['session_id'], (int) $args['user_id'], 5 );
            $history = self::clamp_history( $history, 3 );
            foreach ( $history as $item ) {
                if ( empty( $item['content'] ) ) {
                    continue;
                }
                $messages[] = array(
                    'role'    => $item['role'] === 'assistant' ? 'assistant' : 'user',
                    'content' => $item['content'],
                );
            }
        }

        $messages[] = array(
            'role'    => 'user',
            'content' => $message,
        );

        $payload = array(
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0.2,
            'max_tokens'  => 380,
        );

        $request_args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 60,
        );

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', $request_args );
        if ( is_wp_error( $response ) ) {
            if ( $fallback_payload ) {
                self::cache_payload( $cache_enabled, $cache_key, $fallback_payload, $model );

                return $fallback_payload;
            }

            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 || empty( $data['choices'][0]['message']['content'] ) ) {
            if ( $fallback_payload ) {
                self::cache_payload( $cache_enabled, $cache_key, $fallback_payload, $model );

                return $fallback_payload;
            }

            return new WP_Error( 'api_error', 'OpenAI error: ' . substr( $body, 0, 250 ) );
        }

        $answer = trim( $data['choices'][0]['message']['content'] );
        $source = 'openai';

        if ( '' === $answer && ! empty( $context ) ) {
            $answer = self::format_job_context_reply( $context );
            $source = 'job_context';
        } elseif ( '' === $answer ) {
            return new WP_Error( 'empty_response', 'Empty response from model' );
        }

        $result = self::build_response_payload(
            $answer,
            $context,
            $message,
            false,
            $source,
            array(
                'model'              => $model,
                'category'           => $resolved_category,
                'job_title'          => ! empty( $context['job_title'] ) ? $context['job_title'] : $cache_job_title,
                'job_slug'           => ! empty( $context['job_slug'] ) ? $context['job_slug'] : $job_slug,
                'normalized_message' => $normalized_message,
            )
        );

        if ( $cache_enabled ) {
            $target_cache_key = $cache_key;
            $result_job_title = self::extract_payload_job_title( $result );

            if ( '' !== $result_job_title && $result_job_title !== $cache_job_title ) {
                $legacy_key_to_clear = '';
                if ( '' !== $cache_job_title ) {
                    $legacy_key_to_clear = self::build_cache_key( $normalized_message, $resolved_category, $model, $cache_job_title );
                }

                $target_cache_key = self::build_cache_key( $normalized_message, $resolved_category, $model, $result_job_title );

                if ( '' !== $legacy_key_to_clear && $legacy_key_to_clear !== $target_cache_key ) {
                    delete_transient( $legacy_key_to_clear );
                }
            }

            self::cache_payload( true, $target_cache_key, $result, $model );
        }

        return $result;
    }

}
