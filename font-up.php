下面这个插件，每次处理5个就不继续处理了，请优化一下这个插件，让他更健壮，优化完后给出完整代码，不要省略，我是编程小白看不懂，另外不要修改原来的功能和界面，只需要优化，代码如下：
<?php
/*
Plugin Name: 01字体发布
Plugin URI: #
Description: 通过批量上传字体文件，批量发布文章。
Version: 3.0.9
Author: 奥巴牛
Author URI: #
License: GPL2
*/

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义常量
define('FONT_PUBLISHER_TEMP_DIR', WP_CONTENT_DIR . '/font_temp/');
define('FONT_PUBLISHER_LIB_DIR', plugin_dir_path(__FILE__) . 'php-font-lib/');
define('FONT_PUBLISHER_BATCH_SIZE', 5); // 每次处理的文件数量
define('FONT_PUBLISHER_LOG_FILE', WP_CONTENT_DIR . '/plugins/font-publisher/font_up.log');

// 插件激活时创建必要的文件夹
register_activation_hook(__FILE__, 'font_publisher_activate');
function font_publisher_activate() {
    if (!file_exists(FONT_PUBLISHER_TEMP_DIR)) {
        wp_mkdir_p(FONT_PUBLISHER_TEMP_DIR);
    }
}

// 插件卸载时删除文件夹
register_uninstall_hook(__FILE__, 'font_publisher_uninstall');
function font_publisher_uninstall() {
    if (file_exists(FONT_PUBLISHER_TEMP_DIR)) {
        array_map('unlink', glob(FONT_PUBLISHER_TEMP_DIR . '*'));
        rmdir(FONT_PUBLISHER_TEMP_DIR);
    }
}

// 加载 php-font-lib
if (file_exists(FONT_PUBLISHER_LIB_DIR . 'autoload.inc.php')) {
    require_once FONT_PUBLISHER_LIB_DIR . 'autoload.inc.php';
} else {
    wp_die('php-font-lib 库缺失，请确保已正确安装。');
}

// 验证 php-font-lib 是否正确加载
if (!class_exists('FontLib\Font')) {
    wp_die('php-font-lib 库未正确加载。');
}

// 处理字体文件上传
add_action('admin_post_font_upload', 'font_publisher_handle_upload');
function font_publisher_handle_upload() {
    set_time_limit(0); // 取消执行时间限制
    ini_set('memory_limit', '512M'); // 增加内存限制

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['font_files'])) {
        $files = $_FILES['font_files'];
        $category_id = isset($_POST['font_category']) ? intval($_POST['font_category']) : 0;

        if (!file_exists(FONT_PUBLISHER_TEMP_DIR)) {
            wp_mkdir_p(FONT_PUBLISHER_TEMP_DIR);
        }

        $queue = array();
        foreach ($files['name'] as $index => $name) {
            if ($files['error'][$index] === UPLOAD_ERR_OK) {
                $file_name = sanitize_file_name(basename($name));
                $file_path = FONT_PUBLISHER_TEMP_DIR . $file_name;

                if (move_uploaded_file($files['tmp_name'][$index], $file_path)) {
                    $queue[] = array(
                        'file_path' => $file_path,
                        'category_id' => $category_id,
                    );
                } else {
                    error_log('文件移动失败: ' . $file_path);
                }
            }
        }

        $queue_file = FONT_PUBLISHER_TEMP_DIR . 'queue.txt';
        file_put_contents($queue_file, json_encode($queue));

        font_publisher_process_queue();

        wp_send_json_success(array(
            'message' => '字体文件上传成功，后台处理中。',
            'redirect_url' => admin_url('admin.php?page=font-publisher'),
        ));
    } else {
        wp_send_json_error(array(
            'message' => '未上传文件或请求无效。',
        ));
    }
}

// 处理队列（后续代码保持不变，只显示修改部分）
// 处理队列
function font_publisher_process_queue() {
    $queue_file = FONT_PUBLISHER_TEMP_DIR . 'queue.txt';
    if (!file_exists($queue_file)) {
        return;
    }

    $queue = json_decode(file_get_contents($queue_file), true);
    if (empty($queue)) {
        unlink($queue_file);
        return;
    }

    $batch = array_slice($queue, 0, FONT_PUBLISHER_BATCH_SIZE);

    foreach ($batch as $item) {
        $file_path = $item['file_path'];
        $category_id = $item['category_id'];
        font_publisher_process_font($file_path, $category_id);
    }

    $remaining_queue = array_slice($queue, FONT_PUBLISHER_BATCH_SIZE);
    if (!empty($remaining_queue)) {
        file_put_contents($queue_file, json_encode($remaining_queue));
        wp_schedule_single_event(time() + 1, 'font_publisher_process_queue');
    } else {
        unlink($queue_file);
    }

    spawn_cron();
}

// 注册调度任务
add_action('font_publisher_process_queue', 'font_publisher_process_queue');

// 处理字体文件并发布文章
function font_publisher_process_font($file_path, $category_id = 0) {
    if (!class_exists('FontLib\Font')) {
        wp_die('FontLib\Font 类未找到，请检查 php-font-lib 安装。');
    }

    $log_message = "文件: " . basename($file_path) . ", 时间: " . current_time('Y-m-d H:i:s') . ", ";
    try {
        $font = \FontLib\Font::load($file_path);
        $font->parse();

        $font_name = $font->getFontName();
        $font_subfamily = $font->getFontSubfamily();
        $font_subfamily_id = $font->getFontSubfamilyID();
        $font_full_name = $font->getFontFullName();
        $font_version = $font->getFontVersion();
        $font_weight = $font->getFontWeight();
        $font_postscript_name = $font->getFontPostscriptName();
        $font_fontcopyright = $font->getFontCopyright();
        $file_size = filesize($file_path);
        $file_md5 = md5_file($file_path);
        $file_extension = pathinfo($file_path, PATHINFO_EXTENSION);

        if (font_publisher_check_md5($file_md5)) {
            $log_message .= "状态: MD5 重复, 处理结果: 删除文件成功\n";
            log_to_file($log_message);
            unlink($file_path);
            return;
        }

        $year = date('Y');
        $month = date('m');
        $font_upload_dir = WP_CONTENT_DIR . "/uploads/{$year}/{$month}/";
        if (!file_exists($font_upload_dir)) {
            wp_mkdir_p($font_upload_dir);
        }

        $font_file_name = $file_md5 . '.' . $file_extension;
        $font_upload_path = $font_upload_dir . $font_file_name;
        if (!file_exists($font_upload_path)) {
            rename($file_path, $font_upload_path);
        }

        $preview_url = font_publisher_generate_preview($font_name, $font_upload_path, $year, $month);
        $character_map_url = font_publisher_generate_character_map($font_name, $font_upload_path, $year, $month);

        $character_count = 0;
        if ($font instanceof \FontLib\TrueType\File) {
            $cmap = $font->getData("cmap");
            if ($cmap && isset($cmap['subtables'])) {
                foreach ($cmap['subtables'] as $subtable) {
                    if (isset($subtable['glyphIndexArray'])) {
                        $character_count += count($subtable['glyphIndexArray']);
                    }
                }
            }
        }

        $maxp = $font->getData("maxp");
        $glyph_count = isset($maxp["numGlyphs"]) ? $maxp["numGlyphs"] : 0;

        $license_info = 'Unknown';
        $manufacturer = 'Unknown';
        $designer = 'Unknown';
        $description = 'Unknown';
        $name = $font->getData("name");
        if ($name && isset($name["records"])) {
            foreach ($name["records"] as $record) {
                if (isset($record->nameID)) {
                    switch ($record->nameID) {
                        case 8: // 字体制造商
                            $manufacturer = $record->string;
                            break;
                        case 9: // 字体设计师
                            $designer = $record->string;
                            break;
                        case 10: // 字体描述
                            $description = $record->string;
                            break;
                        case 13: // 字体许可证信息
                            $license_info = $record->string;
                            break;
                    }
                }
            }
        }

        $post_content = "<h2>$font_name 字体基本信息</h2>";
        $post_content .= "<ul>";
        $post_content .= "<li>字体名称: $font_name</li>";
        $post_content .= "<li>字体子家族: $font_subfamily</li>";
        $post_content .= "<li>字体子家族 ID: $font_subfamily_id</li>";
        $post_content .= "<li>字体全名: $font_full_name</li>";
        $post_content .= "<li>字体版本: $font_version</li>";
        $post_content .= "<li>字体权重: $font_weight</li>";
        $post_content .= "<li>Postscript 名称: $font_postscript_name</li>";
        $post_content .= "<li>文件大小: " . size_format($file_size) . "</li>";
        $post_content .= "<li>文件扩展名: .$file_extension</li>";
        $post_content .= "<li>字符数量: $character_count</li>";
        $post_content .= "<li>字形数量: $glyph_count</li>";
        $post_content .= "<li>字体版权: $font_fontcopyright</li>";
        $post_content .= "<li>文件MD5值: $file_md5</li>";
        $post_content .= "<li>字体制造商: $manufacturer</li>";
        $post_content .= "<li>字体设计师: $designer</li>";
        $post_content .= "<li>字体描述: $description</li>";
        $post_content .= "</ul>";
        $post_content .= "<h2>$font_name 字体预览图</h2>";

        if ($preview_url) {
            $post_content .= "\n<img src='" . esc_url($preview_url) . "' alt='$font_name 预览图'>";
        }

        if ($character_map_url) {
            $post_content .= "<h2>$font_name 字符映射表</h2>";
            $post_content .= "\n<img src='" . esc_url($character_map_url) . "' alt='$font_name 字符映射表'>";
        }

        $font_download_url = content_url("/uploads/{$year}/{$month}/{$font_file_name}");
        $post_content .= "<h2>$font_name 下载</h2>";
        $post_content .= "\n\n<a href='" . esc_url($font_download_url) . "' download='$font_name.$file_extension' class='button'>下载字体</a>";

        $post_id = wp_insert_post(array(
            'post_title'    => $font_name,
            'post_content'  => $post_content,
            'post_status'   => 'publish',
            'post_author'   => 1,
            'post_type'     => 'post',
            'post_category' => array($category_id),
        ));

        if ($post_id) {
            update_post_meta($post_id, 'font_md5', $file_md5);
            update_post_meta($post_id, 'font_file', $font_file_name);
            $font_relative_path = "/wp-content/uploads/{$year}/{$month}/{$font_file_name}";
            update_post_meta($post_id, 'cfg_font_path', $font_relative_path);
            $log_message .= "状态: 成功, 处理结果: 发布成功\n";
        } else {
            $log_message .= "状态: 失败, 处理结果: 发布失败\n";
        }

    } catch (Exception $e) {
        $log_message .= "状态: 失败, 处理结果: " . $e->getMessage() . "\n";
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }

    log_to_file($log_message);
}

// 检查字体是否已发布
function font_publisher_check_md5($md5) {
    static $md5_cache = array();
    if (isset($md5_cache[$md5])) {
        return true;
    }
    $args = array(
        'post_type' => 'post',
        'meta_key' => 'font_md5',
        'meta_value' => $md5,
        'posts_per_page' => 1,
    );
    $query = new WP_Query($args);
    if ($query->have_posts()) {
        $md5_cache[$md5] = true;
        return true;
    }
    return false;
}

// 生成字体预览图
function font_publisher_generate_preview($font_name, $font_path, $year, $month) {
    if (!file_exists($font_path)) {
        return false;
    }

    $font_size = 48;
    $bbox = imagettfbbox($font_size, 0, $font_path, $font_name);
    $text_width = abs($bbox[2] - $bbox[0]);
    $image_width = $text_width + 20;
    $image_height = 100;

    $image = imagecreatetruecolor($image_width, $image_height);
    if (!$image) {
        return false;
    }

    $background_color = imagecolorallocate($image, 255, 255, 255);
    $text_color = imagecolorallocate($image, 0, 0, 0);
    imagefilledrectangle($image, 0, 0, $image_width, $image_height, $background_color);
    imagettftext($image, $font_size, 0, 10, 80, $text_color, $font_path, $font_name);

    $preview_dir = WP_CONTENT_DIR . "/uploads/{$year}/{$month}/";
    if (!file_exists($preview_dir)) {
        if (!wp_mkdir_p($preview_dir)) {
            return false;
        }
    }

    $random_filename = bin2hex(random_bytes(16));
    $preview_path = $preview_dir . $random_filename . '.png';
    imagepng($image, $preview_path);
    imagedestroy($image);

    $attachment = array(
        'post_mime_type' => 'image/png',
        'post_title'     => preg_replace('/\.[^.]+$/', '', basename($preview_path)),
        'post_content'   => '',
        'post_status'    => 'inherit'
    );

    $attachment_id = wp_insert_attachment($attachment, $preview_path);
    if (is_wp_error($attachment_id)) {
        return false;
    }

    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attachment_data = wp_generate_attachment_metadata($attachment_id, $preview_path);
    wp_update_attachment_metadata($attachment_id, $attachment_data);

    $preview_url = wp_get_attachment_url($attachment_id);
    if (!$preview_url) {
        return false;
    }

    return $preview_url;
}

// 生成字符映射表图片
function font_publisher_generate_character_map($font_name, $font_path, $year, $month) {
    if (!file_exists($font_path)) {
        return false;
    }

    $font_size = 36;
    $text_lines = [
        "好雨知时节当春乃发生",
        "随风潜入夜润物细无声",
        "ABCDEFGHIJ",
        "KLMNOPQRST",
        "UVWXYZ",
        "abcdefghij",
        "klmnopqrst",
        "uvwxyz",
        "1234567890"
    ];

    $cell_margin = 2;
    $max_char_width = 0;
    $max_char_height = 0;
    foreach ($text_lines as $line) {
        $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chars as $char) {
            $bbox = imagettfbbox($font_size, 0, $font_path, $char);
            $char_width = abs($bbox[2] - $bbox[0]);
            $char_height = abs($bbox[7] - $bbox[1]);
            if ($char_width > $max_char_width) {
                $max_char_width = $char_width;
            }
            if ($char_height > $max_char_height) {
                $max_char_height = $char_height;
            }
        }
    }

    $cell_width = $max_char_width + 30;
    $cell_height = $max_char_height + 30;
    $columns = 10;
    $rows = count($text_lines);
    $image_width = ($cell_width + $cell_margin) * $columns + $cell_margin;
    $image_height = ($cell_height + $cell_margin) * $rows + $cell_margin;

    $image = imagecreatetruecolor($image_width, $image_height);
    if (!$image) {
        return false;
    }

    $background_color = imagecolorallocate($image, 246, 247, 248);
    $cell_color = imagecolorallocate($image, 230, 230, 230);
    $text_color = imagecolorallocate($image, 0, 0, 0);
    imagefilledrectangle($image, 0, 0, $image_width, $image_height, $background_color);

    for ($row = 0; $row < $rows; $row++) {
        $line = $text_lines[$row];
        $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);

        for ($col = 0; $col < $columns; $col++) {
            $cell_x = $col * ($cell_width + $cell_margin) + $cell_margin;
            $cell_y = $row * ($cell_height + $cell_margin) + $cell_margin;

            imagefilledrectangle($image, $cell_x, $cell_y, $cell_x + $cell_width, $cell_y + $cell_height, $cell_color);

            if (isset($chars[$col])) {
                $char = $chars[$col];
                $bbox = imagettfbbox($font_size, 0, $font_path, $char);
                $char_width = abs($bbox[2] - $bbox[0]);
                $char_height = abs($bbox[7] - $bbox[1]);

                $char_x = $cell_x + ($cell_width - $char_width) / 2;
                $char_y = $cell_y + ($cell_height - $char_height) / 2 + $char_height;

                imagettftext($image, $font_size, 0, $char_x, $char_y, $text_color, $font_path, $char);
            }
        }
    }

    $character_map_dir = WP_CONTENT_DIR . "/uploads/{$year}/{$month}/";
    if (!file_exists($character_map_dir)) {
        if (!wp_mkdir_p($character_map_dir)) {
            return false;
        }
    }

    $random_filename = bin2hex(random_bytes(16));
    $character_map_path = $character_map_dir . $random_filename . '.png';
    imagepng($image, $character_map_path);
    imagedestroy($image);

    $attachment = array(
        'post_mime_type' => 'image/png',
        'post_title'     => preg_replace('/\.[^.]+$/', '', basename($character_map_path)),
        'post_content'   => '',
        'post_status'    => 'inherit'
    );

    $attachment_id = wp_insert_attachment($attachment, $character_map_path);
    if (is_wp_error($attachment_id)) {
        return false;
    }

    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attachment_data = wp_generate_attachment_metadata($attachment_id, $character_map_path);
    wp_update_attachment_metadata($attachment_id, $attachment_data);

    $character_map_url = wp_get_attachment_url($attachment_id);
    if (!$character_map_url) {
        return false;
    }

    return $character_map_url;
}

// 添加管理页面
add_action('admin_menu', 'font_publisher_admin_menu');
function font_publisher_admin_menu() {
    add_menu_page(
        'Font Publisher',
        '字体发布',
        'manage_options',
        'font-publisher',
        'font_publisher_admin_page',
        'dashicons-editor-textcolor',
        6
    );
}
// 管理页面内容（修改JS部分）
function font_publisher_admin_page() {
    ?>
<style>
    .wrap {
        max-width: 400px;
        margin: 50px auto;
        padding: 20px;
        background-color: #f9f9f9;
        border-radius: 10px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .wrap h1 {
        text-align: center;
        color: #2271b1;
        font-size: 2.5em;
        margin-bottom: 20px;
    }

    #font-upload-form {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    #font-upload-form input[type="file"] {
        padding: 10px;
        border: 2px dashed #2271b1;
        border-radius: 5px;
        background-color: #fff;
        color: #2271b1;
        font-size: 1em;
        cursor: pointer;
        text-align: center;
    }

    #font-upload-form input[type="file"]:hover {
        background-color: #f0f8ff;
    }

    #font-upload-form input[type="submit"] {
        padding: 15px 20px;
        background-color: #2271b1;
        color: #fff;
        border: none;
        border-radius: 5px;
        font-size: 1em;
        cursor: pointer;
        transition: background-color 0.3s ease;
    }

    #font-upload-form input[type="submit"]:hover {
        background-color: #1e84cf;
    }

    #font-upload-form label {
        font-size: 1.1em;
        color: #333;
        margin-bottom: 5px;
    }

    #font_category {
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 5px;
        font-size: 1em;
        width: 100% !important;
        background-color: #fff;
        color: #333;
    }

    #upload-progress {
        margin-top: 20px;
    }

    #progress-bar {
        width: 0%;
        height: 10px;
        border-radius: 50px;
        background: linear-gradient(90deg, #ffffff, #1e84cf, #2271b1);
        transition: width 0.3s ease;
    }

    #progress-message {
        margin-top: 10px;
        font-size: 0.9em;
        color: #333;
        text-align: center;
    }
</style>
<div class="wrap">
    <h1>字体批量发布</h1>
    <form id="font-upload-form" method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
        <input type="hidden" name="action" value="font_upload">
        <input type="file" name="font_files[]" multiple accept=".ttf,.otf">
        
        <!-- 分类选择保持不变 -->
       <div style="margin-top: 20px;">
            <label for="font_category"></label>
            <?php wp_dropdown_categories(array(
                'show_option_none' => '选择分类',
                'option_none_value' => '',
                'hide_empty' => 0,
                'name' => 'font_category',
                'id' => 'font_category',
                'orderby' => 'name',
                'selected' => '',
                'hierarchical' => true,
            )); ?>
        </div>

        <input type="submit" value="批量上传发布字体">
    </form>
    <div id="upload-progress" style="margin-top: 20px; display: none;">
        <div id="progress-bar"></div>
        <p id="progress-message"></p>
    </div>
</div>
<script>
    // 移除文件检查逻辑
    document.getElementById('font-upload-form').addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        var xhr = new XMLHttpRequest();

        // 显示进度条
        document.getElementById('upload-progress').style.display = 'block';
        document.getElementById('progress-message').textContent = '上传中...';

        // 进度监听保持不变
        xhr.upload.addEventListener('progress', function(event) {
            if (event.lengthComputable) {
                var percent = (event.loaded / event.total) * 100;
                document.getElementById('progress-bar').style.width = percent + '%';
            }
        });

        // 结果处理保持不变
        xhr.addEventListener('load', function() {
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        document.getElementById('progress-message').textContent = response.data.message;
                        setTimeout(function() {
                            window.location.href = response.data.redirect_url;
                        }, 1000);
                    } else {
                        document.getElementById('progress-message').textContent = '上传失败: ' + response.data.message;
                    }
                } catch (e) {
                    document.getElementById('progress-message').textContent = '上传失败: 服务器响应无效。';
                }
            } else {
                document.getElementById('progress-message').textContent = '上传失败: 服务器错误，状态码: ' + xhr.status;
            }
        });

        xhr.addEventListener('error', function() {
            document.getElementById('progress-message').textContent = '上传失败: 网络错误。';
        });

        xhr.open('POST', '<?php echo admin_url('admin-post.php'); ?>', true);
        xhr.send(formData);
    });
</script>
    <?php
}

// 日志记录函数
function log_to_file($message) {
    $log_file = FONT_PUBLISHER_LOG_FILE;
    if (!file_exists($log_file)) {
        touch($log_file);
    }
    file_put_contents($log_file, $message, FILE_APPEND);
}
?>