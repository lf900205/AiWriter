<?php
/**
 * Typecho AI写作助手插件 - 智能排版合并版（支持自定义作家）
 * 
 * @package AiWriter
 * @author Lan-Feng
 * @version 2.2.0
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/Action.php';

class AiWriter_Plugin implements Typecho_Plugin_Interface
{
    const VERSION = '2.2.0';
    const PLUGIN_NAME = 'AiWriter';
    
    private static $supportedServices = [
        'deepseek' => ['name' => 'DeepSeek', 'url' => 'https://platform.deepseek.com/']
    ];
    
    private static $defaultModels = [
        'deepseek' => 'deepseek-flash'
    ];
    
    public static function activate()
    {
        Typecho_Plugin::factory('admin/write-post.php')->bottom = [__CLASS__, 'renderEditorScript'];
        Typecho_Plugin::factory('admin/write-page.php')->bottom = [__CLASS__, 'renderEditorScript'];
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->write = [__CLASS__, 'filterPostData'];
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->write = [__CLASS__, 'filterPostData'];
        
        Helper::addRoute('ai_writer_process', '/ai-writer/process', 'AiWriter_Action', 'process');
        
        return 'AI写作助手插件已激活，请在插件设置中配置DeepSeek API密钥。';
    }
    
    public static function deactivate()
    {
        Helper::removeRoute('ai_writer_process');
        return '插件已禁用';
    }
    
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        echo '<div class="message success">AI写作助手 v' . self::VERSION . ' - 智能排版合并版（支持自定义作家）</div>';
        
        echo '<h3>DeepSeek 配置</h3>';
        
        $keyField = new Typecho_Widget_Helper_Form_Element_Text(
            'deepseekKey', null, '', 'DeepSeek API密钥',
            '从 <a href="https://platform.deepseek.com/" target="_blank">DeepSeek平台</a> 获取'
        );
        $keyField->input->setAttribute('style', 'width: 400px;');
        $form->addInput($keyField);
        
        $modelField = new Typecho_Widget_Helper_Form_Element_Text(
            'deepseekModel', null, self::$defaultModels['deepseek'], 'DeepSeek 模型',
            '默认 ' . self::$defaultModels['deepseek'] . '，建议使用 deepseek-chat 或 deepseek-v4-flash 以获得更长输出'
        );
        $modelField->input->setAttribute('style', 'width: 200px;');
        $form->addInput($modelField);
        
        echo '<h3>生活类文章润色风格</h3>';
        
        // 预设作家列表（用于下拉默认值）
        $presetStyles = [
            'murakami' => '村上春树（细腻隐喻）',
            'yu hua' => '余华（简洁冷峻）',
            'mo yan' => '莫言（魔幻乡土）',
            'none' => '仅通用润色（不模仿特定作家）'
        ];
        
        $literaryStyle = new Typecho_Widget_Helper_Form_Element_Select(
            'literaryStyle',
            $presetStyles,
            'murakami',
            '默认作家风格',
            '选择默认使用的作家风格（可在撰写文章时临时切换）'
        );
        $form->addInput($literaryStyle);
        
        // 自定义作家列表（文本域）
        $customWriters = new Typecho_Widget_Helper_Form_Element_Textarea(
            'customWriters',
            null,
            '',
            '自定义作家列表',
            '每行一个，格式：<strong>作家名|风格描述</strong><br>例如：<br>鲁迅|冷峻犀利，深刻批判<br>张爱玲|细腻婉约，充满市井气息<br>自定义作家将自动出现在前端下拉菜单中。'
        );
        $customWriters->input->setAttribute('style', 'width: 400px; height: 100px;');
        $form->addInput($customWriters);
        
        echo '<h3>通用设置</h3>';
        
        $defaultService = new Typecho_Widget_Helper_Form_Element_Select(
            'defaultService', ['deepseek' => 'DeepSeek'], 'deepseek', '默认AI服务',
            '插件仅支持DeepSeek'
        );
        $form->addInput($defaultService);
        
        $enableSlug = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableSlug', ['1' => '启用', '0' => '禁用'], '1', '自动生成文章ID',
            '启用后，智能排版会自动生成文章ID（Slug）'
        );
        $form->addInput($enableSlug);
        
        $timeout = new Typecho_Widget_Helper_Form_Element_Text(
            'timeout', null, '60', 'API超时时间（秒）',
            'AI API请求的超时时间，建议30-120秒'
        );
        $timeout->input->setAttribute('style', 'width: 100px;');
        $form->addInput($timeout);
        
        $debugMode = new Typecho_Widget_Helper_Form_Element_Radio(
            'debugMode', ['1' => '启用', '0' => '禁用'], '0', '调试模式',
            '启用后会在浏览器控制台显示详细日志'
        );
        $form->addInput($debugMode);
    }
    
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}
    
    public static function filterPostData($contents)
    {
        if (isset($contents['slug']) && is_string($contents['slug'])) {
            $slug = trim($contents['slug']);
            if (empty($slug)) {
                if (isset($contents['title']) && !empty(trim($contents['title']))) {
                    $contents['slug'] = self::generateSlug($contents['title']);
                } else {
                    $contents['slug'] = 'post-' . time();
                }
            } else {
                $contents['slug'] = preg_replace('/[^A-Za-z0-9\-_]/', '', $slug);
                if (empty($contents['slug'])) {
                    $contents['slug'] = 'post-' . time();
                }
            }
        }
        return $contents;
    }
    
    private static function generateSlug($title)
    {
        if (empty($title)) {
            return 'post-' . time();
        }
        $slug = strip_tags($title);
        $slug = preg_replace('/[^\w\s-]/', '', $slug);
        $slug = preg_replace('/[\s_-]+/', '-', $slug);
        $slug = strtolower($slug);
        $slug = trim($slug, '-');
        if (empty($slug)) {
            $slug = 'post-' . time();
        }
        return substr($slug, 0, 50);
    }
    
    public static function renderAdminMenu() {}
    
    public static function renderEditorScript()
    {
        $options = Helper::options();
        $pluginUrl = $options->pluginUrl . '/' . self::PLUGIN_NAME . '/';
        $pluginOptions = $options->plugin(self::PLUGIN_NAME);
        
        $config = [
            'ajaxUrl' => Typecho_Common::url('/ai-writer/process', $options->index),
            'defaultService' => 'deepseek',
            'enableSlug' => $pluginOptions->enableSlug ?: '1',
            'debugMode' => $pluginOptions->debugMode ?: '0',
            'version' => self::VERSION
        ];
        
        echo self::getAIWriterPanelHTML();
        
        echo '<script>
            window.AiWriterConfig = ' . json_encode($config, JSON_UNESCAPED_UNICODE) . ';
        </script>';
        
        echo '<link rel="stylesheet" href="' . $pluginUrl . 'ai-writer.css?v=' . self::VERSION . '">';
        echo '<script src="' . $pluginUrl . 'ai-writer.js?v=' . self::VERSION . '" defer></script>';
    }
    
    private static function getAIWriterPanelHTML()
    {
        $options = Helper::options()->plugin('AiWriter');
        
        // 预设作家
        $presetStyles = [
            'murakami' => '村上春树（细腻隐喻）',
            'yu hua' => '余华（简洁冷峻）',
            'mo yan' => '莫言（魔幻乡土）',
            'none' => '仅通用润色（不模仿特定作家）'
        ];
        
        // 解析自定义作家
        $customWriters = $options->customWriters ?: '';
        $customList = [];
        if ($customWriters) {
            $lines = explode("\n", $customWriters);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $parts = explode('|', $line, 2);
                if (count($parts) == 2) {
                    $name = trim($parts[0]);
                    $desc = trim($parts[1]);
                    if ($name && $desc) {
                        $customList[$name] = $name . '（' . $desc . '）'; // 显示为 "作家名（风格描述）"
                    }
                }
            }
        }
        
        // 合并所有选项（预设 + 自定义）
        $allOptions = $presetStyles;
        foreach ($customList as $value => $label) {
            $allOptions[$value] = $label; // value为作家名，label为显示文本
        }
        
        // 默认值
        $defaultStyle = $options->literaryStyle ?? 'murakami';
        if (!isset($allOptions[$defaultStyle])) {
            $defaultStyle = 'murakami'; // 若默认值不存在则回退
        }
        
        $selectHtml = '';
        foreach ($allOptions as $value => $label) {
            $selected = ($value == $defaultStyle) ? ' selected' : '';
            $selectHtml .= "<option value=\"" . htmlspecialchars($value) . "\"{$selected}>" . htmlspecialchars($label) . "</option>";
        }
        
        return '
        <div id="ai-writer-attach-extension" style="display: none;">
            <div class="ai-writer-panel">
                <div class="ai-service-select">
                    <label for="ai-literary-style" class="ai-service-label">作家风格：</label>
                    <select id="ai-literary-style" class="ai-service-select-input">
                        ' . $selectHtml . '
                    </select>
                </div>
                
                <div class="ai-feature-buttons" style="grid-template-columns: 1fr;">
                    <button class="ai-feature-btn" data-action="format" style="grid-column: span 1;">
                        <span class="ai-feature-icon">🚀</span>
                        <span class="ai-feature-title">智能排版（含分类润色）</span>
                        <span class="ai-feature-desc">自动识别类型，优化内容和结构</span>
                    </button>
                </div>
                
                <div class="ai-processing-status" id="ai-processing-status" style="display: none;">
                    <div class="ai-loading-spinner"></div>
                    <span id="ai-status-text" class="ai-status-text">AI正在分析文章类型并处理，请稍候...</span>
                </div>
                
                <div class="ai-writer-footer">
                    <small class="ai-writer-hint">💡 处理过程需要几秒到几十秒，请勿关闭页面</small>
                </div>
            </div>
        </div>
        ';
    }
}