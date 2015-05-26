<?php

/**
 * This file is part of the hyyan/woo-poly-integration plugin.
 * (c) Hyyan Abo Fakher <tiribthea4hyyan@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hyyan\WPI;

use Hyyan\WPI\HooksInterface,
    Hyyan\WPI\Admin\Settings,
    Hyyan\WPI\Admin\Features;

/**
 * Lanaguage
 *
 * @author Hyyan
 */
class Lanaguage
{

    /**
     * Construct object
     */
    public function __construct()
    {
        add_action('load-settings_page_mlang', array(
            $this, 'downlaodWhenPolylangAddLangauge'
        ));

        add_action('woo-poly.settings.wpi-features_fields', array(
            $this, 'addSettingFields'
        ));
    }

    /**
     * Add setting fields
     *
     * Add langauge setting fields
     *
     * @param array $fields
     *
     * @return array
     */
    public function addSettingFields(array $fields)
    {

        $fields [] = array(
            'name' => 'language-downloader',
            'type' => 'checkbox',
            'default' => 'on',
            'label' => __('Translation Downloader', 'woo-poly-integration'),
            'desc' => __(
                    'Download Woocommerce translations when a new polylang language is added'
                    , 'woo-poly-integration'
            )
        );

        return $fields;
    }

    /**
     * Download Translation
     *
     * Download woocommerce translation when polylang add new langauge
     *
     * @return boolean true if action executed successfully , false otherwise
     */
    public function downlaodWhenPolylangAddLangauge()
    {

        if ('off' === Settings::getOption('language-downloader', Features::getID(), 'on')) {
            return false;
        }

        if (
                !isset($_REQUEST['pll_action']) ||
                'add' !== esc_attr($_REQUEST['pll_action'])
        ) {
            return false;
        }

        $name = esc_attr($_REQUEST['name']);
        $locale = esc_attr($_REQUEST['locale']);

        try {
            return static::download($locale, $name);
        } catch (\RuntimeException $ex) {

            add_settings_error(
                    'general'
                    , $ex->getCode()
                    , $ex->getMessage()
            );

            return false;
        }
    }

    /**
     * Download translation files from woocommerce repo
     *
     * @global \WP_Filesystem_Base $wp_filesystem
     *
     * @param string $locale locale
     * @param string $name   langauge name
     *
     * @return boolean true when the translation is downloaded successfully
     *
     * @throws \RuntimeException on errors
     */
    public static function download($locale, $name)
    {
        /* Check if already downlaoded */
        if (static::isDownloaded($locale)) {
            return true;
        }

        /* Check if we can download */
        if (!static::isAvaliable($locale)) {

            $notAvaliable = sprintf(
                    __(
                            'Woocommerce translation %s can not be found in : <a href="%2$s">%2$s</a>'
                            , 'woo-poly-integration'
                    )
                    , sprintf('%s(%s)', $name, $locale)
                    , static::getRepoUrl()
            );

            throw new \RuntimeException($notAvaliable);
        }

        /* Download the language pack */
        $cantDownload = sprintf(
                __('Unable to download woocommerce translation %s from : <a href="%2$s">%2$s</a>')
                , sprintf('%s(%s)', $name, $locale)
                , static::getRepoUrl()
        );
        $response = wp_remote_get(
                sprintf('%s/%s.zip', static::getRepoUrl(), $locale)
                , array('sslverify' => false, 'timeout' => 200)
        );

        if (
                !is_wp_error($response) &&
                ($response['response']['code'] >= 200 &&
                $response['response']['code'] < 300)
        ) {

            /* Initialize the WP filesystem, no more using 'file-put-contents' function */
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once (ABSPATH . '/wp-admin/includes/file.php');
                WP_Filesystem();
            }

            $uploadDir = wp_upload_dir();
            $file = trailingslashit($uploadDir['path']) . $locale . '.zip';

            /* Save the zip file */
            if (!$wp_filesystem->put_contents($file, $response['body'], FS_CHMOD_FILE)) {
                throw new \RuntimeException($cantDownload);
            }

            /* Unzip the file to wp-content/languages/woocommerce directory */
            $dir = trailingslashit(WP_LANG_DIR) . 'woocommerce/';
            $unzip = unzip_file($file, $dir);
            if (true !== $unzip) {
                throw new \RuntimeException($cantDownload);
            }

            /* Delete the package file */
            $wp_filesystem->delete($file);

            return true;
        } else {

            throw new \RuntimeException($cantDownload);
        }
    }

    /**
     * Check if the langauge pack is avaliable in the langauge repo
     *
     * @param string $locale locale
     *
     * @return boolean true if exists , false otherwise
     */
    public static function isAvaliable($locale)
    {
        $response = wp_remote_get(
                sprintf('%s/%s.zip', static::getRepoUrl(), $locale)
                , array('sslverify' => false, 'timeout' => 200)
        );

        if (
                !is_wp_error($response) &&
                ($response['response']['code'] >= 200 &&
                $response['response']['code'] < 300)
        ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Check if woocommerce language file is already downloaded
     *
     * @param string $locale locale
     *
     * @return boolean true if downaloded , false otherwise
     */
    public static function isDownloaded($locale)
    {
        return file_exists(
                sprintf(
                        trailingslashit(WP_LANG_DIR)
                        . '/woocommerce/woocommerce-%s.mo'
                        , $locale
                )
        );
    }

    /**
     * Get langauge repo URL
     *
     * @return string
     */
    public static function getRepoUrl()
    {
        $url = sprintf(
                'https://github.com/woothemes/woocommerce-language-packs/raw/v%s/packages'
                , WC()->version
        );

        return apply_filters(HooksInterface::LANGUAGE_REPO_URL_FILTER, $url);
    }

}
