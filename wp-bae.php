<?php
/**
 * 百度云插件
 *
 * @license     General Public License
 * @author      Yang,Junlong at 2013-8-22 PM3:55:02 build.
 * @link        http://www.crossyou.cn/
 */
/**
 * @package    WordPress
 * @subpackage wp-bae
 * @version    $Id$
 */
/**
 * Plugin Name: 百度云插件
 * Plugin URI: https://github.com/anic/wp-bae
 * Description: Baidu BAE Plugin for wordpress
 * Version: 1.0.0
 * Author: anic
 * Author URI: https://github.com/anic/wp-bae
 */
//使用PHP SDK，并且使用自定义配置文件
include 'BaiduBce.phar';

use BaiduBce\BceClientConfigOptions;
use BaiduBce\Util\Time;
use BaiduBce\Util\MimeTypes;
use BaiduBce\Http\HttpHeaders;
use BaiduBce\Services\Bos\BosClient;

define('BAE_BASENAME', plugin_basename(__FILE__));
define('BAE_BASEFOLDER', plugin_basename(dirname(__FILE__)));
// 初始化选项
register_activation_hook(__FILE__, 'bae_set_options');
function bae_set_options()
{
    $options = array(
        'bucket' => '',
        'ak' => '',
        'sk' => '',
        'host' => 'bj.bcebos.com',
    );

    add_option('bae_options', $options, '', 'yes');
}

//提示信息
function bae_admin_warnings()
{
    $bos_options = get_option('bcs_options', TRUE);
    if (!$bos_options['bucket'] && !isset($_POST['submit'])) {
        function bos_warning()
        {
            echo "<div id='bcs-warning' class='updated fade'><p><strong>" . __('Bos is almost ready.') . "</strong> " . sprintf(__('You must <a href="%1$s">enter your Bos Bucket </a> for it to work.'), "options-general.php?page=" . BCS_BASEFOLDER . "/bcs-support.php") . "</p></div>";
        }

        add_action('admin_notices', 'bos_warning');
        return;
    }
}

//获取BaiduBOS对象
function bae_get_baidu_bos()
{
    $bae_options = get_option('bae_options', TRUE);
    $ak = esc_attr($bae_options['ak']);
    $sk = esc_attr($bae_options['sk']);
    $host = esc_attr($bae_options['host']);
    $config =
        array(
            'credentials' => array(
                'ak' => $ak,
                'sk' => $sk,
            ),
            'endpoint' => "http://$host",
        );
    $bos_client = new BosClient($config);
    return $bos_client;
}

#解决上传文件中文编码
function bae_wp_handle_upload_prefilter($file)
{
    $time = date("Y-m-d H:i:s");
    $file['name'] = $time . "" . mt_rand(1, 100) . "." . pathinfo($file['name'], PATHINFO_EXTENSION);
    return $file;
}


//上传文件到bos远程服务器
function bae_file_upload_to_bos($object, $file, $opt = array())
{
    $bos_client = bae_get_baidu_bos();
    $bae_options = get_option('bae_options', TRUE);
    $bucket = esc_attr($bae_options['bucket']);
    try {
        $bos_client->putObjectFromFile($bucket, $object, $file);
    } catch (Exception $e) {
        return new WP_Error('bae_error', $e->getMessage());
    }
    return true;
}

function bae_upload_attachments_to_bos($data)
{
    $type = $data['type'];
    //获取上传路径
    $wp_upload_dir = wp_upload_dir();
    $upload_path = get_option('upload_path');
    if ($upload_path == '.' || $upload_path == "") {
        $upload_path = '/';
    } else {
        $upload_path = '/' . trim($upload_path, '/');
    }

    //上传原始文件
    $object = $upload_path . $wp_upload_dir['subdir'] . '/' . basename($data['file']);
    $file = $data['file'];

    $opt = array(
        'headers' => array('Content-Type' => $type)
    );
    $result = bae_file_upload_to_bos($object, $file, $opt);
    if (is_wp_error($result)) {
        $data['error'] = $result->get_error_message();
    }
    return $data;
}

function bae_upload_attachment($metadata)
{
    $wp_upload_dir = wp_upload_dir();

    $upload_path = get_option('upload_path');
    if ($upload_path == '.' || $upload_path == '') {
        $upload_path = '/';
    } else {
        $upload_path = '/' . trim($upload_path, '/') . '/';
    }

    if (isset($metadata["file"])) {
        $object = $upload_path . $metadata['file'];
        $file = $wp_upload_dir['basedir'] . '/' . $metadata['file'];
        //上传原始文件
        bae_file_upload_to_bos($object, $file);
        //上传小尺寸文件
        if (isset($metadata['sizes']) && count($metadata['sizes']) > 0) {
            foreach ($metadata['sizes'] as $val) {
                $object = $upload_path . $wp_upload_dir['subdir'] . '/' . $val['file'];
                $file = $wp_upload_dir['path'] . '/' . $val['file'];
                $opt = array(
                    'headers' => array('Content-Type' => $val['mime-type'])
                );
                bae_file_upload_to_bos($object, $file, $opt);
            }
        }
    }
    return $metadata;
}


function bae_image_editor_choose($options)
{
//   $options =  array( 'WP_Image_Editor_Imagick','WP_Image_Editor_GD');
    return array('WP_Image_Editor_GD', 'WP_Image_Editor_Imagick');
}

function bae_format_url($url)
{
    $bae_options = get_option('bae_options', TRUE);
    $bucket = esc_attr($bae_options['bucket']);
    $host = esc_attr($bae_options['host']);

    //TODO:应该根据wp-config.php配置进行分割
    $start = "wp-content/uploads/";

    if (strpos($url, $start) !== false) {
        $arr = explode($start, $url);
        $url = "http://$bucket.$host/" . $arr[1];
    }
    return $url;
}

function bae_image_make_intermediate_size($filename)
{
    error_log($filename);
    //TODO:应该根据wp-config.php配置进行分割
    $start = "wp-content/uploads/";

    if (strpos($filename, $start) !== false) {
        $arr = explode($start, $filename);
        $object = $arr[1];
        $result = bae_file_upload_to_bos($object,$filename);
        if(!is_wp_error($result))
        {
            return bae_format_url($filename);
        }
    }
    return $filename;
}


//删除BOS上的附件 Thanks Loveyuki（loveyuki@gmail.com）
function bae_del_attachments_from_bos($file)
{
    $upload_dir = wp_upload_dir();

    $object = str_replace($upload_dir['basedir'], '', $file);
//    $object = ltrim($object, '/');
    $bae_options = get_option('bae_options', TRUE);
    $bucket = esc_attr($bae_options['bucket']);
    $client = bae_get_baidu_bos();
    try {
        $client->deleteObject($bucket, $object);
    } catch (Exception $e) {
        error_log("bae_del_attachments_from_bos" . $e->getMessage());
    }
    return $file;
}

function bae_plugin_action_links($links, $file)
{
    if ($file == plugin_basename(dirname(__FILE__) . '/wp-bae.php')) {
        $links[] = '<a href="options-general.php?page=' . BAE_BASEFOLDER . '/wp-bae.php">' . __('Settings') . '</a>';
    }
    return $links;
}

function bae_add_setting_page()
{
    add_options_page('百度云BOS存储设置', '百度云插件', 'manage_options', __FILE__, 'bae_setting_page');
}

function bae_setting_page()
{
    $options = array();
    $settings_updated = false;

    if (isset($_POST['bucket'])) {
        $options['bucket'] = trim(stripslashes($_POST['bucket']));
    }
    if (isset($_POST['ak'])) {
        $options['ak'] = trim(stripslashes($_POST['ak']));
    }
    if (isset($_POST['sk'])) {
        $options['sk'] = trim(stripslashes($_POST['sk']));
    }
    if (isset($_POST['host'])) {
        $options['host'] = trim(stripslashes($_POST['host']));
    }

    if ($options !== array()) {
        update_option('bae_options', $options);
        $settings_updated = true;
    }

    $bae_options = get_option('bae_options', TRUE);

    $bae_bucket = esc_attr($bae_options['bucket']);
    $bae_ak = esc_attr($bae_options['ak']);
    $bae_sk = esc_attr($bae_options['sk']);
    $bae_host = esc_attr($bae_options['host']);

    ?>

    <div class="wrap">
        <div id="icon-options-general" class="icon32"><br></div>
        <h2>百度云BOS存储设置</h2>
        <?php if ($settings_updated): ?>
            <div id="setting-error-settings_updated" class="updated settings-error">
                <p><strong>设置已保存。</strong></p></div>
        <?php endif; ?>
        <form name="form1" method="post"
              action="<?php echo wp_nonce_url('./options-general.php?page=' . BAE_BASEFOLDER . '/wp-bae.php'); ?>">
            <table class="form-table">
                <tbody>
                <tr valign="top">
                    <th scope="row"><label for="bucket">Bucket设置</label></th>
                    <td>
                        <input name="bucket" type="text" id="bucket" value="<?php echo $bae_bucket; ?>"
                               class="regular-text" placeholder="请输入云存储使用的 Bucket">

                        <p class="description">访问 <a href="http://console.bce.baidu.com/bos/" target="_blank">百度开放云对象存储BOS</a> 创建
                            Bucket ，设置权限为“公有读”，填写以上内容。</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="ak">Access Key / API key(AK)</label></th>
                    <td><input name="ak" type="text" id="ak"
                               value="<?php echo $bae_ak; ?>" class="regular-text">

                        <p class="description">访问“安全认证”->“ <a href="http://console.bce.baidu.com/iam/#/iam/accesslist"
                                                     target="_blank">AccessKey</a>”，获取 AK和SK。</p></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="sk">Secret Key (SK)</label></th>
                    <td><input name="sk" type="text" id="sk"
                               value="<?php echo $bae_sk; ?>" class="regular-text">
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="sk">HOST设置</label></th>
                    <td><input name="host" type="text" id="sk"
                               value="<?php echo $bae_host; ?>" class="regular-text">

                        <p class="description">根据地域设置HOST，例如“华北 - 北京”为bj.bcebos.com（请勿带http前缀）</p></td>
                </tr>
                </tbody>
            </table>
            <p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="保存更改">
            </p>
        </form>
    </div>
<?php }
//Support image attribude srcset
function bos_custom_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ){
        $result = array();

        foreach ( $sources as $source ) {
            $source['url'] = bae_format_url($source['url']);
            $result[] = $source;
        }

       return $result;
 }

add_filter( 'wp_calculate_image_srcset', 'bos_custom_image_srcset', 10, 5 );

//生成缩略图后立即上传
add_filter('wp_generate_attachment_metadata', 'bae_upload_attachment', 999);
//add_filter("wp_image_editors", "bae_image_editor_choose");
add_filter('plugin_action_links', 'bae_plugin_action_links', 10, 2);
add_filter('wp_get_attachment_url', 'bae_format_url');
//BAE不支持中文文件名，上传时候修改
add_filter('wp_handle_upload_prefilter', 'bae_wp_handle_upload_prefilter');
//BAE文件上传到BOS上
add_filter('wp_handle_upload', 'bae_upload_attachments_to_bos');
add_action('wp_delete_file', 'bae_del_attachments_from_bos');
add_action('admin_menu', 'bae_add_setting_page');
add_filter("image_make_intermediate_size","bae_image_make_intermediate_size");

/* End of file: index.php */
/* Location: ./index.php */
