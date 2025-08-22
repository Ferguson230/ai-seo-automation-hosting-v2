<?php
if (!defined('ABSPATH')) exit;

class AISA_H_OpenAI {

    public static function complete( $api_key, $system, $prompt, $model = 'gpt-4o-mini', $max_tokens = 1600 ) {
        if ( empty($api_key) ) {
            return new WP_Error('no_key','OpenAI API key not configured.');
        }
        $url = 'https://api.openai.com/v1/chat/completions';
        $body = array(
            'model' => $model,
            'messages' => array(
                array('role'=>'system','content'=>$system),
                array('role'=>'user','content'=>$prompt),
            ),
            'temperature' => 0.3,
            'max_tokens' => $max_tokens,
        );
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($body),
            'timeout' => 80,
        );
        $resp = wp_remote_post($url, $args);
        if ( is_wp_error($resp) ) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $text = wp_remote_retrieve_body($resp);
        if ( $code !== 200 ) {
            return new WP_Error('openai_error','OpenAI returned HTTP ' . $code . ': ' . $text );
        }
        $json = json_decode($text, true);
        $content = $json['choices'][0]['message']['content'] ?? '';
        if ( ! $content ) {
            return new WP_Error('openai_no_content','OpenAI returned no content.');
        }
        return $content;
    }
}
