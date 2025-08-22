<?php
if (!defined('ABSPATH')) exit;

/**
 * Core generator
 * - Discovers topics
 * - Fetches competitor headlines via RSS
 * - Builds OpenAI prompt with readability + SEO requirements
 * - Checks duplicates (title similarity + content substring)
 * - Publishes and writes SEO meta for Yoast/RankMath/AIOSEO
 */

class AISA_H_Generator {

    public static function run_once( $max = 3 ) {
        $s = get_option('aisa_h_settings', array());
        $api_key = $s['openai_api_key'] ?? '';
        $brand = $s['brand'] ?? 'TurnUpHosting';
        $min_words = intval($s['min_words'] ?? 1000);
        $threshold = intval($s['duplicate_threshold'] ?? 80);

        // 1) Discover topics (simple seeded hosting topics + comparisons gleaned from competitor domains)
        $topics = self::discover_topics( $s, $max );

        // 2) Fetch recent competitor headlines (RSS) for context/avoidance
        $comp_headlines = self::fetch_competitor_headlines( $s );

        $published = 0;
        foreach( $topics as $topic ) {
            if ( $published >= $max ) break;

            // Build prompt with competitor headlines for context & to avoid duplication
            $system = 'You are a senior SEO writer producing clear, human-friendly, well-structured articles for the web hosting industry.';
            $prompt = self::build_prompt( $topic, $brand, $min_words, $comp_headlines );

            // Call OpenAI
            $content = AISA_H_OpenAI::complete( $api_key, $system, $prompt );
            if ( is_wp_error($content) ) {
                error_log('AISA_H OpenAI error: ' . $content->get_error_message());
                continue;
            }

            // Basic duplicate checks: title similarity & content substring
            $title = self::extract_title( $content ) ?: $topic;
            if ( self::is_duplicate( $title, $content, $threshold ) ) {
                error_log('AISA_H: Skipping duplicate topic: ' . $title);
                continue;
            }

            // Publish
            $postarr = array(
                'post_title' => wp_strip_all_tags( $title ),
                'post_content' => wp_kses_post( wpautop( $content ) ),
                'post_status' => $s['post_status'] ?? 'draft',
                'post_type' => 'post',
            );
            if ( ! empty( $s['publish_category'] ) ) {
                $postarr['post_category'] = array( intval($s['publish_category']) );
            }
            $pid = wp_insert_post( $postarr );
            if ( is_wp_error($pid) || ! $pid ) continue;

            // Write SEO metadata (Yoast/RankMath/AIOSEO fallbacks)
            self::write_seo_meta( $pid, $title, self::make_meta_description($content) );

            $published++;
        }

        return array('ok'=>true, 'message'=>"Published {$published} item(s).");
    }

    public static function discover_topics( $settings, $limit = 3 ) {
        $brand = $settings['brand'] ?? 'TurnUpHosting';
        $competitors_text = $settings['competitors'] ?? '';
        $competitors = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $competitors_text))));

        $topics = array();
        // Comparison topics for each competitor domain/name
        foreach( $competitors as $c ) {
            // derive short name
            $name = preg_replace('/^https?:\/\//','',$c);
            $name = preg_replace('/^www\./','',$name);
            $name = preg_replace('/\..*$/','',$name);
            $name = ucwords(str_replace(array('-','_'), ' ', $name));
            $topics[] = "{$brand} vs {$name}: Which Hosting Provider Should You Choose in 2025?";
        }

        // Hosting guides
        $seed = array(
            'Best Web Hosting for Small Businesses in 2025',
            'VPS vs Shared Hosting: Which Is Right for Your Site?',
            'How to Choose the Best Managed WordPress Hosting',
            'How SSD and NVMe Storage Improve Hosting Performance',
            'Hardening WordPress on cPanel: Security Checklist',
        );
        foreach($seed as $s) $topics[] = $s;

        // dedupe & limit
        $out = array();
        $seen = array();
        foreach($topics as $t) {
            $k = strtolower(trim($t));
            if ( isset($seen[$k]) ) continue;
            $seen[$k] = true;
            $out[] = $t;
            if ( count($out) >= $limit ) break;
        }
        return $out;
    }

    public static function fetch_competitor_headlines( $settings ) {
        include_once ABSPATH . WPINC . '/feed.php';
        $feeds_text = $settings['competitors'] ?? '';
        $feeds = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $feeds_text))));
        $titles = array();
        foreach($feeds as $f) {
            $feed = @fetch_feed($f);
            if ( is_wp_error($feed) || ! $feed ) continue;
            $items = $feed->get_items(0,10);
            foreach($items as $it) {
                $titles[] = wp_strip_all_tags( $it->get_title() );
            }
        }
        return array_slice(array_values(array_unique($titles)), 0, 50);
    }

    public static function build_prompt( $topic, $brand, $min_words, $comp_headlines = array() ) {
        $comp_text = '';
        if ( ! empty($comp_headlines) ) {
            $comp_text = "Recent competitor headlines:\n- " . implode("\n- ", array_slice($comp_headlines,0,10)) . "\n\n";
        }
        $prompt = "Write a clear, human-friendly, well-structured SEO article. Readability matters: use short paragraphs, simple sentences, bullet lists where helpful, and subheadings. Make it informative and authoritative for the web hosting industry.\n\n";
        $prompt .= "Title: {$topic}\n\n";
        $prompt .= "Include: an intro (2-3 short paragraphs), 4-6 H2 sections with H3 subsections where helpful, a comparison table if applicable, a short FAQ (3 Q&A), conclusion with an internal CTA to sign up for {$brand}, and a TL;DR summary at the top.\n\n";
        $prompt .= "Target length: {$min_words} words. Tone: human, expert yet friendly. Avoid copying competitor headlines verbatim. If this topic is a direct comparison, include balanced pros/cons and a clear recommendation.\n\n";
        if ( $comp_text ) $prompt .= $comp_text;
        $prompt .= "Write the article in plain English, easy to scan, with clear formatting markers (use headings). Also provide a 155-character meta description and 5 keyword suggestions at the end in JSON format like: {\"meta\":\"...\",\"keywords\":[...]}";
        return $prompt;
    }

    public static function extract_title( $content ) {
        // If model provided an H1 or first line, try to capture it
        if ( preg_match('/^#\s*(.+)/m', $content, $m) ) return trim($m[1]);
        if ( preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $content, $m) ) return wp_strip_all_tags($m[1]);
        // fallback: first sentence (up to 12 words)
        $text = wp_strip_all_tags($content);
        $words = preg_split('/\s+/', $text);
        $title = implode(' ', array_slice($words, 0, min(12, count($words))));
        return $title;
    }

    public static function is_duplicate( $title, $content, $threshold = 80 ) {
        // 1) Title similarity against existing post titles
        $args = array('post_type'=>'post','posts_per_page'=>500,'post_status'=>'any','fields'=>'ids');
        $posts = get_posts($args);
        foreach($posts as $pid) {
            $existing = get_the_title($pid);
            similar_text( strtolower($existing), strtolower($title), $perc );
            if ( $perc >= $threshold ) return true;
            // also check content substring match for moderate similarity
            $existing_content = wp_strip_all_tags(get_post_field('post_content', $pid));
            $short = substr($existing_content, 0, 1000);
            $common = similar_text( strtolower(substr($short,0,500)), strtolower(substr($content,0,500)), $p2 );
            if ( $p2 >= $threshold - 10 ) return true;
        }
        return false;
    }

    public static function make_meta_description( $content ) {
        $text = wp_strip_all_tags($content);
        $desc = mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 155);
        return $desc;
    }

    public static function write_seo_meta( $post_id, $title, $meta_desc ) {
        // Yoast
        update_post_meta( $post_id, '_yoast_wpseo_title', $title );
        update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
        // RankMath
        update_post_meta( $post_id, 'rank_math_title', $title );
        update_post_meta( $post_id, 'rank_math_description', $meta_desc );
        // AIOSEO
        update_post_meta( $post_id, '_aioseo_title', $title );
        update_post_meta( $post_id, '_aioseo_description', $meta_desc );
        // Generic WP meta
        update_post_meta( $post_id, '_aisa_meta_description', $meta_desc );
    }
}

// Expose action for manual runs
add_action('aisa_h_run_pipeline', function($max=3){ AISA_H_Generator::run_once($max); }, 10, 1);
